-- Migration: add notes column to admissions
ALTER TABLE admissions ADD COLUMN notes TEXT DEFAULT NULL;
