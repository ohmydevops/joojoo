<?php

declare(strict_types=1);

/**
 * Configuration constants and shared HTTP header names.
 */

define('HOST', '0.0.0.0');
define('PORT', 8000);
define('WORKER_COUNT', get_processor_cores_number() * 2);
define('KEEP_ALIVE_TIMEOUT', 5);
define('KEEP_ALIVE_MAX_REQUESTS', 100);

const DEFAULT_RESPONSE_HEADERS = [
    'Server' => 'joojoo',
];

const HTTP_METHOD_GET = 'GET';
const HTTP_METHOD_HEAD = 'HEAD';
const HTTP_METHODS_ALLOWED = [HTTP_METHOD_GET, HTTP_METHOD_HEAD];

const HEADER_CONNECTION = 'Connection';
const HEADER_KEEP_ALIVE = 'Keep-Alive';
const HEADER_CONTENT_LENGTH = 'Content-Length';
const HEADER_CONTENT_TYPE = 'Content-Type';
const HEADER_CONTENT_ENCODING = 'Content-Encoding';
const HEADER_CACHE_CONTROL = 'Cache-Control';
const HEADER_ETAG = 'ETag';
const HEADER_ALLOW = 'Allow';
const HEADER_VARY = 'Vary';

const CONNECTION_CLOSE = 'close';
const CONNECTION_KEEP_ALIVE = 'Keep-Alive';

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
 * Logging helpers.
 */

function logging(string $message): void
{
    echo $message . PHP_EOL;
}

function startup_logging(string $web_dir, array $workers): void
{
    logging('');
    logging("\033[92m 🐣 Joojoo is running on: \033[0mhttp://" . HOST . ':' . PORT);
    logging("\033[92m Worker processes: \033[0m" . count($workers));
    logging("\033[92m Cache headers: \033[0malways enabled");
    logging("\033[92m Serving files from: \033[0m" . $web_dir);

    if (! is_dir($web_dir)) {
        logging('Warning: directory does not exist');
    } elseif (! is_readable($web_dir)) {
        logging('Warning: directory is not readable');
    }

    logging(' Press Ctrl+C to stop the server');
}

function access_log(\Socket $client, string $first_line, string $status, int $content_length): void
{
    socket_getpeername($client, $address);
    $timestamp = date('d/M/Y:H:i:s O');
    logging("$address - - [$timestamp] \"$first_line\" $status $content_length");
}

/**
 * Request parsing helpers.
 */

function parse_request_context(string $raw_request): HttpRequest
{
    $request_lines = explode("\r\n", trim($raw_request));
    $first_line = $request_lines[0] ?? '';

    $first_line_parts = explode(' ', $first_line);
    $method = strtoupper($first_line_parts[0] ?? HTTP_METHOD_GET);

    $request_uri = $first_line_parts[1] ?? '/';
    $request_path = parse_url($request_uri, PHP_URL_PATH) ?? '/';

    $headers = parse_request_headers($request_lines);

    return new HttpRequest($method, $request_path, $first_line, $headers);
}

function parse_request_headers(array $request_lines): array
{
    $headers = [];

    foreach (array_slice($request_lines, 1) as $line) {
        if ($line === '') {
            break;
        }

        if (! str_contains($line, ':')) {
            continue;
        }

        [$header_name, $header_value] = explode(':', $line, 2);
        $headers[strtolower(trim($header_name))] = trim($header_value);
    }

    return $headers;
}

function is_client_keepalive(HttpRequest $request): bool
{
    return ! isset($request->headers['connection'])
        || strtolower($request->headers['connection']) === 'keep-alive';
}

/**
 * Content type, compression, and caching helpers.
 */

function get_cache_control(string $extension): string
{
    $extension = strtolower($extension);
    $cacheable_extensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'webp'];

    return match (true) {
        $extension === 'html' => 'no-cache',
        in_array($extension, $cacheable_extensions, true) => 'public, max-age=86400',
        default => 'no-cache',
    };
}

function get_content_type(string $file_path): string
{
    static $finfo = null;
    static $extension_to_mime = [
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

    $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

    if ($extension !== '' && isset($extension_to_mime[$extension])) {
        return $extension_to_mime[$extension];
    }

    if ($finfo === null) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE) ?: false;
    }

    if ($finfo !== false) {
        $detected_type = finfo_file($finfo, $file_path);
        if (is_string($detected_type) && $detected_type !== '') {
            return $detected_type;
        }
    }

    return 'application/octet-stream';
}

function generate_etag(string $file_path, string $representation = 'identity'): string
{
    $modification_time = filemtime($file_path) ?: 0;
    $file_size = filesize($file_path) ?: 0;
    $representation_hash = substr(sha1($representation), 0, 8);

    return '"' . dechex($modification_time) . '-' . dechex($file_size) . '-' . $representation_hash . '"';
}

function normalize_etag_token(string $etag): string
{
    $etag = trim($etag);

    if (str_starts_with($etag, 'W/')) {
        $etag = substr($etag, 2);
    }

    return trim($etag);
}

function is_etag_match(string $if_none_match_header, string $current_etag): bool
{
    $if_none_match_header = trim($if_none_match_header);

    if ($if_none_match_header === '') {
        return false;
    }

    if ($if_none_match_header === '*') {
        return true;
    }

    $normalized_current_etag = normalize_etag_token($current_etag);

    foreach (explode(',', $if_none_match_header) as $candidate_etag) {
        if (normalize_etag_token($candidate_etag) === $normalized_current_etag) {
            return true;
        }
    }

    return false;
}

/**
 * Error and response builders.
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

    $headers = [...DEFAULT_RESPONSE_HEADERS, HEADER_CONTENT_TYPE => 'text/html'];

    if ($allowed_methods !== []) {
        $headers[HEADER_ALLOW] = implode(', ', $allowed_methods);
    }

    return new HttpResponse($status, $headers, $body);
}

function build_http_response(HttpResponse $response): string
{
    $status_line = $response->status->value . ' ' . $response->status->reason();

    $headers = $response->headers;
    if (! isset($headers[HEADER_CONTENT_LENGTH])) {
        $headers[HEADER_CONTENT_LENGTH] = strlen($response->body);
    }

    $header_string = '';
    foreach ($headers as $name => $value) {
        $header_string .= "$name: $value\r\n";
    }

    return "HTTP/1.1 $status_line\r\n$header_string\r\n{$response->body}";
}

/**
 * File serving and resource resolution helpers.
 */

function is_forbidden_path(string $request_path): bool
{
    if (str_contains($request_path, "\0")) {
        return true;
    }

    foreach (explode('/', $request_path) as $segment) {
        if ($segment === '..') {
            return true;
        }
    }

    return false;
}

function resolve_target_file_path(string $web_dir, string $request_path): string
{
    $file_path = rtrim($web_dir, '/') . $request_path;

    if (is_dir($file_path)) {
        return rtrim($file_path, '/') . '/index.html';
    }

    return $file_path;
}

function parse_accepted_encodings(string $accept_encoding_header): array
{
    return array_map('trim', explode(',', $accept_encoding_header));
}

function determine_representation(array $accepted_encodings, string $file_path): array
{
    $client_accepts_gzip = in_array('gzip', $accepted_encodings, true);
    $has_precompressed_file = is_readable($file_path . '.gz');

    $use_gzip_representation = $client_accepts_gzip;
    $representation_key = 'identity';

    if ($use_gzip_representation) {
        $representation_key = $has_precompressed_file ? 'gzip-static' : 'gzip-dynamic';
    }

    return [$use_gzip_representation, $has_precompressed_file, $representation_key];
}

function load_file_representation(string $file_path, bool $use_gzip_representation, bool $has_precompressed_file): string
{
    if ($use_gzip_representation && $has_precompressed_file) {
        return file_get_contents($file_path . '.gz');
    }

    if ($use_gzip_representation) {
        return gzencode(file_get_contents($file_path));
    }

    return file_get_contents($file_path);
}

function resolve_file_response(
    string $web_dir,
    string $request_path,
    array $accepted_encodings,
    string $file_etag_sent_by_client = ''
): HttpResponse {
    if (is_forbidden_path($request_path)) {
        return handle_error_response(HTTP_STATUS::FORBIDDEN);
    }

    $file_path = resolve_target_file_path($web_dir, $request_path);

    if (! is_file($file_path) || ! is_readable($file_path)) {
        return handle_error_response(HTTP_STATUS::NOT_FOUND);
    }

    $headers = [...DEFAULT_RESPONSE_HEADERS];
    $headers[HEADER_CONTENT_TYPE] = get_content_type($file_path);

    [$use_gzip_representation, $has_precompressed_file, $representation_key] = determine_representation(
        $accepted_encodings,
        $file_path
    );

    if ($use_gzip_representation) {
        $headers[HEADER_CONTENT_ENCODING] = 'gzip';
        $headers[HEADER_VARY] = 'Accept-Encoding';
    }

    $headers[HEADER_CACHE_CONTROL] = get_cache_control(pathinfo($file_path, PATHINFO_EXTENSION));

    $etag = generate_etag($file_path, $representation_key);
    $headers[HEADER_ETAG] = $etag;

    if (is_etag_match($file_etag_sent_by_client, $etag)) {
        return new HttpResponse(HTTP_STATUS::NOT_MODIFIED, $headers, '');
    }

    $body = load_file_representation($file_path, $use_gzip_representation, $has_precompressed_file);

    return new HttpResponse(HTTP_STATUS::OK, $headers, $body);
}

/**
 * Request dispatch helpers.
 */

function create_response(
    string $web_dir,
    HttpRequest $request,
    int $request_count,
    int $keep_alive_max_requests,
    int $keep_alive_timeout
): HttpResponse {
    $accepted_encodings = parse_accepted_encodings($request->headers['accept-encoding'] ?? '');
    $client_etag = trim($request->headers['if-none-match'] ?? '');

    $resource_response = resolve_file_response(
        $web_dir,
        $request->path,
        $accepted_encodings,
        $client_etag
    );

    $response = match ($request->method) {
        HTTP_METHOD_GET => $resource_response,
        HTTP_METHOD_HEAD => new HttpResponse(
            $resource_response->status,
            [...$resource_response->headers, HEADER_CONTENT_LENGTH => strlen($resource_response->body)],
            ''
        ),
        default => handle_error_response(HTTP_STATUS::METHOD_NOT_ALLOWED, HTTP_METHODS_ALLOWED),
    };

    return apply_connection_headers(
        $response,
        $request,
        $request_count,
        $keep_alive_max_requests,
        $keep_alive_timeout
    );
}

function apply_connection_headers(
    HttpResponse $response,
    HttpRequest $request,
    int $request_count,
    int $keep_alive_max_requests,
    int $keep_alive_timeout
): HttpResponse {
    $close_connection = ! is_client_keepalive($request) || $request_count >= $keep_alive_max_requests;

    if ($close_connection) {
        $headers = [...$response->headers, HEADER_CONNECTION => CONNECTION_CLOSE];
        return new HttpResponse($response->status, $headers, $response->body);
    }

    $remaining_requests = $keep_alive_max_requests - $request_count;
    $headers = [
        ...$response->headers,
        HEADER_CONNECTION => CONNECTION_KEEP_ALIVE,
        HEADER_KEEP_ALIVE => "timeout=$keep_alive_timeout, max=$remaining_requests",
    ];

    return new HttpResponse($response->status, $headers, $response->body);
}

/**
 * Socket and I/O helpers.
 */

function create_server_socket(string $host, int $port): \Socket|false
{
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($socket === false) {
        return false;
    }

    if (! socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
        socket_close($socket);
        return false;
    }

    if (! socket_bind($socket, $host, $port)) {
        socket_close($socket);
        return false;
    }

    if (! socket_listen($socket, SOMAXCONN)) {
        socket_close($socket);
        return false;
    }

    return $socket;
}

function read_request(\Socket $client): string|false
{
    $raw_request = '';

    while (! str_ends_with($raw_request, "\r\n\r\n")) {
        $chunk = @socket_read($client, 1024);
        if ($chunk === false || $chunk === '') {
            return false;
        }
        $raw_request .= $chunk;
    }

    return $raw_request;
}

function send_response(\Socket $client, string $raw_response): bool
{
    $bytes_written = @socket_write($client, $raw_response, strlen($raw_response));

    if ($bytes_written === false) {
        logging('Error writing to socket: ' . socket_strerror(socket_last_error($client)));
        return false;
    }

    return true;
}

/**
 * Worker and server runtime helpers.
 */

function handle_client_connection(
    \Socket $client,
    string $web_dir,
    int $keep_alive_max_requests,
    int $keep_alive_timeout
): void {
    $request_count = 0;
    $keep_connection_open = true;

    while ($keep_connection_open && $request_count < $keep_alive_max_requests) {
        $raw_request = read_request($client);
        if ($raw_request === false || $raw_request === '') {
            break;
        }

        $request_count++;
        $request = parse_request_context($raw_request);

        $response = create_response(
            $web_dir,
            $request,
            $request_count,
            $keep_alive_max_requests,
            $keep_alive_timeout
        );

        $keep_connection_open = $response->headers[HEADER_CONNECTION] === CONNECTION_KEEP_ALIVE;

        $raw_response = build_http_response($response);
        if (! send_response($client, $raw_response)) {
            break;
        }

        $content_length = $response->headers[HEADER_CONTENT_LENGTH] ?? strlen($response->body);
        access_log($client, $request->first_line, $response->status->value, $content_length);
    }
}

function worker_process(
    \Socket $socket,
    string $web_dir,
    int $keep_alive_max_requests,
    int $keep_alive_timeout
): void {
    while ($client = socket_accept($socket)) {
        socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $keep_alive_timeout, 'usec' => 0]);
        socket_set_option($client, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $keep_alive_timeout, 'usec' => 0]);

        handle_client_connection(
            client: $client,
            web_dir: $web_dir,
            keep_alive_max_requests: $keep_alive_max_requests,
            keep_alive_timeout: $keep_alive_timeout
        );

        socket_close($client);
    }
}

function run_server(string $web_dir, ?int $worker_count): void
{
    $workers = [];
    $socket = socket_create(domain: AF_INET, type: SOCK_STREAM, protocol: SOL_TCP);

    if ($socket === false) {
        logging('Failed to create server socket: ' . socket_strerror(socket_last_error()));
        exit(1);
    }

    if (! socket_set_option(socket: $socket, level: SOL_SOCKET, option: SO_REUSEADDR, value: 1)) {
        socket_close($socket);
        logging('Failed to set socket option: ' . socket_strerror(socket_last_error($socket)));
        exit(1);
    }

    if (! socket_bind(socket: $socket, address: HOST, port: PORT)) {
        socket_close($socket);
        logging('Failed to bind server socket: ' . socket_strerror(socket_last_error($socket)));
        exit(1);
    }

    if (! socket_listen(socket: $socket, backlog: SOMAXCONN)) {
        socket_close($socket);
        logging('Failed to listen on server socket: ' . socket_strerror(socket_last_error($socket)));
        exit(1);
    }

    $worker_count = $worker_count ?? WORKER_COUNT;

    for ($index = 0; $index < $worker_count; $index++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            logging('Failed to fork worker process: ' . pcntl_strerror(pcntl_get_last_error()));
            exit(1);
        }

        if ($pid) {
            $workers[] = $pid;
            continue;
        }

        worker_process(
            socket: $socket,
            web_dir: $web_dir,
            keep_alive_max_requests: KEEP_ALIVE_MAX_REQUESTS,
            keep_alive_timeout: KEEP_ALIVE_TIMEOUT
        );
        exit(0);
    }

    startup_logging($web_dir, $workers);

    foreach ($workers as $worker_pid) {
        $wait_status = 0;
        pcntl_waitpid($worker_pid, $wait_status);
    }
}

/**
 * Platform utility helpers.
 */

function get_processor_cores_number(): int
{
    return match (PHP_OS_FAMILY) {
        'Darwin' => (int) shell_exec('sysctl -n hw.ncpu'),
        default => (int) shell_exec('nproc'),
    };
}
