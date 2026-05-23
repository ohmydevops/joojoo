#!/usr/bin/env bash
#
# Memory benchmark: compare joojoo worker RSS at bootstrap vs after N requests.
# Sends a mix of HTML page requests and video Range requests.
#
# Usage: ./benchmark_memory.sh [requests] [host] [web-dir]
# Example:
#   ./benchmark_memory.sh 1000 http://127.0.0.1:8000 /path/to/web-dir

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REQUESTS="${1:-1000}"
HOST="${2:-http://127.0.0.1:8000}"
WEB_DIR="${3:-$SCRIPT_DIR}"
SERVER_PID=""

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
BOLD='\033[1m'
RESET='\033[0m'

# ---------------------------------------------------------------------------
# Dependencies
# ---------------------------------------------------------------------------
for cmd in curl php ps awk bc; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo -e "${RED}Required command not found: $cmd${RESET}"
        exit 1
    fi
done

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

# Total RSS (KB) of a PID and all its children
total_rss_kb() {
    local root_pid="$1"
    # Collect root + all descendants
    local all_pids
    all_pids=$(pgrep -P "$root_pid" 2>/dev/null || true)
    all_pids="$root_pid $all_pids"

    local total=0
    for pid in $all_pids; do
        local rss
        rss=$(ps -o rss= -p "$pid" 2>/dev/null | tr -d ' ') || continue
        [[ -n "$rss" ]] && total=$((total + rss))
    done
    echo "$total"
}

kb_to_mb() {
    echo "scale=2; $1 / 1024" | bc
}

worker_count() {
    pgrep -P "$1" 2>/dev/null | wc -l | tr -d ' '
}

start_server() {
    local stale
    stale=$(lsof -ti :8000 -sTCP:LISTEN 2>/dev/null) || true
    if [[ -n "$stale" ]]; then
        echo -e "${YELLOW}Port 8000 already in use — killing stale processes...${RESET}"
        echo "$stale" | xargs kill 2>/dev/null || true
        sleep 0.5
    fi

    echo -e "${CYAN}Starting joojoo server (web-dir: $WEB_DIR)...${RESET}"
    php "$SCRIPT_DIR/../server.php" --web-dir "$WEB_DIR" >/dev/null 2>&1 &
    SERVER_PID=$!

    for _ in $(seq 1 30); do
        if curl -fsS -o /dev/null "$HOST/index.html" 2>/dev/null || \
           curl -fsS -o /dev/null "$HOST/" 2>/dev/null; then
            break
        fi
        sleep 0.2
    done
}

stop_server() {
    if [[ -n "$SERVER_PID" ]]; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
}

trap stop_server EXIT

# ---------------------------------------------------------------------------
# Detect what to request
# ---------------------------------------------------------------------------
find_html_path() {
    for candidate in "/index.html" "/docs/index.html" "/"; do
        if curl -fsS -o /dev/null "${HOST}${candidate}" 2>/dev/null; then
            echo "$candidate"
            return
        fi
    done
    echo "/"
}

find_video_path() {
    for candidate in "/sample_video.mp4" "/video.mp4" "/benchmark.mp4"; do
        if curl -fsS -o /dev/null -r 0-1023 "${HOST}${candidate}" 2>/dev/null; then
            echo "$candidate"
            return
        fi
    done
    echo ""
}

# ---------------------------------------------------------------------------
# Run requests with controlled concurrency
# ---------------------------------------------------------------------------
run_requests() {
    local html_path="$1"
    local video_path="$2"
    local count="$3"
    local concurrency=20   # max parallel curl processes

    local half=$((count / 2))
    local has_video=false
    [[ -n "$video_path" ]] && has_video=true

    echo -e "${CYAN}Sending $count requests (concurrency: $concurrency)...${RESET}"

    if $has_video; then
        echo -e "  ${CYAN}→ $half HTML  ($html_path)${RESET}"
        echo -e "  ${CYAN}→ $half video HEAD  ($video_path) — HEAD avoids downloading full body${RESET}"
        seq 1 "$half" | xargs -P "$concurrency" -I{} \
            curl -fsS -o /dev/null "$HOST$html_path"
        seq 1 "$half" | xargs -P "$concurrency" -I{} \
            curl -fsS -o /dev/null -I "$HOST$video_path"
    else
        echo -e "  ${CYAN}→ $count HTML  ($html_path) — no video found${RESET}"
        seq 1 "$count" | xargs -P "$concurrency" -I{} \
            curl -fsS -o /dev/null "$HOST$html_path"
    fi
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
start_server
sleep 0.5  # let workers fully initialize

WORKERS=$(worker_count "$SERVER_PID")

echo ""
echo -e "${BOLD}=== Memory Benchmark ===${RESET}"
echo -e "  Workers : $WORKERS"
echo -e "  Requests: $REQUESTS"
echo -e "  Host    : $HOST"
echo -e "  Web-dir : $WEB_DIR"
echo ""

# Bootstrap memory snapshot
BOOTSTRAP_KB=$(total_rss_kb "$SERVER_PID")
BOOTSTRAP_MB=$(kb_to_mb "$BOOTSTRAP_KB")
echo -e "${GREEN}[Bootstrap]  Total RSS: ${BOOTSTRAP_MB} MB  (${BOOTSTRAP_KB} KB across parent + ${WORKERS} workers)${RESET}"

# Detect paths
HTML_PATH=$(find_html_path)
VIDEO_PATH=$(find_video_path)

echo ""
run_requests "$HTML_PATH" "$VIDEO_PATH" "$REQUESTS"
echo ""

# Post-request memory snapshot
AFTER_KB=$(total_rss_kb "$SERVER_PID")
AFTER_MB=$(kb_to_mb "$AFTER_KB")
echo -e "${GREEN}[After $REQUESTS req]  Total RSS: ${AFTER_MB} MB  (${AFTER_KB} KB)${RESET}"

# Delta
DELTA_KB=$((AFTER_KB - BOOTSTRAP_KB))
DELTA_MB=$(kb_to_mb "$DELTA_KB")

echo ""
echo -e "${BOLD}=== Result ===${RESET}"
printf "  %-22s %8s MB  (%s KB)\n" "Bootstrap memory:"    "$BOOTSTRAP_MB" "$BOOTSTRAP_KB"
printf "  %-22s %8s MB  (%s KB)\n" "After $REQUESTS requests:" "$AFTER_MB"     "$AFTER_KB"
printf "  %-22s %8s MB  (%s KB)\n" "Delta:"               "$DELTA_MB"     "$DELTA_KB"
echo ""

# Allow ~5 MB warmup per worker before flagging as suspicious
WARMUP_THRESHOLD_KB=$((WORKERS * 5120))
if (( DELTA_KB <= 0 )); then
    echo -e "${GREEN}  No memory growth detected. Server is stable.${RESET}"
elif (( DELTA_KB < WARMUP_THRESHOLD_KB )); then
    echo -e "${YELLOW}  Growth within expected warmup range ($DELTA_MB MB for $WORKERS workers).${RESET}"
    echo -e "${YELLOW}  Run again on a warm server to check for true leaks.${RESET}"
else
    echo -e "${RED}  Growth exceeds warmup budget ($DELTA_MB MB > $(kb_to_mb $WARMUP_THRESHOLD_KB) MB threshold).${RESET}"
    echo -e "${RED}  Possible memory leak worth investigating.${RESET}"
fi
echo ""
