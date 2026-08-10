<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse', 'doctor']);

$pageTitle = 'New Admission';
$currentPage = 'admissions';

$conn = getDBConnection();

// Load patients
$patients = $conn->query("SELECT id, patient_code, first_name, last_name FROM patients WHERE status = 'active' ORDER BY first_name ASC");

// Load available rooms with free capacity
$rooms = $conn->query("SELECT r.*, (SELECT COUNT(*) FROM admissions a WHERE a.room_id = r.id AND a.status = 'admitted') AS occupied_count FROM rooms r WHERE r.status = 'available' AND ((SELECT COUNT(*) FROM admissions a WHERE a.room_id = r.id AND a.status = 'admitted') < r.capacity) ORDER BY r.room_number ASC");

// Load doctors into array
$doctorsRes = $conn->query("SELECT id, full_name FROM users WHERE role = 'doctor' AND status = 'active' ORDER BY full_name ASC");
$doctors = [];
if ($doctorsRes) {
    while ($dd = $doctorsRes->fetch_assoc()) {
        $doctors[] = $dd;
    }
}
$singleDoctor = null;
if (count($doctors) === 1) {
    $singleDoctor = $doctors[0];
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_id = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0;
    $room_id = isset($_POST['room_id']) && $_POST['room_id'] !== '' ? (int)$_POST['room_id'] : null;
    $doctor_id = isset($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : 0;
    // If only one doctor exists, default to that doctor if none provided
    if ($doctor_id <= 0 && $singleDoctor) {
        $doctor_id = (int)$singleDoctor['id'];
    }
    $admission_date = sanitize($_POST['admission_date'] ?? date('Y-m-d H:i:s'));
    $skip_triage = isset($_POST['skip_triage']) ? 1 : 0;
    $admission_reason = sanitize($_POST['admission_reason'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');

    // If patient did not go through nurse/assessment, admission reason is required
    if ($skip_triage && trim($admission_reason) === '') {
        setFlashMessage('error', 'Reason for admission is required when the patient did not go through nurse/assessment.');
        redirect('modules/admission/admission-add.php');
    }

    // Prepend admission reason to notes when provided
    if (trim($admission_reason) !== '') {
        $notes = 'Admission reason: ' . $admission_reason . "\n" . $notes;
    }
    $created_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    if ($patient_id <= 0 || $doctor_id <= 0) {
        setFlashMessage('error', 'Patient and doctor must be selected.');
        redirect('modules/admission/admission-add.php');
    }

    // Find or create visit_id for this admission
    $visit_id = null;
    $visitRes = $conn->query("SELECT id FROM patient_visits WHERE patient_id = $patient_id AND status NOT IN ('discharged', 'cancelled') ORDER BY id DESC LIMIT 1");
    if ($visitRes && $visitRes->num_rows > 0) {
        $visit_id = (int)$visitRes->fetch_assoc()['id'];
        // Update visit status to 'admitted'
        $conn->query("UPDATE patient_visits SET status = 'admitted' WHERE id = $visit_id");
    } else {
        // Create a new patient visit for this admission
        // Generate queue number
        $queueResult = $conn->query("SELECT COUNT(*) as count FROM patient_visits WHERE visit_date = CURDATE()");
        $queueCount = $queueResult ? $queueResult->fetch_assoc()['count'] : 0;
        $queueNumber = 'Q' . date('Ymd') . '-' . str_pad($queueCount + 1, 3, '0', STR_PAD_LEFT);
        
        $visit_date = date('Y-m-d');
        $visit_type = 'walk-in';
        $visit_status = 'admitted';
        $priority = 'normal';
        $chief_complaint = 'Direct admission to room/bed';
        
        $vStmt = $conn->prepare("INSERT INTO patient_visits (patient_id, queue_number, visit_date, visit_type, status, priority, chief_complaint, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if ($vStmt) {
            $vStmt->bind_param('issssssi', $patient_id, $queueNumber, $visit_date, $visit_type, $visit_status, $priority, $chief_complaint, $created_by);
            if ($vStmt->execute()) {
                $visit_id = $vStmt->insert_id;
                logActivity('create', 'patient_visits', $visit_id, null, json_encode(['reason' => 'automatic for admission']));
            }
            $vStmt->close();
        }
    }

    if (!$visit_id) {
        setFlashMessage('error', 'Failed to associate or create a patient visit for this admission.');
        redirect('modules/admission/admission-add.php');
    }

    // Insert admission (resilient to missing `notes` column)
    $sql = 'INSERT INTO admissions (visit_id, patient_id, room_id, doctor_id, admission_date, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $err = $conn->error;
        if ($err && (stripos($err, "Unknown column 'notes'") !== false || stripos($err, 'unknown column') !== false)) {
            // Add missing column and retry
            $conn->query("ALTER TABLE admissions ADD COLUMN notes TEXT DEFAULT NULL");
            $stmt = $conn->prepare($sql);
        }
    }
    $status = 'admitted';
    if ($stmt) {
        $stmt->bind_param('iiissssi', $visit_id, $patient_id, $room_id, $doctor_id, $admission_date, $status, $notes, $created_by);
        $ok = $stmt->execute();
    } else {
        $ok = false;
    }

    if ($ok) {
        $newId = $stmt->insert_id;
        
        // AUTO ADD ROOM CHARGES - FIX ADMISSION PAYMENT BUG
        require_once __DIR__ . '/../billing/auto_admission_charges.php';
        $chargeResult = auto_admission_charges($conn, $newId);
        
        // Generate admission code and update
        $code = generateCode('ADM', $newId);
        $conn->query("UPDATE admissions SET admission_code = '" . $conn->real_escape_string($code) . "' WHERE id = $newId");

        logActivity('create', 'admissions', $newId, null, json_encode(['patient_id'=>$patient_id,'room_id'=>$room_id]));
        // Notify nurses about admission
        try {
            $notifStmt = $conn->prepare("INSERT INTO notifications (recipient_user_id, title, message) VALUES (?, ?, ?)");
            $nurses = $conn->query("SELECT id FROM users WHERE role = 'nurse' AND status = 'active'");
            $title = 'Patient admitted';
            $message = 'Patient ID ' . intval($patient_id) . ' has been admitted. Invoice: ' . ($chargeResult['invoice_id'] ?? 'TBD');
            if ($nurses) {
                while ($n = $nurses->fetch_assoc()) {
                    $notifStmt->bind_param('iss', $n['id'], $title, $message);
                    $notifStmt->execute();
                }
                $notifStmt->close();
            }
        } catch (Exception $e) {
            // ignore notification errors
        }
        $successMsg = 'Patient admitted successfully';
        if (isset($chargeResult['status']) && $chargeResult['status'] === 'success') {
            $successMsg .= '. Room charges added: ' . $chargeResult['message'];
        }
        setFlashMessage('success', $successMsg);
        $stmt->close();
        $conn->close();
        redirect('modules/admission/admissions.php');

    } else {
        setFlashMessage('error', 'Failed to admit patient: ' . $stmt->error);
        $stmt->close();
    }
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">New Admission</h1>
        <p class="page-subtitle">Admit a patient to a room/bed</p>
    </div>
    <div>
        <a href="admissions.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="admission-add.php">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Patient</label>
                    <select name="patient_id" class="form-control" required>
                        <option value="">Select patient</option>
                        <?php while ($p = $patients->fetch_assoc()): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo (isset($_GET['patient_id']) && (int)$_GET['patient_id'] === (int)$p['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['patient_code'] . ' — ' . $p['first_name'] . ' ' . $p['last_name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group col-md-6">
                    <label>Doctor</label>
                    <?php if ($singleDoctor): ?>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($singleDoctor['full_name']); ?>" readonly>
                        <input type="hidden" name="doctor_id" value="<?php echo (int)$singleDoctor['id']; ?>">
                    <?php else: ?>
                        <select name="doctor_id" class="form-control" required>
                            <option value="">Select doctor</option>
                            <?php foreach ($doctors as $d): ?>
                                <option value="<?php echo $d['id']; ?>" <?php echo (isset($_GET['doctor_id']) && (int)$_GET['doctor_id'] === (int)$d['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Room (optional)</label>
                    <select name="room_id" class="form-control">
                        <option value="">No room</option>
                        <?php while ($r = $rooms->fetch_assoc()): ?>
                            <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['room_number'] . ' — ' . $r['room_type'] . ' (' . ($r['occupied_count'] ?? 0) . '/' . ($r['capacity'] ?? 0) . ')'); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <label>Admission Date</label>
                    <input type="datetime-local" name="admission_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>">
                </div>
            </div>

            <div class="form-group">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="skip_triage" name="skip_triage" value="1" <?php echo isset($_GET['skip_triage']) && $_GET['skip_triage'] == '1' ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="skip_triage">Patient did not go through nurse/assessment (skip)</label>
                </div>
            </div>

            <div class="form-group" id="admission-reason-group" style="display: none;">
                <label>Reason for admission <span style="color: red;">*</span></label>
                <textarea name="admission_reason" id="admission_reason" class="form-control" rows="3" placeholder="Explain why the patient needs admission..."><?php echo isset($_GET['reason']) ? htmlspecialchars($_GET['reason']) : ''; ?></textarea>
            </div>

            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control"><?php echo isset($_GET['notes']) ? htmlspecialchars($_GET['notes']) : ''; ?></textarea>
            </div>

            <div class="form-group text-right">
                <button type="submit" class="btn btn-primary">Admit Patient</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var skipCheckbox = document.getElementById('skip_triage');
    var reasonGroup = document.getElementById('admission-reason-group');
    var reasonField = document.getElementById('admission_reason');

    function updateReasonVisibility() {
        if (skipCheckbox && skipCheckbox.checked) {
            reasonGroup.style.display = '';
            reasonField.setAttribute('required', 'required');
        } else {
            reasonGroup.style.display = 'none';
            reasonField.removeAttribute('required');
        }
    }

    if (skipCheckbox) {
        skipCheckbox.addEventListener('change', updateReasonVisibility);
        updateReasonVisibility();
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
