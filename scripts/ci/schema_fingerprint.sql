WITH fingerprint AS (
    SELECT format('EXTENSION|%I|%I', e.extname, n.nspname) AS line
      FROM pg_extension e
      JOIN pg_namespace n ON n.oid = e.extnamespace
     WHERE n.nspname = 'public'

    UNION ALL

    SELECT format('TABLE|%I', c.relname) AS line
      FROM pg_class c
      JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE n.nspname = 'public'
       AND c.relkind IN ('r', 'p')

    UNION ALL

    SELECT format(
        'COLUMN|%I|%I|%s|%s|%s|%s|%s',
        c.relname,
        a.attname,
        pg_catalog.format_type(a.atttypid, a.atttypmod),
        CASE WHEN a.attnotnull THEN 'notnull' ELSE 'nullable' END,
        CASE WHEN a.attidentity <> '' THEN 'identity=' || a.attidentity::text ELSE 'identity=' END,
        CASE WHEN a.attgenerated <> '' THEN 'generated=' || a.attgenerated::text ELSE 'generated=' END,
        COALESCE(regexp_replace(pg_get_expr(ad.adbin, ad.adrelid), '\s+', ' ', 'g'), '')
    ) AS line
      FROM pg_attribute a
      JOIN pg_class c ON c.oid = a.attrelid
      JOIN pg_namespace n ON n.oid = c.relnamespace
 LEFT JOIN pg_attrdef ad
        ON ad.adrelid = a.attrelid
       AND ad.adnum = a.attnum
     WHERE n.nspname = 'public'
       AND c.relkind IN ('r', 'p')
       AND a.attnum > 0
       AND NOT a.attisdropped

    UNION ALL

    SELECT format(
        'SEQUENCE|%I|%s|%s|%s|%s|%s|%s|%s',
        c.relname,
        pg_catalog.format_type(s.seqtypid, NULL),
        s.seqstart,
        s.seqincrement,
        s.seqmin,
        s.seqmax,
        s.seqcache,
        CASE WHEN s.seqcycle THEN 'cycle' ELSE 'no_cycle' END
    ) AS line
      FROM pg_class c
      JOIN pg_namespace n ON n.oid = c.relnamespace
      JOIN pg_sequence s ON s.seqrelid = c.oid
     WHERE n.nspname = 'public'
       AND c.relkind = 'S'

    UNION ALL

    SELECT format(
        'CONSTRAINT|%I|%I|%s|%s',
        rel.relname,
        con.conname,
        con.contype,
        regexp_replace(
            regexp_replace(
                regexp_replace(pg_get_constraintdef(con.oid, true), '\s+', ' ', 'g'),
                '::character varying::text',
                '::character varying',
                'g'
            ),
            '\]::text\[\]',
            ']',
            'g'
        )
    ) AS line
      FROM pg_constraint con
      JOIN pg_class rel ON rel.oid = con.conrelid
      JOIN pg_namespace n ON n.oid = rel.relnamespace
     WHERE n.nspname = 'public'

    UNION ALL

    SELECT format(
        'INDEX|%I|%s',
        idx.relname,
        regexp_replace(pg_get_indexdef(idx.oid), '\s+', ' ', 'g')
    ) AS line
      FROM pg_class idx
      JOIN pg_namespace n ON n.oid = idx.relnamespace
     WHERE n.nspname = 'public'
       AND idx.relkind = 'i'

    UNION ALL

    SELECT format(
        'TRIGGER|%I|%I|%s|%s',
        rel.relname,
        trg.tgname,
        trg.tgenabled,
        regexp_replace(pg_get_triggerdef(trg.oid, true), '\s+', ' ', 'g')
    ) AS line
      FROM pg_trigger trg
      JOIN pg_class rel ON rel.oid = trg.tgrelid
      JOIN pg_namespace n ON n.oid = rel.relnamespace
     WHERE n.nspname = 'public'
       AND NOT trg.tgisinternal

    UNION ALL

    SELECT format(
        'FUNCTION|%I|%s|%s',
        p.proname,
        pg_get_function_identity_arguments(p.oid),
        regexp_replace(pg_get_functiondef(p.oid), '\s+', ' ', 'g')
    ) AS line
      FROM pg_proc p
      JOIN pg_namespace n ON n.oid = p.pronamespace
     WHERE n.nspname = 'public'
       AND NOT EXISTS (
            SELECT 1
              FROM pg_depend d
             WHERE d.classid = 'pg_proc'::regclass
               AND d.objid = p.oid
               AND d.deptype = 'e'
       )
)
SELECT line
  FROM fingerprint
 ORDER BY line;
