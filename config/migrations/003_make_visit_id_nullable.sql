-- Migration: make visit_id nullable in admissions so admissions can be created without a prior visit
ALTER TABLE admissions MODIFY visit_id INT NULL;
