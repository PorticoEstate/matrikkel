-- ============================================================================
-- PORTICO HIERARCHY - Location Code Schema
-- ============================================================================
-- This migration adds support for 4-level location hierarchy:
-- Eiendom (Property) → Bygg (Building) → Inngang (Entrance) → Bruksenhet (Unit)
--
-- Version: 2.0.0
-- Created: 2025-12-12
-- Purpose: Enable Portico export with consistent location codes
-- ============================================================================

-- ============================================================================
-- 1. MATRIKKELENHETER - Add property location code
-- ============================================================================

ALTER TABLE matrikkel_matrikkelenheter
ADD COLUMN lokasjonskode_eiendom VARCHAR(50);

CREATE INDEX idx_matrikkelenhet_lokasjonskode 
  ON matrikkel_matrikkelenheter(lokasjonskode_eiendom);

COMMENT ON COLUMN matrikkel_matrikkelenheter.lokasjonskode_eiendom 
  IS 'Portico location code for property level (e.g., "5000")';

-- ============================================================================
-- 2. BYGNINGER - Add building sequence number and location code
-- ============================================================================

ALTER TABLE matrikkel_bygninger
ADD COLUMN lopenummer_i_eiendom INTEGER,
ADD COLUMN lokasjonskode_bygg VARCHAR(50);

-- Indexes for fast lookup
CREATE INDEX idx_bygning_lopenummer 
  ON matrikkel_bygninger(lopenummer_i_eiendom);

CREATE INDEX idx_bygning_lokasjonskode_bygg 
  ON matrikkel_bygninger(lokasjonskode_bygg);

COMMENT ON COLUMN matrikkel_bygninger.lopenummer_i_eiendom 
  IS 'Sequential number of building within property (sorted by bygning_id)';

COMMENT ON COLUMN matrikkel_bygninger.lokasjonskode_bygg 
  IS 'Portico location code for building level (e.g., "5000-01")';

-- ============================================================================
-- 3. INNGANGER - New table for entrances
-- ============================================================================

CREATE TABLE matrikkel_innganger (
    inngang_id BIGSERIAL PRIMARY KEY,
    bygning_id BIGINT NOT NULL,
    veg_id BIGINT,
    husnummer INTEGER NOT NULL,
    bokstav VARCHAR(1),
    lopenummer_i_bygg INTEGER NOT NULL,
    lokasjonskode_inngang VARCHAR(50) NOT NULL,
    uuid VARCHAR(36),
    opprettet TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    oppdatert TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_inngang_bygning 
        FOREIGN KEY (bygning_id) 
        REFERENCES matrikkel_bygninger(bygning_id) 
        ON DELETE CASCADE,
    
    CONSTRAINT fk_inngang_veg
        FOREIGN KEY (veg_id)
        REFERENCES matrikkel_veger(veg_id)
        ON DELETE SET NULL,
    
    -- Ensures unique entrance per building/address combination
    UNIQUE (bygning_id, veg_id, husnummer, bokstav)
);

-- Indexes for fast lookup
CREATE INDEX idx_inngang_bygning 
  ON matrikkel_innganger(bygning_id);

CREATE INDEX idx_inngang_adresse 
  ON matrikkel_innganger(veg_id, husnummer, bokstav);

CREATE INDEX idx_inngang_lokasjonskode 
  ON matrikkel_innganger(lokasjonskode_inngang);

CREATE INDEX idx_inngang_lopenummer 
  ON matrikkel_innganger(bygning_id, lopenummer_i_bygg);

-- Table comment
COMMENT ON TABLE matrikkel_innganger 
  IS 'Entrances to buildings - represents unique address per building for Portico hierarchy';

COMMENT ON COLUMN matrikkel_innganger.lopenummer_i_bygg 
  IS 'Sequential number of entrance within building (sorted by husnummer, bokstav)';

COMMENT ON COLUMN matrikkel_innganger.lokasjonskode_inngang 
  IS 'Portico location code for entrance level (e.g., "5000-01-01")';

-- ============================================================================
-- 4. BRUKSENHETER - Add entrance reference and location code
-- ============================================================================

ALTER TABLE matrikkel_bruksenheter
ADD COLUMN inngang_id BIGINT,
ADD COLUMN lopenummer_i_inngang INTEGER,
ADD COLUMN lokasjonskode_bruksenhet VARCHAR(50);

-- Foreign key constraint
ALTER TABLE matrikkel_bruksenheter
ADD CONSTRAINT fk_bruksenhet_inngang 
    FOREIGN KEY (inngang_id) 
    REFERENCES matrikkel_innganger(inngang_id) 
    ON DELETE SET NULL;

-- Indexes for fast lookup
CREATE INDEX idx_bruksenhet_inngang 
  ON matrikkel_bruksenheter(inngang_id);

CREATE INDEX idx_bruksenhet_lopenummer 
  ON matrikkel_bruksenheter(inngang_id, lopenummer_i_inngang);

CREATE INDEX idx_bruksenhet_lokasjonskode 
  ON matrikkel_bruksenheter(lokasjonskode_bruksenhet);

COMMENT ON COLUMN matrikkel_bruksenheter.inngang_id 
  IS 'Reference to entrance (inngang) that this unit belongs to';

COMMENT ON COLUMN matrikkel_bruksenheter.lopenummer_i_inngang 
  IS 'Sequential number of unit within entrance (sorted by etasjenummer, lopenummer)';

COMMENT ON COLUMN matrikkel_bruksenheter.lokasjonskode_bruksenhet 
  IS 'Portico location code for unit level (e.g., "5000-01-01-001")';

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================

-- Verify all tables and columns exist
DO $$
DECLARE
    v_count INTEGER;
BEGIN
    -- Check matrikkelenheter column
    SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
    WHERE table_name = 'matrikkel_matrikkelenheter' 
      AND column_name = 'lokasjonskode_eiendom';
    
    IF v_count = 0 THEN
        RAISE EXCEPTION 'Migration failed: lokasjonskode_eiendom column not created';
    END IF;
    
    -- Check bygninger columns
    SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
    WHERE table_name = 'matrikkel_bygninger' 
      AND column_name IN ('lopenummer_i_eiendom', 'lokasjonskode_bygg');
    
    IF v_count != 2 THEN
        RAISE EXCEPTION 'Migration failed: bygninger columns not created';
    END IF;
    
    -- Check innganger table
    SELECT COUNT(*) INTO v_count
    FROM information_schema.tables
    WHERE table_name = 'matrikkel_innganger';
    
    IF v_count = 0 THEN
        RAISE EXCEPTION 'Migration failed: matrikkel_innganger table not created';
    END IF;
    
    -- Check bruksenheter columns
    SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
    WHERE table_name = 'matrikkel_bruksenheter' 
      AND column_name IN ('inngang_id', 'lopenummer_i_inngang', 'lokasjonskode_bruksenhet');
    
    IF v_count != 3 THEN
        RAISE EXCEPTION 'Migration failed: bruksenheter columns not created';
    END IF;
    
    RAISE NOTICE 'Migration V2__portico_hierarchy completed successfully';
END $$;
