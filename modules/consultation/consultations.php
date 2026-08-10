<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'doctor']);

$pageTitle = 'Consultations';
$currentPage = 'consultations';

$conn = getDBConnection();

// Get patients ready for consultation (triaged or completed labs)
$readyPatients = $conn->query("
    SELECT v.*, p.first_name, p.last_name, p.patient_code, p.date_of_birth, p.gender,
           p.blood_type, p.allergies, t.blood_pressure, t.heart_rate, t.temperature, 
           t.weight, t.symptoms, t.notes as triage_notes,
           (SELECT COUNT(*) FROM laboratory_requests WHERE visit_id = v.id) AS total_labs,
           (SELECT COUNT(*) FROM laboratory_requests WHERE visit_id = v.id AND status = 'completed') AS completed_labs
    FROM patient_visits v
    JOIN patients p ON v.patient_id = p.id
    LEFT JOIN triage_records t ON v.id = t.visit_id
    WHERE v.visit_date = CURDATE() 
        AND v.status IN ('in-triage', 'waiting', 'in-consultation')
        /* Exclude visits explicitly assigned to maternity checkups so they don't appear in general consultation */
        AND (v.checkup_type IS NULL OR v.checkup_type <> 'maternity')
    ORDER BY v.priority DESC, v.created_at ASC
");

// Get patients pending laboratory results
$pendingLabPatients = $conn->query("
    SELECT v.*, p.first_name, p.last_name, p.patient_code, p.date_of_birth, p.gender,
           (SELECT COUNT(*) FROM laboratory_requests WHERE visit_id = v.id) AS total_labs,
           (SELECT COUNT(*) FROM laboratory_requests WHERE visit_id = v.id AND status = 'completed') AS completed_labs
    FROM patient_visits v
    JOIN patients p ON v.patient_id = p.id
    WHERE v.visit_date = CURDATE() 
    AND v.status = 'in-laboratory'
    ORDER BY v.created_at ASC
");

// Get my consultations today
$myConsultations = $conn->query("
    SELECT c.*, p.first_name, p.last_name, p.patient_code, v.queue_number
    FROM consultations c
    JOIN patients p ON c.patient_id = p.id
    JOIN patient_visits v ON c.visit_id = v.id
    WHERE c.doctor_id = {$_SESSION['user_id']} AND DATE(c.created_at) = CURDATE()
    ORDER BY c.created_at DESC
");

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Consultations</h1>
        <p class="page-subtitle">Patient examination and diagnosis</p>
    </div>
</div>

<!-- Patients Ready for Consultation -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-user-clock"></i> Patients Ready for Consultation</h3>
        <span class="badge badge-primary"><?php echo $readyPatients->num_rows; ?> Waiting</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if ($readyPatients && $readyPatients->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Queue #</th>
                        <th>Patient</th>
                        <th>Vital Signs</th>
                        <th>Symptoms</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($patient = $readyPatients->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo $patient['queue_number']; ?></strong></td>
                        <td>
                            <?php echo $patient['first_name'] . ' ' . $patient['last_name']; ?><br>
                            <small class="text-muted"><?php echo $patient['patient_code']; ?> | 
                            <?php echo calculateAge($patient['date_of_birth']); ?> yrs | <?php echo $patient['gender']; ?></small>
                        </td>
                        <td>
                            <?php if ($patient['blood_pressure']): ?>
                                <small>
                                BP: <?php echo $patient['blood_pressure']; ?><br>
                                HR: <?php echo $patient['heart_rate']; ?> | Temp: <?php echo $patient['temperature']; ?>°C
                                </small>
                            <?php else: ?>
                                <span class="text-muted">No vitals</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $patient['symptoms'] ? substr($patient['symptoms'], 0, 50) . '...' : 'N/A'; ?></td>
                        <td>
                            <?php 
                            if ($patient['total_labs'] > 0 && $patient['completed_labs'] == $patient['total_labs']) {
                                echo '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Lab Results Received</span>';
                            } else {
                                echo getStatusBadge($patient['status']); 
                            }
                            ?>
                        </td>
                        <td class="table-actions">
                            <?php if (hasRole(['doctor'])): ?>
                                <?php if ($patient['total_labs'] > 0 && $patient['completed_labs'] == $patient['total_labs']): ?>
                                    <a href="consultation-start.php?visit_id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-success">
                                        <i class="fas fa-play"></i> Resume
                                    </a>
                                <?php else: ?>
                                    <a href="consultation-start.php?visit_id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-stethoscope"></i> Start
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">View only</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding: 40px; text-align: center; color: #999;">
            <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 15px;"></i>
            <p>No patients waiting for consultation</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Patients Pending Laboratory Results -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-hourglass-half"></i> Patients Pending Laboratory Results</h3>
        <span class="badge badge-warning"><?php echo $pendingLabPatients->num_rows; ?> Pending Lab</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if ($pendingLabPatients && $pendingLabPatients->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Queue #</th>
                        <th>Patient</th>
                        <th>Lab Tests Status</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($patient = $pendingLabPatients->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo $patient['queue_number']; ?></strong></td>
                        <td>
                            <?php echo $patient['first_name'] . ' ' . $patient['last_name']; ?><br>
                            <small class="text-muted"><?php echo $patient['patient_code']; ?> | 
                            <?php echo calculateAge($patient['date_of_birth']); ?> yrs | <?php echo $patient['gender']; ?></small>
                        </td>
                        <td>
                            <div style="font-size: 13px;">
                                Completed: <strong><?php echo $patient['completed_labs']; ?></strong> / <?php echo $patient['total_labs']; ?> tests
                            </div>
                            <div style="width: 120px; background: rgba(0,0,0,0.1); height: 6px; border-radius: 3px; overflow: hidden; margin-top: 4px;">
                                <div style="width: <?php echo ($patient['total_labs'] > 0 ? ($patient['completed_labs'] / $patient['total_labs'] * 100) : 0); ?>%; background: var(--warning-color); height: 100%;"></div>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-warning"><i class="fas fa-spinner fa-spin"></i> Pending Lab Results</span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding: 30px; text-align: center; color: #999;">
            <i class="fas fa-vial" style="font-size: 36px; margin-bottom: 10px; opacity: 0.5;"></i>
            <p>No patients currently waiting for laboratory results</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- My Consultations Today -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-history"></i> My Consultations Today</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if ($myConsultations && $myConsultations->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Queue #</th>
                        <th>Patient</th>
                        <th>Diagnosis</th>
                        <th>Outcome</th>
                        <th>Time</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($consultation = $myConsultations->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo $consultation['queue_number']; ?></strong></td>
                        <td>
                            <?php echo $consultation['first_name'] . ' ' . $consultation['last_name']; ?><br>
                            <small class="text-muted"><?php echo $consultation['patient_code']; ?></small>
                        </td>
                        <td><?php echo $consultation['diagnosis'] ? substr($consultation['diagnosis'], 0, 50) . '...' : 'N/A'; ?></td>
                        <td>
                            <?php echo getStatusBadge($consultation['outcome']); ?>
                            <?php if ($consultation['outcome'] === 'transfer' && !empty($consultation['transfer_destination'])): ?>
                                <br><small class="text-muted">To: <?php echo htmlspecialchars($consultation['transfer_destination']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo formatDateTime($consultation['created_at'], 'h:i A'); ?></td>
                        <td class="table-actions">
                            <a href="consultation-view.php?id=<?php echo $consultation['id']; ?>" class="btn btn-sm btn-info">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding: 40px; text-align: center; color: #999;">
            <i class="fas fa-info-circle" style="font-size: 48px; margin-bottom: 15px;"></i>
            <p>No consultations today</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
