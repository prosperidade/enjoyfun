BEGIN;

ALTER TABLE public.otp_codes
    ALTER COLUMN code TYPE character varying(128);

COMMIT;
