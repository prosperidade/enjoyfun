#!/bin/bash
# ==============================================================================
# EnjoyFun — Migration Applicator
# ==============================================================================
# PURPOSE:
#   Apply pending database migrations in order, recording each one in the
#   append-only migrations_applied.log.
#
# USAGE:
#   chmod +x scripts/apply_migrations.sh
#   ./scripts/apply_migrations.sh [DB_NAME] [DB_USER] [DB_HOST] [DB_PORT]
#
# DEFAULTS:
#   DB_NAME  = enjoyfun
#   DB_USER  = postgres
#   DB_HOST  = 127.0.0.1
#   DB_PORT  = 5432
#
# SAFETY:
#   - Each migration runs inside psql (which honors BEGIN/COMMIT in the SQL).
#   - On any failure the script stops immediately — no further migrations run.
#   - Already-applied migrations are logged; check migrations_applied.log to
#     avoid double-applying.  The script does NOT auto-skip applied ones;
#     review the log yourself before running.
#
# NOTE ON MIGRATIONS LIST:
#   Edit the MIGRATIONS array below to match the files you actually need to
#   apply.  The array is intentionally explicit so you always know exactly
#   what will run.
# ==============================================================================

set -euo pipefail

# --- arguments ----------------------------------------------------------------
DB_NAME="${1:-enjoyfun}"
DB_USER="${2:-postgres}"
DB_HOST="${3:-127.0.0.1}"
DB_PORT="${4:-5432}"

# --- paths --------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MIGRATIONS_DIR="$SCRIPT_DIR/../database"
LOG_FILE="$MIGRATIONS_DIR/migrations_applied.log"

# --- migrations to apply (edit this list) -------------------------------------
# Add or remove entries to match the files present in database/.
MIGRATIONS=(
  "049_organizer_id_hardening.sql"
  "050_indexes_performance.sql"
  "051_rls_policies.sql"
  "052_messaging_hardening.sql"
  "053_payment_gateway.sql"
)

# --- preflight checks ---------------------------------------------------------
if ! command -v psql &>/dev/null; then
  echo "[FATAL] psql not found in PATH. Install PostgreSQL client tools first."
  exit 1
fi

echo "============================================================"
echo "  EnjoyFun — Migration Applicator"
echo "  $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "============================================================"
echo ""
echo "  Database : $DB_NAME"
echo "  User     : $DB_USER"
echo "  Host     : $DB_HOST:$DB_PORT"
echo "  Log      : $LOG_FILE"
echo ""

# --- verify connectivity ------------------------------------------------------
echo "[CHECK] Testing database connection..."
if ! psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "SELECT 1;" &>/dev/null; then
  echo "[FATAL] Cannot connect to $DB_NAME at $DB_HOST:$DB_PORT as $DB_USER."
  echo "        Check credentials, pg_hba.conf, and that PostgreSQL is running."
  exit 1
fi
echo "[OK] Connected to $DB_NAME"
echo ""

# --- apply migrations ---------------------------------------------------------
APPLIED=0
SKIPPED=0

for migration in "${MIGRATIONS[@]}"; do
  FILE="$MIGRATIONS_DIR/$migration"

  if [ ! -f "$FILE" ]; then
    echo "[SKIP] $migration — file not found in $MIGRATIONS_DIR"
    SKIPPED=$((SKIPPED + 1))
    continue
  fi

  # Check if already in log (informational warning, does not auto-skip)
  if grep -q "$migration" "$LOG_FILE" 2>/dev/null; then
    echo "[WARN] $migration appears in migrations_applied.log already."
    echo "       Re-applying anyway.  Press Ctrl+C within 3s to abort."
    sleep 3
  fi

  echo "[APPLYING] $migration ..."
  OUTPUT=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
           --set ON_ERROR_STOP=1 -f "$FILE" 2>&1) || {
    echo "[ERROR] $migration FAILED — output below:"
    echo "$OUTPUT"
    echo ""
    echo "Stopping.  Fix the issue and re-run.  $APPLIED migration(s) applied before failure."
    exit 1
  }

  # Log success
  TIMESTAMP="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "$TIMESTAMP | $migration | applied" >> "$LOG_FILE"
  echo "[OK] $migration applied successfully"
  APPLIED=$((APPLIED + 1))
done

echo ""
echo "============================================================"
echo "  Done.  Applied: $APPLIED   Skipped: $SKIPPED"
echo "============================================================"
echo ""
echo "Next steps:"
echo "  1. Review migrations_applied.log"
echo "  2. Run smoke tests against the updated schema"
echo "  3. Verify with:  psql -h $DB_HOST -p $DB_PORT -U $DB_USER -d $DB_NAME"
echo "     \\dt   — list tables"
echo "     \\di   — list indexes"
echo ""
