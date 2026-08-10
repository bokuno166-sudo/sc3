<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'staff', 'reception', 'doctor', 'nurse']);

$pageTitle = 'Patient Details';
$currentPage = 'patients';

$conn = getDBConnection();

$patientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get patient details
$patientResult = $conn->query("SELECT * FROM patients WHERE id = $patientId");
if ($patientResult->num_rows === 0) {
    setFlashMessage('error', 'Patient not found.');
    redirect('modules/reception/patients.php');
}
$patient = $patientResult->fetch_assoc();

// Get visit history
$visits = $conn->query("
    SELECT v.*, u.full_name as created_by_name
    FROM patient_visits v
    LEFT JOIN users u ON v.created_by = u.id
    WHERE v.patient_id = $patientId
    ORDER BY v.visit_date DESC
    LIMIT 10
");
$prescriptions = $conn->query("
    SELECT pr.*, c.created_at as prescription_date, u.full_name as doctor_name
    FROM prescriptions pr
    JOIN consultations c ON pr.consultation_id = c.id
    JOIN users u ON pr.doctor_id = u.id
    WHERE pr.patient_id = $patientId
    ORDER BY c.created_at DESC
    LIMIT 10
");


// Get latest visit chief complaint to show on patient card
$latestVisitRes = $conn->query("SELECT chief_complaint, visit_date FROM patient_visits WHERE patient_id = $patientId ORDER BY visit_date DESC LIMIT 1");
$latestVisit = ($latestVisitRes && $latestVisitRes->num_rows) ? $latestVisitRes->fetch_assoc() : null;

// Get latest consultation summary (diagnosis/notes)
$latestConsultRes = $conn->query("SELECT c.diagnosis, c.notes, c.created_at, u.full_name as doctor_name FROM consultations c LEFT JOIN users u ON c.doctor_id = u.id WHERE c.patient_id = $patientId ORDER BY c.created_at DESC LIMIT 1");
$latestConsult = ($latestConsultRes && $latestConsultRes->num_rows) ? $latestConsultRes->fetch_assoc() : null;

// Get full consultation history (including outcomes)
$consultations = $conn->query(
    "SELECT c.id, c.visit_id, c.created_at, c.diagnosis, c.outcome, c.notes, u.full_name as doctor_name
     FROM consultations c
     LEFT JOIN users u ON c.doctor_id = u.id
     WHERE c.patient_id = $patientId
     ORDER BY c.created_at DESC
     LIMIT 50"
);

// Get lab results for this patient (via visit -> requests -> results)
$labResults = $conn->query(
    "SELECT lr.id as result_id, lr.request_id, lr.result_value, lr.interpretation, lr.remarks, lr.status as result_status, lr.created_at as result_date,
            lt.test_name, rq.request_code, rq.visit_id
     FROM laboratory_results lr
     JOIN laboratory_requests rq ON lr.request_id = rq.id
     JOIN patient_visits v ON rq.visit_id = v.id
     LEFT JOIN laboratory_tests lt ON rq.test_id = lt.id
     WHERE v.patient_id = $patientId
     ORDER BY lr.created_at DESC
     LIMIT 100"
);

// Get admissions for this patient
$admissions = $conn->query(
    "SELECT a.*, r.room_number, b.bed_number, u.full_name as doctor_name
     FROM admissions a
     LEFT JOIN rooms r ON a.room_id = r.id
     LEFT JOIN beds b ON a.bed_id = b.id
     LEFT JOIN users u ON a.doctor_id = u.id
     WHERE a.patient_id = $patientId
     ORDER BY a.admission_date DESC
     LIMIT 20"
);

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Patient Details</h1>
        <p class="page-subtitle">View complete patient information</p>
    </div>
    <div>
        <a href="patients.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <a href="patient-edit.php?id=<?php echo $patientId; ?>" class="btn btn-warning">
            <i class="fas fa-edit"></i> Edit
        </a>
        <?php if (hasRole(['admin', 'reception', 'staff'])): ?>
        <a href="visit-add.php?patient_id=<?php echo $patientId; ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> New Visit
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Patient Info Card -->
<div class="patient-info-card">
    <div class="patient-info-header">
        <h2><?php echo $patient['first_name'] . ' ' . $patient['last_name']; ?></h2>
        <span class="patient-code"><?php echo $patient['patient_code']; ?></span>
    </div>
    <div class="patient-info-grid">
        <div class="patient-info-item">
            <label>Age</label>
            <span><?php echo calculateAge($patient['date_of_birth']); ?> years old</span>
        </div>
        <div class="patient-info-item">
            <label>Gender</label>
            <span><?php echo $patient['gender']; ?></span>
        </div>
        <div class="patient-info-item">
            <label>Civil Status</label>
            <span><?php echo $patient['civil_status']; ?></span>
        </div>
        <div class="patient-info-item">
            <label>Blood Type</label>
            <span><?php echo $patient['blood_type']; ?></span>
        </div>
        <div class="patient-info-item">
            <label>Contact</label>
            <span><?php echo $patient['contact_number']; ?></span>
        </div>
        <div class="patient-info-item">
            <label>Weight</label>
            <span><?php echo $patient['weight'] ? htmlspecialchars($patient['weight'] . ' kg') : 'N/A'; ?></span>
        </div>
        <div class="patient-info-item">
            <label>Height</label>
            <span><?php echo $patient['height'] ? htmlspecialchars($patient['height'] . ' cm') : 'N/A'; ?></span>
        </div>
        <div class="patient-info-item">
            <label>Email</label>
            <span><?php echo $patient['email'] ?: 'N/A'; ?></span>
        </div>
        <div class="patient-info-item" style="grid-column: span 2;">
            <label>Address</label>
            <span><?php echo $patient['address']; ?></span>
        </div>
        <div class="patient-info-item" style="grid-column: span 2;">
            <label>Last Visit Reason</label>
            <span><?php echo nl2br(htmlspecialchars($latestVisit ? ($latestVisit['chief_complaint'] ?? 'N/A') : 'N/A')); ?></span>
        </div>
        <div class="patient-info-item consultation" style="grid-column: span 2;">
            <label>Last Consultation</label>
            <span>
                <?php if ($latestConsult): ?>
                    <span class="consult-diagnosis"><?php echo htmlspecialchars($latestConsult['diagnosis'] ?: 'No diagnosis'); ?></span>
                    <span class="consult-notes"><?php echo nl2br(htmlspecialchars($latestConsult['notes'] ?: 'No notes')); ?></span>
                    <small><?php echo htmlspecialchars(formatDateTime($latestConsult['created_at']) . ' — ' . ($latestConsult['doctor_name'] ?? '')); ?></small>
                <?php else: ?>
                    N/A
                <?php endif; ?>
            </span>
        </div>
        <?php if ($patient['is_pregnant']): ?>
        <div class="patient-info-item" style="background: rgba(255,193,7,0.3);">
            <label><i class="fas fa-baby"></i> Pregnant</label>
            <span><?php echo $patient['weeks_of_pregnancy'] ? $patient['weeks_of_pregnancy'] . ' weeks' : 'Confirmed'; ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 25px;">
    <!-- Medical Information -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-notes-medical"></i> Medical Information</h3>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Allergies</label>
                <div class="alert alert-warning">
                    <?php echo $patient['allergies'] ?: 'No known allergies'; ?>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Medical History</label>
                <p><?php echo !empty($patient['medical_history']) ? nl2br(htmlspecialchars($patient['medical_history'])) : 'No significant medical history'; ?></p>
            </div>
            <div class="form-group">
                <label class="form-label">Family Medical History</label>
                <p><?php echo !empty($patient['family_medical_history']) ? nl2br(htmlspecialchars($patient['family_medical_history'])) : 'No significant family medical history'; ?></p>
            </div>
            <div class="form-group">
                <label class="form-label">Emergency Contact</label>
                <p>
                    <strong><?php echo $patient['emergency_contact_name'] ?: 'N/A'; ?></strong><br>
                    <?php echo $patient['emergency_contact_number'] ?: ''; ?>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Visit History -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-history"></i> Recent Visits</h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if ($visits && $visits->num_rows > 0): ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Queue #</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($visit = $visits->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo formatDate($visit['visit_date']); ?></td>
                            <td><?php echo $visit['queue_number']; ?></td>
                            <td><?php echo getStatusBadge($visit['status']); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="padding: 30px; text-align: center; color: #999;">
                <p>No visit history</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Prescription History -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-prescription"></i> Recent Prescriptions</h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if ($prescriptions && $prescriptions->num_rows > 0): ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Medication</th>
                            <th>Dosage</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($prescription = $prescriptions->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $prescription['medication_name']; ?></td>
                            <td><?php echo $prescription['dosage']; ?></td>
                            <td><?php echo formatDate($prescription['prescription_date']); ?></td>
                            <td>
                                <?php if (!hasRole(['doctor'])): ?>
                                    <a href="../prescription/prescription-print.php?id=<?php echo $prescription['id']; ?>" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-print"></i> Print</a>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:12px;">Staff prints</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="padding: 30px; text-align: center; color: #999;">
                <p>No prescriptions</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Admissions -->
<div class="card" style="margin-top:20px;">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-procedures"></i> Admissions</h3>
    </div>
    <div class="card-body">
        <?php if ($admissions && $admissions->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Admission Code</th>
                        <th>Status</th>
                        <th>Room</th>
                        <th>Bed</th>
                        <th>Doctor</th>
                        <th>Notes</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($ad = $admissions->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo formatDateTime($ad['admission_date']); ?></td>
                        <td><?php echo htmlspecialchars($ad['admission_code'] ?? ''); ?></td>
                        <td><?php echo getStatusBadge($ad['status']); ?></td>
                        <td><?php echo htmlspecialchars($ad['room_number'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($ad['bed_number'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($ad['doctor_name'] ?? ''); ?></td>
                        <td><?php echo nl2br(htmlspecialchars(substr($ad['notes'] ?? '', 0, 150))); ?></td>
                        <td>
                            <a href="../admission/admission-view.php?id=<?php echo $ad['id']; ?>" class="btn btn-sm btn-info">View</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding: 20px; text-align: center; color: #999;">No admissions found</div>
        <?php endif; ?>
    </div>
</div>

<!-- Consultation History & Outcomes -->
<div class="card" style="margin-top:20px;">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-user-md"></i> Consultation History & Outcomes</h3>
    </div>
    <div class="card-body">
        <?php if ($consultations && $consultations->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Doctor</th>
                        <th>Diagnosis</th>
                        <th>Outcome</th>
                        <th>Notes</th>
                        <th>Visit</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($c = $consultations->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo formatDateTime($c['created_at']); ?></td>
                        <td><?php echo htmlspecialchars($c['doctor_name'] ?? ''); ?></td>
                        <td><?php echo nl2br(htmlspecialchars($c['diagnosis'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars($c['outcome'] ?? ''); ?></td>
                        <td><?php echo nl2br(htmlspecialchars($c['notes'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars($c['visit_id']); ?></td>
                        <td><a href="../consultation/consultation-view.php?id=<?php echo $c['id']; ?>" class="btn btn-sm btn-info">View</a></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding: 20px; text-align: center; color: #999;">No consultations found</div>
        <?php endif; ?>
    </div>
</div>

<!-- Lab Results -->
<div class="card" style="margin-top:20px;">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-vials"></i> Laboratory Results</h3>
    </div>
    <div class="card-body">
        <?php if ($labResults && $labResults->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Test</th>
                        <th>Result</th>
                        <th>Interpretation</th>
                        <th>Status</th>
                        <th>Visit</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($lr = $labResults->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo formatDateTime($lr['result_date']); ?></td>
                        <td><?php echo htmlspecialchars($lr['test_name'] ?? ('Test ID: ' . $lr['request_id'])); ?></td>
                        <td><?php echo nl2br(htmlspecialchars($lr['result_value'] ?? '')); ?></td>
                        <td><?php echo nl2br(htmlspecialchars($lr['interpretation'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars($lr['result_status'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($lr['visit_id']); ?></td>
                        <td>
                            <a href="../laboratory/result-view.php?request_id=<?php echo $lr['request_id']; ?>" class="btn btn-sm btn-info">View</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding: 20px; text-align: center; color: #999;">No lab results found</div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

