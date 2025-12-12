-- ============================================================================
-- Add adressekode to matrikkel_innganger
-- ============================================================================
-- This migration adds the adressekode field to the innganger table
-- to store the address code from matrikkel_veger
--
-- Version: 3.0.0
-- Created: 2025-12-12
-- ============================================================================

-- Add adressekode column to matrikkel_innganger
ALTER TABLE matrikkel_innganger 
ADD COLUMN adressekode INTEGER;

-- Add index for adressekode lookups
CREATE INDEX idx_inngang_adressekode ON matrikkel_innganger(adressekode);

-- Add foreign key constraint to matrikkel_veger
-- Note: This is a soft reference since veg_id may be NULL
-- The adressekode should match matrikkel_veger.adressekode when veg_id is present

-- Add comment to document the field
COMMENT ON COLUMN matrikkel_innganger.adressekode IS 'Address code from matrikkel_veger.adressekode - used for entrance address reference';
