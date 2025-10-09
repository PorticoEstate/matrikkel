-- ================================================================
-- Database Schema for 7 Primary Tables - Matrikkel System
-- Created: 2025-10-07
-- Purpose: Support filtered data retrieval by kommune and tinglyst eier
-- ================================================================

BEGIN;

-- ================================================================
-- 1. KOMMUNE TABLE
-- ================================================================
CREATE TABLE IF NOT EXISTS matrikkel_kommuner (
    kommune_id SERIAL PRIMARY KEY,
    kommunenummer INT NOT NULL UNIQUE,
    kommunenavn VARCHAR(255) NOT NULL,
    fylkesnummer INT NOT NULL,
    fylkesnavn VARCHAR(255),
    gyldig_til_dato DATE,
    koordinatsystem_kode VARCHAR(50),
    eksklusiv_bruker VARCHAR(100),
    nedsatt_konsesjonsgrense BOOLEAN DEFAULT FALSE,
    senterpunkt_nord FLOAT,
    senterpunkt_ost FLOAT,
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    timestamp_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_matrikkel_kommuner_kommunenummer ON matrikkel_kommuner (kommunenummer);
CREATE INDEX IF NOT EXISTS idx_matrikkel_kommuner_fylkesnummer ON matrikkel_kommuner (fylkesnummer);
CREATE INDEX IF NOT EXISTS idx_matrikkel_kommuner_navn ON matrikkel_kommuner (kommunenavn);

COMMENT ON TABLE matrikkel_kommuner IS 'Alle norske kommuner fra KommuneServiceWS';
COMMENT ON COLUMN matrikkel_kommuner.kommunenummer IS 'Unikt 4-sifret kommunenummer (f.eks. 5001 for Trondheim)';
COMMENT ON COLUMN matrikkel_kommuner.fylkesnummer IS '2-sifret fylkesnummer (f.eks. 50 for Trøndelag)';
COMMENT ON COLUMN matrikkel_kommuner.gyldig_til_dato IS 'Når kommune utgår (ved sammenslåing)';


-- ================================================================
-- 2. PERSONER (Fysiske personer som eiere)
-- ================================================================
CREATE TABLE IF NOT EXISTS matrikkel_personer (
    person_id BIGINT PRIMARY KEY,
    fornavn VARCHAR(255),
    etternavn VARCHAR(255),
    fullt_navn VARCHAR(500),
    fodselsnummer VARCHAR(20), -- Kryptert/anonymisert
    adresse_id BIGINT, -- Referanse til matrikkel_adresser hvis tilgjengelig
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    timestamp_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_matrikkel_personer_navn ON matrikkel_personer (etternavn, fornavn);
CREATE INDEX IF NOT EXISTS idx_matrikkel_personer_adresse ON matrikkel_personer (adresse_id);

COMMENT ON TABLE matrikkel_personer IS 'Fysiske personer (FysiskPerson) fra Matrikkel API - lastet on-demand via StoreService';
COMMENT ON COLUMN matrikkel_personer.person_id IS 'PersonId fra MatrikkelBubbleId';


-- ================================================================
-- 3. JURIDISKE PERSONER (Organisasjoner som eiere)
-- ================================================================
CREATE TABLE IF NOT EXISTS matrikkel_juridiske_personer (
    juridisk_person_id BIGINT PRIMARY KEY,
    organisasjonsnavn VARCHAR(500) NOT NULL,
    organisasjonsnummer VARCHAR(20) UNIQUE,
    organisasjonsform VARCHAR(100),
    adresse_id BIGINT, -- Referanse til matrikkel_adresser hvis tilgjengelig
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    timestamp_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_matrikkel_juridiske_personer_orgnr ON matrikkel_juridiske_personer (organisasjonsnummer);
CREATE INDEX IF NOT EXISTS idx_matrikkel_juridiske_personer_navn ON matrikkel_juridiske_personer (organisasjonsnavn);
CREATE INDEX IF NOT EXISTS idx_matrikkel_juridiske_personer_adresse ON matrikkel_juridiske_personer (adresse_id);

COMMENT ON TABLE matrikkel_juridiske_personer IS 'Juridiske personer (JuridiskPerson) fra Matrikkel API - lastet on-demand via StoreService';
COMMENT ON COLUMN matrikkel_juridiske_personer.juridisk_person_id IS 'JuridiskPersonId fra MatrikkelBubbleId';


-- ================================================================
-- 4. MATRIKKELENHET TABLE (mest sentrale for eierforhold)
-- ================================================================
CREATE TABLE IF NOT EXISTS matrikkel_matrikkelenheter (
    matrikkelenhet_id BIGINT PRIMARY KEY,
    kommunenummer INT NOT NULL,
    gardsnummer INT NOT NULL,
    bruksnummer INT NOT NULL,
    festenummer INT DEFAULT 0,
    seksjonsnummer INT DEFAULT 0,
    
    -- Matrikkel identifikasjon
    matrikkelnummer_tekst VARCHAR(50) NOT NULL, -- "5001/123/45/0/0"
    
    -- Eierforhold (normalisert med foreign keys)
    eier_type VARCHAR(50), -- 'person', 'juridisk_person', 'ukjent'
    eier_person_id BIGINT, -- Foreign key til matrikkel_personer
    eier_juridisk_person_id BIGINT, -- Foreign key til matrikkel_juridiske_personer
    
    -- Areal og eiendominformasjon
    historisk_oppgitt_areal FLOAT,
    areal_kilde VARCHAR(100),
    tinglyst BOOLEAN DEFAULT FALSE,
    skyld FLOAT,
    bruksnavn VARCHAR(255),
    
    -- Datoer
    etableringsdato DATE,
    
    -- Status-flagg
    er_seksjonert BOOLEAN DEFAULT FALSE,
    har_aktive_festegrunner BOOLEAN DEFAULT FALSE,
    har_anmerket_klage BOOLEAN DEFAULT FALSE,
    har_grunnforurensing BOOLEAN DEFAULT FALSE,
    har_kulturminne BOOLEAN DEFAULT FALSE,
    utgatt BOOLEAN DEFAULT FALSE,
    nymatrikulert BOOLEAN DEFAULT FALSE,
    
    -- Metadata
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    timestamp_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (kommunenummer) REFERENCES matrikkel_kommuner (kommunenummer) ON DELETE RESTRICT,
    FOREIGN KEY (eier_person_id) REFERENCES matrikkel_personer (person_id) ON DELETE SET NULL,
    FOREIGN KEY (eier_juridisk_person_id) REFERENCES matrikkel_juridiske_personer (juridisk_person_id) ON DELETE SET NULL,
    CHECK ((eier_type = 'person' AND eier_person_id IS NOT NULL AND eier_juridisk_person_id IS NULL) OR
           (eier_type = 'juridisk_person' AND eier_juridisk_person_id IS NOT NULL AND eier_person_id IS NULL) OR
           (eier_type = 'ukjent' AND eier_person_id IS NULL AND eier_juridisk_person_id IS NULL))
);

-- Indexes for ytelse
CREATE INDEX IF NOT EXISTS idx_matrikkel_matrikkelenheter_kommune ON matrikkel_matrikkelenheter (kommunenummer);
CREATE INDEX IF NOT EXISTS idx_matrikkel_matrikkelenheter_eier_person ON matrikkel_matrikkelenheter (eier_person_id) WHERE eier_person_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_matrikkel_matrikkelenheter_eier_juridisk ON matrikkel_matrikkelenheter (eier_juridisk_person_id) WHERE eier_juridisk_person_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_matrikkel_matrikkelenheter_eier_type ON matrikkel_matrikkelenheter (eier_type);
CREATE INDEX IF NOT EXISTS idx_matrikkel_matrikkelenheter_matr ON matrikkel_matrikkelenheter (kommunenummer, gardsnummer, bruksnummer, festenummer, seksjonsnummer);
CREATE UNIQUE INDEX IF NOT EXISTS idx_matrikkel_matrikkelenheter_unique_matr ON matrikkel_matrikkelenheter (kommunenummer, gardsnummer, bruksnummer, festenummer, seksjonsnummer);
CREATE INDEX IF NOT EXISTS idx_matrikkel_matrikkelenheter_tinglyst ON matrikkel_matrikkelenheter (tinglyst);
CREATE INDEX IF NOT EXISTS idx_matrikkel_matrikkelenheter_gnr_bnr ON matrikkel_matrikkelenheter (gardsnummer, bruksnummer);

COMMENT ON TABLE matrikkel_matrikkelenheter IS 'Matrikkelenheter (eiendommer) med normaliserte eierforhold';
COMMENT ON COLUMN matrikkel_matrikkelenheter.eier_person_id IS 'Foreign key til matrikkel_personer (kun satt hvis eier_type=person)';
COMMENT ON COLUMN matrikkel_matrikkelenheter.eier_juridisk_person_id IS 'Foreign key til matrikkel_juridiske_personer (kun satt hvis eier_type=juridisk_person)';
COMMENT ON COLUMN matrikkel_matrikkelenheter.eier_type IS 'Type eier: person, juridisk_person, eller ukjent';
COMMENT ON COLUMN matrikkel_matrikkelenheter.matrikkelnummer_tekst IS 'Fullt matrikkelnummer som tekst: kommunenr/gnr/bnr/fnr/snr';


-- ================================================================
-- 5. BYGNING TABLE
-- ================================================================
CREATE TABLE IF NOT EXISTS matrikkel_bygninger (
    bygning_id BIGINT PRIMARY KEY,
    bygningsnummer BIGINT NOT NULL UNIQUE,
    kommunenummer INT NOT NULL,
    
    -- Bygningstype og klassifisering
    bygningstype_kode VARCHAR(10),
    bygningstype_navn VARCHAR(255),
    bygningsstatus_kode VARCHAR(10),
    bygningsstatus_navn VARCHAR(100),
    
    -- Areal og størrelse
    bebygd_areal FLOAT,
    bruksareal_totalt INT,
    uten_bebygd_areal BOOLEAN DEFAULT FALSE,
    ufullstendig_areal BOOLEAN DEFAULT FALSE,
    
    -- Etasjer og bruksenheter
    etasjer_antall INT,
    bruksenheter_antall INT,
    
    -- Tilleggsinformasjon
    har_heis BOOLEAN DEFAULT FALSE,
    har_sefrakminne BOOLEAN DEFAULT FALSE,
    har_kulturminne BOOLEAN DEFAULT FALSE,
    skjermingsverdig BOOLEAN DEFAULT FALSE,
    
    -- Oppvarming og energi
    oppvarming_koder VARCHAR(255), -- Kommaseparert liste
    energikilde_koder VARCHAR(255), -- Kommaseparert liste
    avlop_kode VARCHAR(10),
    vannforsyning_kode VARCHAR(10),
    
    -- Koordinater (representasjonspunkt)
    representasjonspunkt_nord FLOAT,
    representasjonspunkt_ost FLOAT,
    epsg_kode INT DEFAULT 25833,
    
    -- Metadata
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    timestamp_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (kommunenummer) REFERENCES matrikkel_kommuner (kommunenummer) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_matrikkel_bygninger_bygningsnummer ON matrikkel_bygninger (bygningsnummer);
CREATE INDEX IF NOT EXISTS idx_matrikkel_bygninger_kommune ON matrikkel_bygninger (kommunenummer);
CREATE INDEX IF NOT EXISTS idx_matrikkel_bygninger_bygningstype ON matrikkel_bygninger (bygningstype_kode);
CREATE INDEX IF NOT EXISTS idx_matrikkel_bygninger_status ON matrikkel_bygninger (bygningsstatus_kode);

COMMENT ON TABLE matrikkel_bygninger IS 'Bygninger fra BygningServiceWS';
COMMENT ON COLUMN matrikkel_bygninger.bygningsnummer IS 'Unikt bygningsnummer i Norge';
COMMENT ON COLUMN matrikkel_bygninger.bebygd_areal IS 'Bebygd areal i m²';


-- ================================================================
-- 6. GATE TABLE
-- ================================================================
CREATE TABLE IF NOT EXISTS matrikkel_gater (
    gate_id SERIAL PRIMARY KEY,
    kommunenummer INT NOT NULL,
    gatenavn VARCHAR(255) NOT NULL,
    adresser_antall INT DEFAULT 0,
    postnummer_liste VARCHAR(255), -- Kommaseparert liste av postnumre som bruker gaten
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    timestamp_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (kommunenummer) REFERENCES matrikkel_kommuner (kommunenummer) ON DELETE RESTRICT,
    UNIQUE (kommunenummer, gatenavn)
);

CREATE INDEX IF NOT EXISTS idx_matrikkel_gater_kommune ON matrikkel_gater (kommunenummer);
CREATE INDEX IF NOT EXISTS idx_matrikkel_gater_navn ON matrikkel_gater (gatenavn);
CREATE INDEX IF NOT EXISTS idx_matrikkel_gater_kommune_navn ON matrikkel_gater (kommunenummer, gatenavn);

COMMENT ON TABLE matrikkel_gater IS 'Unike gatenavn per kommune (ekstrahert fra adresser)';
COMMENT ON COLUMN matrikkel_gater.adresser_antall IS 'Antall adresser som bruker dette gatenavnet';


-- ================================================================
-- 5. RENAME EXISTING TABLES TO old_ PREFIX (for CSV import)
-- ================================================================
DO $$
BEGIN
    -- Rename matrikkel_adresser to old_matrikkel_adresser
    IF EXISTS (
        SELECT 1 FROM information_schema.tables 
        WHERE table_name = 'matrikkel_adresser'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.tables 
        WHERE table_name = 'old_matrikkel_adresser'
    ) THEN
        ALTER TABLE matrikkel_adresser RENAME TO old_matrikkel_adresser;
    END IF;
    
    -- Rename matrikkel_bruksenheter to old_matrikkel_bruksenheter
    IF EXISTS (
        SELECT 1 FROM information_schema.tables 
        WHERE table_name = 'matrikkel_bruksenheter'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.tables 
        WHERE table_name = 'old_matrikkel_bruksenheter'
    ) THEN
        ALTER TABLE matrikkel_bruksenheter RENAME TO old_matrikkel_bruksenheter;
    END IF;
END $$;

COMMENT ON TABLE old_matrikkel_adresser IS 'LEGACY: CSV-basert adresseimport (flat struktur)';
COMMENT ON TABLE old_matrikkel_bruksenheter IS 'LEGACY: CSV-basert bruksenhetimport (flat struktur)';


-- ================================================================
-- 6. NEW: matrikkel_adresser (SOAP API-basert, Vegadresse struktur)
-- ================================================================
CREATE TABLE IF NOT EXISTS matrikkel_adresser (
    adresse_id BIGINT PRIMARY KEY,
    kommunenummer INT NOT NULL,
    
    -- Vegadresse-spesifikke felter
    veg_id BIGINT, -- Referanse til matrikkel_gater (fra vegId)
    adresse_nummer INT, -- nummer fra Vegadresse
    adresse_bokstav VARCHAR(1), -- bokstav fra Vegadresse
    
    -- Matrikkelenhet-kobling
    matrikkelenhet_id BIGINT,
    
    -- Adressetillegg
    adressetilleggsnavn VARCHAR(255),
    kortnavn VARCHAR(100),
    
    -- Representasjonspunkt (koordinater)
    representasjonspunkt_nord DOUBLE PRECISION,
    representasjonspunkt_ost DOUBLE PRECISION,
    epsg_kode INT DEFAULT 25833,
    
    -- UUID fra API
    uuid VARCHAR(36),
    
    -- Post-informasjon (må hentes fra Postnummer-tjeneste eller PostadresseInMatrikkel)
    postnummer VARCHAR(4),
    poststed VARCHAR(100),
    
    -- Metadata
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    timestamp_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (kommunenummer) REFERENCES matrikkel_kommuner (kommunenummer) ON DELETE RESTRICT,
    FOREIGN KEY (veg_id) REFERENCES matrikkel_gater (gate_id) ON DELETE SET NULL,
    FOREIGN KEY (matrikkelenhet_id) REFERENCES matrikkel_matrikkelenheter (matrikkelenhet_id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_matrikkel_adresser_kommune ON matrikkel_adresser (kommunenummer);
CREATE INDEX IF NOT EXISTS idx_matrikkel_adresser_veg ON matrikkel_adresser (veg_id);
CREATE INDEX IF NOT EXISTS idx_matrikkel_adresser_matrikkelenhet ON matrikkel_adresser (matrikkelenhet_id);
CREATE INDEX IF NOT EXISTS idx_matrikkel_adresser_uuid ON matrikkel_adresser (uuid);
CREATE INDEX IF NOT EXISTS idx_matrikkel_adresser_postnummer ON matrikkel_adresser (postnummer);

COMMENT ON TABLE matrikkel_adresser IS 'Vegadresser fra NedlastningServiceWS (SOAP API struktur)';
COMMENT ON COLUMN matrikkel_adresser.veg_id IS 'Kobling til gate (fra Vegadresse.vegId)';
COMMENT ON COLUMN matrikkel_adresser.matrikkelenhet_id IS 'Kobling til matrikkelenhet fra Vegadresse.matrikkelenhetId';
COMMENT ON COLUMN matrikkel_adresser.adresse_nummer IS 'Adressenummer (fra Vegadresse.nummer)';
COMMENT ON COLUMN matrikkel_adresser.uuid IS 'UUID fra Matrikkel API';


-- ================================================================
-- 7. NEW: matrikkel_bruksenheter (SOAP API-basert, Bruksenhet struktur)
-- ================================================================
CREATE TABLE IF NOT EXISTS matrikkel_bruksenheter (
    bruksenhet_id BIGINT PRIMARY KEY,
    kommunenummer INT NOT NULL,
    
    -- Bygg-referanse
    bygg_id BIGINT, -- Fra Bruksenhet.byggId
    
    -- Etasje-informasjon
    etasjeplan_kode VARCHAR(10), -- Fra etasjeplanKodeId
    etasjenummer INT, -- Fra etasjenummer
    lopenummer INT, -- Fra lopenummer
    
    -- Relasjoner
    adresse_id BIGINT, -- Fra Bruksenhet.adresseId
    matrikkelenhet_id BIGINT, -- Fra Bruksenhet.matrikkelenhetId
    bygning_id BIGINT, -- Kobling til matrikkel_bygninger (via bygg_id lookup)
    
    -- Bruksenhet-type
    bruksenhet_type_kode VARCHAR(10), -- Fra bruksenhetstypeKodeId
    
    -- Areal og rom
    antall_rom INT,
    antall_bad INT,
    antall_wc INT,
    bruksareal DOUBLE PRECISION,
    
    -- Kjøkken
    kjokkentilgang_kode VARCHAR(10), -- Fra kjokkentilgangId
    
    -- Flagg
    skal_utga BOOLEAN DEFAULT FALSE,
    bygg_skjermingsverdig BOOLEAN DEFAULT FALSE,
    
    -- KOSTRA (kommunal rapportering)
    kostra_funksjon_kode VARCHAR(10),
    kostra_leieareal BOOLEAN DEFAULT FALSE,
    kostra_virksomhet_id BIGINT,
    
    -- UUID fra API
    uuid VARCHAR(36),
    
    -- Metadata
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    timestamp_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (kommunenummer) REFERENCES matrikkel_kommuner (kommunenummer) ON DELETE RESTRICT,
    FOREIGN KEY (adresse_id) REFERENCES matrikkel_adresser (adresse_id) ON DELETE SET NULL,
    FOREIGN KEY (matrikkelenhet_id) REFERENCES matrikkel_matrikkelenheter (matrikkelenhet_id) ON DELETE SET NULL,
    FOREIGN KEY (bygning_id) REFERENCES matrikkel_bygninger (bygning_id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_matrikkel_bruksenheter_kommune ON matrikkel_bruksenheter (kommunenummer);
CREATE INDEX IF NOT EXISTS idx_matrikkel_bruksenheter_bygg ON matrikkel_bruksenheter (bygg_id);
CREATE INDEX IF NOT EXISTS idx_matrikkel_bruksenheter_adresse ON matrikkel_bruksenheter (adresse_id);
CREATE INDEX IF NOT EXISTS idx_matrikkel_bruksenheter_matrikkelenhet ON matrikkel_bruksenheter (matrikkelenhet_id);
CREATE INDEX IF NOT EXISTS idx_matrikkel_bruksenheter_bygning ON matrikkel_bruksenheter (bygning_id);
CREATE INDEX IF NOT EXISTS idx_matrikkel_bruksenheter_uuid ON matrikkel_bruksenheter (uuid);
CREATE INDEX IF NOT EXISTS idx_matrikkel_bruksenheter_type ON matrikkel_bruksenheter (bruksenhet_type_kode);

COMMENT ON TABLE matrikkel_bruksenheter IS 'Bruksenheter fra NedlastningServiceWS (SOAP API struktur)';
COMMENT ON COLUMN matrikkel_bruksenheter.bygg_id IS 'Referanse til Bygg (fra Bruksenhet.byggId i API)';
COMMENT ON COLUMN matrikkel_bruksenheter.etasjeplan_kode IS 'Etasjeplan kode (H=Hovedetasje, K=Kjeller, L=Loft, etc.)';
COMMENT ON COLUMN matrikkel_bruksenheter.lopenummer IS 'Løpenummer innen etasje';
COMMENT ON COLUMN matrikkel_bruksenheter.bygning_id IS 'Kobling til Bygning-tabell (resolved fra bygg_id)';


-- ================================================================
-- 7. BYGNING-MATRIKKELENHET KOBLING (Many-to-Many)
-- ================================================================
CREATE TABLE IF NOT EXISTS matrikkel_bygning_matrikkelenhet (
    bygning_id BIGINT NOT NULL,
    matrikkelenhet_id BIGINT NOT NULL,
    er_hovedmatrikkelenhet BOOLEAN DEFAULT FALSE,
    timestamp_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (bygning_id, matrikkelenhet_id),
    FOREIGN KEY (bygning_id) REFERENCES matrikkel_bygninger (bygning_id) ON DELETE CASCADE,
    FOREIGN KEY (matrikkelenhet_id) REFERENCES matrikkel_matrikkelenheter (matrikkelenhet_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_bygning_matrikkelenhet_bygning ON matrikkel_bygning_matrikkelenhet (bygning_id);
CREATE INDEX IF NOT EXISTS idx_bygning_matrikkelenhet_matrikkel ON matrikkel_bygning_matrikkelenhet (matrikkelenhet_id);

COMMENT ON TABLE matrikkel_bygning_matrikkelenhet IS 'Mange-til-mange relasjon mellom bygninger og matrikkelenheter';
COMMENT ON COLUMN matrikkel_bygning_matrikkelenhet.er_hovedmatrikkelenhet IS 'TRUE hvis dette er hovedmatrikkelenheten for bygningen';


-- ================================================================
-- VIEWS FOR CONVENIENCE
-- ================================================================

-- Drop existing views if they exist (to handle schema changes)
DROP VIEW IF EXISTS v_matrikkelenheter_med_eier CASCADE;
DROP VIEW IF EXISTS v_bygninger_med_matrikkelenhet CASCADE;
DROP VIEW IF EXISTS v_adresser_full CASCADE;
DROP VIEW IF EXISTS v_old_adresser_full CASCADE;

-- View: Matrikkelenheter med eier og kommune-info
CREATE OR REPLACE VIEW v_matrikkelenheter_med_eier AS
SELECT 
    m.matrikkelenhet_id,
    m.matrikkelnummer_tekst,
    m.kommunenummer,
    k.kommunenavn,
    k.fylkesnummer,
    k.fylkesnavn,
    m.gardsnummer,
    m.bruksnummer,
    m.festenummer,
    m.seksjonsnummer,
    m.eier_type,
    m.eier_id,
    m.eier_navn,
    m.eier_organisasjonsnr,
    m.historisk_oppgitt_areal,
    m.tinglyst,
    m.bruksnavn,
    m.etableringsdato,
    m.timestamp_updated
FROM matrikkel_matrikkelenheter m
LEFT JOIN matrikkel_kommuner k ON m.kommunenummer = k.kommunenummer
WHERE m.utgatt = FALSE;

COMMENT ON VIEW v_matrikkelenheter_med_eier IS 'Matrikkelenheter med eier- og kommune-informasjon (kun aktive)';


-- View: Bygninger med matrikkelenheter
CREATE OR REPLACE VIEW v_bygninger_med_matrikkelenhet AS
SELECT 
    b.bygning_id,
    b.bygningsnummer,
    b.kommunenummer,
    k.kommunenavn,
    b.bygningstype_navn,
    b.bebygd_areal,
    b.bruksareal_totalt,
    b.etasjer_antall,
    bm.matrikkelenhet_id,
    m.matrikkelnummer_tekst,
    m.eier_navn,
    bm.er_hovedmatrikkelenhet
FROM matrikkel_bygninger b
LEFT JOIN matrikkel_kommuner k ON b.kommunenummer = k.kommunenummer
LEFT JOIN matrikkel_bygning_matrikkelenhet bm ON b.bygning_id = bm.bygning_id
LEFT JOIN matrikkel_matrikkelenheter m ON bm.matrikkelenhet_id = m.matrikkelenhet_id;

COMMENT ON VIEW v_bygninger_med_matrikkelenhet IS 'Bygninger med tilhørende matrikkelenheter og eiere';


-- View: Adresser med full kontekst (nye SOAP-baserte adresser)
CREATE OR REPLACE VIEW v_adresser_full AS
SELECT 
    a.adresse_id,
    CONCAT(g.gatenavn, ' ', a.adresse_nummer, COALESCE(a.adresse_bokstav, '')) AS adresse_tekst,
    g.gatenavn AS adressenavn,
    a.adresse_nummer AS nummer,
    a.adresse_bokstav AS bokstav,
    a.postnummer,
    a.poststed,
    a.kommunenummer,
    k.kommunenavn,
    a.matrikkelenhet_id,
    m.matrikkelnummer_tekst,
    m.eier_navn,
    m.eier_type,
    a.representasjonspunkt_nord AS nord,
    a.representasjonspunkt_ost AS ost,
    a.uuid
FROM matrikkel_adresser a
LEFT JOIN matrikkel_kommuner k ON a.kommunenummer = k.kommunenummer
LEFT JOIN matrikkel_gater g ON a.veg_id = g.gate_id
LEFT JOIN matrikkel_matrikkelenheter m ON a.matrikkelenhet_id = m.matrikkelenhet_id;

COMMENT ON VIEW v_adresser_full IS 'Adresser med gate, kommune, matrikkelenhet og eier-informasjon (SOAP API struktur)';


-- View: Legacy adresser (CSV-baserte)
CREATE OR REPLACE VIEW v_old_adresser_full AS
SELECT 
    a.adresse_id,
    a.adresse_tekst,
    a.adressenavn,
    a.nummer,
    a.bokstav,
    a.postnummer,
    a.poststed,
    a.kommunenummer,
    a.grunnkretsnavn,
    a.soknenavn,
    a.tettstednavn,
    a.nord,
    a.ost
FROM old_matrikkel_adresser a;

COMMENT ON VIEW v_old_adresser_full IS 'LEGACY: CSV-baserte adresser for bakoverkompatibilitet';


COMMIT;

-- ================================================================
-- END OF SCHEMA
-- ================================================================
