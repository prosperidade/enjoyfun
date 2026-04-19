-- Migration 106: media + performance fields in artists
-- Adiciona foto/video do artista e metadados de apresentacao usados pelo Lineup do B2C app.
-- Idempotente.

ALTER TABLE public.artists
    ADD COLUMN IF NOT EXISTS photo_url              VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS performance_video_url  VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS bio                    TEXT         NULL,
    ADD COLUMN IF NOT EXISTS genre                  VARCHAR(120) NULL;

COMMENT ON COLUMN public.artists.photo_url
    IS 'Foto oficial do artista (ref "file:{id}:{name}" ou https://). Renderizada no card do Lineup.';

COMMENT ON COLUMN public.artists.performance_video_url
    IS 'Video de apresentacao/clipe do artista (ref "file:{id}:{name}" ou https://). Abre em modal fullscreen no app.';

COMMENT ON COLUMN public.artists.bio
    IS 'Biografia curta do artista (texto livre).';

COMMENT ON COLUMN public.artists.genre
    IS 'Genero musical/tipo de atracao (ex: "Rock", "DJ", "Stand-up").';
