#!/usr/bin/env php
<?php

declare(strict_types=1);

function get_processor_cores_number(): int
{
    return match (PHP_OS_FAMILY) {
        'Darwin' => (int) shell_exec('sysctl -n hw.ncpu'),
        default => (int) shell_exec('nproc'),
    };
}

// Server configuration
define('HOST', '0.0.0.0');
define('PORT', 8000);
define('WORKER_COUNT', get_processor_cores_number());
define('KEEP_ALIVE_TIMEOUT', 5);
define('KEEP_ALIVE_MAX_REQUESTS', 100);

$web_dir = getenv('BASE_WEB_DIR') ?: __DIR__;
$workers = [];

const DEFAULT_RESPONSE_HEADERS = [
    'Server' => 'joojoo',
    'Connection' => 'Keep-alive',
];

enum HTTP_STATUS: string
{
    case OK = '200';
    case NO_CONTENT = '204';
    case MOVED_PERMANENTLY = '301';
    case FOUND = '302';
    case NOT_MODIFIED = '304';
    case BAD_REQUEST = '400';
    case FORBIDDEN = '403';
    case NOT_FOUND = '404';
    case METHOD_NOT_ALLOWED = '405';
    case INTERNAL_SERVER_ERROR = '500';
    case NOT_IMPLEMENTED = '501';
    case SERVICE_UNAVAILABLE = '503';
}

function get_status_message(HTTP_STATUS $status): string
{
    return match ($status) {
        HTTP_STATUS::OK => '200 OK',
        HTTP_STATUS::NO_CONTENT => '204 No Content',
        HTTP_STATUS::MOVED_PERMANENTLY => '301 Moved Permanently',
        HTTP_STATUS::FOUND => '302 Found',
        HTTP_STATUS::NOT_MODIFIED => '304 Not Modified',
        HTTP_STATUS::BAD_REQUEST => '400 Bad Request',
        HTTP_STATUS::FORBIDDEN => '403 Forbidden',
        HTTP_STATUS::NOT_FOUND => '404 Not Found',
        HTTP_STATUS::METHOD_NOT_ALLOWED => '405 Method Not Allowed',
        HTTP_STATUS::INTERNAL_SERVER_ERROR => '500 Internal Server Error',
        HTTP_STATUS::NOT_IMPLEMENTED => '501 Not Implemented',
        HTTP_STATUS::SERVICE_UNAVAILABLE => '503 Service Unavailable',
        default => "$status->value Unknown",
    };
}

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
    'apk' => 'application/vnd.android.package-archive',
];

function logging(string $message): void
{
    echo $message . PHP_EOL;
}

function get_first_line_http(string $request): string
{
    return explode("\r\n", trim($request), 2)[0];
}

function get_headers_from_request(string $request): array
{
    $lines = explode("\r\n", trim($request));
    array_shift($lines); // Remove request line

    $headers = [];
    foreach ($lines as $line) {
        if (empty($line)) {
            break;
        }
        if (! str_contains($line, ':')) {
            continue;
        }

        [$key, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($key))] = trim($value);
    }
    return $headers;
}

function should_keep_alive(array $request_headers): bool
{
    return isset($request_headers['connection'])
        ? strtolower($request_headers['connection']) === 'keep-alive'
        : true; // HTTP/1.1 defaults to keep-alive (RFC 7230)
}

function file_mime_detector(string $requested_file, array $content_types): string
{
    $file_extension = pathinfo($requested_file, PATHINFO_EXTENSION);
    return $content_types[$file_extension] ?? 'application/octet-stream';
}

function build_http_response(HTTP_STATUS $status_code, array $headers, string $body): string
{

    $status_line = get_status_message($status_code);

    $header_string = '';
    foreach ($headers as $key => $value) {
        $header_string .= "$key: $value\r\n";
    }

    return "HTTP/1.1 $status_line\r\n$header_string\r\n$body";
}

function handle_file_response(string $requested_file, array $content_types): array
{
    if (! is_readable($requested_file)) {
        return handle_not_found_response();
    }

    $body = file_get_contents($requested_file);
    $headers = [...DEFAULT_RESPONSE_HEADERS, 'Content-Type' => file_mime_detector($requested_file, $content_types)];

    return [HTTP_STATUS::OK, $headers, $body];
}

function handle_not_found_response(): array
{
    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<meta content="width=device-width,initial-scale=1.0" name="viewport">'
        . '<title>Not Found</title></head><body><h1>404 Not Found</h1>'
        . '<p>The requested file was not found.</p></body></html>';

    $headers = [...DEFAULT_RESPONSE_HEADERS, 'Content-Type' => 'text/html'];

    return [HTTP_STATUS::NOT_FOUND, $headers, $body];
}

function create_server_socket(string $host, int $port): Socket|false
{
    $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($sock === false) {
        return false;
    }

    if (! socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1)) {
        socket_close($sock);
        return false;
    }

    if (! socket_bind($sock, $host, $port)) {
        socket_close($sock);
        return false;
    }

    if (! socket_listen($sock, SOMAXCONN)) {
        socket_close($sock);
        return false;
    }

    return $sock;
}

function read_request(Socket $client): string|false
{
    $request = '';
    while (! str_ends_with($request, "\r\n\r\n")) {
        $data = @socket_read($client, 1024);
        if ($data === false || $data === '') {
            return false;
        }
        $request .= $data;
    }
    return $request;
}

function worker_process(
    Socket $socket,
    string $web_dir,
    array $content_types,
    int $keep_alive_max_requests,
    int $keep_alive_timeout
): void {
    while ($client = socket_accept($socket)) {
        // Configure timeouts for keep-alive
        socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $keep_alive_timeout, 'usec' => 0]);
        socket_set_option($client, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $keep_alive_timeout, 'usec' => 0]);

        handle_client_connection($client, $web_dir, $content_types, $keep_alive_max_requests, $keep_alive_timeout);

        socket_close($client);
    }
}

function handle_client_connection(
    Socket $client,
    string $web_dir,
    array $content_types,
    int $keep_alive_max_requests,
    int $keep_alive_timeout
): void {
    $request_count = 0;
    $keep_connection = true;

    while ($keep_connection && $request_count < $keep_alive_max_requests) {
        $request = read_request($client);

        if ($request === false || empty($request)) {
            break;
        }

        $request_count++;
        $request_headers = get_headers_from_request($request);
        $first_line = get_first_line_http($request);

        // Parse request path
        $request_uri = explode(' ', $first_line)[1] ?? '/';
        $request_path = parse_url($request_uri, PHP_URL_PATH) ?? '/';

        // Generate response
        $file_path = $web_dir . $request_path;
        [$status_code, $headers, $body] = is_file($file_path)
            ? handle_file_response($file_path, $content_types)
            : handle_not_found_response();

        // Determine connection persistence
        $client_wants_keepalive = should_keep_alive($request_headers);
        $should_close = ! $client_wants_keepalive || $request_count >= $keep_alive_max_requests;

        if ($should_close) {
            $headers['Connection'] = 'close';
            $keep_connection = false;
        } else {
            $headers['Connection'] = 'Keep-Alive';
            $headers['Keep-Alive'] = "timeout=$keep_alive_timeout, max=" . ($keep_alive_max_requests - $request_count);
        }

        $headers['Content-Length'] = strlen($body);

        // Send response
        $response = build_http_response($status_code, $headers, $body);
        $bytes_written = @socket_write($client, $response, strlen($response));

        if ($bytes_written === false) {
            logging('Error writing to socket: ' . socket_strerror(socket_last_error($client)));
            break;
        }

        // Log request
        socket_getpeername($client, $address);
        $pid = posix_getpid();
        $timestamp = date('d/M/Y:H:i:s O');
        logging("[$pid] $address - - [$timestamp] \"$first_line\" $status_code->value " . strlen($body));
    }
}

$sock = create_server_socket(HOST, PORT);
if ($sock === false) {
    logging('Failed to create server socket: ' . socket_strerror(socket_last_error()));
    exit(1);
}

// Fork worker processes
for ($i = 0; $i < WORKER_COUNT; $i++) {
    $pid = pcntl_fork();

    if ($pid === -1) {
        logging('Failed to fork worker process');
        exit(1);
    }

    if ($pid) {
        // Parent process
        $workers[] = $pid;
    } else {
        // Child process - become a worker
        worker_process($sock, $web_dir, $content_types, KEEP_ALIVE_MAX_REQUESTS, KEEP_ALIVE_TIMEOUT);
        exit(0);
    }
}

logging('🚀 Server is running on ' . HOST . ':' . PORT . ' with ' . WORKER_COUNT . ' workers');

// Wait for all workers
foreach ($workers as $worker_pid) {
    pcntl_waitpid($worker_pid, $status);
}


// refactor: make code more organized and modular, add comments, and improve error handling.