-- Migration: add extra fields to progress_notes for structured nursing notes
ALTER TABLE progress_notes
    ADD COLUMN general_condition TEXT NULL AFTER temperature,
    ADD COLUMN observation TEXT NULL AFTER general_condition,
    ADD COLUMN intervention TEXT NULL AFTER observation,
    ADD COLUMN patient_response TEXT NULL AFTER intervention,
    ADD COLUMN intake_output TEXT NULL AFTER patient_response;

-- Optional: ensure notes remains NOT NULL (existing schema)
