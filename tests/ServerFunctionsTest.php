<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/server_lib.php';

final class ServerFunctionsTest extends TestCase
{
    public function test_parse_request_context_extracts_headers_and_path(): void
    {
        $request = "GET /sample-website/index.html HTTP/1.1\r\n"
            . "Host: localhost:8000\r\n"
            . "Connection: keep-alive\r\n\r\n";

        $context = parse_request_context($request);

        $this->assertSame('GET /sample-website/index.html HTTP/1.1', $context['first_line']);
        $this->assertSame('/sample-website/index.html', $context['request_path']);
        $this->assertSame('localhost:8000', $context['headers']['host']);
        $this->assertSame('keep-alive', strtolower($context['headers']['connection']));
    }

    public function test_apply_connection_policy_closes_when_max_reached(): void
    {
        $result = apply_connection_policy(
            DEFAULT_RESPONSE_HEADERS,
            true,
            100,
            100,
            5
        );

        $this->assertFalse($result['keep_connection']);
        $this->assertSame('close', $result['headers']['Connection']);
    }

    public function test_head_request_returns_empty_body_and_content_length(): void
    {
        $requestContext = [
            'method' => 'HEAD',
            'request_path' => '/docs/index.html',
        ];

        [$status, $headers, $body] = handle_request_by_method(
            dirname(__DIR__),
            $requestContext,
        );

        $this->assertSame(HTTP_STATUS::OK, $status);
        $this->assertSame('', $body);
        $this->assertArrayHasKey('Content-Length', $headers);
        $this->assertGreaterThan(0, (int) $headers['Content-Length']);
    }

    public function test_path_traversal_returns_forbidden(): void
    {
        [$status] = route_request_response(
            dirname(__DIR__),
            '/../composer.json',
            [],
        );

        $this->assertSame(HTTP_STATUS::FORBIDDEN, $status);
    }

    public function test_gzip_response_sets_encoding_and_is_decodable(): void
    {
        $tempDir = sys_get_temp_dir() . '/joojoo-test-' . uniqid('', true);
        mkdir($tempDir);
        $filePath = $tempDir . '/sample.txt';
        $originalBody = str_repeat('joojoo-compression-test-', 300);
        file_put_contents($filePath, $originalBody);

        try {
            [$status, $headers, $body] = route_request_response(
                $tempDir,
                '/sample.txt',
                ['gzip'],
            );

            $this->assertSame(HTTP_STATUS::OK, $status);
            $this->assertSame('gzip', $headers['Content-Encoding']);
            $this->assertSame('Accept-Encoding', $headers['Vary']);
            $this->assertSame($originalBody, gzdecode($body));
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
            [, , $plainBody] = route_request_response(
                $tempDir,
                '/sample.txt',
                [],
            );

            [, $gzipHeaders, $gzipBody] = route_request_response(
                $tempDir,
                '/sample.txt',
                ['gzip'],
            );

            $this->assertSame('gzip', $gzipHeaders['Content-Encoding']);
            $this->assertLessThan(strlen($plainBody), strlen($gzipBody));
            $this->assertSame($plainBody, gzdecode($gzipBody));
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
                'content' => "wOF2" . str_repeat("\0", 32),
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

                [, $headers] = route_request_response($tempDir, "/$filename", []);

                $this->assertTrue(($case['assertion'])($headers['Content-Type']), "Failed for $filename");

                unlink("$tempDir/$filename");
            }
        } finally {
            rmdir($tempDir);
        }
    }
}
