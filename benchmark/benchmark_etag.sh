#!/usr/bin/env bash
#
# ETag benchmark: compare 1000 sequential requests with and without ETag revalidation.
# Serves logo.png from the benchmark directory using joojoo.
#
# Usage: ./benchmark_etag.sh [host] [path]
# Example:
#   ./benchmark_etag.sh http://127.0.0.1:8000 /logo.png
#
# Requirements: curl, php

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
HOST="${1:-http://127.0.0.1:8000}"
PATH_URL="${2:-/logo.png}"
URL="${HOST}${PATH_URL}"
REQUESTS=1000
SERVER_PID=""

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
RESET='\033[0m'

if ! command -v curl >/dev/null 2>&1; then
    echo "curl is required."
    exit 1
fi

if ! command -v php >/dev/null 2>&1; then
    echo "php is required."
    exit 1
fi

if ! command -v bc >/dev/null 2>&1; then
    echo "bc is required."
    exit 1
fi

# -------------------------------------------------------------------
# Start joojoo server serving the benchmark directory
# -------------------------------------------------------------------
start_server() {
    # Clean up any stale processes still holding the port
    local stale
    stale=$(lsof -ti :8000 -sTCP:LISTEN 2>/dev/null) || true
    if [[ -n "$stale" ]]; then
        echo -e "${YELLOW}Port 8000 already in use — killing stale processes...${RESET}"
        echo "$stale" | xargs kill 2>/dev/null || true
        sleep 0.5
    fi

    echo -e "${CYAN}Starting joojoo server on ${HOST} (serving: ${SCRIPT_DIR})...${RESET}"
    php "$SCRIPT_DIR/../server.php" --web-dir "$SCRIPT_DIR" >/dev/null 2>&1 &
    SERVER_PID=$!

    # Wait until server is ready
    for _ in $(seq 1 20); do
        if curl -fs -o /dev/null "$URL" 2>/dev/null; then
            echo -e "${GREEN}Server ready (pid=$SERVER_PID)${RESET}"
            return
        fi
        sleep 0.3
    done
    echo -e "${RED}Server did not start in time. Is port 8000 already in use?${RESET}"
    kill "$SERVER_PID" 2>/dev/null || true
    exit 1
}

stop_server() {
    if [[ -n "$SERVER_PID" ]]; then
        # Kill forked worker children first, then the parent
        pkill -P "$SERVER_PID" 2>/dev/null || true
        kill "$SERVER_PID" 2>/dev/null || true
        echo -e "${CYAN}Server stopped (pid=$SERVER_PID + workers)${RESET}"
    fi
}

trap stop_server EXIT

start_server

echo
echo -e "${CYAN}=== ETag Benchmark: $URL ===${RESET}"
echo -e "${CYAN}Requests per run: $REQUESTS${RESET}"

# -------------------------------------------------------------------
# Helper: run N requests, optionally with an If-None-Match header
# Returns: "total_time count_200 count_304 total_bytes"
# -------------------------------------------------------------------
run_requests() {
    local with_etag="$1"
    local total_time=0
    local count_200=0
    local count_304=0
    local total_bytes=0
    local etag_value=""

    for _ in $(seq 1 "$REQUESTS"); do
        if [[ "$with_etag" == "yes" && -z "$etag_value" ]]; then
            # First request: capture ETag from response headers
            raw=$(curl -s -D - -o /dev/null "$URL" \
                -w "\n%{http_code} %{time_total} %{size_download}")
            status=$(echo "$raw" | tail -1 | awk '{print $1}')
            t=$(echo "$raw" | tail -1 | awk '{print $2}')
            sz=$(echo "$raw" | tail -1 | awk '{print $3}')
            etag_value=$(echo "$raw" | grep -i '^ETag:' | awk '{print $2}' | tr -d '\r')
        elif [[ "$with_etag" == "yes" && -n "$etag_value" ]]; then
            raw=$(curl -s -o /dev/null \
                -w "%{http_code} %{time_total} %{size_download}" \
                -H "If-None-Match: $etag_value" \
                "$URL")
            status=$(echo "$raw" | awk '{print $1}')
            t=$(echo "$raw" | awk '{print $2}')
            sz=$(echo "$raw" | awk '{print $3}')
        else
            raw=$(curl -s -o /dev/null \
                -w "%{http_code} %{time_total} %{size_download}" \
                "$URL")
            status=$(echo "$raw" | awk '{print $1}')
            t=$(echo "$raw" | awk '{print $2}')
            sz=$(echo "$raw" | awk '{print $3}')
        fi

        total_time=$(echo "$total_time + $t" | bc)
        total_bytes=$(echo "$total_bytes + $sz" | bc)
        [[ "$status" == "200" ]] && ((count_200++)) || true
        [[ "$status" == "304" ]] && ((count_304++)) || true
    done

    echo "$total_time $count_200 $count_304 $total_bytes"
}

# -------------------------------------------------------------------
# RUN 1: Without ETag
# -------------------------------------------------------------------
echo
echo -e "${YELLOW}--- Run 1: Without ETag (${REQUESTS} requests)... ---${RESET}"
read -r no_total no_200 no_304 no_bytes <<< "$(run_requests no)"
no_avg=$(echo "scale=4; $no_total / $REQUESTS" | bc)

# -------------------------------------------------------------------
# RUN 2: With ETag
# -------------------------------------------------------------------
echo -e "${YELLOW}--- Run 2: With ETag / If-None-Match (${REQUESTS} requests)... ---${RESET}"
read -r et_total et_200 et_304 et_bytes <<< "$(run_requests yes)"
et_avg=$(echo "scale=4; $et_total / $REQUESTS" | bc)

# -------------------------------------------------------------------
# Summary
# -------------------------------------------------------------------
saved_time=$(echo "scale=4; $no_total - $et_total" | bc)
saved_bytes=$(echo "$no_bytes - $et_bytes" | bc)

fmt_bytes() {
    local b="$1"
    if [[ "$b" -ge 1048576 ]]; then
        echo "$(echo "scale=2; $b / 1048576" | bc) MB"
    elif [[ "$b" -ge 1024 ]]; then
        echo "$(echo "scale=2; $b / 1024" | bc) KB"
    else
        echo "${b} B"
    fi
}

no_bytes_fmt=$(fmt_bytes "$no_bytes")
et_bytes_fmt=$(fmt_bytes "$et_bytes")
saved_bytes_fmt=$(fmt_bytes "$saved_bytes")

BOLD='\033[1m'
DIM='\033[2m'

# Column widths (content only, borders and spaces added separately)
C1=28   # label
C2=8    # HTTP 200
C3=8    # HTTP 304
C4=12   # total time
C5=11   # avg / req
C6=13   # transferred

# Pad helpers (no ANSI codes — safe for printf width)
pr() { printf "%-*s" "$1" "$2"; }   # pad right (left-align)
pl() { printf "%*s"  "$1" "$2"; }   # pad left  (right-align)

divider() {
    local char="$1" left="$2" mid="$3" right="$4"
    local cols=($C1 $C2 $C3 $C4 $C5 $C6)
    local line="$left"
    for i in "${!cols[@]}"; do
        line+="$(printf '%0.s'"$char" $(seq 1 $((cols[i] + 2))))"
        [[ $i -lt $((${#cols[@]} - 1)) ]] && line+="$mid"
    done
    line+="$right"
    echo -e "${DIM}${line}${RESET}"
}

header_row() {
    echo -e "${DIM}│${RESET} ${BOLD}$(pr $C1 'Run')${RESET} ${DIM}│${RESET} ${BOLD}$(pl $C2 'HTTP 200')${RESET} ${DIM}│${RESET} ${BOLD}$(pl $C3 'HTTP 304')${RESET} ${DIM}│${RESET} ${BOLD}$(pl $C4 'Total Time')${RESET} ${DIM}│${RESET} ${BOLD}$(pl $C5 'Avg / Req')${RESET} ${DIM}│${RESET} ${BOLD}$(pl $C6 'Transferred')${RESET} ${DIM}│${RESET}"
}

data_row() {
    local color="$1" label="$2" c200="$3" c304="$4" ttotal="$5" tavg="$6" tbytes="$7"
    echo -e "${DIM}│${RESET} ${color}$(pr $C1 "$label")${RESET} ${DIM}│${RESET} $(pl $C2 "$c200") ${DIM}│${RESET} $(pl $C3 "$c304") ${DIM}│${RESET} $(pl $C4 "$ttotal") ${DIM}│${RESET} $(pl $C5 "$tavg") ${DIM}│${RESET} $(pl $C6 "$tbytes") ${DIM}│${RESET}"
}

echo
echo -e "${CYAN}${BOLD}  ETag Benchmark — $REQUESTS requests  ·  $URL${RESET}"
echo

divider "─" "┌" "┬" "┐"
header_row
divider "─" "├" "┼" "┤"
data_row "$YELLOW" "Without ETag"             "$no_200" "$no_304" "${no_total}s" "${no_avg}s" "$no_bytes_fmt"
divider "─" "├" "┼" "┤"
data_row "$GREEN"  "With ETag (If-None-Match)" "$et_200" "$et_304" "${et_total}s" "${et_avg}s" "$et_bytes_fmt"
divider "─" "└" "┴" "┘"

echo
echo -e "  ${GREEN}${BOLD}  Time saved :${RESET}  ${saved_time}s over $REQUESTS requests"
echo -e "  ${GREEN}${BOLD} Bytes saved :${RESET}  ${saved_bytes_fmt} not transferred"
echo
