<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse']);

$pageTitle = 'Assessment Record';
$currentPage = 'triage';

$conn = getDBConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    setFlashMessage('error', 'Invalid assessment record.');
    redirect('modules/triage/triage.php');
}

$res = $conn->query(
    "SELECT t.*, p.first_name, p.last_name, p.patient_code, p.date_of_birth, p.gender, p.is_pregnant, p.family_medical_history, u.full_name AS nurse_name, t.visit_id "
    . "FROM triage_records t "
    . "JOIN patients p ON t.patient_id = p.id "
    . "LEFT JOIN users u ON t.nurse_id = u.id "
    . "WHERE t.id = $id"
);

if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Assessment record not found.');
    redirect('modules/triage/triage.php');
}

$triage = $res->fetch_assoc();

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Assessment Record</h1>
        <p class="page-subtitle">View initial assessment details</p>
    </div>
    <div>
        <a href="triage.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
        <a href="triage-assess.php?visit_id=<?php echo intval($triage['visit_id']); ?>" class="btn btn-warning"><i class="fas fa-edit"></i> Edit</a>
    </div>
</div>

<div class="patient-info-card">
    <div class="patient-info-header">
        <h2><?php echo htmlspecialchars($triage['first_name'] . ' ' . $triage['last_name']); ?></h2>
        <span class="patient-code"><?php echo htmlspecialchars($triage['patient_code']); ?></span>
    </div>
    <div class="patient-info-grid">
        <div class="patient-info-item">
            <label>Age</label>
            <span><?php echo calculateAge($triage['date_of_birth']); ?> years</span>
        </div>
        <div class="patient-info-item">
            <label>Gender</label>
            <span><?php echo htmlspecialchars($triage['gender']); ?></span>
        </div>
        <?php if ($triage['is_pregnant']): ?>
        <div class="patient-info-item" style="background: rgba(255,193,7,0.3);">
            <label><i class="fas fa-baby"></i> Pregnant</label>
            <span><?php echo !empty($triage['weeks_of_pregnancy']) ? intval($triage['weeks_of_pregnancy']) . ' weeks' : 'Yes'; ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-heartbeat"></i> Vital Signs</h3>
    </div>
    <div class="card-body">
        <div class="vitals-grid">
            <div class="vital-card">
                <div class="vital-label">Blood Pressure</div>
                <div class="vital-value"><?php echo htmlspecialchars($triage['blood_pressure'] ?: 'N/A'); ?></div>
            </div>
            <div class="vital-card">
                <div class="vital-label">Heart Rate</div>
                <div class="vital-value"><?php echo $triage['heart_rate'] ? intval($triage['heart_rate']) . ' bpm' : 'N/A'; ?></div>
            </div>
            <div class="vital-card">
                <div class="vital-label">Temperature</div>
                <div class="vital-value"><?php echo $triage['temperature'] ? htmlspecialchars($triage['temperature'] . ' °C') : 'N/A'; ?></div>
            </div>
            <div class="vital-card">
                <div class="vital-label">Respiratory Rate</div>
                <div class="vital-value"><?php echo $triage['respiratory_rate'] ? intval($triage['respiratory_rate']) : 'N/A'; ?></div>
            </div>
            <div class="vital-card">
                <div class="vital-label">Weight</div>
                <div class="vital-value"><?php echo $triage['weight'] ? htmlspecialchars($triage['weight'] . ' kg') : 'N/A'; ?></div>
            </div>
            <div class="vital-card">
                <div class="vital-label">Height</div>
                <div class="vital-value"><?php echo $triage['height'] ? htmlspecialchars($triage['height'] . ' cm') : 'N/A'; ?></div>
            </div>
            <div class="vital-card">
                <div class="vital-label">BMI</div>
                <div class="vital-value"><?php echo $triage['bmi'] ? number_format($triage['bmi'], 2) : '--'; ?></div>
            </div>
            <div class="vital-card">
                <div class="vital-label">Oxygen Saturation</div>
                <div class="vital-value"><?php echo $triage['oxygen_saturation'] ? intval($triage['oxygen_saturation']) . ' %' : 'N/A'; ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-clipboard-list"></i> Assessment & Notes</h3>
    </div>
    <div class="card-body">
        <div class="form-group">
            <label>Symptoms / Chief Complaint</label>
            <div class="readonly-field"><?php echo nl2br(htmlspecialchars($triage['symptoms'] ?: 'N/A')); ?></div>
        </div>
        <div class="form-group">
            <label>Medical History Notes</label>
            <div class="readonly-field"><?php echo nl2br(htmlspecialchars($triage['medical_history_notes'] ?: 'N/A')); ?></div>
        </div>
        <div class="form-group">
            <label>Family Medical History</label>
            <div class="readonly-field"><?php echo nl2br(htmlspecialchars($triage['family_medical_history'] ?: 'N/A')); ?></div>
        </div>
        <?php if (!empty($triage['fetal_heartbeat']) || !empty($triage['weeks_of_pregnancy'])): ?>
        <div class="form-row">
            <div class="form-group">
                <label>Fetal Heartbeat</label>
                <div class="readonly-field"><?php echo !empty($triage['fetal_heartbeat']) ? intval($triage['fetal_heartbeat']) . ' bpm' : 'N/A'; ?></div>
            </div>
            <div class="form-group">
                <label>Weeks of Pregnancy</label>
                <div class="readonly-field"><?php echo !empty($triage['weeks_of_pregnancy']) ? intval($triage['weeks_of_pregnancy']) . ' weeks' : 'N/A'; ?></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label>Additional Notes</label>
            <div class="readonly-field"><?php echo nl2br(htmlspecialchars($triage['notes'] ?: 'N/A')); ?></div>
        </div>

        <div class="form-group">
            <label>Assessed By</label>
            <div class="readonly-field"><?php echo htmlspecialchars($triage['nurse_name'] ?: 'System'); ?> — <?php echo formatDateTime($triage['created_at']); ?></div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
