# Joojoo | جوجو

A lightweight multi-process static site server built with PHP 8, inspired by `micro_httpd`.

## Story Behind

Inspired by `micro_httpd` (the web server in my TP-Link TD-8811 modem), this project focuses on serving static files with a small and understandable codebase.

## Quick Start

**Local:**

```bash
php server.php
# or set custom web directory
BASE_WEB_DIR=/path/to/site php server.php
# or override worker count
WORKERS_COUNT=8 php server.php
# or use CLI args
php server.php --base-web-dir /path/to/site --workers-count 8
```

**Docker (build and run):**

```bash
# Build image
docker build -t joojoo .

# Run container
docker run --name joojoo --init --rm \
  -v /path/to/your/website:/html \
  -p 80:8000 \
  joojoo
```

## Configuration

### Environment Variables

- `BASE_WEB_DIR` — Root directory for serving files (default: current directory)
- `WORKERS_COUNT` — Number of worker processes (default: CPU cores × 2)

### CLI Arguments

CLI arguments take precedence over environment variables:

- `--base-web-dir PATH` — Set root directory for serving files
- `--workers-count N` — Set worker process count (must be >= 1)

### Configuration Precedence

1. CLI arguments (highest priority)
2. Environment variables
3. Default values (lowest priority)

## Scope

- Static file server only (not a route/controller framework)
- Supported methods: `GET`, `HEAD`
- Unsupported methods return `405 Method Not Allowed`
- Directory requests serve `index.html` when present
- Basic path traversal protection (`..` and null-byte blocked)
- Keep-alive connection support with prefork workers

## Static Server Roadmap

<!-- markdownlint-disable MD033 -->
<details>
<summary>Show roadmap</summary>

### Core HTTP Behavior

- [x] `GET` support for static files
- [x] `HEAD` support with correct `Content-Length` and empty body
- [x] `405 Method Not Allowed` for unsupported methods
- [ ] `400 Bad Request` for malformed request lines

### Static File Resolution

- [x] Serve files from configured base directory
- [x] Directory fallback to `index.html`
- [x] Path traversal guard (`..` / null-byte)
- [ ] Configurable default index file name
- [ ] Optional extensionless route fallback (for SPA/static routing)

### Response Metadata

- [x] `Content-Type` based on file extension map
- [x] `Content-Length`
- [x] `Server`, `Connection`, `Keep-Alive`
- [ ] `Last-Modified`
- [ ] `ETag`
- [ ] `Cache-Control`

### Performance and Transfer

- [x] Prefork worker model
- [x] Keep-alive connection reuse
- [ ] Gzip/Brotli (prefer precompressed `.gz`/`.br` assets)
- [ ] `206 Partial Content` (Range requests)

### Operations

- [x] Common request logging
- [x] Docker image and benchmark scripts
- [ ] Config file for runtime options (port, workers, keep-alive)
- [ ] Graceful shutdown and worker restart strategy

### TLS / Certificates

- [ ] HTTPS listener support
- [ ] Load TLS certificate and private key from config/environment
- [ ] HTTP to HTTPS redirect mode
- [ ] Certificate rotation/reload strategy without long downtime

</details>
<!-- markdownlint-enable MD033 -->

## Benchmark

```bash
./benchmark/benchmark_ab.sh
```

## Development

**Install dependencies:**

```bash
composer install
```

**Code formatting with PHP-CS-Fixer:**

```bash
# Fix code style
composer cs:fix

# Check code style (without fixing)
composer cs:check
```
