<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse', 'doctor']);

$pageTitle = 'Add Prenatal Check-up';
$currentPage = 'maternity';

$conn = getDBConnection();

$patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
$visitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;

// Fetch patient details
$patientRes = $conn->query("SELECT * FROM patients WHERE id = $patientId AND is_pregnant = 1");
if (!$patientRes || $patientRes->num_rows === 0) {
    setFlashMessage('error', 'Pregnant patient not found.');
    redirect('modules/maternity/index.php');
}
$patient = $patientRes->fetch_assoc();

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $checkupDate = sanitize($_POST['checkup_date']);
    $weeks = (int)$_POST['weeks_of_pregnancy'];
    $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
    $bp = sanitize($_POST['blood_pressure']);
    $fetalHeartbeat = !empty($_POST['fetal_heartbeat']) ? (int)$_POST['fetal_heartbeat'] : null;
    $fundalHeight = !empty($_POST['fundal_height']) ? (float)$_POST['fundal_height'] : null;
    $presentation = sanitize($_POST['presentation']);
    $notes = sanitize($_POST['notes']);
    $vitamins = sanitize($_POST['prescribed_vitamins']);
    $nextDate = !empty($_POST['next_appointment_date']) ? sanitize($_POST['next_appointment_date']) : null;
    $createdBy = $_SESSION['user_id'];

    if ($weeks > 0 && !empty($checkupDate)) {
        // Start transaction
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO maternity_checkups (patient_id, checkup_date, weeks_of_pregnancy, weight, blood_pressure, fetal_heartbeat, fundal_height, presentation, notes, prescribed_vitamins, next_appointment_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isidsidssssi", $patientId, $checkupDate, $weeks, $weight, $bp, $fetalHeartbeat, $fundalHeight, $presentation, $notes, $vitamins, $nextDate, $createdBy);
            $stmt->execute();
            $checkupId = $stmt->insert_id;
            $stmt->close();

            // Update patient's current weeks of pregnancy
            $updateStmt = $conn->prepare("UPDATE patients SET weeks_of_pregnancy = ? WHERE id = ?");
            $updateStmt->bind_param("ii", $weeks, $patientId);
            $updateStmt->execute();
            $updateStmt->close();

            // If this was from triage workflow, update visit status to in-consultation and set checkup_type
            if ($visitId > 0) {
                $visitUpdateStmt = $conn->prepare("UPDATE patient_visits SET status = 'in-consultation', checkup_type = 'maternity' WHERE id = ?");
                $visitUpdateStmt->bind_param("i", $visitId);
                $visitUpdateStmt->execute();
                $visitUpdateStmt->close();
            }

            $conn->commit();
            logActivity("Add Maternity Checkup", "maternity_checkups", $checkupId, null, json_encode(['weeks' => $weeks, 'bp' => $bp, 'fetal_heart' => $fetalHeartbeat, 'visit_id' => $visitId]));
            setFlashMessage('success', 'Prenatal check-up logged successfully.');
            
            // Redirect based on where we came from
            if ($visitId > 0) {
                redirect('../triage/triage.php');
            } else {
                redirect('modules/maternity/index.php');
            }
        } catch (Exception $e) {
            $conn->rollback();
            setFlashMessage('error', 'Error logging check-up: ' . $e->getMessage());
        }
    } else {
        setFlashMessage('error', 'Please fill in all required fields.');
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Add Prenatal Check-up</h1>
        <p class="page-subtitle">Log prenatal progress details for patient</p>
    </div>
    <div>
        <a href="<?php echo $visitId > 0 ? '../triage/triage.php' : 'index.php'; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Cancel
        </a>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 3fr; gap: 25px; align-items: start;">
    
    <!-- Patient Card Summary -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-user-circle"></i> Patient Info</h3>
        </div>
        <div class="card-body">
            <h4 style="margin: 0 0 10px;"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h4>
            <p class="text-muted" style="margin: 0 0 15px; font-size:13px;"><?php echo htmlspecialchars($patient['patient_code']); ?></p>
            <hr>
            <div style="font-size: 14px; line-height: 1.8;">
                <div><strong>Age:</strong> <?php echo calculateAge($patient['date_of_birth']); ?> years</div>
                <div><strong>EDD:</strong> <?php echo formatDate($patient['expected_due_date']); ?></div>
                <div><strong>Current Gestation:</strong> <?php echo intval($patient['weeks_of_pregnancy']); ?> weeks</div>
                <div><strong>Blood Type:</strong> <?php echo htmlspecialchars($patient['blood_type']); ?></div>
                <div><strong>Allergies:</strong> <br><span class="text-danger"><?php echo htmlspecialchars($patient['allergies'] ?: 'None'); ?></span></div>
            </div>
        </div>
    </div>
    
    <!-- Checkup Form -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-notes-medical"></i> Clinical Examination Details</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                    
                    <div class="form-group">
                        <label class="form-label" for="checkup_date">Check-up Date <span class="text-danger">*</span></label>
                        <input type="date" name="checkup_date" id="checkup_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="weeks_of_pregnancy">Gestation Age (Weeks) <span class="text-danger">*</span></label>
                        <input type="number" name="weeks_of_pregnancy" id="weeks_of_pregnancy" class="form-control" min="1" max="45" value="<?php echo intval($patient['weeks_of_pregnancy']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="weight">Weight (kg)</label>
                        <input type="number" step="0.01" name="weight" id="weight" class="form-control" placeholder="e.g. 62.5" value="<?php echo $patient['weight'] ? htmlspecialchars($patient['weight']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="blood_pressure">Blood Pressure (BP)</label>
                        <input type="text" name="blood_pressure" id="blood_pressure" class="form-control" placeholder="e.g. 120/80">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="fetal_heartbeat">Fetal Heart Rate (bpm)</label>
                        <input type="number" name="fetal_heartbeat" id="fetal_heartbeat" class="form-control" placeholder="e.g. 145">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="fundal_height">Fundal Height (cm)</label>
                        <input type="number" step="0.1" name="fundal_height" id="fundal_height" class="form-control" placeholder="e.g. 24.5">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="presentation">Fetal Presentation</label>
                        <select name="presentation" id="presentation" class="form-control">
                            <option value="">-- Choose Presentation --</option>
                            <option value="Cephalic">Cephalic (Head down)</option>
                            <option value="Breech">Breech (Feet/Bottom down)</option>
                            <option value="Transverse">Transverse (Sideways)</option>
                            <option value="Oblique">Oblique (Diagonal)</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="next_appointment_date">Next Prenatal Visit Date</label>
                        <input type="date" name="next_appointment_date" id="next_appointment_date" class="form-control">
                    </div>
                    
                </div>
                
                <div style="margin-top: 20px;">
                    <div class="form-group">
                        <label class="form-label" for="prescribed_vitamins">Prescribed Vitamins / Medications</label>
                        <textarea name="prescribed_vitamins" id="prescribed_vitamins" class="form-control" rows="2" placeholder="e.g. Folic Acid 400mcg QD, Iron supplement 325mg daily"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="notes">Clinical Findings / Recommendations</label>
                        <textarea name="notes" id="notes" class="form-control" rows="4" placeholder="Describe maternal condition, uterine tone, edema, advice on diet or signs of danger..."></textarea>
                    </div>
                </div>
                
                <div style="margin-top: 30px; display: flex; gap: 10px; justify-content: flex-end;">
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Check-up Record
                    </button>
                </div>
            </form>
        </div>
    </div>
    
</div>

<?php
$conn->close();
include __DIR__ . '/../../includes/footer.php';
?>
