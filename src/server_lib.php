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
];

enum HTTP_STATUS: string
{
    case OK = '200';
    case NOT_MODIFIED = '304';
    case FORBIDDEN = '403';
    case NOT_FOUND = '404';
    case METHOD_NOT_ALLOWED = '405';

    public function reason(): string
    {
        return match ($this) {
            self::OK => 'OK',
            self::NOT_MODIFIED => 'Not Modified',
            self::FORBIDDEN => 'Forbidden',
            self::NOT_FOUND => 'Not Found',
            self::METHOD_NOT_ALLOWED => 'Method Not Allowed',
        };
    }
}

readonly class HttpResponse
{
    public function __construct(
        public HTTP_STATUS $status,
        public array $headers,
        public string $body,
    ) {
    }
}

readonly class HttpRequest
{
    public function __construct(
        public string $method,
        public string $path,
        public string $first_line,
        public array $headers,
    ) {
    }
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

function get_cache_control(string $extension): string
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
function parse_request_context(string $request): HttpRequest
{
    $trimmed = trim($request);
    $lines = explode("\r\n", $trimmed);
    $first_line = $lines[0] ?? '';
    $first_line_parts = explode(' ', $first_line);
    $method = strtoupper($first_line_parts[0] ?? 'GET');
    $request_uri = $first_line_parts[1] ?? '/';
    $request_path = parse_url($request_uri, PHP_URL_PATH) ?? '/';

    $headers = [];
    foreach (array_slice($lines, 1) as $line) {
        if ($line === '') {
            break;
        }
        if (! str_contains($line, ':')) {
            continue;
        }
        [$key, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($key))] = trim($value);
    }

    return new HttpRequest($method, $request_path, $first_line, $headers);
}

/**
 * Determine Content-Type based on file extension or MIME type detection.
 * Source: https://svn.apache.org/repos/asf/httpd/httpd/trunk/docs/conf/mime.types
 */
function get_content_type(string $file_path): string
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
function is_etag_match(string $if_none_match_header, string $current_etag): bool
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
function resolve_file_response(string $web_dir, string $request_path, array $accepted_encodings, bool $cache_enabled = true, string $file_etag_sent_by_client = ''): HttpResponse
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

    $headers['Content-Type'] = get_content_type($file_path);

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
        $headers['Cache-Control'] = get_cache_control(pathinfo($file_path, PATHINFO_EXTENSION));
    }

    // Handle ETag and If-None-Match
    $representation_key = 'identity';
    if ($uses_gzip_representation) {
        $representation_key = $has_precompressed ? 'gzip-static' : 'gzip-dynamic';
    }
    $etag = generate_etag($file_path, $representation_key);
    $headers['ETag'] = $etag;

    if (is_etag_match($file_etag_sent_by_client, $etag)) {
        return new HttpResponse(HTTP_STATUS::NOT_MODIFIED, $headers, '');
    }

    // Handle accepted encodings and static/on-the-fly gzip body generation.
    if ($uses_gzip_representation && $has_precompressed) {
        $body = file_get_contents($file_path . '.gz');
    } elseif ($uses_gzip_representation) {
        $body = gzencode(file_get_contents($file_path));
    } else {
        $body = file_get_contents($file_path);
    }

    return new HttpResponse(HTTP_STATUS::OK, $headers, $body);
}

/**
 * Build a minimal HTML error response tuple for 403/404/405.
 */
function handle_error_response(HTTP_STATUS $status, array $allowed_methods = []): HttpResponse
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

    return new HttpResponse($status, $headers, $body);
}

/**
 * Dispatch the request and apply connection headers.
 */
function create_response(
    string $web_dir,
    HttpRequest $request,
    int $request_count,
    int $keep_alive_max_requests,
    int $keep_alive_timeout,
    bool $cache_enabled
): HttpResponse {
    $accept_encoding_header = $request->headers['accept-encoding'] ?? '';
    $accepted_encodings = array_map('trim', explode(',', $accept_encoding_header));
    $file_etag_sent_by_client = trim($request->headers['if-none-match'] ?? '');
    $resource_response = resolve_file_response(
        $web_dir,
        $request->path,
        $accepted_encodings,
        $cache_enabled,
        $file_etag_sent_by_client,
    );

    $response = match ($request->method) {
        'GET' => $resource_response,
        'HEAD' => new HttpResponse(
            $resource_response->status,
            [...$resource_response->headers, 'Content-Length' => strlen($resource_response->body)],
            '',
        ),
        default => handle_error_response(HTTP_STATUS::METHOD_NOT_ALLOWED, ['GET', 'HEAD']),
    };

    $should_close = ! is_client_keepalive($request) || $request_count >= $keep_alive_max_requests;

    if ($should_close) {
        $merged_headers = [...$response->headers, 'Connection' => 'close'];
    } else {
        $remaining_requests = $keep_alive_max_requests - $request_count;
        $merged_headers = [
            ...$response->headers,
            'Connection' => 'Keep-Alive',
            'Keep-Alive' => "timeout=$keep_alive_timeout, max=$remaining_requests",
        ];
    }

    return new HttpResponse($response->status, $merged_headers, $response->body);
}

/**
 * Build a full HTTP/1.1 response string from an HttpResponse.
 */
function build_http_response(HttpResponse $response): string
{
    $status_line = $response->status->value . ' ' . $response->status->reason();

    $headers = $response->headers;
    if (! isset($headers['Content-Length'])) {
        $headers['Content-Length'] = strlen($response->body);
    }

    $header_string = '';
    foreach ($headers as $key => $value) {
        $header_string .= "$key: $value\r\n";
    }

    return "HTTP/1.1 $status_line\r\n$header_string\r\n{$response->body}";
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

function startup_logging(string $web_dir, bool $cache_enabled, array $workers): void
{
    logging('');
    logging("\033[92m 🐣 Joojoo is running on: \033[0mhttp://" . HOST . ':' . PORT);
    logging("\033[92m Worker processes: \033[0m" . count($workers));
    logging("\033[92m Cache headers: \033[0m" . ($cache_enabled ? 'enabled' : 'disabled'));
    logging("\033[92m Serving files from: \033[0m" . $web_dir);
    if (! is_dir($web_dir)) {
        logging('Warning: directory does not exist');
    } elseif (! is_readable($web_dir)) {
        logging('Warning: directory is not readable');
    }
    logging(' Press Ctrl+C to stop the server');
}


/**
 * Return true if the client wants the connection kept alive (HTTP/1.1 default per RFC 7230).
 */
function is_client_keepalive(HttpRequest $request): bool
{
    return ! isset($request->headers['connection'])
        || strtolower($request->headers['connection']) === 'keep-alive';
}

/**
 * Write a raw HTTP response to the client socket.
 * Returns false and logs an error if the write fails.
 */
function send_response(\Socket $client, string $raw): bool
{
    $bytes_written = @socket_write($client, $raw, strlen($raw));

    if ($bytes_written === false) {
        logging('Error writing to socket: ' . socket_strerror(socket_last_error($client)));
        return false;
    }

    return true;
}

/**
 * Write one access log line for a completed request.
 */
function access_log(\Socket $client, string $first_line, string $status, int $content_length): void
{
    socket_getpeername($client, $address);
    $timestamp = date('d/M/Y:H:i:s O');
    logging("$address - - [$timestamp] \"$first_line\" $status $content_length");
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

        if ($request === false || $request === '') {
            break;
        }

        $request_count++;
        $http_request = parse_request_context($request);
        $response = create_response(
            $web_dir,
            $http_request,
            $request_count,
            $keep_alive_max_requests,
            $keep_alive_timeout,
            $cache_enabled
        );
        $keep_connection = $response->headers['Connection'] === 'Keep-Alive';

        $raw = build_http_response($response);
        if (! send_response($client, $raw)) {
            break;
        }

        $content_length = $response->headers['Content-Length'] ?? strlen($response->body);
        access_log($client, $http_request->first_line, $response->status->value, $content_length);
    }
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

        handle_client_connection(
            client: $client,
            web_dir: $web_dir,
            keep_alive_max_requests: $keep_alive_max_requests,
            keep_alive_timeout: $keep_alive_timeout,
            cache_enabled: $cache_enabled
        );

        socket_close($client);
    }
}

/**
 * Start the prefork HTTP server.
 */
function run_server(string $web_dir, ?int $worker_count, bool $cache_enabled = true): void
{
    $workers = [];
    $sock = socket_create(domain: AF_INET, type: SOCK_STREAM, protocol: SOL_TCP);

    if ($sock === false) {
        logging('Failed to create server socket: ' . socket_strerror(socket_last_error()));
        exit(1);
    }

    if (! socket_set_option(socket: $sock, level:SOL_SOCKET, option:SO_REUSEADDR, value:1)) {
        socket_close($sock);
        logging('Failed to set socket option: ' . socket_strerror(socket_last_error($sock)));
        exit(1);
    }

    if (! socket_bind(socket: $sock, address: HOST, port: PORT)) {
        socket_close($sock);
        logging('Failed to bind server socket: ' . socket_strerror(socket_last_error($sock)));
        exit(1);
    }

    if (! socket_listen(socket: $sock, backlog: SOMAXCONN)) {
        socket_close($sock);
        logging('Failed to listen on server socket: ' . socket_strerror(socket_last_error($sock)));
        exit(1);
    }

    // Fork worker processes
    $worker_count = $worker_count ?? WORKER_COUNT;
    for ($i = 0; $i < $worker_count; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            // Parent process failed to fork
            logging('Failed to fork worker process: ' . pcntl_strerror(pcntl_get_last_error()));
            exit(1);
        } elseif ($pid) {
            // Parent process continues from here and keeps track of worker PIDs
            $workers[] = $pid;
        } else {
            // Child process continues from here
            worker_process(
                socket: $sock,
                web_dir: $web_dir,
                keep_alive_max_requests: KEEP_ALIVE_MAX_REQUESTS,
                keep_alive_timeout: KEEP_ALIVE_TIMEOUT,
                cache_enabled: $cache_enabled
            );
            exit(0);
        }
    }
    startup_logging($web_dir, $cache_enabled, $workers);

    // Wait for all workers
    foreach ($workers as $worker_pid) {
        $wait_status = 0;
        pcntl_waitpid($worker_pid, $wait_status);
    }
}
