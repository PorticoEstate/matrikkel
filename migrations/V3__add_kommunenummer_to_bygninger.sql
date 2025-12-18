-- Add kommunenummer to matrikkel_bygninger for alternative linking (byggnummer+kommune)
ALTER TABLE matrikkel_bygninger
    ADD COLUMN IF NOT EXISTS kommunenummer INTEGER;

CREATE INDEX IF NOT EXISTS idx_bygning_kommunenummer
    ON matrikkel_bygninger(kommunenummer);
