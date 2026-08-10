-- Migration: Create referrals table for managing patient referrals to other hospitals
-- Date: 2026-07-22

-- Create referrals table
CREATE TABLE IF NOT EXISTS referrals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    referral_code VARCHAR(20) UNIQUE NOT NULL,
    consultation_id INT NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    visit_id INT NOT NULL,
    
    -- Referral details
    referral_hospital VARCHAR(100) NOT NULL,
    referral_department VARCHAR(100),
    reason_for_referral TEXT NOT NULL,
    clinical_summary TEXT,
    relevant_investigations TEXT,
    recommendations TEXT,
    
    -- Urgency and priority
    urgency ENUM('routine', 'urgent', 'emergency') DEFAULT 'routine',
    
    -- Doctor signature (optional digital signature)
    doctor_signature LONGBLOB,
    signature_timestamp DATETIME,
    
    -- Status tracking
    status ENUM('pending', 'printed', 'handed-to-patient', 'received-by-hospital', 'completed', 'cancelled') DEFAULT 'pending',
    
    -- Follow-up
    follow_up_date DATE,
    follow_up_instructions TEXT,
    
    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign keys
    FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(id),
    FOREIGN KEY (visit_id) REFERENCES patient_visits(id) ON DELETE CASCADE,
    
    -- Indexes
    INDEX idx_consultation (consultation_id),
    INDEX idx_patient (patient_id),
    INDEX idx_status (status),
    INDEX idx_referral_code (referral_code)
);

-- Table to track referral letter views/prints
CREATE TABLE IF NOT EXISTS referral_letter_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    referral_id INT NOT NULL,
    user_id INT NOT NULL,
    action ENUM('viewed', 'printed', 'downloaded') DEFAULT 'viewed',
    viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    
    INDEX idx_referral (referral_id),
    INDEX idx_user (user_id)
);
