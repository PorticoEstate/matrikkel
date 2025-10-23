-- ============================================================================
-- BASELINE SCHEMA - Matrikkel Integration Database
-- ============================================================================
-- This is a consolidated baseline migration that replaces V1-V9.
-- For new installations, only this migration will run.
-- Old migrations are archived in archive/ folder.
--
-- Version: 1.0.0
-- Created: 2025-10-22
-- Author: Matrikkel Integration System
-- ============================================================================

-- ============================================================================
-- 1. MATRIKKELENHETER (Cadastral Units)
-- ============================================================================

CREATE TABLE IF NOT EXISTS matrikkel_matrikkelenheter (
    matrikkelenhet_id BIGINT NOT NULL,
    kommunenummer INTEGER NOT NULL,
    gardsnummer INTEGER NOT NULL,
    bruksnummer INTEGER NOT NULL,
    festenummer INTEGER DEFAULT 0,
    seksjonsnummer INTEGER DEFAULT 0,
    matrikkelnummer_tekst VARCHAR(50) NOT NULL,
    historisk_oppgitt_areal DOUBLE PRECISION,
    areal_kilde VARCHAR(100),
    tinglyst BOOLEAN DEFAULT false,
    skyld DOUBLE PRECISION,
    bruksnavn VARCHAR(255),
    etableringsdato DATE,
    er_seksjonert BOOLEAN DEFAULT false,
    har_aktive_festegrunner BOOLEAN DEFAULT false,
    har_anmerket_klage BOOLEAN DEFAULT false,
    har_grunnforurensing BOOLEAN DEFAULT false,
    har_kulturminne BOOLEAN DEFAULT false,
    utgatt BOOLEAN DEFAULT false,
    nymatrikulert BOOLEAN DEFAULT false,
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    timestamp_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT matrikkel_matrikkelenheter_pkey PRIMARY KEY (matrikkelenhet_id)
);

-- Indexes for matrikkelenheter
CREATE UNIQUE INDEX IF NOT EXISTS idx_matrikkel_matrikkelenheter_unique_matr
    ON matrikkel_matrikkelenheter 
    (kommunenummer, gardsnummer, bruksnummer, festenummer, seksjonsnummer);

CREATE INDEX IF NOT EXISTS idx_matrikkel_matrikkelenheter_kommune
    ON matrikkel_matrikkelenheter (kommunenummer);

CREATE INDEX IF NOT EXISTS idx_matrikkel_matrikkelenheter_matr
    ON matrikkel_matrikkelenheter 
    (kommunenummer, gardsnummer, bruksnummer, festenummer, seksjonsnummer);

CREATE INDEX IF NOT EXISTS idx_matrikkel_matrikkelenheter_gnr_bnr
    ON matrikkel_matrikkelenheter (gardsnummer, bruksnummer);

CREATE INDEX IF NOT EXISTS idx_matrikkel_matrikkelenheter_tinglyst
    ON matrikkel_matrikkelenheter (tinglyst);

-- ============================================================================
-- 2. KOMMUNER (Municipalities)
-- ============================================================================

CREATE TABLE IF NOT EXISTS matrikkel_kommuner (
    kommune_id SERIAL PRIMARY KEY,
    kommunenummer INTEGER NOT NULL,
    kommunenavn VARCHAR(255) NOT NULL,
    fylkesnummer INTEGER NOT NULL,
    fylkesnavn VARCHAR(255),
    gyldig_til_dato DATE,
    koordinatsystem_kode VARCHAR(50),
    eksklusiv_bruker VARCHAR(100),
    nedsatt_konsesjonsgrense BOOLEAN DEFAULT false,
    senterpunkt_nord DOUBLE PRECISION,
    senterpunkt_ost DOUBLE PRECISION,
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    timestamp_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT matrikkel_kommuner_kommunenummer_key UNIQUE (kommunenummer)
);

-- Indexes for kommuner
CREATE INDEX IF NOT EXISTS idx_matrikkel_kommuner_kommunenummer
    ON matrikkel_kommuner (kommunenummer);

CREATE INDEX IF NOT EXISTS idx_matrikkel_kommuner_fylkesnummer
    ON matrikkel_kommuner (fylkesnummer);

CREATE INDEX IF NOT EXISTS idx_matrikkel_kommuner_navn
    ON matrikkel_kommuner (kommunenavn);

-- Foreign key from matrikkelenheter to kommuner
ALTER TABLE matrikkel_matrikkelenheter 
    ADD CONSTRAINT matrikkel_matrikkelenheter_kommunenummer_fkey 
    FOREIGN KEY (kommunenummer)
    REFERENCES matrikkel_kommuner (kommunenummer)
    ON UPDATE NO ACTION
    ON DELETE RESTRICT;

-- ============================================================================
-- 3. PERSONER (Persons - Base table)
-- ============================================================================

CREATE TABLE matrikkel_personer (
    id BIGSERIAL PRIMARY KEY,
    matrikkel_person_id BIGINT NOT NULL UNIQUE,
    uuid VARCHAR(36),
    nummer VARCHAR(50),
    navn VARCHAR(500),
    postadresse_adresselinje1 VARCHAR(200),
    postadresse_adresselinje2 VARCHAR(200),
    postadresse_adresselinje3 VARCHAR(200),
    postadresse_postnummer VARCHAR(10),
    postadresse_poststed VARCHAR(100),
    postadresse_land_kode VARCHAR(10),
    sist_lastet_ned TIMESTAMP NOT NULL
);

-- Indexes for personer
CREATE INDEX idx_person_nummer ON matrikkel_personer(nummer);
CREATE INDEX idx_person_uuid ON matrikkel_personer(uuid);
CREATE INDEX idx_person_navn ON matrikkel_personer(navn);

-- ============================================================================
-- 4. FYSISKE PERSONER (Physical Persons)
-- ============================================================================

CREATE TABLE matrikkel_fysiske_personer (
    id BIGINT PRIMARY KEY REFERENCES matrikkel_personer(id) ON DELETE CASCADE,
    fodselsnummer VARCHAR(11) UNIQUE,
    etternavn VARCHAR(200),
    fornavn VARCHAR(200),
    person_status_kode_id VARCHAR(50),
    bostedsadresse_kommunenummer VARCHAR(10),
    bostedsadresse_adressekode BIGINT,
    bostedsadresse_husnummer INTEGER,
    bostedsadresse_husbokstav VARCHAR(10),
    bostedsadresse_postnummer VARCHAR(10),
    hjemlandsadresse_adresselinje1 VARCHAR(200),
    hjemlandsadresse_adresselinje2 VARCHAR(200),
    hjemlandsadresse_adresselinje3 VARCHAR(200),
    hjemlandsadresse_land_kode_id VARCHAR(10)
);

-- Indexes for fysiske personer
CREATE INDEX idx_fysisk_person_fodselsnummer ON matrikkel_fysiske_personer(fodselsnummer);
CREATE INDEX idx_fysisk_person_etternavn ON matrikkel_fysiske_personer(etternavn);
CREATE INDEX idx_fysisk_person_fornavn ON matrikkel_fysiske_personer(fornavn);

-- ============================================================================
-- 5. JURIDISKE PERSONER (Legal Entities)
-- ============================================================================

CREATE TABLE matrikkel_juridiske_personer (
    id BIGINT PRIMARY KEY REFERENCES matrikkel_personer(id) ON DELETE CASCADE,
    organisasjonsnummer VARCHAR(20) UNIQUE,
    organisasjonsform_kode VARCHAR(50),
    slettet_dato DATE,
    forretningsadresse_adresselinje1 VARCHAR(200),
    forretningsadresse_adresselinje2 VARCHAR(200),
    forretningsadresse_adresselinje3 VARCHAR(200),
    forretningsadresse_postnummer VARCHAR(10),
    forretningsadresse_poststed VARCHAR(100),
    forretningsadresse_land_kode VARCHAR(10)
);

-- Indexes for juridiske personer
CREATE INDEX idx_juridisk_person_organisasjonsnummer ON matrikkel_juridiske_personer(organisasjonsnummer);
CREATE INDEX idx_juridisk_person_organisasjonsform ON matrikkel_juridiske_personer(organisasjonsform_kode);

-- ============================================================================
-- 6. EIERFORHOLD (Ownership)
-- ============================================================================

CREATE TABLE matrikkel_eierforhold (
    id BIGSERIAL PRIMARY KEY,
    matrikkelenhet_id BIGINT NOT NULL,
    matrikkel_eierforhold_id BIGINT,
    uuid VARCHAR(36),
    eierforhold_type VARCHAR(50),
    andel_teller INTEGER,
    andel_nevner INTEGER,
    dato_fra DATE,
    
    -- DEPRECATED: Legacy person IDs (kept for migration compatibility)
    person_id BIGINT,
    juridisk_person_id BIGINT,
    
    -- NEW: Foreign keys to person entities
    fysisk_person_id BIGINT,
    juridisk_person_entity_id BIGINT,
    
    -- Matrikkelenhet as owner
    eier_matrikkelenhet_id BIGINT,
    
    andelsnummer INTEGER,
    tinglyst BOOLEAN DEFAULT false,
    sist_lastet_ned TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_eierforhold_matrikkelenhet 
        FOREIGN KEY (matrikkelenhet_id) 
        REFERENCES matrikkel_matrikkelenheter(matrikkelenhet_id) 
        ON DELETE CASCADE,
        
    CONSTRAINT fk_eierforhold_fysisk_person
        FOREIGN KEY (fysisk_person_id)
        REFERENCES matrikkel_fysiske_personer(id)
        ON DELETE SET NULL,
        
    CONSTRAINT fk_eierforhold_juridisk_person
        FOREIGN KEY (juridisk_person_entity_id)
        REFERENCES matrikkel_juridiske_personer(id)
        ON DELETE SET NULL
);

-- Indexes for eierforhold
CREATE INDEX idx_eierforhold_matrikkelenhet ON matrikkel_eierforhold(matrikkelenhet_id);
CREATE INDEX idx_eierforhold_person ON matrikkel_eierforhold(person_id);
CREATE INDEX idx_eierforhold_juridisk_person ON matrikkel_eierforhold(juridisk_person_id);
CREATE INDEX idx_eierforhold_fysisk_person ON matrikkel_eierforhold(fysisk_person_id);
CREATE INDEX idx_eierforhold_juridisk_person_entity ON matrikkel_eierforhold(juridisk_person_entity_id);

-- ============================================================================
-- 7. BYGNINGER (Buildings)
-- ============================================================================

CREATE TABLE matrikkel_bygninger (
    bygning_id BIGINT PRIMARY KEY,
    matrikkel_bygning_nummer BIGINT NOT NULL,
    lopenummer INTEGER,
    uuid VARCHAR(36),
    bygningstype_kode_id INTEGER,
    bygningsstatus_kode_id INTEGER,
    bebygd_areal DOUBLE PRECISION,
    bruksareal DOUBLE PRECISION,
    uten_bebygd_areal BOOLEAN DEFAULT FALSE,
    ufullstendig_areal BOOLEAN DEFAULT FALSE,
    byggeaar INTEGER,
    har_sefrakminne BOOLEAN DEFAULT FALSE,
    har_kulturminne BOOLEAN DEFAULT FALSE,
    har_heis BOOLEAN DEFAULT FALSE,
    skjermingsverdig BOOLEAN DEFAULT FALSE,
    nymatrikulert BOOLEAN DEFAULT FALSE,
    avlops_kode_id INTEGER,
    vannforsynings_kode_id INTEGER,
    oppvarmings_kode_ids INTEGER[],
    energikilde_kode_ids INTEGER[],
    naringsgruppe_kode_id INTEGER,
    opprinnelses_kode_id INTEGER,
    antall_etasjer INTEGER,
    representasjonspunkt_x DOUBLE PRECISION,
    representasjonspunkt_y DOUBLE PRECISION,
    representasjonspunkt_z DOUBLE PRECISION,
    koordinatsystem VARCHAR(50),
    sist_lastet_ned TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opprettet TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    oppdatert TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for bygninger
CREATE INDEX idx_bygning_bygningsnummer ON matrikkel_bygninger(matrikkel_bygning_nummer);
CREATE INDEX idx_bygning_uuid ON matrikkel_bygninger(uuid);
CREATE INDEX idx_bygning_bygningstype ON matrikkel_bygninger(bygningstype_kode_id);
CREATE INDEX idx_bygning_bygningsstatus ON matrikkel_bygninger(bygningsstatus_kode_id);
CREATE INDEX idx_bygning_sist_lastet_ned ON matrikkel_bygninger(sist_lastet_ned);

-- ============================================================================
-- 8. BYGNING ↔ MATRIKKELENHET (Many-to-Many)
-- ============================================================================

CREATE TABLE matrikkel_bygning_matrikkelenhet (
    bygning_id BIGINT NOT NULL,
    matrikkelenhet_id BIGINT NOT NULL,
    opprettet TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (bygning_id, matrikkelenhet_id),
    
    CONSTRAINT fk_bygning_matrikkelenhet_bygning
        FOREIGN KEY (bygning_id)
        REFERENCES matrikkel_bygninger(bygning_id)
        ON DELETE CASCADE,
        
    CONSTRAINT fk_bygning_matrikkelenhet_matrikkelenhet
        FOREIGN KEY (matrikkelenhet_id)
        REFERENCES matrikkel_matrikkelenheter(matrikkelenhet_id)
        ON DELETE CASCADE
);

-- Indexes for bygning_matrikkelenhet
CREATE INDEX idx_bygning_matrikkelenhet_bygning ON matrikkel_bygning_matrikkelenhet(bygning_id);
CREATE INDEX idx_bygning_matrikkelenhet_matrikkelenhet ON matrikkel_bygning_matrikkelenhet(matrikkelenhet_id);

-- ============================================================================
-- 9. BRUKSENHETER (Dwelling Units)
-- ============================================================================

CREATE TABLE matrikkel_bruksenheter (
    bruksenhet_id BIGINT PRIMARY KEY,
    matrikkelenhet_id BIGINT NOT NULL,
    lopenummer INTEGER,
    uuid VARCHAR(36),
    bruksenhettype_kode_id INTEGER,
    etasjeplan_kode_id INTEGER,
    etasjenummer INTEGER,
    adresse_id BIGINT,
    antall_rom INTEGER,
    antall_bad INTEGER,
    antall_wc INTEGER,
    bruksareal DOUBLE PRECISION,
    kjokkentilgang_kode_id INTEGER,
    skal_utga BOOLEAN DEFAULT FALSE,
    bygg_skjermingsverdig BOOLEAN DEFAULT FALSE,
    kostra_funksjon_kode_id INTEGER,
    sist_lastet_ned TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opprettet TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    oppdatert TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_bruksenhet_bygning
        FOREIGN KEY (bygning_id)
        REFERENCES matrikkel_bygninger(bygning_id)
        ON DELETE CASCADE,
        
    CONSTRAINT fk_bruksenhet_matrikkelenhet
        FOREIGN KEY (matrikkelenhet_id)
        REFERENCES matrikkel_matrikkelenheter(matrikkelenhet_id)
        ON DELETE CASCADE
);

-- Indexes for bruksenheter
CREATE INDEX idx_bruksenhet_bygning ON matrikkel_bruksenheter(bygning_id);
CREATE INDEX idx_bruksenhet_matrikkelenhet ON matrikkel_bruksenheter(matrikkelenhet_id);
CREATE INDEX idx_bruksenhet_uuid ON matrikkel_bruksenheter(uuid);
CREATE INDEX idx_bruksenhet_adresse ON matrikkel_bruksenheter(adresse_id);
CREATE INDEX idx_bruksenhet_type ON matrikkel_bruksenheter(bruksenhettype_kode_id);
CREATE INDEX idx_bruksenhet_sist_lastet_ned ON matrikkel_bruksenheter(sist_lastet_ned);

-- ============================================================================
-- 10. VEGER (Streets/Roads)
-- ============================================================================

CREATE TABLE matrikkel_veger (
    veg_id BIGINT PRIMARY KEY,
    kommune_id BIGINT NOT NULL,
    adressekode INTEGER NOT NULL,
    adressenavn VARCHAR(200) NOT NULL,
    kort_adressenavn VARCHAR(100),
    stedsnummer VARCHAR(50),
    uuid VARCHAR(36),
    sist_lastet_ned TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opprettet TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    oppdatert TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for veger
CREATE INDEX idx_veg_kommune ON matrikkel_veger(kommune_id);
CREATE INDEX idx_veg_adressekode ON matrikkel_veger(adressekode);
CREATE INDEX idx_veg_adressenavn ON matrikkel_veger(adressenavn);
CREATE INDEX idx_veg_uuid ON matrikkel_veger(uuid);
CREATE INDEX idx_veg_sist_lastet_ned ON matrikkel_veger(sist_lastet_ned);

-- ============================================================================
-- 11. ADRESSER (Addresses - Base table)
-- ============================================================================

CREATE TABLE matrikkel_adresser (
    adresse_id BIGINT PRIMARY KEY,
    adressetype VARCHAR(20) NOT NULL CHECK (adressetype IN ('VEGADRESSE', 'MATRIKKELADRESSE')),
    matrikkelenhet_id BIGINT,
    representasjonspunkt_x DOUBLE PRECISION,
    representasjonspunkt_y DOUBLE PRECISION,
    representasjonspunkt_z DOUBLE PRECISION,
    koordinatsystem VARCHAR(50),
    adressetilleggsnavn VARCHAR(200),
    kortnavn VARCHAR(100),
    uuid VARCHAR(36),
    sist_lastet_ned TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opprettet TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    oppdatert TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_adresse_matrikkelenhet
        FOREIGN KEY (matrikkelenhet_id)
        REFERENCES matrikkel_matrikkelenheter(matrikkelenhet_id)
        ON DELETE SET NULL
);

-- Indexes for adresser
CREATE INDEX idx_adresse_type ON matrikkel_adresser(adressetype);
CREATE INDEX idx_adresse_matrikkelenhet ON matrikkel_adresser(matrikkelenhet_id);
CREATE INDEX idx_adresse_uuid ON matrikkel_adresser(uuid);
CREATE INDEX idx_adresse_sist_lastet_ned ON matrikkel_adresser(sist_lastet_ned);

-- ============================================================================
-- 12. VEGADRESSER (Street Addresses)
-- ============================================================================

CREATE TABLE matrikkel_vegadresser (
    vegadresse_id BIGINT PRIMARY KEY,
    veg_id BIGINT NOT NULL,
    nummer INTEGER,
    bokstav VARCHAR(1),
    
    CONSTRAINT fk_vegadresse_adresse
        FOREIGN KEY (vegadresse_id)
        REFERENCES matrikkel_adresser(adresse_id)
        ON DELETE CASCADE,
        
    CONSTRAINT fk_vegadresse_veg
        FOREIGN KEY (veg_id)
        REFERENCES matrikkel_veger(veg_id)
        ON DELETE CASCADE
);

-- Indexes for vegadresser
CREATE INDEX idx_vegadresse_veg ON matrikkel_vegadresser(veg_id);
CREATE INDEX idx_vegadresse_nummer ON matrikkel_vegadresser(nummer);

-- ============================================================================
-- 13. MATRIKKELENHET ↔ ADRESSE (Many-to-Many)
-- ============================================================================

CREATE TABLE matrikkel_matrikkelenhet_adresse (
    matrikkelenhet_id BIGINT NOT NULL,
    adresse_id BIGINT NOT NULL,
    opprettet TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (matrikkelenhet_id, adresse_id),
    
    CONSTRAINT fk_matrikkelenhet_adresse_matrikkelenhet
        FOREIGN KEY (matrikkelenhet_id)
        REFERENCES matrikkel_matrikkelenheter(matrikkelenhet_id)
        ON DELETE CASCADE,
        
    CONSTRAINT fk_matrikkelenhet_adresse_adresse
        FOREIGN KEY (adresse_id)
        REFERENCES matrikkel_adresser(adresse_id)
        ON DELETE CASCADE
);

-- Indexes for matrikkelenhet_adresse
CREATE INDEX idx_matrikkelenhet_adresse_matrikkelenhet ON matrikkel_matrikkelenhet_adresse(matrikkelenhet_id);
CREATE INDEX idx_matrikkelenhet_adresse_adresse ON matrikkel_matrikkelenhet_adresse(adresse_id);

-- ============================================================================
-- TABLE COMMENTS
-- ============================================================================

COMMENT ON TABLE matrikkel_matrikkelenheter IS 'Cadastral units from Norwegian Matrikkel API';
COMMENT ON TABLE matrikkel_kommuner IS 'Norwegian municipalities from KommuneServiceWS API';
COMMENT ON TABLE matrikkel_personer IS 'Base table for all persons (owners) - both fysiske and juridiske personer';
COMMENT ON TABLE matrikkel_fysiske_personer IS 'Physical persons (natural persons with fødselsnummer)';
COMMENT ON TABLE matrikkel_juridiske_personer IS 'Juridiske personer (legal entities/organizations with organisasjonsnummer)';
COMMENT ON TABLE matrikkel_eierforhold IS 'Ownership records for matrikkelenheter';
COMMENT ON TABLE matrikkel_bygninger IS 'Bygninger (buildings) - physical structures';
COMMENT ON TABLE matrikkel_bygning_matrikkelenhet IS 'Junction table: bygning ↔ matrikkelenhet (many-to-many)';
COMMENT ON TABLE matrikkel_bruksenheter IS 'Bruksenheter (dwelling units) - links bygning to matrikkelenhet';
COMMENT ON TABLE matrikkel_veger IS 'Veger (streets/roads) - basis for vegadresser';
COMMENT ON TABLE matrikkel_adresser IS 'Adresser (addresses) - base table for all address types';
COMMENT ON TABLE matrikkel_vegadresser IS 'Vegadresser (street addresses) - addresses on streets with house numbers';
COMMENT ON TABLE matrikkel_matrikkelenhet_adresse IS 'Junction table: matrikkelenhet ↔ adresse (many-to-many)';

-- Deprecated field comments
COMMENT ON COLUMN matrikkel_eierforhold.person_id IS 'DEPRECATED: Use fysisk_person_id instead - kept for backwards compatibility';
COMMENT ON COLUMN matrikkel_eierforhold.juridisk_person_id IS 'DEPRECATED: Use juridisk_person_entity_id instead - kept for backwards compatibility';
COMMENT ON COLUMN matrikkel_eierforhold.fysisk_person_id IS 'Foreign key to fysisk person entity';
COMMENT ON COLUMN matrikkel_eierforhold.juridisk_person_entity_id IS 'Foreign key to juridisk person entity';
