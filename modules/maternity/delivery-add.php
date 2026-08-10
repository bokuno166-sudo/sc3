<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse', 'doctor']);

$pageTitle = 'Record Birthing / Delivery';
$currentPage = 'maternity';

$conn = getDBConnection();

$patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

// Fetch patient details
$patientRes = $conn->query("SELECT * FROM patients WHERE id = $patientId AND is_pregnant = 1");
if (!$patientRes || $patientRes->num_rows === 0) {
    setFlashMessage('error', 'Pregnant patient not found.');
    redirect('modules/maternity/index.php');
}
$patient = $patientRes->fetch_assoc();

// Find active admission
$admissionRes = $conn->query("
    SELECT a.*, r.room_number, b.bed_number 
    FROM admissions a
    LEFT JOIN rooms r ON a.room_id = r.id
    LEFT JOIN beds b ON a.bed_id = b.id
    WHERE a.patient_id = $patientId AND a.status = 'admitted'
    ORDER BY a.admission_date DESC 
    LIMIT 1
");

$activeAdmission = null;
if ($admissionRes && $admissionRes->num_rows > 0) {
    $activeAdmission = $admissionRes->fetch_assoc();
}

// Fetch users for Doctor (Attended By) and Nurse/Midwife (Assistant Midwife)
$doctors = [];
$doctorRes = $conn->query("SELECT id, full_name, role FROM users WHERE role IN ('doctor', 'admin') AND status = 'active' ORDER BY full_name ASC");
if ($doctorRes) {
    while ($doc = $doctorRes->fetch_assoc()) {
        $doctors[] = $doc;
    }
}

$midwives = [];
$midwifeRes = $conn->query("SELECT id, full_name, role FROM users WHERE role IN ('nurse', 'staff', 'admin') AND status = 'active' ORDER BY full_name ASC");
if ($midwifeRes) {
    while ($mid = $midwifeRes->fetch_assoc()) {
        $midwives[] = $mid;
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $activeAdmission) {
    $deliveryDate = sanitize($_POST['delivery_date']);
    $deliveryType = sanitize($_POST['delivery_type']);
    $attendedBy = !empty($_POST['attended_by']) ? (int)$_POST['attended_by'] : null;
    $assistantMidwife = !empty($_POST['assistant_midwife']) ? (int)$_POST['assistant_midwife'] : null;
    
    $laborStartTime = !empty($_POST['labor_start_time']) ? sanitize($_POST['labor_start_time']) : null;
    $deliveryCompletionTime = !empty($_POST['delivery_completion_time']) ? sanitize($_POST['delivery_completion_time']) : null;
    $cervixDilation = !empty($_POST['cervix_dilation_at_admission']) ? (float)$_POST['cervix_dilation_at_admission'] : null;
    
    $babyWeight = !empty($_POST['baby_weight']) ? (float)$_POST['baby_weight'] : null;
    $babyLength = !empty($_POST['baby_length']) ? (float)$_POST['baby_length'] : null;
    $babyGender = sanitize($_POST['baby_gender']);
    $apgar1 = !empty($_POST['apgar_score_1min']) ? (int)$_POST['apgar_score_1min'] : null;
    $apgar5 = !empty($_POST['apgar_score_5min']) ? (int)$_POST['apgar_score_5min'] : null;
    $apgar10 = !empty($_POST['apgar_score_10min']) ? (int)$_POST['apgar_score_10min'] : null;
    $babyCondition = sanitize($_POST['baby_condition']);
    
    $placentaTime = !empty($_POST['placenta_delivery_time']) ? sanitize($_POST['placenta_delivery_time']) : null;
    $bloodLoss = !empty($_POST['blood_loss_ml']) ? (int)$_POST['blood_loss_ml'] : null;
    $motherCondition = sanitize($_POST['mother_condition']);
    $complications = sanitize($_POST['complications']);
    $notes = sanitize($_POST['notes']);

    if (!empty($deliveryDate) && !empty($deliveryType) && !empty($babyGender)) {
        // Start transaction
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("
                INSERT INTO delivery_records (
                    admission_id, patient_id, delivery_date, delivery_type, attended_by, assistant_midwife,
                    labor_start_time, delivery_completion_time, cervix_dilation_at_admission,
                    baby_weight, baby_length, baby_gender, apgar_score_1min, apgar_score_5min, apgar_score_10min, baby_condition,
                    placenta_delivery_time, blood_loss_ml, mother_condition, complications, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->bind_param(
                "iissiissddssiiississs", 
                $activeAdmission['id'], $patientId, $deliveryDate, $deliveryType, $attendedBy, $assistantMidwife,
                $laborStartTime, $deliveryCompletionTime, $cervixDilation,
                $babyWeight, $babyLength, $babyGender, $apgar1, $apgar5, $apgar10, $babyCondition,
                $placentaTime, $bloodLoss, $motherCondition, $complications, $notes
            );
            
            $stmt->execute();
            $deliveryId = $stmt->insert_id;
            $stmt->close();
            
            // Automatically clear pregnant flag on patient
            $updateStmt = $conn->prepare("UPDATE patients SET is_pregnant = 0, weeks_of_pregnancy = NULL, expected_due_date = NULL WHERE id = ?");
            $updateStmt->bind_param("i", $patientId);
            $updateStmt->execute();
            $updateStmt->close();
            
            $conn->commit();
            logActivity("Record Delivery", "delivery_records", $deliveryId, null, json_encode(['baby_gender' => $babyGender, 'baby_weight' => $babyWeight]));
            setFlashMessage('success', 'Birthing / Delivery record saved successfully! Patient pregnancy is marked as completed.');
            redirect('modules/maternity/index.php');
        } catch (Exception $e) {
            $conn->rollback();
            setFlashMessage('error', 'Error saving delivery record: ' . $e->getMessage());
        }
    } else {
        setFlashMessage('error', 'Please fill in all required fields.');
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Record Birthing / Delivery</h1>
        <p class="page-subtitle">Log the clinical details of the child birth event</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Cancel
        </a>
    </div>
</div>

<?php if (!$activeAdmission): ?>
<!-- Warning: Active Admission Required -->
<div class="card" style="border-top: 4px solid #dc3545;">
    <div class="card-body" style="text-align: center; padding: 40px;">
        <i class="fas fa-exclamation-triangle" style="font-size: 50px; color: #dc3545; margin-bottom: 20px;"></i>
        <h2 style="margin-bottom: 10px;">Hospital Admission Required</h2>
        <p style="color: #666; max-width: 600px; margin: 0 auto 20px;">
            To log a birth record, the patient <strong><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></strong> must first be admitted to the hospital (e.g. to the delivery room, private room, or ward) to establish a valid inpatient stay.
        </p>
        <div>
            <a href="../admission/admission-add.php?patient_id=<?php echo $patientId; ?>" class="btn btn-primary">
                <i class="fas fa-procedures"></i> Admit Patient Now
            </a>
            <a href="index.php" class="btn btn-secondary" style="margin-left: 10px;">
                Back to Dashboard
            </a>
        </div>
    </div>
</div>

<?php else: ?>
<!-- Admission found, show Delivery Form -->
<div style="display: grid; grid-template-columns: 1fr 3fr; gap: 25px; align-items: start;">
    
    <!-- Patient Card Summary -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-procedures"></i> Admission Stay</h3>
        </div>
        <div class="card-body">
            <h4 style="margin: 0 0 10px;"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h4>
            <p class="text-muted" style="margin: 0 0 15px; font-size:13px;"><?php echo htmlspecialchars($patient['patient_code']); ?></p>
            <hr>
            <div style="font-size: 14px; line-height: 1.8;">
                <div><strong>Room:</strong> <?php echo htmlspecialchars($activeAdmission['room_number'] ?: 'N/A'); ?></div>
                <div><strong>Bed:</strong> <?php echo htmlspecialchars($activeAdmission['bed_number'] ?: 'N/A'); ?></div>
                <div><strong>Admitted Date:</strong> <?php echo formatDateTime($activeAdmission['admission_date']); ?></div>
                <div><strong>Gestation Weeks:</strong> <?php echo intval($patient['weeks_of_pregnancy']); ?> weeks</div>
                <div><strong>Blood Type:</strong> <?php echo htmlspecialchars($patient['blood_type']); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Delivery Record Form -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-baby-carriage"></i> Delivery & Birth Log</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                
                <!-- Section 1: Labor & Delivery Details -->
                <h4 style="border-bottom: 2px solid #eaeaea; padding-bottom: 8px; margin-bottom: 15px; color:#333;"><i class="fas fa-clock"></i> Labor & Delivery Times</h4>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                    <div class="form-group">
                        <label class="form-label" for="labor_start_time">Labor Start Time</label>
                        <input type="datetime-local" name="labor_start_time" id="labor_start_time" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="delivery_date">Delivery Date & Time <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="delivery_date" id="delivery_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="delivery_completion_time">Labor Completion Time</label>
                        <input type="datetime-local" name="delivery_completion_time" id="delivery_completion_time" class="form-control">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-top: 15px;">
                    <div class="form-group">
                        <label class="form-label" for="delivery_type">Delivery Type <span class="text-danger">*</span></label>
                        <select name="delivery_type" id="delivery_type" class="form-control" required>
                            <option value="normal">Normal (Vaginal)</option>
                            <option value="assisted">Assisted (Forceps/Vacuum)</option>
                            <option value="c-section">C-Section (Caesarean)</option>
                            <option value="water-birth">Water Birth</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="attended_by">Attended By (Doctor)</label>
                        <select name="attended_by" id="attended_by" class="form-control">
                            <option value="">-- Choose Doctor --</option>
                            <?php foreach ($doctors as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo ($activeAdmission['doctor_id'] == $d['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="assistant_midwife">Assistant Midwife / Nurse</label>
                        <select name="assistant_midwife" id="assistant_midwife" class="form-control">
                            <option value="">-- Choose Midwife/Nurse --</option>
                            <?php foreach ($midwives as $m): ?>
                            <option value="<?php echo $m['id']; ?>">
                                <?php echo htmlspecialchars($m['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Section 2: Baby Details -->
                <h4 style="border-bottom: 2px solid #eaeaea; padding-bottom: 8px; margin-top: 30px; margin-bottom: 15px; color:#333;"><i class="fas fa-baby"></i> Baby Details</h4>
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
                    <div class="form-group">
                        <label class="form-label" for="baby_gender">Baby Gender <span class="text-danger">*</span></label>
                        <select name="baby_gender" id="baby_gender" class="form-control" required>
                            <option value="">-- Choose --</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="baby_weight">Weight (kg)</label>
                        <input type="number" step="0.01" name="baby_weight" id="baby_weight" class="form-control" placeholder="e.g. 3.25">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="baby_length">Length (cm)</label>
                        <input type="number" step="0.1" name="baby_length" id="baby_length" class="form-control" placeholder="e.g. 50.5">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="baby_condition">Baby Condition</label>
                        <select name="baby_condition" id="baby_condition" class="form-control">
                            <option value="healthy">Healthy</option>
                            <option value="distressed">Distressed</option>
                            <option value="stillborn">Stillborn</option>
                            <option value="nicu">Needs NICU</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-top: 15px;">
                    <div class="form-group">
                        <label class="form-label" for="apgar_score_1min">APGAR Score (1 Min)</label>
                        <input type="number" name="apgar_score_1min" id="apgar_score_1min" class="form-control" min="0" max="10" placeholder="0 to 10">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="apgar_score_5min">APGAR Score (5 Min)</label>
                        <input type="number" name="apgar_score_5min" id="apgar_score_5min" class="form-control" min="0" max="10" placeholder="0 to 10">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="apgar_score_10min">APGAR Score (10 Min)</label>
                        <input type="number" name="apgar_score_10min" id="apgar_score_10min" class="form-control" min="0" max="10" placeholder="0 to 10">
                    </div>
                </div>
                
                <!-- Section 3: Mother Details -->
                <h4 style="border-bottom: 2px solid #eaeaea; padding-bottom: 8px; margin-top: 30px; margin-bottom: 15px; color:#333;"><i class="fas fa-heart"></i> Mother Post-Delivery details</h4>
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
                    <div class="form-group">
                        <label class="form-label" for="cervix_dilation_at_admission">Admission Dilation (cm)</label>
                        <input type="number" step="0.1" name="cervix_dilation_at_admission" id="cervix_dilation_at_admission" class="form-control" placeholder="e.g. 4.0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="placenta_delivery_time">Placenta Delivery Time</label>
                        <input type="datetime-local" name="placenta_delivery_time" id="placenta_delivery_time" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="blood_loss_ml">Estimated Blood Loss (ml)</label>
                        <input type="number" name="blood_loss_ml" id="blood_loss_ml" class="form-control" placeholder="e.g. 350">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mother_condition">Mother Condition</label>
                        <select name="mother_condition" id="mother_condition" class="form-control">
                            <option value="stable">Stable</option>
                            <option value="unstable">Unstable</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                </div>
                
                <div style="margin-top: 20px;">
                    <div class="form-group">
                        <label class="form-label" for="complications">Complications (if any)</label>
                        <textarea name="complications" id="complications" class="form-control" rows="2" placeholder="e.g. Perineal tear 2nd degree, postpartum hemorrhage resolved..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="notes">Clinical Notes / Recommendations</label>
                        <textarea name="notes" id="notes" class="form-control" rows="3" placeholder="Additional notes about mother recovery, breastfeeding initiation..."></textarea>
                    </div>
                </div>
                
                <div style="margin-top: 30px; display: flex; gap: 10px; justify-content: flex-end;">
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Birthing & Delivery Record
                    </button>
                </div>
            </form>
        </div>
    </div>
    
</div>
<?php endif; ?>

<?php
$conn->close();
include __DIR__ . '/../../includes/footer.php';
?>
