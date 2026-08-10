-- Migration: 001_add_weeks_of_pregnancy.sql
-- Add the weeks_of_pregnancy column to the patients table
-- Run this against the `prototype1` database (or your active DB) using MySQL client or phpMyAdmin.

ALTER TABLE patients
    ADD COLUMN weeks_of_pregnancy INT DEFAULT NULL;

-- Example (CLI):
-- mysql -u root -p prototype1 < config/migrations/001_add_weeks_of_pregnancy.sql
