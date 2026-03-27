BEGIN;

ALTER TABLE public.offline_queue
    DROP CONSTRAINT IF EXISTS chk_offline_queue_payload_type;

ALTER TABLE public.offline_queue
    ADD CONSTRAINT chk_offline_queue_payload_type
    CHECK (
        payload_type IN (
            'sale',
            'meal',
            'topup',
            'ticket_validate',
            'guest_validate',
            'participant_validate',
            'parking_entry',
            'parking_exit',
            'parking_validate'
        )
    ) NOT VALID;

COMMIT;
