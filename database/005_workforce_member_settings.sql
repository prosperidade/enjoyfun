-- Configuração operacional individual por membro do workforce
CREATE TABLE IF NOT EXISTS public.workforce_member_settings (
    id SERIAL PRIMARY KEY,
    participant_id integer NOT NULL UNIQUE,
    max_shifts_event integer NOT NULL DEFAULT 1,
    shift_hours numeric(5,2) NOT NULL DEFAULT 8.00,
    meals_per_day integer NOT NULL DEFAULT 4,
    payment_amount numeric(12,2) NOT NULL DEFAULT 0.00,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_workforce_member_settings_participant
    ON public.workforce_member_settings (participant_id);

