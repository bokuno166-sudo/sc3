<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'doctor']);

$pageTitle = 'Doctor Round';
$currentPage = 'admissions';

$conn = getDBConnection();

// Get admission_id
$admissionId = isset($_GET['admission_id']) ? (int)$_GET['admission_id'] : (isset($_POST['admission_id']) ? (int)$_POST['admission_id'] : 0);
if ($admissionId <= 0) {
    setFlashMessage('error', 'Invalid Admission ID.');
    redirect('modules/admission/admissions.php');
    exit;
}

// Fetch admission, patient details
$admissionResult = $conn->query("
    SELECT a.*, p.first_name, p.last_name, p.patient_code, p.gender, p.date_of_birth, p.blood_type, p.allergies,
           r.room_number, b.bed_number
    FROM admissions a
    JOIN patients p ON a.patient_id = p.id
    LEFT JOIN rooms r ON a.room_id = r.id
    LEFT JOIN beds b ON a.bed_id = b.id
    WHERE a.id = $admissionId
    LIMIT 1
");

if ($admissionResult->num_rows === 0) {
    setFlashMessage('error', 'Admission not found.');
    redirect('modules/admission/admissions.php');
    exit;
}

$admission = $admissionResult->fetch_assoc();
$patientId = $admission['patient_id'];
$visitId = $admission['visit_id'];

// Check and add status column to progress_notes if missing
$col = $conn->query("SHOW COLUMNS FROM progress_notes LIKE 'status'");
if (!$col || $col->num_rows === 0) {
    $conn->query("ALTER TABLE progress_notes ADD COLUMN status VARCHAR(20) DEFAULT 'completed'");
}

// Get available laboratory tests
$labTests = $conn->query("SELECT * FROM laboratory_tests WHERE status = 'active' ORDER BY test_name");

// Fetch existing draft progress note for this admission (note_type = 'doctor-round', status = 'draft')
$existingDraft = null;
$draftRes = $conn->query("SELECT * FROM progress_notes WHERE admission_id = $admissionId AND note_type = 'doctor-round' AND status = 'draft' LIMIT 1");
if ($draftRes && $draftRes->num_rows > 0) {
    $existingDraft = $draftRes->fetch_assoc();
}

$assessment = $existingDraft ? ($existingDraft['notes'] ?? '') : '';
$diagnosis = '';
$treatmentPlan = '';
$decision = 'admitted';

// Fetch laboratory requests and results for this visit
$labResults = [];
if ($visitId) {
    $labResultsRes = $conn->query("
        SELECT lr.id AS request_id, lr.priority, lt.test_name, lres.result_value, lres.interpretation, lres.remarks, lres.attachment_path, lres.created_at, u.full_name as tech_name
        FROM laboratory_requests lr
        JOIN laboratory_results lres ON lr.id = lres.request_id
        JOIN laboratory_tests lt ON lr.test_id = lt.id
        LEFT JOIN users u ON lres.technician_id = u.id
        WHERE lr.visit_id = $visitId
    ");
    if ($labResultsRes && $labResultsRes->num_rows > 0) {
        $docId = (int)($_SESSION['user_id'] ?? 0);
        
        // Ensure doctor_lab_views table exists
        $conn->query("CREATE TABLE IF NOT EXISTS doctor_lab_views (
            id INT AUTO_INCREMENT PRIMARY KEY,
            visit_id INT NOT NULL,
            request_id INT NOT NULL,
            doctor_id INT NOT NULL,
            viewed_at DATETIME NOT NULL,
            UNIQUE KEY uq_req_doc (request_id, doctor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        while ($row = $labResultsRes->fetch_assoc()) {
            $labResults[] = $row;
            if ($docId) {
                $viewStmt = $conn->prepare("INSERT INTO doctor_lab_views (visit_id, request_id, doctor_id, viewed_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE viewed_at = NOW()");
                if ($viewStmt) {
                    $viewStmt->bind_param('iii', $visitId, $row['request_id'], $docId);
                    $viewStmt->execute();
                    $viewStmt->close();
                }
            }
        }
    }
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitAction = isset($_POST['submit_action']) ? sanitize($_POST['submit_action']) : 'complete';
    
    $assessment = sanitize($_POST['assessment'] ?? '');
    $diagnosis = sanitize($_POST['diagnosis'] ?? '');
    $treatmentPlan = sanitize($_POST['treatment_plan'] ?? '');
    $decision = sanitize($_POST['decision'] ?? 'admitted');
    
    $doctorId = (int)($_SESSION['user_id'] ?? 0);
    
    if ($submitAction === 'request_lab') {
        // Save/Update draft progress note
        if ($existingDraft) {
            $upStmt = $conn->prepare("UPDATE progress_notes SET notes = ?, recorded_at = NOW() WHERE id = ?");
            $upStmt->bind_param('si', $assessment, $existingDraft['id']);
            $upStmt->execute();
            $upStmt->close();
        } else {
            $note_type = 'doctor-round';
            $status = 'draft';
            $insStmt = $conn->prepare("INSERT INTO progress_notes (admission_id, patient_id, doctor_id, note_type, status, notes, recorded_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $insStmt->bind_param('iiisss', $admissionId, $patientId, $doctorId, $note_type, $status, $assessment);
            $insStmt->execute();
            $insStmt->close();
        }
        
        // Handle laboratory requests
        if (isset($_POST['lab_tests']) && is_array($_POST['lab_tests'])) {
            foreach ($_POST['lab_tests'] as $testId) {
                $testId = (int)$testId;
                $priority = sanitize($_POST['lab_priority'][$testId] ?? 'routine');
                $labNotes = sanitize($_POST['lab_notes'][$testId] ?? '');
                
                // Generate request code
                $reqResult = $conn->query("SELECT COUNT(*) as count FROM laboratory_requests WHERE DATE(requested_at) = CURDATE()");
                $reqCount = $reqResult->fetch_assoc()['count'];
                $requestCode = 'LAB' . date('Ymd') . '-' . str_pad($reqCount + 1, 3, '0', STR_PAD_LEFT);
                
                $labStmt = $conn->prepare("
                    INSERT INTO laboratory_requests 
                    (request_code, visit_id, patient_id, doctor_id, test_id, priority, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $labStmt->bind_param("siiiiss", $requestCode, $visitId, $patientId, $doctorId, $testId, $priority, $labNotes);
                $labStmt->execute();
                $labId = $labStmt->insert_id;
                $labStmt->close();
                
                // Add laboratory invoice item (billing)
                try {
                    $tRes = $conn->query("SELECT price, test_name FROM laboratory_tests WHERE id = $testId LIMIT 1");
                    $testPrice = 0.0;
                    $testName = 'Lab Test';
                    if ($tRes && $tRes->num_rows > 0) {
                        $tRow = $tRes->fetch_assoc();
                        $testPrice = (float)$tRow['price'];
                        $testName = $tRow['test_name'];
                    }
                    
                    // Find or create pending invoice for this admission
                    $invoiceId = null;
                    $invRes = $conn->query("SELECT id FROM invoices WHERE admission_id = $admissionId AND status IN ('pending','partial') LIMIT 1");
                    if ($invRes && $invRes->num_rows > 0) {
                        $invoiceId = (int)$invRes->fetch_assoc()['id'];
                    } else {
                        $insSql = "INSERT INTO invoices (invoice_number, patient_id, visit_id, admission_id, total_amount, discount_amount, tax_amount, net_amount, paid_amount, balance_amount, status, created_by) VALUES ('TBD', $patientId, $visitId, $admissionId, 0, 0, 0, 0, 0, 0, 'pending', $doctorId)";
                        if ($conn->query($insSql)) {
                            $invoiceId = (int)$conn->insert_id;
                            $invoiceNumber = generateCode('INV', $invoiceId);
                            $conn->query("UPDATE invoices SET invoice_number = '" . $conn->real_escape_string($invoiceNumber) . "' WHERE id = $invoiceId");
                        }
                    }
                    
                    if ($invoiceId) {
                        $desc = $conn->real_escape_string('Laboratory: ' . $testName);
                        $qty = 1;
                        $unit = $testPrice;
                        $total = round($unit * $qty, 2);
                        $conn->query("INSERT INTO invoice_items (invoice_id, service_id, item_description, quantity, unit_price, total_price, reference_type, reference_id) VALUES ($invoiceId, NULL, '$desc', $qty, $unit, $total, 'laboratory', $labId)");
                        
                        // Update invoice total
                        $sum = $conn->query("SELECT COALESCE(SUM(total_price),0) AS total FROM invoice_items WHERE invoice_id = $invoiceId");
                        $totalAmt = ($sum && $sum->num_rows) ? (float)$sum->fetch_assoc()['total'] : 0.00;
                        $conn->query("UPDATE invoices SET total_amount = $totalAmt, net_amount = $totalAmt, balance_amount = $totalAmt WHERE id = $invoiceId");
                    }
                } catch (Throwable $e) {
                    // ignore billing errors here
                }
            }
        }
        
        // Update visit status to in-laboratory if requests made
        if (isset($_POST['lab_tests']) && count($_POST['lab_tests']) > 0) {
            $conn->query("UPDATE patient_visits SET status = 'in-laboratory' WHERE id = $visitId");
        }
        
        setFlashMessage('success', 'Doctor round placed on hold. Laboratory requests submitted.');
        redirect('modules/admission/admission-view.php?id=' . $admissionId);
        exit;
    }
    
    if ($submitAction === 'complete') {
        // Compile the progress note text
        $labText = "None";
        if (count($labResults) > 0) {
            $labLines = [];
            foreach ($labResults as $lr) {
                $line = "- " . $lr['test_name'] . ": " . $lr['result_value'];
                if (!empty($lr['interpretation'])) {
                    $line .= " (Interpretation: " . $lr['interpretation'] . ")";
                }
                if (!empty($lr['remarks'])) {
                    $line .= " [Remarks: " . $lr['remarks'] . "]";
                }
                if (!empty($lr['attachment_path'])) {
                    $attachmentUrl = (strpos($lr['attachment_path'], 'http://') === 0 || strpos($lr['attachment_path'], 'https://') === 0)
                        ? $lr['attachment_path']
                        : (BASE_URL . ltrim($lr['attachment_path'], '/'));
                    $line .= " | Attachment: " . basename($lr['attachment_path']) . " (" . $attachmentUrl . ")";
                }
                $labLines[] = $line;
            }
            $labText = implode("\n", $labLines);
        }
        
        $decisionText = ($decision === 'discharge') ? 'discharges' : 'still admitted';
        
        $finalNote = "assessment: " . $assessment . "\n\n" .
                     "lab result:\n" . $labText . "\n\n" .
                     "diagnosis: " . $diagnosis . "\n\n" .
                     "decision: " . $decisionText;
        
        // Save completed progress note
        if ($existingDraft) {
            $upStmt = $conn->prepare("UPDATE progress_notes SET notes = ?, status = 'completed', recorded_at = NOW() WHERE id = ?");
            $upStmt->bind_param('si', $finalNote, $existingDraft['id']);
            $upStmt->execute();
            $upStmt->close();
        } else {
            $note_type = 'doctor-round';
            $status = 'completed';
            $insStmt = $conn->prepare("INSERT INTO progress_notes (admission_id, patient_id, doctor_id, note_type, status, notes, recorded_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $insStmt->bind_param('iiisss', $admissionId, $patientId, $doctorId, $note_type, $status, $finalNote);
            $insStmt->execute();
            $insStmt->close();
        }
        
        // Handle discharge if decision is discharge
        if ($decision === 'discharge') {
            // Update admission status
            $u = $conn->prepare("UPDATE admissions SET status = 'discharged', actual_discharge_date = NOW() WHERE id = ?");
            $u->bind_param('i', $admissionId);
            $u->execute();
            $u->close();
            
            // Insert discharge record
            $finalDiagnosis = $diagnosis;
            $summary = "Doctor Round Assessment:\n" . $assessment . "\n\nTreatment Plan:\n" . $treatmentPlan;
            $meds = ""; 
            
            $insDR = $conn->prepare("INSERT INTO discharge_records (admission_id, patient_id, discharge_date, final_diagnosis, discharge_summary, medications_on_discharge, discharge_checked_by, discharge_approved_by) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?)");
            $insDR->bind_param('iisssii', $admissionId, $patientId, $finalDiagnosis, $summary, $meds, $doctorId, $doctorId);
            $insDR->execute();
            $insDR->close();
            
            // Ensure there is a pending invoice for this admission
            try {
                $invoiceId = null;
                $invRes = $conn->query("SELECT id FROM invoices WHERE admission_id = $admissionId AND status IN ('pending','partial') LIMIT 1");
                if ($invRes && $invRes->num_rows > 0) {
                    $invoiceId = (int)$invRes->fetch_assoc()['id'];
                } else {
                    $insSql = "INSERT INTO invoices (invoice_number, patient_id, visit_id, admission_id, total_amount, discount_amount, tax_amount, net_amount, paid_amount, balance_amount, status, created_by) VALUES ('TBD', $patientId, $visitId, $admissionId, 0, 0, 0, 0, 0, 0, 'pending', $doctorId)";
                    if ($conn->query($insSql)) {
                        $invoiceId = (int)$conn->insert_id;
                        $invoiceNumber = generateCode('INV', $invoiceId);
                        $conn->query("UPDATE invoices SET invoice_number = '" . $conn->real_escape_string($invoiceNumber) . "' WHERE id = $invoiceId");
                    }
                }
            } catch (Throwable $e) {
                // ignore billing errors
            }
            
            // Update visit status to ready-for-discharge or discharged
            $conn->query("UPDATE patient_visits SET status = 'ready-for-discharge' WHERE id = $visitId");
            
            logActivity('discharge', 'admissions', $admissionId);
            setFlashMessage('success', 'Doctor round completed. Patient has been discharged.');
        } else {
            setFlashMessage('success', 'Doctor round completed. Patient remains admitted.');
        }
        
        redirect('modules/admission/admission-view.php?id=' . $admissionId);
        exit;
    }
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Doctor Round</h1>
        <p class="page-subtitle">Patient: <?php echo htmlspecialchars($admission['first_name'] . ' ' . $admission['last_name']); ?></p>
    </div>
    <a href="../admission/admission-view.php?id=<?php echo $admissionId; ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<!-- Patient Info Summary Card -->
<div class="patient-info-card" style="margin-bottom: 20px;">
    <div class="patient-info-header">
        <h2><?php echo htmlspecialchars($admission['first_name'] . ' ' . $admission['last_name']); ?></h2>
        <span class="patient-code"><?php echo htmlspecialchars($admission['patient_code']); ?></span>
    </div>
    <div class="patient-info-grid">
        <div class="patient-info-item">
            <label>Admission Code</label>
            <span><?php echo htmlspecialchars($admission['admission_code'] ?: 'N/A'); ?></span>
        </div>
        <div class="patient-info-item">
            <label>Age / Gender</label>
            <span><?php echo calculateAge($admission['date_of_birth']); ?> / <?php echo htmlspecialchars($admission['gender']); ?></span>
        </div>
        <div class="patient-info-item">
            <label>Room / Bed</label>
            <span><?php echo $admission['room_number'] ? 'Room ' . htmlspecialchars($admission['room_number']) : 'None'; ?> <?php echo $admission['bed_number'] ? '(Bed ' . htmlspecialchars($admission['bed_number']) . ')' : ''; ?></span>
        </div>
        <div class="patient-info-item">
            <label>Blood Type</label>
            <span><?php echo htmlspecialchars($admission['blood_type']); ?></span>
        </div>
        <div class="patient-info-item" style="grid-column: span 2;">
            <label>Allergies</label>
            <span><?php echo htmlspecialchars($admission['allergies'] ?: 'None recorded'); ?></span>
        </div>
    </div>
</div>

<form method="POST" action="" id="doctorRoundForm">
    <input type="hidden" name="admission_id" value="<?php echo $admissionId; ?>">
    <input type="hidden" name="submit_action" id="submit_action" value="complete">

    <!-- Progress steps indicator bar -->
    <ul class="wizard-steps">
        <li class="wizard-step active" data-step="1">
            <span class="step-num">1</span>
            <span>Assessment</span>
        </li>
        <li class="wizard-step" data-step="2">
            <span class="step-num">2</span>
            <span>Lab Request</span>
        </li>
        <li class="wizard-step" data-step="3">
            <span class="step-num">3</span>
            <span>Diagnosis</span>
        </li>
        <li class="wizard-step" data-step="4">
            <span class="step-num">4</span>
            <span>Treatment Plan</span>
        </li>
    </ul>

    <!-- Step 1: Assessment -->
    <div class="wizard-panel active" id="panel_1">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-stethoscope"></i> Physical Examination / Assessment Findings</h3>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Physical Examination Findings</label>
                        <select id="phys_choice" class="form-control" style="margin-bottom: 12px;">
                            <option value="">-- Select finding --</option>
                            <option value="Normal">Normal</option>
                            <option value="Respiratory: rales">Respiratory: rales</option>
                            <option value="Respiratory: wheeze">Respiratory: wheeze</option>
                            <option value="Cardiac: murmur">Cardiac: murmur</option>
                            <option value="Abdomen: tenderness">Abdomen: tenderness</option>
                            <option value="Neurologic deficit">Neurologic deficit</option>
                            <option value="Jaundice">Jaundice</option>
                            <option value="Pallor">Pallor</option>
                            <option value="Other">Other (specify)</option>
                        </select>
                        <textarea name="assessment" id="assessment" class="form-control" rows="4" placeholder="Document physical examination findings/assessment details here..." required><?php echo htmlspecialchars($assessment); ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-group" style="text-align: right; margin-top: 20px;">
            <a href="../admission/admission-view.php?id=<?php echo $admissionId; ?>" class="btn btn-secondary">Cancel</a>
            <button type="button" class="btn btn-primary btn-next-step" onclick="validateStep1()">
                Next Step <i class="fas fa-arrow-right"></i>
            </button>
        </div>
    </div>

    <!-- Step 2: Lab Request -->
    <div class="wizard-panel" id="panel_2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-vials"></i> Laboratory Requests</h3>
            </div>
            <div class="card-body">
                <p class="text-muted">Select any laboratory tests needed for this patient. If no tests are required, you can click <strong>Skip Laboratory Request</strong> to continue to the Diagnosis step.</p>
                <div class="form-row" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px;">
                    <?php if ($labTests && $labTests->num_rows > 0): ?>
                        <?php while ($test = $labTests->fetch_assoc()): ?>
                        <div style="border: 1px solid var(--border-color); padding: 12px; border-radius: 8px; background: var(--surface-muted);">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                <input type="checkbox" name="lab_tests[]" value="<?php echo $test['id']; ?>" id="lab_<?php echo $test['id']; ?>" class="lab-checkbox">
                                <label for="lab_<?php echo $test['id']; ?>" style="margin: 0; font-weight: 600; cursor: pointer;"><?php echo htmlspecialchars($test['test_name']); ?></label>
                            </div>
                            <div class="lab-options-fields" style="display: none; padding-left: 24px; border-left: 2px solid var(--primary-color);">
                                <div style="margin-bottom: 8px;">
                                    <label style="font-size: 12px; display: block; margin-bottom: 4px;">Priority</label>
                                    <select name="lab_priority[<?php echo $test['id']; ?>]" class="form-control" style="font-size: 12px; padding: 4px 8px; height: auto; width: 100%;">
                                        <option value="routine">Routine</option>
                                        <option value="urgent">Urgent</option>
                                        <option value="stat">STAT</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size: 12px; display: block; margin-bottom: 4px;">Specific Notes / Instructions</label>
                                    <textarea name="lab_notes[<?php echo $test['id']; ?>]" class="form-control" rows="2" style="font-size: 12px; width: 100%;" placeholder="Instructions for lab technician..."></textarea>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="text-muted">No active laboratory tests found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
            <button type="button" class="btn btn-secondary" onclick="changeStep(1)">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-info" onclick="changeStep(3)">
                    Skip Laboratory Request <i class="fas fa-arrow-right"></i>
                </button>
                <button type="button" class="btn btn-warning" onclick="submitLabRequest()">
                    <i class="fas fa-save"></i> Submit Lab Request & Place on Hold
                </button>
            </div>
        </div>
    </div>

    <!-- Step 3: Diagnosis -->
    <div class="wizard-panel" id="panel_3">
        <!-- Laboratory Results -->
        <?php if (!empty($labResults)): ?>
            <div class="lab-results-box" style="margin-bottom: 20px; background: #eef9f0; padding: 15px; border-radius: 8px; border: 1px solid #d4edda;">
                <h4 style="color: var(--success-color); font-weight: bold; margin-bottom: 12px;"><i class="fas fa-vial"></i> Laboratory Results Received</h4>
                <div style="display: flex; flex-direction: column; gap: 15px;">
                    <?php foreach ($labResults as $lr): ?>
                        <div class="result-item" style="background: var(--surface-color); padding: 12px; border-radius: 8px; border: 1px solid var(--border-color);">
                            <div style="display:flex; justify-content:space-between; margin-bottom: 6px; align-items: center;">
                                <strong style="font-size: 15px; color: var(--text-color);"><?php echo htmlspecialchars($lr['test_name']); ?></strong>
                                <span class="badge badge-success" style="text-transform: uppercase;"><?php echo htmlspecialchars($lr['priority']); ?></span>
                            </div>
                            <div style="margin-bottom: 6px;">
                                <span style="font-size: 13px; color: var(--text-muted);">Result Value:</span>
                                <div style="font-size: 14px; font-weight: bold; background: var(--surface-muted); padding: 8px; border-radius: 4px; margin-top: 2px;">
                                    <?php echo nl2br(htmlspecialchars($lr['result_value'])); ?>
                                </div>
                            </div>
                            <?php if (!empty($lr['interpretation'])): ?>
                                <div style="margin-bottom: 6px;">
                                    <span style="font-size: 13px; color: var(--text-muted);">Interpretation:</span>
                                    <div style="font-size: 13px; background: var(--surface-muted); padding: 6px; border-radius: 4px; margin-top: 2px;">
                                        <?php echo htmlspecialchars($lr['interpretation']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($lr['remarks'])): ?>
                                <div style="margin-bottom: 4px;">
                                    <span style="font-size: 13px; color: var(--text-muted);">Remarks:</span>
                                    <div style="font-size: 13px; background: var(--surface-muted); padding: 6px; border-radius: 4px; margin-top: 2px;">
                                        <?php echo htmlspecialchars($lr['remarks']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($lr['attachment_path'])): ?>
                                <?php
                                $attachmentUrl = (strpos($lr['attachment_path'], 'http://') === 0 || strpos($lr['attachment_path'], 'https://') === 0)
                                    ? $lr['attachment_path']
                                    : (BASE_URL . ltrim($lr['attachment_path'], '/'));
                                ?>
                                <div style="margin-top: 8px;">
                                    <strong>Attachment:</strong>
                                    <a href="<?php echo htmlspecialchars($attachmentUrl); ?>" target="_blank" class="btn btn-sm btn-info" style="margin-left: 8px;">
                                        <i class="fas fa-paperclip"></i> View File
                                    </a>
                                </div>
                            <?php endif; ?>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 6px; text-align: right;">
                                Completed: <?php echo htmlspecialchars($lr['created_at']); ?> by <?php echo htmlspecialchars($lr['tech_name'] ?? 'Lab Tech'); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-diagnoses"></i> Diagnosis</h3>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Diagnosis <span style="color: red;">*</span></label>
                        <select id="diagnosis_choice" class="form-control" style="margin-bottom: 12px;" required>
                            <option value="">-- Select diagnosis --</option>
                            <option value="Upper respiratory infection">Upper respiratory infection</option>
                            <option value="Gastroenteritis">Gastroenteritis</option>
                            <option value="Urinary tract infection">Urinary tract infection</option>
                            <option value="Hypertensive crisis">Hypertensive crisis</option>
                            <option value="Diabetic ketoacidosis">Diabetic ketoacidosis</option>
                            <option value="Fracture">Fracture</option>
                            <option value="Other">Other (specify)</option>
                        </select>
                        <textarea name="diagnosis" id="diagnosis" class="form-control" rows="3" placeholder="Enter diagnosis details..." required><?php echo htmlspecialchars($diagnosis); ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
            <button type="button" class="btn btn-secondary" onclick="changeStep(2)">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <button type="button" class="btn btn-primary" onclick="validateStep3()">
                Next Step <i class="fas fa-arrow-right"></i>
            </button>
        </div>
    </div>

    <!-- Step 4: Treatment Plan & Decision -->
    <div class="wizard-panel" id="panel_4">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-clipboard-list"></i> Treatment Plan & Decision</h3>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Treatment Plan</label>
                        <select id="treatment_choice" class="form-control" style="margin-bottom: 12px;">
                            <option value="">-- Select treatment plan --</option>
                            <option value="Medication only">Medication only</option>
                            <option value="Observation">Observation</option>
                            <option value="Admission">Admission</option>
                            <option value="Surgery">Surgery</option>
                            <option value="Referral">Referral</option>
                            <option value="Physiotherapy">Physiotherapy</option>
                            <option value="Other">Other (specify)</option>
                        </select>
                        <textarea name="treatment_plan" id="treatment_plan" class="form-control" rows="3" placeholder="Describe the treatment plan..."><?php echo htmlspecialchars($treatmentPlan); ?></textarea>
                    </div>
                </div>

                <div class="form-row" style="margin-top: 20px;">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label" style="font-weight: bold;">Decision / Outcome <span style="color: red;">*</span></label>
                        <select name="decision" id="decision" class="form-control" required>
                            <option value="admitted" <?php echo ($decision === 'admitted') ? 'selected' : ''; ?>>Stay in Admission / Still Admitted</option>
                            <option value="discharge" <?php echo ($decision === 'discharge') ? 'selected' : ''; ?>>Allow to Discharge</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
            <button type="button" class="btn btn-secondary" onclick="changeStep(3)">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <div style="display: flex; gap: 10px;">
                <a href="../admission/admission-view.php?id=<?php echo $admissionId; ?>" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-check-circle"></i> Complete Doctor Round
                </button>
            </div>
        </div>
    </div>
</form>

<script>
let currentStep = 1;
const isResume = <?php echo ($existingDraft !== null) ? 'true' : 'false'; ?>;

function changeStep(step) {
    if (step < 1 || step > 4) return;
    
    // Hide all panels
    document.querySelectorAll('.wizard-panel').forEach(p => p.classList.remove('active'));
    // Show current panel
    const panel = document.getElementById('panel_' + step);
    if (panel) panel.classList.add('active');
    
    // Update step indicator classes
    document.querySelectorAll('.wizard-step').forEach(s => {
        const sNum = parseInt(s.getAttribute('data-step'));
        s.classList.remove('active', 'completed');
        if (sNum === step) {
            s.classList.add('active');
        } else if (sNum < step) {
            s.classList.add('completed');
        }
    });
    
    currentStep = step;
    
    // Scroll to top of form
    const form = document.getElementById('doctorRoundForm');
    if (form) {
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function validateStep1() {
    const assessment = document.getElementById('assessment');
    if (assessment.value.trim() === '') {
        alert('Please enter your assessment findings.');
        assessment.focus();
        return;
    }
    changeStep(2);
}

function validateStep3() {
    const diagChoice = document.getElementById('diagnosis_choice');
    const diagField = document.getElementById('diagnosis');
    
    if (diagChoice.value === '') {
        alert('Please select or specify a diagnosis.');
        diagChoice.focus();
        return;
    }
    
    if (diagChoice.value === 'Other' && diagField.value.trim() === '') {
        alert('Please specify the diagnosis details.');
        diagField.focus();
        return;
    }
    
    changeStep(4);
}

function submitLabRequest() {
    const checked = document.querySelectorAll('.lab-checkbox:checked');
    if (checked.length === 0) {
        alert('Please select at least one laboratory test to request, or click "Skip Laboratory Request".');
        return;
    }
    
    document.getElementById('submit_action').value = 'request_lab';
    document.getElementById('doctorRoundForm').submit();
}

document.addEventListener('DOMContentLoaded', function() {
    // Wire up lab checkboxes toggle
    document.querySelectorAll('.lab-checkbox').forEach(cb => {
        const toggleFields = () => {
            const fields = cb.closest('div').nextElementSibling;
            if (fields) {
                fields.style.display = cb.checked ? 'block' : 'none';
            }
        };
        cb.addEventListener('change', toggleFields);
        toggleFields();
    });

    if (isResume) {
        changeStep(3); // Resume directly at Diagnosis step if there was a draft round (on hold)
    } else {
        changeStep(1);
    }
});

document.getElementById('doctorRoundForm').addEventListener('submit', function(e) {
    const action = document.getElementById('submit_action').value;
    if (action === 'complete') {
        const diagChoice = document.getElementById('diagnosis_choice');
        const diagField = document.getElementById('diagnosis');
        
        if (diagChoice.value === '' || (diagChoice.value === 'Other' && diagField.value.trim() === '')) {
            e.preventDefault();
            alert('Please complete the Diagnosis step before finalizing the doctor round.');
            changeStep(3);
            return;
        }
    }
});

// Setup Choice sync helper dropdowns
(function() {
    // Physical Exam sync
    var physChoice = document.getElementById('phys_choice');
    var physField = document.getElementById('assessment');
    var existingPhys = <?php echo json_encode($assessment); ?>;
    
    function syncPhys() {
        var v = physChoice.value;
        if (v === 'Other') {
            physField.style.display = '';
            physField.readOnly = false;
            if (!physField.value && existingPhys) physField.value = existingPhys;
        } else if (v === '') {
            physField.style.display = 'none';
            if (!existingPhys) physField.value = '';
        } else {
            physField.style.display = 'none';
            physField.value = v;
            physField.readOnly = true;
        }
    }

    if (existingPhys && ["Normal", "Respiratory: rales", "Respiratory: wheeze", "Cardiac: murmur", "Abdomen: tenderness", "Neurologic deficit", "Jaundice", "Pallor"].indexOf(existingPhys) !== -1) {
        physChoice.value = existingPhys;
    } else if (existingPhys && existingPhys !== '') {
        physChoice.value = 'Other';
    } else {
        physChoice.value = '';
    }

    physChoice.addEventListener('change', syncPhys);
    syncPhys();

    // Diagnosis sync
    var diagChoice = document.getElementById('diagnosis_choice');
    var diagField = document.getElementById('diagnosis');
    var existingDiag = <?php echo json_encode($diagnosis); ?>;

    function syncDiag() {
        var v = diagChoice.value;
        if (v === 'Other') {
            diagField.style.display = '';
            diagField.readOnly = false;
            if (!diagField.value && existingDiag) diagField.value = existingDiag;
        } else if (v === '') {
            diagField.style.display = 'none';
            if (!existingDiag) diagField.value = '';
        } else {
            diagField.style.display = 'none';
            diagField.value = v;
            diagField.readOnly = true;
        }
    }

    diagChoice.addEventListener('change', syncDiag);
    syncDiag();

    // Treatment Choice sync
    var treatChoice = document.getElementById('treatment_choice');
    var treatField = document.getElementById('treatment_plan');
    var existingTreat = <?php echo json_encode($treatmentPlan); ?>;

    function syncTreat() {
        var v = treatChoice.value;
        if (v === 'Other') {
            treatField.style.display = '';
            treatField.readOnly = false;
            if (!treatField.value && existingTreat) treatField.value = existingTreat;
        } else if (v === '') {
            treatField.style.display = 'none';
            if (!existingTreat) treatField.value = '';
        } else {
            treatField.style.display = 'none';
            treatField.value = v;
            treatField.readOnly = true;
        }
    }

    treatChoice.addEventListener('change', syncTreat);
    syncTreat();
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
