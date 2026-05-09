<?php

declare(strict_types=1);

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
 * Map an HTTP status enum case to an HTTP/1.1 status line (code and reason phrase).
 */
function get_status_message(HTTP_STATUS $status): string
{
    return match ($status) {
        HTTP_STATUS::OK => '200 OK',
        HTTP_STATUS::FORBIDDEN => '403 Forbidden',
        HTTP_STATUS::NOT_FOUND => '404 Not Found',
        HTTP_STATUS::METHOD_NOT_ALLOWED => '405 Method Not Allowed',
        default => "$status->value Unknown",
    };
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
 * Write a single log line to standard output.
 */
function logging(string $message): void
{
    echo $message . PHP_EOL;
}

/**
 * Extract the request line (for example: GET / HTTP/1.1) from a raw request.
 */
function get_first_line_http(string $request): string
{
    return explode("\r\n", trim($request), 2)[0];
}

/**
 * Parse HTTP headers from a raw request into a lowercase key/value map.
 */
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

/**
 * Parse request metadata needed for routing and connection decisions.
 */
function parse_request_context(string $request): array
{
    $first_line = get_first_line_http($request);
    $method = strtoupper(explode(' ', $first_line)[0] ?? 'GET');
    $request_uri = explode(' ', $first_line)[1] ?? '/';
    $request_path = parse_url($request_uri, PHP_URL_PATH) ?? '/';

    return [
        'method' => $method,
        'first_line' => $first_line,
        'headers' => get_headers_from_request($request),
        'request_path' => $request_path,
    ];
}

/**
 * Build an absolute file path inside the configured web root.
 */
function resolve_request_file_path(string $web_dir, string $request_path): string
{
    return rtrim($web_dir, '/') . $request_path;
}

/**
 * Keep static file serving safe by rejecting traversal and null-byte paths.
 */
function is_safe_request_path(string $request_path): bool
{
    if (str_contains($request_path, "\0")) {
        return false;
    }

    foreach (explode('/', $request_path) as $segment) {
        if ($segment === '..') {
            return false;
        }
    }

    return true;
}

/**
 * Route request path to either static file response or not-found response.
 */
function route_request_response(string $web_dir, string $request_path, array $content_types): array
{
    if (! is_safe_request_path($request_path)) {
        return handle_forbidden_response();
    }

    $file_path = resolve_request_file_path($web_dir, $request_path);

    if (is_dir($file_path)) {
        $file_path = rtrim($file_path, '/') . '/index.html';
    }

    return is_file($file_path)
        ? handle_file_response($file_path, $content_types)
        : handle_not_found_response();
}

/**
 * Build the HEAD response from an already resolved GET-style response.
 */
function build_head_response(array $response): array
{
    [$status_code, $headers, $body] = $response;
    $headers['Content-Length'] = strlen($body);

    return [$status_code, $headers, ''];
}

/**
 * Return a 405 response tuple and list supported methods.
 */
function handle_method_not_allowed_response(array $allowed_methods): array
{
    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<meta content="width=device-width,initial-scale=1.0" name="viewport">'
        . '<title>Method Not Allowed</title></head><body><h1>405 Method Not Allowed</h1>'
        . '<p>The requested method is not supported for this resource.</p></body></html>';

    $headers = [
        ...DEFAULT_RESPONSE_HEADERS,
        'Content-Type' => 'text/html',
        'Allow' => implode(', ', $allowed_methods),
    ];

    return [HTTP_STATUS::METHOD_NOT_ALLOWED, $headers, $body];
}

/**
 * Dispatch request handling by HTTP method.
 */
function handle_request_by_method(string $web_dir, array $request_context, array $content_types): array
{
    $method = $request_context['method'];
    $request_path = $request_context['request_path'];
    $resource_response = route_request_response($web_dir, $request_path, $content_types);

    return match ($method) {
        'GET' => $resource_response,
        'HEAD' => build_head_response($resource_response),
        default => handle_method_not_allowed_response(['GET', 'HEAD']),
    };
}

/**
 * Decide whether the client connection should remain persistent.
 */
function should_keep_alive(array $request_headers): bool
{
    return isset($request_headers['connection'])
        ? strtolower($request_headers['connection']) === 'keep-alive'
        : true; // HTTP/1.1 defaults to keep-alive (RFC 7230)
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
    $max_requests_reached = $request_count >= $keep_alive_max_requests;
    $should_close = ! $client_wants_keepalive || $max_requests_reached;

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
 * Detect MIME type from file extension with a safe binary fallback.
 */
function file_mime_detector(string $requested_file, array $content_types): string
{
    $file_extension = pathinfo($requested_file, PATHINFO_EXTENSION);
    return $content_types[$file_extension] ?? 'application/octet-stream';
}

/**
 * Build a full HTTP/1.1 response string from status, headers, and body.
 */
function build_http_response(HTTP_STATUS $status_code, array $headers, string $body): string
{

    $status_line = get_status_message($status_code);

    $header_string = '';
    foreach ($headers as $key => $value) {
        $header_string .= "$key: $value\r\n";
    }

    return "HTTP/1.1 $status_line\r\n$header_string\r\n$body";
}

/**
 * Return a successful file response tuple for an existing readable file.
 */
function handle_file_response(string $requested_file, array $content_types): array
{
    if (! is_readable($requested_file)) {
        return handle_not_found_response();
    }

    $body = file_get_contents($requested_file);
    $headers = [...DEFAULT_RESPONSE_HEADERS, 'Content-Type' => file_mime_detector($requested_file, $content_types)];

    return [HTTP_STATUS::OK, $headers, $body];
}

/**
 * Return a minimal 404 HTML response tuple.
 */
function handle_not_found_response(): array
{
    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<meta content="width=device-width,initial-scale=1.0" name="viewport">'
        . '<title>Not Found</title></head><body><h1>404 Not Found</h1>'
        . '<p>The requested file was not found.</p></body></html>';

    $headers = [...DEFAULT_RESPONSE_HEADERS, 'Content-Type' => 'text/html'];

    return [HTTP_STATUS::NOT_FOUND, $headers, $body];
}

/**
 * Return a minimal 403 HTML response tuple for blocked paths.
 */
function handle_forbidden_response(): array
{
    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<meta content="width=device-width,initial-scale=1.0" name="viewport">'
        . '<title>Forbidden</title></head><body><h1>403 Forbidden</h1>'
        . '<p>Access to this resource is not allowed.</p></body></html>';

    $headers = [...DEFAULT_RESPONSE_HEADERS, 'Content-Type' => 'text/html'];

    return [HTTP_STATUS::FORBIDDEN, $headers, $body];
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

        // Determine connection persistence
        $client_wants_keepalive = should_keep_alive($request_headers);
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
function run_server(string $web_dir): void
{
    $workers = [];
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
            worker_process($sock, $web_dir, DEFAULT_CONTENT_TYPES, KEEP_ALIVE_MAX_REQUESTS, KEEP_ALIVE_TIMEOUT);
            exit(0);
        }
    }

    logging('🚀 Server is running on ' . HOST . ':' . PORT . ' with ' . WORKER_COUNT . ' workers');

    // Wait for all workers
    foreach ($workers as $worker_pid) {
        pcntl_waitpid($worker_pid, $status);
    }
}
