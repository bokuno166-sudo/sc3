-- Migration: set empty admission_code to NULL and make column nullable
UPDATE admissions SET admission_code = NULL WHERE admission_code = '';
ALTER TABLE admissions MODIFY admission_code VARCHAR(20) NULL;
