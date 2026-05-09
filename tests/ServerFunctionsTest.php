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
            'request_path' => '/sample-website/index.html',
        ];

        [$status, $headers, $body] = handle_request_by_method(
            dirname(__DIR__),
            $requestContext,
            get_default_content_types()
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
            get_default_content_types()
        );

        $this->assertSame(HTTP_STATUS::FORBIDDEN, $status);
    }
}
