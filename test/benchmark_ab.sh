#!/bin/bash

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Change to script directory
cd "$(dirname "$0")"

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo -e "${RED}Docker is not installed${NC}"
    echo "Install Docker Desktop from: https://www.docker.com/products/docker-desktop"
    exit 1
fi

# Check if docker-compose is installed
if ! command -v docker-compose &> /dev/null && ! docker compose version &> /dev/null; then
    echo -e "${RED}Docker Compose is not installed${NC}"
    exit 1
fi

# Check if ab is installed
if ! command -v ab &> /dev/null; then
    echo -e "${RED}Apache Bench (ab) is not installed${NC}"
    echo "Install it with: brew install httpd (macOS) or apt install apache2-utils (Linux)"
    exit 1
fi

# Use docker compose or docker-compose
if docker compose version &> /dev/null 2>&1; then
    COMPOSE_CMD="docker compose"
else
    COMPOSE_CMD="docker-compose"
fi

# Default values
REQUESTS="${1:-1000}"
CONCURRENCY="${2:-1}"
STOP_AFTER="${3:-no}"
JOOJOO_URL="http://localhost:8347/index.html"
NGINX_URL="http://localhost:8591/index.html"

echo -e "${BLUE}╔════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     Web Server Benchmark Comparison    ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════╝${NC}"
echo ""
echo "Configuration:"
echo "  Total requests: $REQUESTS"
echo "  Concurrency: $CONCURRENCY"
echo "  Keep-Alive: Enabled"
echo ""

# Function to check if container is running
is_container_running() {
    docker ps --format '{{.Names}}' | grep -q "^$1$"
}

# Function to parse ab output and format it nicely
parse_ab_output() {
    local output="$1"
    local server_name="$2"
    
    echo -e "${GREEN}▶ $server_name${NC}"
    echo "  ────────────────────────────────────────"
    
    # Extract key metrics
    local rps=$(echo "$output" | grep "Requests per second" | awk '{print $4}')
    local time_per_req=$(echo "$output" | grep "Time per request" | head -1 | awk '{print $4}')
    local transfer_rate=$(echo "$output" | grep "Transfer rate" | awk '{print $3}')
    local failed=$(echo "$output" | grep "Failed requests" | awk '{print $3}')
    local total_time=$(echo "$output" | grep "Time taken for tests" | awk '{print $5}')
    
    echo "  Requests/sec:     $rps"
    echo "  Time/request:     ${time_per_req}ms"
    echo "  Transfer rate:    ${transfer_rate} KB/sec"
    echo "  Total time:       ${total_time}s"
    echo "  Failed requests:  $failed"
    echo ""
}

# Start Docker containers
echo -e "${YELLOW}Starting Docker containers...${NC}"
if is_container_running "joojoo" && is_container_running "nginx-benchmark"; then
    echo -e "${GREEN}✓ Containers already running${NC}"
else
    echo "Building and starting containers..."
    $COMPOSE_CMD up -d --build
    
    if [ $? -ne 0 ]; then
        echo -e "${RED}✗ Failed to start containers${NC}"
        exit 1
    fi
    
    echo -e "${YELLOW}Waiting for containers to be ready...${NC}"
    sleep 3
    
    # Wait for servers to respond
    for i in {1..30}; do
        if curl -s -o /dev/null "$JOOJOO_URL" && curl -s -o /dev/null "$NGINX_URL"; then
            echo -e "${GREEN}✓ Containers are ready${NC}"
            break
        fi
        if [ $i -eq 30 ]; then
            echo -e "${RED}✗ Containers did not start properly${NC}"
            $COMPOSE_CMD logs
            exit 1
        fi
        sleep 1
    done
fi
echo ""

# Benchmark Joojoo
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}Testing Joojoo Server${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
joojoo_output=$(ab -n $REQUESTS -c $CONCURRENCY -k "$JOOJOO_URL" 2>&1)
parse_ab_output "$joojoo_output" "Joojoo (PHP)"

# Benchmark Nginx
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}Testing Nginx Server${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
nginx_output=$(ab -n $REQUESTS -c $CONCURRENCY -k "$NGINX_URL" 2>&1)
parse_ab_output "$nginx_output" "Nginx"

# Comparison summary
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}Summary${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

joojoo_rps=$(echo "$joojoo_output" | grep "Requests per second" | awk '{print $4}')
nginx_rps=$(echo "$nginx_output" | grep "Requests per second" | awk '{print $4}')

if [ ! -z "$joojoo_rps" ] && [ ! -z "$nginx_rps" ]; then
    percentage=$(echo "scale=1; ($joojoo_rps / $nginx_rps) * 100" | bc)
    echo "  Joojoo performs at ${percentage}% of Nginx speed"
fi
echo ""

echo -e "${GREEN}✓ Benchmark complete${NC}"

# Stop containers if requested
if [ "$STOP_AFTER" == "yes" ] || [ "$STOP_AFTER" == "y" ]; then
    echo ""
    echo -e "${YELLOW}Stopping containers...${NC}"
    $COMPOSE_CMD down
    echo -e "${GREEN}✓ Containers stopped${NC}"
else
    echo ""
    echo -e "${YELLOW}Containers are still running${NC}"
    echo "Stop them with: $COMPOSE_CMD down"
fi
