#!/usr/bin/env bash

set -euo pipefail

REQUESTS="${1:-1000}"
CONCURRENCY="${2:-10}"
JOOJOO_URL="http://127.0.0.1:8347/index.html"
NGINX_URL="http://127.0.0.1:8591/index.html"

cd "$(dirname "$0")"

if ! command -v ab >/dev/null 2>&1; then
    echo "Apache Bench (ab) is required. Install with: brew install httpd"
    exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker is required for Joojoo vs Nginx comparison."
    exit 1
fi

if docker compose version >/dev/null 2>&1; then
    COMPOSE_CMD="docker compose"
else
    COMPOSE_CMD="docker-compose"
fi

cleanup() {
    echo "Stopping and removing benchmark containers..."
    $COMPOSE_CMD down --remove-orphans >/dev/null 2>&1 || true
}

trap cleanup EXIT

is_running() {
    docker ps --format '{{.Names}}' | grep -q "^$1$"
}

extract_rps() {
    echo "$1" | awk -F: '/Requests per second/ {gsub(/^ +| +$/, "", $2); split($2, a, " "); print a[1]}'
}

extract_ms() {
    echo "$1" | awk '/Time per request/ {print $4; exit}'
}

extract_failed() {
    echo "$1" | awk '/Failed requests/ {print $3; exit}'
}

if ! is_running "joojoo" || ! is_running "nginx-benchmark"; then
    echo "Starting benchmark containers..."
    $COMPOSE_CMD up -d --build
fi

echo "Waiting for servers..."
for _ in {1..30}; do
    if curl -fsS -o /dev/null "$JOOJOO_URL" && curl -fsS -o /dev/null "$NGINX_URL"; then
        break
    fi
    sleep 1
done

echo "Running benchmark: n=$REQUESTS c=$CONCURRENCY keep-alive=on"

joojoo_output="$(ab -n "$REQUESTS" -c "$CONCURRENCY" -k "$JOOJOO_URL" 2>&1)"
nginx_output="$(ab -n "$REQUESTS" -c "$CONCURRENCY" -k "$NGINX_URL" 2>&1)"

joojoo_rps="$(extract_rps "$joojoo_output")"
nginx_rps="$(extract_rps "$nginx_output")"
joojoo_ms="$(extract_ms "$joojoo_output")"
nginx_ms="$(extract_ms "$nginx_output")"
joojoo_failed="$(extract_failed "$joojoo_output")"
nginx_failed="$(extract_failed "$nginx_output")"

echo
echo "Joojoo: rps=$joojoo_rps time_ms=$joojoo_ms failed=$joojoo_failed"
echo "Nginx:  rps=$nginx_rps time_ms=$nginx_ms failed=$nginx_failed"

if [[ -n "$joojoo_rps" && -n "$nginx_rps" ]]; then
    ratio="$(awk -v j="$joojoo_rps" -v n="$nginx_rps" 'BEGIN { if (n == 0) print 0; else printf "%.1f", (j/n)*100 }')"
    echo "Relative speed: Joojoo is ${ratio}% of Nginx"
fi
