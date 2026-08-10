ALTER TABLE progress_notes MODIFY COLUMN note_type ENUM('vital-signs', 'nursing-note', 'doctor-round', 'medication', 'procedure', 'with-doctor', 'other') NOT NULL;
