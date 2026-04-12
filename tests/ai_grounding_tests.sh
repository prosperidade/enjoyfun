#!/usr/bin/env bash
# BE-S4-C3: AI Grounding Validation Test Suite
# Usage: bash tests/ai_grounding_tests.sh

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

php "${PROJECT_ROOT}/tests/ai_grounding_tests_runner.php"
