<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/server_lib.php';

final class ServerFunctionsTest extends TestCase
{
    private function makeServerConfig(string $webDir): ServerConfig
    {
        return new ServerConfig(
            webDir: $webDir,
            keepAliveMaxRequests: 100,
            keepAliveTimeout: 5,
        );
    }

    public function test_parse_request_context_extracts_headers_and_path(): void
    {
        $request = "GET /sample-website/index.html HTTP/1.1\r\n"
            . "Host: localhost:8000\r\n"
            . "Connection: keep-alive\r\n\r\n";

        $context = parse_request_context($request);

        $this->assertSame('GET /sample-website/index.html HTTP/1.1', $context->first_line);
        $this->assertSame('/sample-website/index.html', $context->path);
        $this->assertSame('localhost:8000', $context->headers['host']);
        $this->assertSame('keep-alive', strtolower($context->headers['connection']));
    }

    public function test_connection_closes_when_max_requests_reached(): void
    {
        $request = new HttpRequest('GET', '/docs/index.html', 'GET /docs/index.html HTTP/1.1', []);

        $result = create_response($this->makeServerConfig(dirname(__DIR__)), $request, 100);

        $this->assertSame('close', $result->headers['Connection']);
    }

    public function test_head_request_returns_empty_body_and_content_length(): void
    {
        $request = new HttpRequest('HEAD', '/docs/index.html', 'HEAD /docs/index.html HTTP/1.1', []);

        $response = create_response($this->makeServerConfig(dirname(__DIR__)), $request, 1);

        $this->assertSame(HTTP_STATUS::OK, $response->status);
        $this->assertSame('', $response->body);
        $this->assertArrayHasKey('Content-Length', $response->headers);
        $this->assertGreaterThan(0, (int) $response->headers['Content-Length']);
    }

    public function test_path_traversal_returns_forbidden(): void
    {
        $response = resolve_file_response(
            dirname(__DIR__),
            '/../composer.json',
            [],
        );

        $this->assertSame(HTTP_STATUS::FORBIDDEN, $response->status);
    }

    public function test_gzip_response_sets_encoding_and_is_decodable(): void
    {
        $tempDir = sys_get_temp_dir() . '/joojoo-test-' . uniqid('', true);
        mkdir($tempDir);
        $filePath = $tempDir . '/sample.txt';
        $originalBody = str_repeat('joojoo-compression-test-', 300);
        file_put_contents($filePath, $originalBody);

        try {
            $response = resolve_file_response(
                $tempDir,
                '/sample.txt',
                ['gzip'],
            );

            $this->assertSame(HTTP_STATUS::OK, $response->status);
            $this->assertSame('gzip', $response->headers['Content-Encoding']);
            $this->assertSame('Accept-Encoding', $response->headers['Vary']);
            $this->assertSame($originalBody, gzdecode($response->body));
        } finally {
            unlink($filePath);
            rmdir($tempDir);
        }
    }

    public function test_gzip_response_body_is_smaller_than_plain_for_repetitive_content(): void
    {
        $tempDir = sys_get_temp_dir() . '/joojoo-test-' . uniqid('', true);
        mkdir($tempDir);
        $filePath = $tempDir . '/sample.txt';
        $originalBody = str_repeat('aaaaaaaaaabbbbbbbbbbcccccccccc', 500);
        file_put_contents($filePath, $originalBody);

        try {
            $plainResponse = resolve_file_response(
                $tempDir,
                '/sample.txt',
                [],
            );

            $gzipResponse = resolve_file_response(
                $tempDir,
                '/sample.txt',
                ['gzip'],
            );

            $this->assertSame('gzip', $gzipResponse->headers['Content-Encoding']);
            $this->assertLessThan(strlen($plainResponse->body), strlen($gzipResponse->body));
            $this->assertSame($plainResponse->body, gzdecode($gzipResponse->body));
        } finally {
            unlink($filePath);
            rmdir($tempDir);
        }
    }

    public function test_content_type_is_set_based_on_file_extension(): void
    {
        $tempDir = sys_get_temp_dir() . '/joojoo-test-' . uniqid('', true);
        mkdir($tempDir);

        $cases = [
            'index.html' => [
                'content' => '<!doctype html><html><head><title>t</title></head><body>x</body></html>',
                'assertion' => static fn (string $type): bool => str_starts_with($type, 'text/html'),
            ],
            'style.css' => [
                'content' => 'body { color: #111; font-family: sans-serif; }',
                'assertion' => static fn (string $type): bool => in_array($type, ['text/css', 'text/plain'], true),
            ],
            'font.woff2' => [
                'content' => 'wOF2' . str_repeat("\0", 32),
                'assertion' => static fn (string $type): bool => in_array(
                    $type,
                    ['font/woff2', 'application/font-woff2', 'application/octet-stream'],
                    true
                ),
            ],
            'logo.png' => [
                'content' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl3xQAAAABJRU5ErkJggg==', true),
                'assertion' => static fn (string $type): bool => $type === 'image/png',
            ],
        ];

        try {
            foreach ($cases as $filename => $case) {
                file_put_contents("$tempDir/$filename", $case['content']);

                $response = resolve_file_response($tempDir, "/$filename", []);

                $this->assertTrue(($case['assertion'])($response->headers['Content-Type']), "Failed for $filename");

                unlink("$tempDir/$filename");
            }
        } finally {
            rmdir($tempDir);
        }
    }

    public function test_response_includes_etag_header(): void
    {
        $tempDir = sys_get_temp_dir() . '/joojoo-test-' . uniqid('', true);
        mkdir($tempDir);
        $filePath = $tempDir . '/index.html';
        file_put_contents($filePath, '<html><body>etag</body></html>');

        try {
            $response = resolve_file_response(
                $tempDir,
                '/index.html',
                []
            );

            $this->assertSame(HTTP_STATUS::OK, $response->status);
            $this->assertNotEmpty($response->body);
            $this->assertArrayHasKey('ETag', $response->headers);
            $this->assertMatchesRegularExpression('/^"[0-9a-f]+-[0-9a-f]+-[0-9a-f]+"$/', $response->headers['ETag']);
        } finally {
            unlink($filePath);
            rmdir($tempDir);
        }
    }

    public function test_if_none_match_returns_not_modified(): void
    {
        $tempDir = sys_get_temp_dir() . '/joojoo-test-' . uniqid('', true);
        mkdir($tempDir);
        $filePath = $tempDir . '/index.html';
        file_put_contents($filePath, '<html><body>etag</body></html>');

        try {
            $initialResponse = resolve_file_response(
                $tempDir,
                '/index.html',
                []
            );

            $requestContext = new HttpRequest(
                'GET',
                '/index.html',
                'GET /index.html HTTP/1.1',
                ['if-none-match' => $initialResponse->headers['ETag']],
            );

            $response = create_response($this->makeServerConfig($tempDir), $requestContext, 1);

            $this->assertSame(HTTP_STATUS::NOT_MODIFIED, $response->status);
            $this->assertSame('', $response->body);
            $this->assertSame($initialResponse->headers['ETag'], $response->headers['ETag']);
        } finally {
            unlink($filePath);
            rmdir($tempDir);
        }
    }

    public function test_if_none_match_wildcard_returns_not_modified(): void
    {
        $tempDir = sys_get_temp_dir() . '/joojoo-test-' . uniqid('', true);
        mkdir($tempDir);
        $filePath = $tempDir . '/index.html';
        file_put_contents($filePath, '<html><body>wildcard</body></html>');

        try {
            $requestContext = new HttpRequest(
                'GET',
                '/index.html',
                'GET /index.html HTTP/1.1',
                ['if-none-match' => '*'],
            );

            $response = create_response($this->makeServerConfig($tempDir), $requestContext, 1);

            $this->assertSame(HTTP_STATUS::NOT_MODIFIED, $response->status);
            $this->assertSame('', $response->body);
            $this->assertArrayHasKey('ETag', $response->headers);
        } finally {
            unlink($filePath);
            rmdir($tempDir);
        }
    }

    public function test_if_none_match_multiple_values_and_weak_tag_returns_not_modified(): void
    {
        $tempDir = sys_get_temp_dir() . '/joojoo-test-' . uniqid('', true);
        mkdir($tempDir);
        $filePath = $tempDir . '/index.html';
        file_put_contents($filePath, '<html><body>multi</body></html>');

        try {
            $initialResponse = resolve_file_response(
                $tempDir,
                '/index.html',
                []
            );

            $requestContext = new HttpRequest(
                'GET',
                '/index.html',
                'GET /index.html HTTP/1.1',
                ['if-none-match' => '"non-match", W/' . $initialResponse->headers['ETag']],
            );

            $response = create_response($this->makeServerConfig($tempDir), $requestContext, 1);

            $this->assertSame(HTTP_STATUS::NOT_MODIFIED, $response->status);
            $this->assertSame('', $response->body);
            $this->assertSame($initialResponse->headers['ETag'], $response->headers['ETag']);
        } finally {
            unlink($filePath);
            rmdir($tempDir);
        }
    }

    public function test_range_request_returns_partial_content(): void
    {
        $tempDir = sys_get_temp_dir() . '/joojoo-test-' . uniqid('', true);
        mkdir($tempDir);
        $filePath = $tempDir . '/video.bin';
        $fullBody = '0123456789abcdefghijklmnopqrstuvwxyz';
        file_put_contents($filePath, $fullBody);

        try {
            $requestContext = new HttpRequest(
                'GET',
                '/video.bin',
                'GET /video.bin HTTP/1.1',
                ['range' => 'bytes=5-9'],
            );

            $response = create_response($this->makeServerConfig($tempDir), $requestContext, 1);

            $this->assertSame(HTTP_STATUS::PARTIAL_CONTENT, $response->status);
            $this->assertSame('56789', $response->body);
            $this->assertSame('bytes 5-9/' . strlen($fullBody), $response->headers['Content-Range']);
            $this->assertSame(strlen($response->body), (int) $response->headers['Content-Length']);
        } finally {
            unlink($filePath);
            rmdir($tempDir);
        }
    }

    public function test_unsatisfiable_range_request_returns_416(): void
    {
        $tempDir = sys_get_temp_dir() . '/joojoo-test-' . uniqid('', true);
        mkdir($tempDir);
        $filePath = $tempDir . '/video.bin';
        $fullBody = '0123456789';
        file_put_contents($filePath, $fullBody);

        try {
            $requestContext = new HttpRequest(
                'GET',
                '/video.bin',
                'GET /video.bin HTTP/1.1',
                ['range' => 'bytes=100-200'],
            );

            $response = create_response($this->makeServerConfig($tempDir), $requestContext, 1);

            $this->assertSame(HTTP_STATUS::RANGE_NOT_SATISFIABLE, $response->status);
            $this->assertSame('', $response->body);
            $this->assertSame('bytes */' . strlen($fullBody), $response->headers['Content-Range']);
        } finally {
            unlink($filePath);
            rmdir($tempDir);
        }
    }

    public function test_range_request_ignores_gzip_and_returns_partial_identity_body(): void
    {
        $tempDir = sys_get_temp_dir() . '/joojoo-test-' . uniqid('', true);
        mkdir($tempDir);
        $filePath = $tempDir . '/video.bin';
        $fullBody = str_repeat('abcdefghij', 2000);
        file_put_contents($filePath, $fullBody);

        try {
            $requestContext = new HttpRequest(
                'GET',
                '/video.bin',
                'GET /video.bin HTTP/1.1',
                [
                    'range' => 'bytes=100-199',
                    'accept-encoding' => 'gzip',
                ],
            );

            $response = create_response($this->makeServerConfig($tempDir), $requestContext, 1);

            $this->assertSame(HTTP_STATUS::PARTIAL_CONTENT, $response->status);
            $this->assertSame(substr($fullBody, 100, 100), $response->body);
            $this->assertArrayNotHasKey('Content-Encoding', $response->headers);
            $this->assertSame('bytes 100-199/' . strlen($fullBody), $response->headers['Content-Range']);
        } finally {
            unlink($filePath);
            rmdir($tempDir);
        }
    }

    public function test_large_html_falls_back_to_identity_without_precompressed_variant(): void
    {
        $tempDir = sys_get_temp_dir() . '/joojoo-test-' . uniqid('', true);
        mkdir($tempDir);
        $filePath = $tempDir . '/large.html';

        $stream = fopen($filePath, 'wb');
        fwrite($stream, str_repeat('a', MAX_DYNAMIC_GZIP_BYTES + 1024));
        fclose($stream);

        try {
            $response = resolve_file_response(
                $tempDir,
                '/large.html',
                ['gzip'],
            );

            $this->assertSame(HTTP_STATUS::OK, $response->status);
            $this->assertArrayNotHasKey('Content-Encoding', $response->headers);
            $this->assertSame(str_repeat('a', MAX_DYNAMIC_GZIP_BYTES + 1024), $response->body);
        } finally {
            unlink($filePath);
            rmdir($tempDir);
        }
    }

    public function test_large_html_uses_precompressed_gzip_when_available(): void
    {
        $tempDir = sys_get_temp_dir() . '/joojoo-test-' . uniqid('', true);
        mkdir($tempDir);
        $filePath = $tempDir . '/large.html';
        $largeHtml = str_repeat('a', MAX_DYNAMIC_GZIP_BYTES + 1024);

        file_put_contents($filePath, $largeHtml);
        file_put_contents($filePath . '.gz', gzencode($largeHtml));

        try {
            $response = resolve_file_response(
                $tempDir,
                '/large.html',
                ['gzip'],
            );

            $this->assertSame(HTTP_STATUS::OK, $response->status);
            $this->assertSame('gzip', $response->headers['Content-Encoding']);
            $this->assertSame('Accept-Encoding', $response->headers['Vary']);
            $this->assertSame($largeHtml, gzdecode($response->body));
        } finally {
            unlink($filePath);
            unlink($filePath . '.gz');
            rmdir($tempDir);
        }
    }

    public function test_large_media_without_range_uses_auto_partial_content(): void
    {
        $tempDir = sys_get_temp_dir() . '/joojoo-test-' . uniqid('', true);
        mkdir($tempDir);
        $filePath = $tempDir . '/large.mp4';

        $stream = fopen($filePath, 'wb');
        fwrite($stream, str_repeat('m', AUTO_RANGE_MEDIA_THRESHOLD_BYTES + 1024));
        fclose($stream);

        try {
            $response = resolve_file_response(
                $tempDir,
                '/large.mp4',
                [],
            );

            $this->assertSame(HTTP_STATUS::PARTIAL_CONTENT, $response->status);
            $this->assertArrayHasKey('Content-Range', $response->headers);
            $this->assertSame(AUTO_RANGE_MEDIA_CHUNK_BYTES, strlen($response->body));
        } finally {
            unlink($filePath);
            rmdir($tempDir);
        }
    }
}
