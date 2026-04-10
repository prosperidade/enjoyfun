#!/bin/bash
###############################################################################
# EnjoyFun Security Scan
# Static analysis for secrets, leaks, and insecure patterns in the codebase.
#
# Usage:
#   ./tests/security_scan.sh [PROJECT_ROOT]
#
# Defaults:
#   PROJECT_ROOT = (parent directory of this script)
#
# Works on Linux, macOS, and Git Bash on Windows.
###############################################################################

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="${1:-$(dirname "$SCRIPT_DIR")}"

PASS=0
FAIL=0
WARN=0

# ── Colors ────────────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    GREEN='\033[0;32m'
    RED='\033[0;31m'
    YELLOW='\033[0;33m'
    CYAN='\033[0;36m'
    BOLD='\033[1m'
    NC='\033[0m'
else
    GREEN='' RED='' YELLOW='' CYAN='' BOLD='' NC=''
fi

section() {
    echo ""
    echo -e "${CYAN}${BOLD}=== $1 ===${NC}"
}

pass() {
    echo -e "${GREEN}[PASS]${NC} $1"
    ((PASS += 1))
}

fail() {
    echo -e "${RED}[FAIL]${NC} $1"
    ((FAIL += 1))
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
    ((WARN += 1))
}

###############################################################################
# 1. CHECK .gitignore COVERS .env
###############################################################################
section "1. .gitignore covers .env files"

GITIGNORE="${PROJECT_ROOT}/.gitignore"
if [[ -f "$GITIGNORE" ]]; then
    if grep -q '\.env' "$GITIGNORE"; then
        pass ".gitignore contains .env entry"
    else
        fail ".gitignore does NOT contain .env entry -- secrets may be committed"
    fi
else
    fail "No .gitignore file found at project root"
fi

###############################################################################
# 2. SCAN FOR HARDCODED SECRETS
###############################################################################
section "2. Hardcoded secrets in source code"

# Patterns that suggest hardcoded secrets
SECRET_PATTERNS=(
    'GEMINI_API_KEY\s*=\s*["\x27][A-Za-z0-9_-]{20,}'
    'OPENAI_API_KEY\s*=\s*["\x27]sk-[A-Za-z0-9_-]{20,}'
    'JWT_SECRET\s*=\s*["\x27][A-Za-z0-9_-]{10,}'
    'DB_PASS\s*=\s*["\x27][^ "\x27]{4,}'
    'password\s*=\s*["\x27][^"\x27]{8,}["\x27]'
    'api_key\s*=\s*["\x27][A-Za-z0-9_-]{20,}'
    'secret_key\s*=\s*["\x27][A-Za-z0-9_-]{20,}'
    'Bearer\s+[A-Za-z0-9_-]{40,}'
)

# Files/dirs to exclude
EXCLUDE_DIRS="node_modules|vendor|\.git|dist|build|tests"

SECRETS_FOUND=0
for pattern in "${SECRET_PATTERNS[@]}"; do
    MATCHES=$(grep -rn --include="*.php" --include="*.js" --include="*.jsx" --include="*.ts" --include="*.json" \
        -E "$pattern" "$PROJECT_ROOT" 2>/dev/null \
        | grep -vE "(${EXCLUDE_DIRS})" \
        | grep -vE '\.env\.example|\.env\.sample|CLAUDE\.md|\.md$|smoke_test|security_scan' \
        || true)

    if [[ -n "$MATCHES" ]]; then
        SECRETS_FOUND=1
        echo -e "${RED}[FIND]${NC} Pattern: ${pattern}"
        echo "$MATCHES" | head -5
        echo ""
    fi
done

if [[ "$SECRETS_FOUND" -eq 0 ]]; then
    pass "No hardcoded secrets found in source files"
else
    fail "Potential hardcoded secrets found (see above)"
fi

###############################################################################
# 3. CHECK .env FILE IS NOT TRACKED BY GIT
###############################################################################
section "3. .env not tracked in git"

cd "$PROJECT_ROOT"
if git ls-files --error-unmatch backend/.env 2>/dev/null; then
    fail "backend/.env is tracked by git -- credentials are in version history"
elif git ls-files --error-unmatch .env 2>/dev/null; then
    fail ".env is tracked by git -- credentials are in version history"
else
    pass ".env files are not tracked by git"
fi

###############################################################################
# 4. CONSOLE.LOG IN PRODUCTION FRONTEND
###############################################################################
section "4. console.log in frontend production pages"

FRONTEND_PAGES="${PROJECT_ROOT}/frontend/src/pages"
if [[ -d "$FRONTEND_PAGES" ]]; then
    CONSOLE_LOGS=$(grep -rn 'console\.log' "$FRONTEND_PAGES" \
        --include="*.jsx" --include="*.js" --include="*.tsx" --include="*.ts" \
        2>/dev/null | grep -v '// DEBUG' | grep -v '//.*console' || true)

    if [[ -n "$CONSOLE_LOGS" ]]; then
        COUNT=$(echo "$CONSOLE_LOGS" | wc -l)
        warn "Found ${COUNT} console.log statements in frontend pages:"
        echo "$CONSOLE_LOGS" | head -10
        if [[ "$COUNT" -gt 10 ]]; then
            echo "       ... and $((COUNT - 10)) more"
        fi
    else
        pass "No console.log found in frontend pages"
    fi
else
    warn "Frontend pages directory not found at ${FRONTEND_PAGES}"
fi

###############################################################################
# 5. STACK TRACE LEAKS IN CONTROLLERS
###############################################################################
section "5. Stack trace patterns in error responses"

CONTROLLERS_DIR="${PROJECT_ROOT}/backend/src/Controllers"
if [[ -d "$CONTROLLERS_DIR" ]]; then
    # Check for patterns that could leak stack traces to clients
    LEAK_PATTERNS=(
        'getTraceAsString'
        'getMessage\(\).*json'
        'getFile\(\)'
        'getLine\(\)'
        'var_dump\s*\('
        'print_r\s*\('
        'debug_backtrace'
        'error_reporting.*E_ALL'
    )

    LEAKS_FOUND=0
    for pattern in "${LEAK_PATTERNS[@]}"; do
        MATCHES=$(grep -rn -E "$pattern" "$CONTROLLERS_DIR" \
            --include="*.php" 2>/dev/null || true)

        if [[ -n "$MATCHES" ]]; then
            # Filter out cases inside error_log() calls which are safe (server-side logging)
            UNSAFE=$(echo "$MATCHES" | grep -v 'error_log' | grep -v '// safe' | grep -v 'development' || true)
            if [[ -n "$UNSAFE" ]]; then
                LEAKS_FOUND=1
                echo -e "${RED}[FIND]${NC} Potential stack trace leak: ${pattern}"
                echo "$UNSAFE" | head -3
                echo ""
            fi
        fi
    done

    if [[ "$LEAKS_FOUND" -eq 0 ]]; then
        pass "No stack trace leak patterns found in controllers"
    else
        fail "Potential stack trace leaks in controllers (see above)"
    fi
else
    warn "Controllers directory not found at ${CONTROLLERS_DIR}"
fi

###############################################################################
# 6. CHECK ERROR HANDLER SANITIZES OUTPUT
###############################################################################
section "6. Global error handler sanitizes output in production"

INDEX_PHP="${PROJECT_ROOT}/backend/public/index.php"
if [[ -f "$INDEX_PHP" ]]; then
    # Check that production errors do NOT include getMessage or line numbers
    if grep -q 'APP_ENV.*production\|APP_ENV.*development' "$INDEX_PHP"; then
        pass "Error handler checks APP_ENV for conditional output"
    else
        warn "Error handler may not differentiate production/development environments"
    fi

    if grep -q 'correlation_id' "$INDEX_PHP"; then
        pass "Error handler includes correlation_id for support reference"
    else
        warn "Error handler does not include correlation_id"
    fi
else
    fail "index.php not found"
fi

###############################################################################
# 7. SESSION SECURITY
###############################################################################
section "7. Session security checks"

# Check no session_start in backend (should be stateless JWT)
SESSION_STARTS=$(grep -rn 'session_start' "${PROJECT_ROOT}/backend/src/" \
    --include="*.php" 2>/dev/null || true)

if [[ -n "$SESSION_STARTS" ]]; then
    fail "session_start() found in backend (should be stateless JWT):"
    echo "$SESSION_STARTS"
else
    pass "Backend is stateless (no session_start found)"
fi

# Check frontend uses sessionStorage, not localStorage for tokens
FRONTEND_SRC="${PROJECT_ROOT}/frontend/src"
if [[ -d "$FRONTEND_SRC" ]]; then
    LOCAL_STORAGE_TOKEN=$(grep -rn 'localStorage.*token\|localStorage.*jwt\|localStorage.*auth' \
        "$FRONTEND_SRC" --include="*.js" --include="*.jsx" --include="*.ts" --include="*.tsx" \
        2>/dev/null | grep -v 'migration\|migrate\|remove\|clear\|// legacy' || true)

    if [[ -n "$LOCAL_STORAGE_TOKEN" ]]; then
        warn "localStorage used for tokens (should use sessionStorage):"
        echo "$LOCAL_STORAGE_TOKEN" | head -5
    else
        pass "No localStorage token storage found (using sessionStorage)"
    fi
fi

###############################################################################
# 8. ORGANIZER_ID FROM BODY CHECK
###############################################################################
section "8. organizer_id must come from JWT, never from request body"

if [[ -d "$CONTROLLERS_DIR" ]]; then
    # Look for patterns where organizer_id is read from $body
    BODY_ORG=$(grep -rn "body\[.organizer_id.\]" "$CONTROLLERS_DIR" \
        --include="*.php" 2>/dev/null \
        | grep -v '// safe\|// audit\|// log\|// ignored\|unset' || true)

    if [[ -n "$BODY_ORG" ]]; then
        warn "organizer_id read from request body in controllers (verify each is safe):"
        echo "$BODY_ORG" | head -5
    else
        pass "No direct organizer_id reads from request body in controllers"
    fi
fi

###############################################################################
# 9. SQL INJECTION PATTERNS
###############################################################################
section "9. SQL injection risk patterns"

if [[ -d "${PROJECT_ROOT}/backend/src" ]]; then
    # Look for string interpolation in SQL queries (not using prepared statements)
    SQL_INJECT=$(grep -rn -E '\$[a-zA-Z_]+.*"(SELECT|INSERT|UPDATE|DELETE)' \
        "${PROJECT_ROOT}/backend/src" --include="*.php" 2>/dev/null \
        | grep -v 'prepare\|PDO\|bindParam\|execute' || true)

    CONCAT_SQL=$(grep -rn -E "(SELECT|INSERT|UPDATE|DELETE).*\\\$" \
        "${PROJECT_ROOT}/backend/src" --include="*.php" 2>/dev/null \
        | grep -vE 'prepare|PDO|placeholder|\?' \
        | grep -v 'comment\|doc\|example\|test' \
        | head -10 || true)

    if [[ -n "$SQL_INJECT" || -n "$CONCAT_SQL" ]]; then
        warn "Potential SQL injection patterns found (review manually):"
        [[ -n "$SQL_INJECT" ]] && echo "$SQL_INJECT" | head -5
        [[ -n "$CONCAT_SQL" ]] && echo "$CONCAT_SQL" | head -5
    else
        pass "No obvious SQL injection patterns found"
    fi
fi

###############################################################################
# 10. DEPENDENCY VULNERABILITIES (if npm available)
###############################################################################
section "10. Frontend dependency check"

FRONTEND_DIR="${PROJECT_ROOT}/frontend"
if [[ -d "$FRONTEND_DIR" && -f "${FRONTEND_DIR}/package.json" ]]; then
    if command -v npm &>/dev/null; then
        cd "$FRONTEND_DIR"
        AUDIT_OUT=$(npm audit --json 2>/dev/null || true)
        CRITICAL=$(echo "$AUDIT_OUT" | grep -o '"critical":[0-9]*' | head -1 | cut -d: -f2 || echo "0")
        HIGH=$(echo "$AUDIT_OUT" | grep -o '"high":[0-9]*' | head -1 | cut -d: -f2 || echo "0")

        if [[ "${CRITICAL:-0}" -gt 0 ]]; then
            fail "npm audit: ${CRITICAL} critical vulnerabilities"
        elif [[ "${HIGH:-0}" -gt 0 ]]; then
            warn "npm audit: ${HIGH} high vulnerabilities"
        else
            pass "npm audit: no critical or high vulnerabilities"
        fi
    else
        warn "npm not available, skipping dependency audit"
    fi
else
    warn "Frontend directory or package.json not found"
fi

###############################################################################
# SUMMARY
###############################################################################
echo ""
echo -e "${BOLD}========================================${NC}"
echo -e "${BOLD}  SECURITY SCAN SUMMARY${NC}"
echo -e "${BOLD}========================================${NC}"
echo -e "  ${GREEN}Passed:   ${PASS}${NC}"
echo -e "  ${RED}Failed:   ${FAIL}${NC}"
echo -e "  ${YELLOW}Warnings: ${WARN}${NC}"
echo -e "  Total:    $((PASS + FAIL + WARN))"
echo -e "${BOLD}========================================${NC}"

if [[ "$FAIL" -gt 0 ]]; then
    echo -e "${RED}Security issues found. Address failures before deploying.${NC}"
    exit 1
elif [[ "$WARN" -gt 0 ]]; then
    echo -e "${YELLOW}Warnings found. Review before production deployment.${NC}"
    exit 0
else
    echo -e "${GREEN}All security checks passed.${NC}"
    exit 0
fi
