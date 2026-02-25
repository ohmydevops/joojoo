#!/bin/bash

# Keep-Alive Test Script for joojoo web server
# This script tests if HTTP keep-alive is working correctly

SERVER="http://localhost:8000"
ENDPOINTS=(
    "/sample-website/index.html"
    "/sample-website/style.css"
    "/sample-website/index.js"
)

echo "=========================================="
echo "     Keep-Alive Connection Test"
echo "=========================================="
echo ""

echo "Testing multiple requests on same connection..."
echo ""

# Test 1: Multiple requests with verbose output
echo "Test 1: Checking for 'Re-using existing connection'"
echo "---"
curl -v --keepalive-time 60 \
    ${SERVER}/sample-website/index.html \
    ${SERVER}/sample-website/style.css \
    ${SERVER}/sample-website/index.js 2>&1 | \
    grep -E "(Re-using|Connection #|Keep-Alive:|Connection:)"

echo ""
echo "---"
echo ""

# Test 2: Check headers only
echo "Test 2: Verify Keep-Alive headers are present"
echo "---"
curl -I ${SERVER}/sample-website/index.html 2>&1 | grep -E "(Connection:|Keep-Alive:|Server:)"

echo ""
echo "---"
echo ""

# Test 3: Count connections used
echo "Test 3: Connection count (should be 1 for keep-alive)"
echo "---"
CONNECTION_COUNT=$(curl -v --keepalive-time 60 \
    ${SERVER}/sample-website/index.html \
    ${SERVER}/sample-website/style.css \
    ${SERVER}/sample-website/index.js 2>&1 | \
    grep "Connected to" | wc -l | tr -d ' ')

echo "Number of connections made: $CONNECTION_COUNT"
if [ "$CONNECTION_COUNT" -eq "1" ]; then
    echo "✅ PASS: Keep-alive is working (only 1 connection for 3 requests)"
else
    echo "❌ FAIL: Keep-alive not working ($CONNECTION_COUNT connections for 3 requests)"
fi

echo ""
echo "=========================================="
echo "            Test Complete"
echo "=========================================="
