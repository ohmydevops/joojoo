# Benchmark Setup

Compare Joojoo (PHP web server) against Nginx using Apache Bench.

## Quick Start

```bash
# Run benchmark (automatically starts Docker containers)
cd test
./benchmark_ab.sh

# Custom benchmark (5000 requests, 200 concurrent)
./benchmark_ab.sh 5000 200

# Stop containers after benchmark
./benchmark_ab.sh 1000 100 yes
```

The script will:
1. Check if Docker and Apache Bench are installed
2. Build and start containers if not running
3. Wait for servers to be ready
4. Run benchmarks on both servers
5. Show comparison results
6. Optionally stop containers (third parameter: yes/no)

## Manual Docker Control

```bash
cd test

# Start containers manually
docker-compose up -d

# View logs
docker-compose logs -f

# Stop containers
docker-compose down
```

## Services

- **Joojoo**: http://localhost:8347 (PHP-based server)
- **Nginx**: http://localhost:8591 (Production web server)

## Requirements

- Docker & Docker Compose
- Apache Bench (`ab`)
  - macOS: `brew install httpd`
  - Linux: `apt install apache2-utils`

## Configuration

Edit `test/docker-compose.yml` to adjust:
- Ports
- Volume mappings
- Network settings

Edit `test/nginx.conf` to adjust nginx settings:
- Worker processes
- Keep-alive timeout
- Connection limits

## Manual Apache Bench Commands

Test Joojoo manually with different configurations:

```bash
# Basic test - 100 requests, 10 concurrent
ab -n 100 -c 10 http://localhost:8347/index.html

# With Keep-Alive
ab -n 1000 -c 50 -k http://localhost:8347/index.html

# High concurrency test
ab -n 5000 -c 200 -k http://localhost:8347/index.html

# Verbose output with timing details
ab -n 100 -c 10 -v 2 http://localhost:8347/index.html

# Test different file
ab -n 500 -c 25 -k http://localhost:8347/style.css

# Long duration stress test
ab -n 10000 -c 100 -k http://localhost:8347/index.html

# Compare Nginx
ab -n 1000 -c 50 -k http://localhost:8591/index.html
```

### Apache Bench Options
- `-n` requests: Total number of requests
- `-c` concurrency: Number of simultaneous connections
- `-k` Keep-Alive: Use persistent connections
- `-v` verbosity: 2=warnings, 3=info, 4=debug
- `-t` timelimit: Test duration in seconds instead of request count
- `-g` output: Save results to gnuplot file

### Example with time limit
```bash
# Run for 30 seconds
ab -t 30 -c 50 -k http://localhost:8347/index.html
```
