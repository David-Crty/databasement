#!/bin/bash

# Test script for Docker permission handling
# Run from the project root: ./docker/php/test-permissions.sh

set -e

IMAGE="databasement-app"
TEST_DIR="/tmp/databasement-test"
PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

cleanup() {
    echo -e "\n${YELLOW}Cleaning up...${NC}"
    docker stop test-container 2>/dev/null || true
    docker rm test-container 2>/dev/null || true
    docker run --rm -v /tmp:/tmp "$IMAGE" rm -rf "$TEST_DIR" 2>/dev/null || true
}

trap cleanup EXIT

pass() {
    echo -e "${GREEN}✓ PASS${NC}: $1"
}

fail() {
    echo -e "${RED}✗ FAIL${NC}: $1"
    exit 1
}

echo "=========================================="
echo "Docker Permission Tests"
echo "=========================================="
echo ""

# Build image first
echo -e "${YELLOW}Building image...${NC}"
docker compose build app --quiet
echo ""

# Test 1: Default (root start, auto permission fix)
echo "=========================================="
echo "Test 1: Default (root start)"
echo "=========================================="
mkdir -p "$TEST_DIR"
echo "$ docker run --rm -d --name test-container -v \"\$TEST_DIR:/data\" -v \"\$PROJECT_DIR:/app\" \"\$IMAGE\""
docker run --rm -d --name test-container -v "$TEST_DIR:/data" -v "$PROJECT_DIR:/app" "$IMAGE"
sleep 5

LOGS=$(docker logs test-container 2>&1)
echo "$LOGS" | head -10

if echo "$LOGS" | grep -q "Setting up user application"; then
    pass "Permission fix ran"
else
    fail "Permission fix did not run"
fi

if echo "$LOGS" | grep -q "Set uid to user 0 succeeded"; then
    pass "Supervisor running as root"
else
    fail "Supervisor not running as root"
fi

# Check that programs were spawned (they may exit quickly without /app mounted)
if echo "$LOGS" | grep -q "spawned: 'frankenphp'"; then
    pass "Programs spawned by supervisor"
else
    fail "Programs not spawned"
fi

PERMS=$(ls -lan "$TEST_DIR" | head -2)
if echo "$PERMS" | grep -q "1000 *1000"; then
    pass "Directory owned by PUID 1000"
else
    echo "$PERMS"
    fail "Directory not owned by PUID 1000"
fi

docker stop test-container >/dev/null
cleanup
echo ""

# Test 2: Custom PUID/PGID
echo "=========================================="
echo "Test 2: Custom PUID=499 PGID=499"
echo "=========================================="
mkdir -p "$TEST_DIR"
echo "$ docker run --rm -d --name test-container -e PUID=499 -e PGID=499 -v \"\$TEST_DIR:/data\" -v \"\$PROJECT_DIR:/app\" \"\$IMAGE\""
docker run --rm -d --name test-container -e PUID=499 -e PGID=499 -v "$TEST_DIR:/data" -v "$PROJECT_DIR:/app" "$IMAGE"
sleep 5

LOGS=$(docker logs test-container 2>&1)
echo "$LOGS" | head -10

if echo "$LOGS" | grep -q "Setting up user application with PUID=499"; then
    pass "Custom PUID/PGID detected"
else
    fail "Custom PUID/PGID not detected"
fi

if echo "$LOGS" | grep -q "spawned: 'frankenphp'"; then
    pass "Programs spawned by supervisor"
else
    fail "Programs not spawned"
fi

PERMS=$(ls -lan "$TEST_DIR" | head -2)
if echo "$PERMS" | grep -q "499 *499"; then
    pass "Directory owned by PUID 499"
else
    echo "$PERMS"
    fail "Directory not owned by PUID 499"
fi

docker stop test-container >/dev/null
cleanup
echo ""

# Test 3: --user flag (no permission fix)
echo "=========================================="
echo "Test 3: --user 1000:1000 flag"
echo "=========================================="
mkdir -p "$TEST_DIR"
# Pre-set permissions (required when using --user)
docker run --rm -v "$TEST_DIR:$TEST_DIR" "$IMAGE" chown 1000:1000 "$TEST_DIR"

echo "$ docker run --rm -d --name test-container --user 1000:1000 -v \"\$TEST_DIR:/data\" -v \"\$PROJECT_DIR:/app\" \"\$IMAGE\""
docker run --rm -d --name test-container --user 1000:1000 -v "$TEST_DIR:/data" -v "$PROJECT_DIR:/app" "$IMAGE"
sleep 5

LOGS=$(docker logs test-container 2>&1)
echo "$LOGS" | head -10

if echo "$LOGS" | grep -q "Not running as root, skipping permission fix"; then
    pass "Permission fix skipped"
else
    fail "Permission fix should be skipped"
fi

if echo "$LOGS" | grep -q "Set uid to user 1000 succeeded"; then
    pass "Supervisor running as user 1000"
else
    fail "Supervisor not running as user 1000"
fi

if echo "$LOGS" | grep -q "spawned: 'frankenphp'"; then
    pass "Programs spawned by supervisor"
else
    fail "Programs not spawned"
fi

docker stop test-container >/dev/null
cleanup
echo ""

# Test 4: Custom command (no permission fix)
echo "=========================================="
echo "Test 4: Custom command (worker simulation)"
echo "=========================================="
mkdir -p "$TEST_DIR"

echo "$ docker run --rm -v \"\$TEST_DIR:/data\" \"\$IMAGE\" sh -c 'id'"
OUTPUT=$(docker run --rm -v "$TEST_DIR:/data" "$IMAGE" sh -c 'echo "CMD_START"; id; echo "CMD_END"')
echo "$OUTPUT"

if echo "$OUTPUT" | grep -q "Setting up permissions"; then
    fail "Permission fix should NOT run with custom command"
else
    pass "Permission fix skipped for custom command"
fi

if echo "$OUTPUT" | grep -q "uid=0(root)"; then
    pass "Custom command runs as root"
else
    fail "Custom command should run as root"
fi

cleanup
echo ""

# Test 5: --user with custom command
echo "=========================================="
echo "Test 5: --user with custom command"
echo "=========================================="
mkdir -p "$TEST_DIR"
docker run --rm -v "$TEST_DIR:$TEST_DIR" "$IMAGE" chown 501:501 "$TEST_DIR"

echo "$ docker run --rm --user 501:501 -v \"\$TEST_DIR:/data\" \"\$IMAGE\" sh -c 'id; touch /data/test-file'"
OUTPUT=$(docker run --rm --user 501:501 -v "$TEST_DIR:/data" "$IMAGE" sh -c 'echo "Running as:"; id; touch /data/test-file && echo "Write OK"')
echo "$OUTPUT"

if echo "$OUTPUT" | grep -q "uid=501"; then
    pass "Running as UID 501"
else
    fail "Should run as UID 501"
fi

if echo "$OUTPUT" | grep -q "Write OK"; then
    pass "Can write to /data"
else
    fail "Cannot write to /data"
fi

cleanup
echo ""

echo "=========================================="
echo -e "${GREEN}All tests passed!${NC}"
echo "=========================================="
