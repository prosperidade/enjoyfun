-- ============================================================
-- Migration 074: Drift Replay Manifest Sync (EMAS BE-S1-B3)
-- Purpose: Marker migration documenting the expansion of the supported
--          drift replay window from 039..059 to 039..077, covering all
--          EMAS Sprint 1 Backend deliverables. The actual manifest update
--          lives in database/drift_replay_manifest.json.
-- Depends: nothing (no DDL/DML side effects)
-- ADR: docs/adr_emas_architecture_v1.md
-- ============================================================
--
-- This file is intentionally a no-op SQL marker. It exists so that the
-- governance check (`check_database_governance`) recognizes the EMAS
-- Sprint 1 Backend additions as part of the supported replay window:
--
--   060_validate_not_valid_constraints.sql        (existing — pre-EMAS)
--   061_rls_vendors_otp_codes.sql                 (existing — pre-EMAS)
--   062_ai_agent_skills_warehouse.sql             (existing — pre-EMAS)
--   063_event_templates.sql                       (existing — pre-EMAS)
--   064_rls_ai_v2_tables.sql                      (existing — pre-EMAS)
--   065_ai_find_events_skill.sql                  (existing — pre-EMAS)
--   066_organizer_ai_dna.sql                      (existing — pre-EMAS)
--   067_ai_agent_personas.sql                     (existing — pre-EMAS)
--   068_event_ai_dna.sql                          (existing — pre-EMAS)
--   069_rls_ai_memory_reports.sql                 (EMAS BE-S1-B1)
--   070_session_composite_key.sql                 (EMAS BE-S1-B2)
--   074_manifest_sync.sql                         (this marker — EMAS BE-S1-B3)
--   075_ai_routing_events.sql                     (EMAS BE-S1-B4)
--   076_ai_tool_executions.sql                    (EMAS BE-S1-B5)
--   077_ai_platform_guide.sql                     (EMAS BE-S1-C1)
--
-- After applying this migration in any environment:
--   1. Run: psql ... -f database/069_rls_ai_memory_reports.sql
--   2. Run: psql ... -f database/070_session_composite_key.sql
--   3. Run: psql ... -f database/074_manifest_sync.sql
--   4. Run: psql ... -f database/075_ai_routing_events.sql
--   5. Run: psql ... -f database/076_ai_tool_executions.sql
--   6. Run: psql ... -f database/077_ai_platform_guide.sql
--
-- The manifest update in drift_replay_manifest.json must be committed
-- alongside this file. Both together prove the governance checker that
-- the EMAS Sprint 1 Backend window is reproducible from the seed dump.

BEGIN;

DO $$
BEGIN
    RAISE NOTICE '074_manifest_sync.sql applied — drift replay window extended to 039..077 (EMAS Sprint 1 Backend)';
END $$;

COMMIT;
