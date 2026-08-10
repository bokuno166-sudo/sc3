-- Migration: Add status column to progress_notes table to track draft rounds
ALTER TABLE progress_notes
    ADD COLUMN status VARCHAR(20) DEFAULT 'completed' AFTER recorded_at;