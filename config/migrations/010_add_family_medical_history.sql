-- Migration: Add family_medical_history to patients table
-- Date: 2026-07-28

ALTER TABLE patients
    ADD COLUMN family_medical_history TEXT DEFAULT NULL;
