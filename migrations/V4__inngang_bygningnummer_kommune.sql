-- ============================================================================
-- INNGANG KEY ALIGNMENT - Use (matrikkel_bygning_nummer, kommunenummer)
-- ============================================================================
-- Adds building number and kommunenummer on matrikkel_innganger so entrances can
-- be de-duplicated across multiple bygning_id values that share the same
-- Matrikkel building number inside a kommune. Keeps bygning_id FK for existing
-- references but changes uniqueness to the new composite key.
-- ============================================================================

-- 1) Add columns for lookup
ALTER TABLE matrikkel_innganger
ADD COLUMN IF NOT EXISTS matrikkel_bygning_nummer BIGINT,
ADD COLUMN IF NOT EXISTS kommunenummer INTEGER;

-- 2) Backfill from matrikkel_bygninger
UPDATE matrikkel_innganger i
SET matrikkel_bygning_nummer = b.matrikkel_bygning_nummer,
    kommunenummer = b.kommunenummer
FROM matrikkel_bygninger b
WHERE i.bygning_id = b.bygning_id
  AND (i.matrikkel_bygning_nummer IS DISTINCT FROM b.matrikkel_bygning_nummer
       OR i.kommunenummer IS DISTINCT FROM b.kommunenummer);

-- 3) Drop old unique constraint based on bygning_id and replace with new key
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'matrikkel_innganger_bygning_id_veg_id_husnummer_bokstav_key'
    ) THEN
        ALTER TABLE matrikkel_innganger
        DROP CONSTRAINT matrikkel_innganger_bygning_id_veg_id_husnummer_bokstav_key;
    END IF;
END$$;

ALTER TABLE matrikkel_innganger
ADD CONSTRAINT matrikkel_innganger_bnr_kommune_veg_husnr_bokstav_key
    UNIQUE (matrikkel_bygning_nummer, kommunenummer, veg_id, husnummer, bokstav);

-- 4) Indexes to match new lookup pattern
CREATE INDEX IF NOT EXISTS idx_inngang_bnr_kommune
    ON matrikkel_innganger (matrikkel_bygning_nummer, kommunenummer);

-- =========================================================================
-- MIGRATION COMPLETE
-- =========================================================================
