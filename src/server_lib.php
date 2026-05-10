<?php

declare(strict_types=1);

// Server configuration
define('HOST', '0.0.0.0');
define('PORT', 8000);
define('WORKER_COUNT', get_processor_cores_number() * 2);
define('KEEP_ALIVE_TIMEOUT', 5);
define('KEEP_ALIVE_MAX_REQUESTS', 100);

const DEFAULT_RESPONSE_HEADERS = [
    'Server' => 'joojoo',
    'Connection' => 'Keep-alive',
];

enum HTTP_STATUS: string
{
    case OK = '200';
    case FORBIDDEN = '403';
    case NOT_FOUND = '404';
    case METHOD_NOT_ALLOWED = '405';
}

/**
 * Default content type map for static files.
 */
const DEFAULT_CONTENT_TYPES = [
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


/**
 * Resolve the number of CPU cores for worker process sizing.
 */
function get_processor_cores_number(): int
{
    return match (PHP_OS_FAMILY) {
        'Darwin' => (int) shell_exec('sysctl -n hw.ncpu'),
        default => (int) shell_exec('nproc'),
    };
}

/**
 * Write a single log line to standard output.
 */
function logging(string $message): void
{
    echo $message . PHP_EOL;
}

/**
 * Parse request metadata (method, path, headers, first line) from a raw request.
 */
function parse_request_context(string $request): array
{
    $lines = explode("\r\n", trim($request));
    $first_line = $lines[0] ?? '';
    $method = strtoupper(explode(' ', $first_line)[0] ?? 'GET');
    $request_uri = explode(' ', $first_line)[1] ?? '/';
    $request_path = parse_url($request_uri, PHP_URL_PATH) ?? '/';

    $headers = [];
    foreach (array_slice($lines, 1) as $line) {
        if (empty($line)) {
            break;
        }
        if (! str_contains($line, ':')) {
            continue;
        }
        [$key, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($key))] = trim($value);
    }

    return [
        'method' => $method,
        'first_line' => $first_line,
        'headers' => $headers,
        'request_path' => $request_path,
    ];
}

/**
 * Route request path to either static file response or error response.
 */
function route_request_response(string $web_dir, string $request_path, array $accepted_encodings, array $content_types): array
{
    if (str_contains($request_path, "\0")) {
        return handle_error_response(HTTP_STATUS::FORBIDDEN);
    }
    foreach (explode('/', $request_path) as $segment) {
        if ($segment === '..') {
            return handle_error_response(HTTP_STATUS::FORBIDDEN);
        }
    }

    $file_path = rtrim($web_dir, '/') . $request_path;
    if (is_dir($file_path)) {
        $file_path = rtrim($file_path, '/') . '/index.html';
    }

    if (! is_file($file_path) || ! is_readable($file_path)) {
        return handle_error_response(HTTP_STATUS::NOT_FOUND);
    }

    $extension = pathinfo($file_path, PATHINFO_EXTENSION);
    $headers = [...DEFAULT_RESPONSE_HEADERS, 'Content-Type' => $content_types[$extension] ?? 'application/octet-stream'];

    // Handle accepted encodings and static/on-the-fly gzip.
    if (in_array('gzip', $accepted_encodings, true)) {
        $headers['Content-Encoding'] = 'gzip';
        $headers['Vary'] = 'Accept-Encoding';
        $body = is_readable($file_path . '.gz')
            ? file_get_contents($file_path . '.gz')
            : gzencode(file_get_contents($file_path));
    } else {
        $body = file_get_contents($file_path);
    }

    return [HTTP_STATUS::OK, $headers, $body];
}

/**
 * Dispatch request handling by HTTP method (GET, HEAD, or 405).
 */
function handle_request_by_method(string $web_dir, array $request_context, array $content_types): array
{
    $accepted_encodings = array_map('trim', explode(',', $request_context['headers']['accept-encoding'] ?? ''));
    $resource_response = route_request_response($web_dir, $request_context['request_path'], $accepted_encodings, $content_types);

    return match ($request_context['method']) {
        'GET' => $resource_response,
        'HEAD' => [
            $resource_response[0],
            [...$resource_response[1], 'Content-Length' => strlen($resource_response[2])],
            '',
        ],
        default => handle_error_response(HTTP_STATUS::METHOD_NOT_ALLOWED, ['GET', 'HEAD']),
    };
}

/**
 * Build a minimal HTML error response tuple for 403/404/405.
 */
function handle_error_response(HTTP_STATUS $status, array $allowed_methods = []): array
{
    $messages = [
        HTTP_STATUS::FORBIDDEN->value => ['403 Forbidden', 'Access to this resource is not allowed.'],
        HTTP_STATUS::NOT_FOUND->value => ['404 Not Found', 'The requested file was not found.'],
        HTTP_STATUS::METHOD_NOT_ALLOWED->value => ['405 Method Not Allowed', 'The requested method is not supported for this resource.'],
    ];
    [$title, $message] = $messages[$status->value];

    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<meta content="width=device-width,initial-scale=1.0" name="viewport">'
        . "<title>$title</title></head><body><h1>$title</h1><p>$message</p></body></html>";

    $headers = [...DEFAULT_RESPONSE_HEADERS, 'Content-Type' => 'text/html'];
    if ($allowed_methods) {
        $headers['Allow'] = implode(', ', $allowed_methods);
    }

    return [$status, $headers, $body];
}

/**
 * Apply connection headers and determine whether this connection should close.
 */
function apply_connection_policy(
    array $headers,
    bool $client_wants_keepalive,
    int $request_count,
    int $keep_alive_max_requests,
    int $keep_alive_timeout
): array {
    $should_close = ! $client_wants_keepalive || $request_count >= $keep_alive_max_requests;

    if ($should_close) {
        return [
            'headers' => [...$headers, 'Connection' => 'close'],
            'keep_connection' => false,
        ];
    }

    $remaining_requests = $keep_alive_max_requests - $request_count;

    return [
        'headers' => [
            ...$headers,
            'Connection' => 'Keep-Alive',
            'Keep-Alive' => "timeout=$keep_alive_timeout, max=$remaining_requests",
        ],
        'keep_connection' => true,
    ];
}

/**
 * Build a full HTTP/1.1 response string from status, headers, and body.
 */
function build_http_response(HTTP_STATUS $status_code, array $headers, string $body): string
{
    $reasons = [
        HTTP_STATUS::OK->value => 'OK',
        HTTP_STATUS::FORBIDDEN->value => 'Forbidden',
        HTTP_STATUS::NOT_FOUND->value => 'Not Found',
        HTTP_STATUS::METHOD_NOT_ALLOWED->value => 'Method Not Allowed',
    ];
    $status_line = $status_code->value . ' ' . ($reasons[$status_code->value] ?? 'Unknown');

    $header_string = '';
    foreach ($headers as $key => $value) {
        $header_string .= "$key: $value\r\n";
    }

    return "HTTP/1.1 $status_line\r\n$header_string\r\n$body";
}

/**
 * Create, configure, bind, and listen on the main TCP server socket.
 */
function create_server_socket(string $host, int $port): \Socket|false
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

/**
 * Read bytes from a client until the end of HTTP headers is reached.
 */
function read_request(\Socket $client): string|false
{
    $request = '';
    // Read until we get the full http header (ending with \r\n\r\n or CRLF CRLF)
    while (! str_ends_with($request, "\r\n\r\n")) {
        $data = @socket_read($client, 1024);
        if ($data === false || $data === '') {
            return false;
        }
        $request .= $data;
    }
    return $request;
}

/**
 * Accept clients in a worker process and hand each to the request loop.
 */
function worker_process(
    \Socket $socket,
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

/**
 * Process one keep-alive connection and serve multiple sequential requests.
 */
function handle_client_connection(
    \Socket $client,
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
        $request_context = parse_request_context($request);
        $request_headers = $request_context['headers'];
        $first_line = $request_context['first_line'];

        [$status_code, $headers, $body] = handle_request_by_method($web_dir, $request_context, $content_types);

        // Determine connection persistence (HTTP/1.1 defaults to keep-alive per RFC 7230)
        $client_wants_keepalive = ! isset($request_headers['connection'])
            || strtolower($request_headers['connection']) === 'keep-alive';
        $connection_policy = apply_connection_policy(
            $headers,
            $client_wants_keepalive,
            $request_count,
            $keep_alive_max_requests,
            $keep_alive_timeout
        );
        $headers = $connection_policy['headers'];
        $keep_connection = $connection_policy['keep_connection'];

        if (! isset($headers['Content-Length'])) {
            $headers['Content-Length'] = strlen($body);
        }

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
        logging("[$pid] $address - - [$timestamp] \"$first_line\" $status_code->value " . $headers['Content-Length']);
    }
}

/**
 * Start the prefork HTTP server.
 */
function run_server(string $web_dir, ?int $worker_count): void
{
    $workers = [];
    $sock = create_server_socket(HOST, PORT);
    if ($sock === false) {
        logging('Failed to create server socket: ' . socket_strerror(socket_last_error()));
        exit(1);
    }

    // Fork worker processes
    $worker_count = $worker_count ?? WORKER_COUNT;
    for ($i = 0; $i < $worker_count; $i++) {
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
            worker_process($sock, $web_dir, DEFAULT_CONTENT_TYPES, KEEP_ALIVE_MAX_REQUESTS, KEEP_ALIVE_TIMEOUT);
            exit(0);
        }
    }
    logging('');
    logging("\033[92m Server is running on " . HOST . ':' . PORT . ' with ' . $worker_count . ' workers. ' . "\033[0m");
    logging("\033[92m Serving files from: " . $web_dir . "\033[0m");

    if (! is_dir($web_dir)) {
        logging('Warning: directory does not exist');
    } elseif (! is_readable($web_dir)) {
        logging('Warning: directory is not readable');
    }

    // Wait for all workers
    foreach ($workers as $worker_pid) {
        pcntl_waitpid($worker_pid, $status);
    }
}
