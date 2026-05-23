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
    case NOT_MODIFIED = '304';
    case FORBIDDEN = '403';
    case NOT_FOUND = '404';
    case METHOD_NOT_ALLOWED = '405';
}

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

function cache_control_header(string $extension): string
{
    $ext = strtolower($extension);
    $static = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'webp'];

    return match (true) {
        $ext === 'html' => 'no-cache',
        in_array($ext, $static, true) => 'public, max-age=86400',
        default => 'no-cache',
    };
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
 * Determine Content-Type based on file extension or MIME type detection.
 * Source: https://svn.apache.org/repos/asf/httpd/httpd/trunk/docs/conf/mime.types
 */
function content_type(string $file_path): string
{
    static $finfo = null;
    static $extension_map = [
        'css' => 'text/css',
        'js' => 'text/javascript',
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'json' => 'application/json',
        'svg' => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'webp' => 'image/webp',
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
        'mp4' => 'video/mp4',
        'mp3' => 'audio/mpeg',
        'mkv' => 'video/x-matroska',
    ];

    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    if ($ext !== '' && isset($extension_map[$ext])) {
        return $extension_map[$ext];
    }

    if ($finfo === null) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE) ?: false;
    }

    if ($finfo !== false) {
        $type = finfo_file($finfo, $file_path);
        if (is_string($type) && $type !== '') {
            return $type;
        }
    }

    return 'application/octet-stream';
}

/**
 * Generate a representation-aware ETag based on file metadata.
 */
function generate_etag(string $file_path, string $representation = 'identity'): string
{
    $modification_time = filemtime($file_path) ?: 0;
    $size = filesize($file_path) ?: 0;
    $representation_hash = substr(sha1($representation), 0, 8);

    return '"' . dechex($modification_time) . '-' . dechex($size) . '-' . $representation_hash . '"';
}

/**
 * Normalize ETag token by trimming spaces and removing optional weak prefix.
 */
function normalize_etag_token(string $etag): string
{
    $etag = trim($etag);
    if (str_starts_with($etag, 'W/')) {
        $etag = substr($etag, 2);
    }

    return trim($etag);
}

/**
 * Match If-None-Match header against current ETag using weak comparison rules.
 */
function etag_matches_if_none_match(string $if_none_match_header, string $current_etag): bool
{
    $header = trim($if_none_match_header);
    if ($header === '') {
        return false;
    }

    if ($header === '*') {
        return true;
    }

    $normalized_current = normalize_etag_token($current_etag);
    foreach (explode(',', $header) as $candidate) {
        if (normalize_etag_token($candidate) === $normalized_current) {
            return true;
        }
    }

    return false;
}

/**
 * Route request path to either static file response or error response.
 */
function route_request_response(string $web_dir, string $request_path, array $accepted_encodings, bool $cache_enabled = true, string $file_etag_sent_by_client = ''): array
{
    $headers = [...DEFAULT_RESPONSE_HEADERS];

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

    $headers['Content-Type'] = content_type($file_path);

    $client_accepts_gzip = in_array('gzip', $accepted_encodings, true);
    $has_precompressed = is_readable($file_path . '.gz');
    $uses_gzip_representation = $client_accepts_gzip;

    // Set representation headers before conditional short-circuit.
    if ($uses_gzip_representation) {
        $headers['Content-Encoding'] = 'gzip';
        $headers['Vary'] = 'Accept-Encoding';
    }

    // Handle Cache-Control for static assets unless disabled by config.
    if ($cache_enabled) {
        $headers['Cache-Control'] = cache_control_header(pathinfo($file_path, PATHINFO_EXTENSION));
    }

    // Handle ETag and If-None-Match
    $representation_key = $uses_gzip_representation
        ? ($has_precompressed ? 'gzip-static' : 'gzip-dynamic')
        : 'identity';
    $etag = generate_etag($file_path, $representation_key);
    $headers['ETag'] = $etag;

    if (etag_matches_if_none_match($file_etag_sent_by_client, $etag)) {
        return [HTTP_STATUS::NOT_MODIFIED, $headers, ''];
    }

    // Handle accepted encodings and static/on-the-fly gzip body generation.
    if ($uses_gzip_representation) {
        $body = $has_precompressed
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
function handle_request_by_method(string $web_dir, array $request_context, bool $cache_enabled = true): array
{
    $accepted_encodings = array_map('trim', explode(',', $request_context['headers']['accept-encoding'] ?? ''));
    $file_etag_sent_by_client = trim($request_context['headers']['if-none-match'] ?? '');
    $resource_response = route_request_response(
        $web_dir,
        $request_context['request_path'],
        $accepted_encodings,
        $cache_enabled,
        $file_etag_sent_by_client,
    );

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
        HTTP_STATUS::NOT_MODIFIED->value => 'Not Modified',
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
    int $keep_alive_max_requests,
    int $keep_alive_timeout,
    bool $cache_enabled
): void {
    while ($client = socket_accept($socket)) {
        // Configure timeouts for keep-alive
        socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $keep_alive_timeout, 'usec' => 0]);
        socket_set_option($client, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $keep_alive_timeout, 'usec' => 0]);

        handle_client_connection($client, $web_dir, $keep_alive_max_requests, $keep_alive_timeout, $cache_enabled);

        socket_close($client);
    }
}

/**
 * Process one keep-alive connection and serve multiple sequential requests.
 */
function handle_client_connection(
    \Socket $client,
    string $web_dir,
    int $keep_alive_max_requests,
    int $keep_alive_timeout,
    bool $cache_enabled
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

        [$status_code, $headers, $body] = handle_request_by_method($web_dir, $request_context, $cache_enabled);

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
function run_server(string $web_dir, ?int $worker_count, bool $cache_enabled = true): void
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
            worker_process($sock, $web_dir, KEEP_ALIVE_MAX_REQUESTS, KEEP_ALIVE_TIMEOUT, $cache_enabled);
            exit(0);
        }
    }
    logging('');
    logging("\033[92m Server is running on: \033[0mhttp://" . HOST . ':' . PORT);
    logging("\033[92m Worker processes: \033[0m" . count($workers));
    logging("\033[92m Cache headers: \033[0m" . ($cache_enabled ? 'enabled' : 'disabled'));
    logging("\033[92m Serving files from: \033[0m" . $web_dir);
    if (! is_dir($web_dir)) {
        logging('Warning: directory does not exist');
    } elseif (! is_readable($web_dir)) {
        logging('Warning: directory is not readable');
    }
    logging(' Press Ctrl+C to stop the server');

    // Wait for all workers
    foreach ($workers as $worker_pid) {
        pcntl_waitpid($worker_pid, $status);
    }
}
