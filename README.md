# Joojoo | جوجو

A lightweight multi-process web server built with PHP 8, inspired by `micro_httpd`. Implements HTTP/1.1 with keep-alive support.

## Story Behind

Inspired by `micro_httpd` (the web server in my TP-Link TD-8811 modem), I built this to learn HTTP/1.1 from scratch and explore network programming concepts. Started with PHP 8, may rewrite in C++ or Go later.

## Quick Start

**Local:**

```bash
php server.php
# or set custom web directory
BASE_WEB_DIR=/path/to/site php server.php
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

## HTTP/1.1 Implementation Roadmap

**Request Methods:**

- [x] GET
- [ ] HEAD
- [ ] POST
- [ ] PUT
- [ ] DELETE
- [ ] OPTIONS
- [ ] TRACE

**Status Codes:**

- [x] 200 OK
- [ ] 206 Partial Content
- [ ] 301 Moved Permanently
- [ ] 304 Not Modified
- [x] 404 Not Found
- [ ] 400 Bad Request
- [ ] 403 Forbidden
- [ ] 405 Method Not Allowed
- [ ] 500 Internal Server Error
- [ ] 501 Not Implemented
- [ ] 503 Service Unavailable

**Request Headers:**

- [x] Connection (Keep-Alive/Close)
- [x] Host
- [ ] Range (for partial content)
- [ ] If-Modified-Since
- [ ] If-None-Match
- [ ] Content-Type
- [ ] Content-Length
- [ ] Accept-Encoding

**Response Headers:**

- [x] Content-Type
- [x] Content-Length
- [x] Server
- [x] Connection
- [x] Keep-Alive
- [ ] Last-Modified
- [ ] ETag
- [ ] Cache-Control
- [ ] Content-Encoding (gzip)
- [ ] Transfer-Encoding (chunked)

**Features:**

- [x] Multi-process worker model (prefork)
- [x] Persistent connections (Keep-Alive)
- [x] Common Log Format
- [ ] Chunked Transfer Encoding
- [ ] Compression (gzip)
- [ ] Range requests (resume downloads)
- [ ] Conditional requests (caching)
- [ ] Virtual hosts
- [ ] HTTPS/TLS support

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
