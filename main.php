#!/usr/bin/env php
<?php

declare(strict_types=1);

$interface = '0.0.0.0';
$port = 8000;
$worker_count = get_processor_cores_number();
$workers = [];

$web_dir = empty(getenv('BASE_WEB_DIR')) ? __DIR__ : getenv('BASE_WEB_DIR');
$default_headers = [
    'Server' => 'joojoo',
    'Connection' => 'Keep-alive'
];

$keep_alive_timeout = 5; // seconds
$keep_alive_max_requests = 100;

// MIME types
$content_types = [
    'html' => 'text/html;charset=utf-8',
    'css' => 'text/css',
    'js' => 'text/javascript',
    'apng' => 'image/apng',
    'gif' => 'image/gif',
    'jpeg' => 'image/jpeg',
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'svg' => 'image/svg+xml',
    'webp' => 'image/webp',
    'ogg' => 'audio/ogg',
    'oga' => 'audio/ogg',
    'mp3' => 'audio/mpeg3',
    'wav' => 'audio/wav',
    'mp4' => 'video/mp4',
    '.3gp' => 'video/3gpp',
    'flv' => 'video/x-flv',
    'mov' => 'video/quicktime',
    'mpg4' => 'video/mp4',
    'json' => 'application/json',
    'apk'  => 'application/vnd.android.package-archive'
];

function file_mime_detector(string $requested_file, array $content_types): string
{
    $file_extension = pathinfo($requested_file, PATHINFO_EXTENSION);
    return $content_types[$file_extension] ?? 'application/octet-stream'; // Default MIME type
}

function logging(string $message): void
{
    echo "$message" . PHP_EOL;
}

function get_headers_from_request(string $request): array
{
    $lines = explode("\r\n", trim($request));
    array_shift($lines); // Remove the request line
    $headers = [];
    foreach ($lines as $line) {
        if (strpos($line, ':') !== false) {
            list($key, $value) = explode(':', $line, 2);
            $headers[strtolower(trim($key))] = trim($value);
        }
    }
    return $headers;
}

function get_first_line_http(string $request): string
{
    return explode("\r\n", trim($request))[0];
}

function handle_file_response(string $requested_file, array $content_types, array $default_headers): array
{
    if (!is_readable($requested_file)) {
        return handle_not_found_response($default_headers);
    }
    $body = file_get_contents($requested_file);
    return ['200', array_merge($default_headers, ['Content-Type' => file_mime_detector($requested_file, $content_types)]), $body];
}

function handle_not_found_response(array $default_headers): array
{
    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta content="width=device-width,initial-scale=1.0" name="viewport"><meta content="ie=edge" http-equiv="X-UA-Compatible"><title>Not founded</title></head><body><p>File or directory not founded.</p></body></html>';
    return [
        '404',
        array_merge($default_headers, ['Content-Type' => 'text/html']),
        $body
    ];
}

function handle_error(string $message, $client_socket): void
{
    logging("Error: $message");
    socket_close($client_socket);
}

function should_keep_alive(array $request_headers): bool
{
    if(isset($request_headers['connection'])) {
        return strtolower($request_headers['connection']) === 'keep-alive';
    }
    // HTTP/1.1 defaults to keep-alive based on rfc7230
    return true;
}

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($sock === false) {
    $error = socket_strerror(socket_last_error());
    logging('Failed to create socket: ' . $error);
    exit();
}

if (!socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1)) {
    $error = socket_strerror(socket_last_error());
    logging('Unable to set option on socket: ' . $error);
    exit();
}

function get_processor_cores_number(): int
{
    return match (PHP_OS_FAMILY) {
        'Darwin' =>  (int) shell_exec('sysctl -n hw.ncpu'),
        default => (int) shell_exec('nproc'),
    };
}

$is_bind = socket_bind($sock, $interface, $port);
if ($is_bind === false) {
    $error = socket_strerror(socket_last_error());
    logging('Failed to bind socket: ' . $error);
    exit();
}

$is_listen = socket_listen($sock, SOMAXCONN);
if ($is_listen === false) {
    $error = socket_strerror(socket_last_error());
    logging('Failed to listen to socket: ' . $error);
    exit();
}

// socket_set_nonblock($sock);

function worker_process(Socket $socket, string $web_dir, array $content_types, array $default_headers, int $keep_alive_max_requests, int $keep_alive_timeout): void
{
    while ($client = socket_accept($socket)) {
        socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $keep_alive_timeout, 'usec' => 0]);
        socket_set_option($client, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $keep_alive_timeout, 'usec' => 0]);
        
        $request_count = 0;
        $keep_connection = true;

        while($keep_connection && $request_count < $keep_alive_max_requests) {
            $request = '';
            while (!str_ends_with($request, "\r\n\r\n")) {
                $data = @socket_read($client, 1024);
                if ($data === false || $data === '') {
                    $keep_connection = false;
                    break 2;
                }
                $request .= $data;
            }

            if (empty($request)) {
                break;
            }

            $request_count++;
            $request_headers = get_headers_from_request($request);
            $keep_connection = should_keep_alive($request_headers);
            $request_path = parse_url(explode(" ", get_first_line_http($request))[1], PHP_URL_PATH);

            if (!is_file($web_dir . $request_path)) {
                list($code, $headers, $body) = handle_not_found_response($default_headers);
            } else {
                list($code, $headers, $body) = handle_file_response(($web_dir . $request_path), $content_types, $default_headers);
            }

            $client_keep_alive = should_keep_alive($request_headers); 
            if(!$client_keep_alive || $request_count >= $keep_alive_max_requests) {
                $headers['Connection'] = 'close';
                $keep_connection = false;
            } else {
                $headers['Connection'] = 'Keep-alive';
                $headers['Keep-Alive'] = "timeout=$keep_alive_timeout, max=".($keep_alive_max_requests - $request_count);
            }

            if(!isset($headers['Content-Length'])) {
                $headers['Content-Length'] = strlen($body);
            }
            
            $header_string = '';
            foreach ($headers as $k => $v) {
                $header_string  .= $k . ': ' . $v . "\r\n";
            }
            $response = "HTTP/1.1 $code OK\r\n$header_string\r\n$body";

            $bytes_written = @socket_write($client, $response, strlen($response));
            if ($bytes_written === false) {
                $error = socket_strerror(socket_last_error($client));
                handle_error("Error writing to socket: $error", $client);
            }
            socket_getpeername($client, $address);
            logging("socket pid: " . posix_getpid() . " - " . $address . ' - - ' . "[" . date("d/M/Y:H:i:s O") . "]" . ' ' . get_first_line_http($request) . ' ' . $code . ' ' . strlen($body));

        }
        socket_close($client);
    }
}


for ($i = 0; $i < $worker_count; $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        logging("Failed to fork the process");
        exit();
    } elseif ($pid) {
        $workers[] = $pid;
    } else {
        worker_process($sock, $web_dir, $content_types, $default_headers, $keep_alive_max_requests, $keep_alive_timeout);
        exit(0);
    }
}

echo "🚀 Server is running on $interface:$port with $worker_count workers." . PHP_EOL;
print_r($workers);

foreach ($workers as $worker_pid) {
    pcntl_waitpid($worker_pid, $status);
}
