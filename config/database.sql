-- Saint Claire Hospital Management System - Database Schema
-- Created: 2026-04-08

CREATE DATABASE IF NOT EXISTS prototype1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE prototype1;

-- ============================================
-- USERS & AUTHENTICATION
-- ============================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    role ENUM('admin', 'doctor', 'nurse', 'cashier', 'staff', 'inventory', 'laboratory') NOT NULL,
    department VARCHAR(50),
    status ENUM('active', 'inactive') DEFAULT 'active',
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Table to store default credentials for display on the login page
CREATE TABLE IF NOT EXISTS default_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    password_plain VARCHAR(100) NOT NULL,
    role VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- PATIENTS
-- ============================================
CREATE TABLE patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_code VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50),
    date_of_birth DATE NOT NULL,
    age INT,
    gender ENUM('Male', 'Female') NOT NULL,
    civil_status ENUM('Single', 'Married', 'Widowed', 'Separated') DEFAULT 'Single',
    address TEXT NOT NULL,
    contact_number VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    emergency_contact_name VARCHAR(100),
    emergency_contact_number VARCHAR(20),
    blood_type ENUM('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown') DEFAULT 'Unknown',
    allergies TEXT,
    medical_history TEXT,
    family_medical_history TEXT,
    is_pregnant BOOLEAN DEFAULT FALSE,
    weeks_of_pregnancy INT DEFAULT NULL,
    expected_due_date DATE,
    status ENUM('active', 'inactive', 'deceased') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================
-- PATIENT VISITS / QUEUE
-- ============================================
CREATE TABLE patient_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    queue_number VARCHAR(20) NOT NULL,
    visit_date DATE NOT NULL,
    visit_type ENUM('walk-in', 'appointment', 'emergency') DEFAULT 'walk-in',
    status ENUM('waiting', 'in-triage', 'in-consultation', 'in-laboratory', 'in-treatment', 'admitted', 'transferred', 'discharged', 'cancelled') DEFAULT 'waiting',
    priority ENUM('low', 'normal', 'high', 'emergency') DEFAULT 'normal',
    chief_complaint TEXT,
    created_by INT,
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
);

-- ============================================
-- TRIAGE / INITIAL ASSESSMENT
-- ============================================
CREATE TABLE triage_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NULL,
    patient_id INT NOT NULL,
    nurse_id INT NOT NULL,
    blood_pressure VARCHAR(20),
    heart_rate INT,
    temperature DECIMAL(4,2),
    respiratory_rate INT,
    weight DECIMAL(5,2),
    height DECIMAL(5,2),
    bmi DECIMAL(4,2),
    oxygen_saturation INT,
    pain_scale INT,
    symptoms TEXT,
    medical_history_notes TEXT,
    -- For pregnant patients
    fetal_heartbeat INT,
    weeks_of_pregnancy INT,
    contractions VARCHAR(50),
    cervix_dilation DECIMAL(3,1),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES patient_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (nurse_id) REFERENCES users(id)
);

-- ============================================
-- CONSULTATIONS
-- ============================================
CREATE TABLE consultations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    triage_id INT,
    physical_examination TEXT,
    diagnosis TEXT,
    diagnosis_codes VARCHAR(100),
    treatment_plan TEXT,
    notes TEXT,
    outcome ENUM('prescription-only', 'laboratory-request', 'admission', 'surgery', 'referral', 'discharge', 'outpatient', 'transfer', 'emergency-operation') NOT NULL,
    transfer_destination VARCHAR(255),
    follow_up_date DATE,
    follow_up_instructions TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES patient_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(id),
    FOREIGN KEY (triage_id) REFERENCES triage_records(id)
);

-- ============================================
-- PRESCRIPTIONS
-- ============================================
CREATE TABLE prescriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consultation_id INT NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    medication_name VARCHAR(100) NOT NULL,
    dosage VARCHAR(50),
    frequency VARCHAR(50),
    duration VARCHAR(50),
    instructions TEXT,
    quantity INT,
    status ENUM('pending', 'dispensed', 'cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(id)
);

-- ============================================
-- LABORATORY TESTS
-- ============================================
CREATE TABLE laboratory_tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_code VARCHAR(20) UNIQUE NOT NULL,
    test_name VARCHAR(100) NOT NULL,
    category ENUM('blood', 'urine', 'imaging', 'ultrasound', 'x-ray', 'ct-scan', 'mri', 'other') NOT NULL,
    description TEXT,
    normal_range TEXT,
    price DECIMAL(10,2) NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- LABORATORY REQUESTS
-- ============================================
CREATE TABLE laboratory_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(20) UNIQUE NOT NULL,
    visit_id INT NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    test_id INT NOT NULL,
    priority ENUM('routine', 'urgent', 'stat') DEFAULT 'routine',
    notes TEXT,
    status ENUM('pending', 'in-progress', 'completed', 'cancelled') DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME,
    FOREIGN KEY (visit_id) REFERENCES patient_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(id),
    FOREIGN KEY (test_id) REFERENCES laboratory_tests(id)
);

-- ============================================
-- LABORATORY RESULTS
-- ============================================
CREATE TABLE laboratory_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    patient_id INT NOT NULL,
    technician_id INT NOT NULL,
    result_value TEXT NOT NULL,
    reference_range TEXT,
    interpretation TEXT,
    remarks TEXT,
    attachment_path VARCHAR(255),
    status ENUM('pending-review', 'reviewed', 'finalized') DEFAULT 'pending-review',
    reviewed_by INT,
    reviewed_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES laboratory_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (technician_id) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
);

-- ============================================
-- ROOMS / BEDS
-- ============================================
CREATE TABLE rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(20) UNIQUE NOT NULL,
    room_type ENUM('ward', 'private', 'semi-private', 'icu', 'delivery', 'operating') NOT NULL,
    floor INT,
    capacity INT DEFAULT 2,
    daily_rate DECIMAL(10,2),
    facilities TEXT,
    status ENUM('available', 'occupied', 'maintenance', 'reserved') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE beds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    bed_number VARCHAR(20) NOT NULL,
    bed_type ENUM('standard', 'electric', 'birthing', 'surgical') DEFAULT 'standard',
    status ENUM('available', 'occupied', 'maintenance') DEFAULT 'available',
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
);

-- ============================================
-- ADMISSIONS
-- ============================================
CREATE TABLE admissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_code VARCHAR(20) UNIQUE DEFAULT NULL,
    visit_id INT NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    room_id INT,
    bed_id INT,
    admission_date DATETIME NOT NULL,
    expected_discharge_date DATE,
    actual_discharge_date DATETIME,
    admission_reason TEXT,
    admitting_diagnosis TEXT,
    notes TEXT,
    status ENUM('admitted', 'discharged', 'transferred', 'absconded') DEFAULT 'admitted',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES patient_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    FOREIGN KEY (bed_id) REFERENCES beds(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- ============================================
-- INPATIENT MONITORING / PROGRESS NOTES
-- ============================================
CREATE TABLE progress_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    patient_id INT NOT NULL,
    nurse_id INT,
    doctor_id INT,
    note_type ENUM('vital-signs', 'nursing-note', 'doctor-round', 'medication', 'procedure', 'with-doctor', 'other') NOT NULL,
    blood_pressure VARCHAR(20),
    heart_rate INT,
    temperature DECIMAL(4,2),
    respiratory_rate INT,
    oxygen_saturation INT,
    notes TEXT NOT NULL,
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (nurse_id) REFERENCES users(id),
    FOREIGN KEY (doctor_id) REFERENCES users(id)
);

-- ============================================
-- LYING-IN / DELIVERY RECORDS
-- ============================================
CREATE TABLE delivery_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    patient_id INT NOT NULL,
    delivery_date DATETIME NOT NULL,
    delivery_type ENUM('normal', 'assisted', 'c-section', 'water-birth') NOT NULL,
    attended_by INT,
    assistant_midwife INT,
    -- Labor details
    labor_start_time DATETIME,
    delivery_completion_time DATETIME,
    cervix_dilation_at_admission DECIMAL(3,1),
    -- Baby details
    baby_weight DECIMAL(5,2),
    baby_length DECIMAL(5,2),
    baby_gender ENUM('Male', 'Female'),
    apgar_score_1min INT,
    apgar_score_5min INT,
    apgar_score_10min INT,
    baby_condition ENUM('healthy', 'distressed', 'stillborn', 'nicu') DEFAULT 'healthy',
    -- Mother details
    placenta_delivery_time DATETIME,
    blood_loss_ml INT,
    mother_condition ENUM('stable', 'unstable', 'critical') DEFAULT 'stable',
    complications TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (attended_by) REFERENCES users(id),
    FOREIGN KEY (assistant_midwife) REFERENCES users(id)
);

-- ============================================
-- OPERATING ROOM / SURGERY
-- ============================================
CREATE TABLE surgeries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    surgery_code VARCHAR(20) UNIQUE NOT NULL,
    patient_id INT NOT NULL,
    admission_id INT,
    doctor_id INT NOT NULL,
    anesthesiologist_id INT,
    surgery_type VARCHAR(100) NOT NULL,
    surgery_date DATETIME NOT NULL,
    operating_room VARCHAR(50),
    -- Pre-op
    consent_signed BOOLEAN DEFAULT FALSE,
    consent_date DATE,
    pre_op_diagnosis TEXT,
    -- Surgery details
    procedure_description TEXT,
    findings TEXT,
    complications TEXT,
    estimated_blood_loss_ml INT,
    -- Post-op
    post_op_diagnosis TEXT,
    recovery_room_start DATETIME,
    recovery_room_end DATETIME,
    status ENUM('scheduled', 'in-progress', 'completed', 'cancelled', 'postponed') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE SET NULL,
    FOREIGN KEY (doctor_id) REFERENCES users(id),
    FOREIGN KEY (anesthesiologist_id) REFERENCES users(id)
);

-- ============================================
-- BILLING / INVOICES
-- ============================================
CREATE TABLE service_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(50) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_code VARCHAR(20) UNIQUE NOT NULL,
    service_name VARCHAR(100) NOT NULL,
    category_id INT,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES service_categories(id)
);

CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(20) UNIQUE NOT NULL,
    patient_id INT NOT NULL,
    visit_id INT,
    admission_id INT,
    total_amount DECIMAL(12,2) DEFAULT 0,
    discount_amount DECIMAL(12,2) DEFAULT 0,
    tax_amount DECIMAL(12,2) DEFAULT 0,
    net_amount DECIMAL(12,2) DEFAULT 0,
    paid_amount DECIMAL(12,2) DEFAULT 0,
    balance_amount DECIMAL(12,2) DEFAULT 0,
    status ENUM('pending', 'partial', 'paid', 'cancelled', 'refunded') DEFAULT 'pending',
    payment_method ENUM('cash', 'card', 'check', 'insurance', 'gcash', 'maya', 'other'),
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (visit_id) REFERENCES patient_visits(id) ON DELETE SET NULL,
    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    service_id INT,
    item_description VARCHAR(200) NOT NULL,
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    reference_type ENUM('consultation', 'laboratory', 'room', 'medication', 'surgery', 'delivery', 'other'),
    reference_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES services(id)
);

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    payment_amount DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash', 'card', 'check', 'insurance', 'gcash', 'maya', 'other') NOT NULL,
    payment_reference VARCHAR(100),
    received_by INT NOT NULL,
    notes TEXT,
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES users(id)
);

-- ============================================
-- DISCHARGE
-- ============================================
CREATE TABLE discharge_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    patient_id INT NOT NULL,
    discharge_date DATETIME NOT NULL,
    discharge_type ENUM('regular', 'against-medical-advice', 'expired', 'transferred') DEFAULT 'regular',
    final_diagnosis TEXT,
    discharge_summary TEXT,
    medications_on_discharge TEXT,
    follow_up_instructions TEXT,
    follow_up_date DATE,
    activity_restrictions TEXT,
    diet_instructions TEXT,
    wound_care TEXT,
    warning_signs TEXT,
    -- For lying-in patients
    baby_records TEXT,
    birth_certificate_processed BOOLEAN DEFAULT FALSE,
    discharge_checked_by INT,
    discharge_approved_by INT,
    patient_signature BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (discharge_checked_by) REFERENCES users(id),
    FOREIGN KEY (discharge_approved_by) REFERENCES users(id)
);

-- ============================================
-- INVENTORY
-- ============================================
CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(100) NOT NULL,
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE inventory_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(50) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE inventory_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(20) UNIQUE NOT NULL,
    item_name VARCHAR(100) NOT NULL,
    category_id INT,
    description TEXT,
    unit_of_measure VARCHAR(20),
    reorder_level INT DEFAULT 10,
    reorder_quantity INT DEFAULT 50,
    unit_cost DECIMAL(10,2),
    selling_price DECIMAL(10,2),
    supplier_id INT,
    status ENUM('active', 'inactive', 'discontinued') DEFAULT 'active',
    item_type VARCHAR(32) DEFAULT 'Medicine',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES inventory_categories(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
);

CREATE TABLE inventory_stock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    batch_number VARCHAR(50),
    expiry_date DATE,
    quantity_in_stock INT DEFAULT 0,
    quantity_reserved INT DEFAULT 0,
    location VARCHAR(50),
    last_movement_date DATE,
    FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
);

CREATE TABLE inventory_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    transaction_type ENUM('receipt', 'issue', 'adjustment', 'return', 'disposal') NOT NULL,
    quantity INT NOT NULL,
    unit_cost DECIMAL(10,2),
    reference_type ENUM('purchase', 'patient', 'transfer', 'adjustment', 'expiry') DEFAULT 'purchase',
    reference_id INT,
    notes TEXT,
    performed_by INT,
    transaction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES inventory_items(id),
    FOREIGN KEY (performed_by) REFERENCES users(id)
);

-- ============================================
-- AUDIT LOG
-- ============================================
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(50) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    old_values TEXT,
    new_values TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================
-- INSERT DEFAULT DATA
-- ============================================

-- Default Admin User (password: admin123)
INSERT INTO users (username, password, full_name, email, role, department, status) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin@saintclaire.com', 'admin', 'IT', 'active');

-- Sample Users (password: password123)
INSERT INTO users (username, password, full_name, email, role, department, status) VALUES
('doctor1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dr. Maria Santos', 'maria.santos@saintclaire.com', 'doctor', 'General Medicine', 'active'),
('nurse1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Nurse Juan Dela Cruz', 'juan.delacruz@saintclaire.com', 'nurse', 'Emergency', 'active'),
('cashier1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ana Reyes', 'ana.reyes@saintclaire.com', 'cashier', 'Billing', 'active'),
('lab1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Pedro Lim', 'pedro.lim@saintclaire.com', 'laboratory', 'Laboratory', 'active'),
('inventory1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Carmen Tan', 'carmen.tan@saintclaire.com', 'inventory', 'Pharmacy', 'active'),
('staff1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Roberto Garcia', 'roberto.garcia@saintclaire.com', 'staff', 'Reception', 'active');

-- Service Categories
INSERT INTO service_categories (category_name, description) VALUES
('Consultation', 'Doctor consultation fees'),
('Laboratory', 'Laboratory test fees'),
('Room and Board', 'Room accommodation charges'),
('Medication', 'Medicines and pharmaceuticals'),
('Surgery', 'Operating room and surgical fees'),
('Delivery', 'Maternity and delivery services'),
('Imaging', 'X-ray, ultrasound, CT scan, MRI'),
('Procedures', 'Medical procedures and treatments');

-- Sample Services
INSERT INTO services (service_code, service_name, category_id, description, price) VALUES
('CONS-GEN', 'General Consultation', 1, 'General medicine consultation', 500.00),
('CONS-SPEC', 'Specialist Consultation', 1, 'Specialist doctor consultation', 800.00),
('LAB-CBC', 'Complete Blood Count', 2, 'CBC blood test', 350.00),
('LAB-URINE', 'Urinalysis', 2, 'Urine analysis', 250.00),
('LAB-ULTRA', 'Ultrasound', 2, 'General ultrasound', 1500.00),
('ROOM-WARD', 'Ward Room', 3, 'Ward room per day', 800.00),
('ROOM-PVT', 'Private Room', 3, 'Private room per day', 2500.00),
('ROOM-ICU', 'ICU', 3, 'Intensive care unit per day', 5000.00),
('DEL-NORMAL', 'Normal Delivery', 5, 'Normal vaginal delivery package', 15000.00),
('DEL-CSEC', 'C-Section Delivery', 5, 'Caesarean section package', 45000.00);

-- Laboratory Tests
INSERT INTO laboratory_tests (test_code, test_name, category, description, price) VALUES
('CBC', 'Complete Blood Count', 'blood', 'Complete blood count with differential', 350.00),
('URINE', 'Urinalysis', 'urine', 'Complete urinalysis', 250.00),
('BLOOD-SUGAR', 'Blood Sugar Test', 'blood', 'Fasting blood sugar / RBS', 200.00),
('ULTRA-ABD', 'Abdominal Ultrasound', 'ultrasound', 'Abdominal ultrasound scan', 1800.00),
('ULTRA-OB', 'OB Ultrasound', 'ultrasound', 'Obstetric ultrasound for pregnancy', 1500.00),
('XRAY-CHEST', 'Chest X-Ray', 'x-ray', 'Chest X-ray PA view', 450.00),
('BLOOD-TYPE', 'Blood Typing', 'blood', 'ABO and Rh blood typing', 300.00);

-- Sample Rooms
INSERT INTO rooms (room_number, room_type, floor, capacity, daily_rate, facilities, status) VALUES
('101', 'ward', 1, 2, 800.00, '2 beds, shared bathroom, TV', 'available'),
('102', 'ward', 1, 2, 800.00, '2 beds, shared bathroom, TV', 'available'),
('201', 'private', 2, 2, 2500.00, '1-2 bed option, private bathroom, TV, AC, refrigerator', 'available'),
('202', 'private', 2, 2, 2500.00, '1-2 bed option, private bathroom, TV, AC, refrigerator', 'available'),
('301', 'delivery', 3, 2, 3000.00, 'Delivery bed, monitoring equipment, private bathroom', 'available'),
('OR-1', 'operating', 3, 2, 5000.00, 'Operating table, surgical lights, anesthesia machine', 'available'),
('ICU-1', 'icu', 2, 2, 5000.00, 'ICU bed, ventilator, cardiac monitor', 'available');

-- Inventory Categories
INSERT INTO inventory_categories (category_name, description) VALUES
('Medicines', 'Pharmaceutical products'),
('Medical Supplies', 'Disposable medical supplies'),
('Equipment', 'Medical equipment and instruments'),
('Office Supplies', 'General office supplies');

-- Sample Inventory Items
INSERT INTO inventory_items (item_code, item_name, category_id, description, unit_of_measure, reorder_level, unit_cost, selling_price, item_type) VALUES
('MED-PARAC', 'Paracetamol 500mg', 1, 'Paracetamol tablets 500mg', 'tablet', 100, 2.50, 5.00, 'Medicine'),
('MED-AMOX', 'Amoxicillin 500mg', 1, 'Amoxicillin capsules 500mg', 'capsule', 100, 8.00, 15.00, 'Medicine'),
('SUP-SYRINGE', 'Syringe 5cc', 2, 'Disposable syringe 5cc', 'piece', 200, 5.00, 12.00, 'Supply'),
('SUP-GLOVES', 'Latex Gloves', 2, 'Latex examination gloves box of 100', 'box', 20, 250.00, 400.00, 'Supply');

-- ============================================
-- MATERNITY / PRENATAL CHECKUPS
-- ============================================
CREATE TABLE IF NOT EXISTS maternity_checkups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    checkup_date DATE NOT NULL,
    weeks_of_pregnancy INT NOT NULL,
    weight DECIMAL(5,2) NULL,
    blood_pressure VARCHAR(20) NULL,
    fetal_heartbeat INT NULL,
    fundal_height DECIMAL(4,1) NULL,
    presentation VARCHAR(50) NULL,
    notes TEXT NULL,
    prescribed_vitamins TEXT NULL,
    next_appointment_date DATE NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
);
