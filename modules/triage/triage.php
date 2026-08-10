<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse']);

$pageTitle = 'Patient Assessment';
$currentPage = 'triage';

$conn = getDBConnection();

// Add checkup_type column to patient_visits if it doesn't exist
$checkColumn = $conn->query("SHOW COLUMNS FROM patient_visits LIKE 'checkup_type'");
if (!$checkColumn || $checkColumn->num_rows === 0) {
    $conn->query("ALTER TABLE patient_visits ADD COLUMN checkup_type VARCHAR(50) NULL DEFAULT NULL");
}

// Get waiting patients for triage
$waitingPatients = $conn->query("
        SELECT v.*, p.first_name, p.last_name, p.patient_code, p.date_of_birth, p.gender, 
            p.is_pregnant, p.blood_type, p.allergies, p.expected_due_date, p.weeks_of_pregnancy
    FROM patient_visits v
    JOIN patients p ON v.patient_id = p.id
    WHERE v.visit_date = CURDATE() 
    AND v.status IN ('waiting', 'in-triage')
    ORDER BY p.is_pregnant DESC, v.priority DESC, v.created_at ASC
");

// Get recent triage records
$recentTriage = $conn->query("
    SELECT t.*, p.first_name, p.last_name, p.patient_code, u.full_name as nurse_name
    FROM triage_records t
    JOIN patients p ON t.patient_id = p.id
    JOIN users u ON t.nurse_id = u.id
    WHERE DATE(t.created_at) = CURDATE()
    ORDER BY t.created_at DESC
    LIMIT 10
");

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Patient Assessment</h1>
        <p class="page-subtitle">Initial patient assessment and vital signs</p>
    </div>
    <a href="../reception/queue.php" class="btn btn-secondary">
        <i class="fas fa-list"></i> View Queue
    </a>
</div>

<!-- Patients Waiting for Triage -->
<div class="card">
    <div class="card-header">
            <h3 class="card-title"><i class="fas fa-user-clock"></i> Patients Waiting for Patient Assessment</h3>
        <span class="badge badge-warning"><?php echo $waitingPatients->num_rows; ?> Waiting</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if ($waitingPatients && $waitingPatients->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Queue #</th>
                        <th>Patient</th>
                        <th>Age/Gender</th>
                        <th>Priority</th>
                        <th>Chief Complaint</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($patient = $waitingPatients->fetch_assoc()): ?>
                    <tr <?php echo $patient['is_pregnant'] ? "style='background-color: #fff8f0; border-left: 4px solid #ff9800;'" : ''; ?>>
                        <td>
                            <strong><?php echo $patient['queue_number']; ?></strong>
                            <?php if ($patient['is_pregnant']): ?>
                            <span class="badge badge-warning" style="margin-left: 8px; font-size: 10px;">
                                <i class="fas fa-baby"></i> PREGNANT
                            </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo $patient['first_name'] . ' ' . $patient['last_name']; ?><br>
                            <small class="text-muted"><?php echo $patient['patient_code']; ?></small>
                        </td>
                        <td><?php echo calculateAge($patient['date_of_birth']) . ' / ' . $patient['gender']; ?></td>
                        <td><?php echo getStatusBadge($patient['priority']); ?></td>
                        <td>
                            <?php echo $patient['chief_complaint'] ? substr($patient['chief_complaint'], 0, 50) . '...' : 'N/A'; ?>
                            <?php if ($patient['is_pregnant'] && $patient['weeks_of_pregnancy']): ?>
                            <br><small class="text-muted">Weeks of pregnancy: <?php echo intval($patient['weeks_of_pregnancy']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo getStatusBadge($patient['status']); ?></td>
                        <td class="table-actions">
                            <?php if ($patient['is_pregnant'] && !$patient['checkup_type']): ?>
                            <!-- For pregnant patients without selected checkup type, show select type button -->
                            <button type="button" class="btn btn-sm btn-warning select-checkup-type-btn" 
                                    data-visit-id="<?php echo $patient['id']; ?>"
                                    data-patient-id="<?php echo $patient['patient_id']; ?>"
                                    data-weeks="<?php echo intval($patient['weeks_of_pregnancy']); ?>"
                                    data-patient-name="<?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>"
                                    data-due-date="<?php echo $patient['expected_due_date']; ?>"
                                    data-code="<?php echo htmlspecialchars($patient['patient_code']); ?>">
                                <i class="fas fa-heartbeat"></i> Select Type
                            </button>
                            <?php elseif ($patient['is_pregnant'] && $patient['checkup_type']): ?>
                            <!-- For pregnant patients with selected checkup type -->
                            <span class="badge <?php echo $patient['checkup_type'] === 'maternity' ? 'badge-info' : 'badge-primary'; ?>" style="margin-right: 8px;">
                                <i class="fas fa-<?php echo $patient['checkup_type'] === 'maternity' ? 'heart' : 'stethoscope'; ?>"></i>
                                <?php echo ucfirst($patient['checkup_type']); ?>
                            </span>
                            <?php if ($patient['checkup_type'] === 'maternity'): ?>
                            <a href="../maternity/checkup-add.php?visit_id=<?php echo $patient['id']; ?>&patient_id=<?php echo $patient['patient_id']; ?>" class="btn btn-sm btn-info">
                                <i class="fas fa-edit"></i> Add Checkup
                            </a>
                            <?php else: ?>
                            <a href="triage-assess.php?visit_id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-heartbeat"></i> Assess
                            </a>
                            <?php endif; ?>
                            <?php else: ?>
                            <!-- For non-pregnant patients -->
                            <a href="triage-assess.php?visit_id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-heartbeat"></i> Assess
                            </a>
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
            <p>No patients waiting for Patient Assessment</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Triage Records -->
<div class="card">
        <div class="card-header">
        <h3 class="card-title"><i class="fas fa-history"></i> Today's Patient Assessment Records</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if ($recentTriage && $recentTriage->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>BP</th>
                        <th>HR</th>
                        <th>Temp</th>
                        <th>Weight</th>
                        <th>Assessed By</th>
                        <th>Time</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($triage = $recentTriage->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <?php echo $triage['first_name'] . ' ' . $triage['last_name']; ?><br>
                            <small class="text-muted"><?php echo $triage['patient_code']; ?></small>
                        </td>
                        <td><?php echo $triage['blood_pressure'] ?: 'N/A'; ?></td>
                        <td><?php echo $triage['heart_rate'] ? $triage['heart_rate'] . ' bpm' : 'N/A'; ?></td>
                        <td><?php echo $triage['temperature'] ? $triage['temperature'] . ' °C' : 'N/A'; ?></td>
                        <td><?php echo $triage['weight'] ? $triage['weight'] . ' kg' : 'N/A'; ?></td>
                        <td><?php echo $triage['nurse_name']; ?></td>
                        <td><?php echo formatDateTime($triage['created_at'], 'h:i A'); ?></td>
                        <td class="table-actions">
                            <a href="triage-view.php?id=<?php echo $triage['id']; ?>" class="btn btn-sm btn-info">
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
            <p>No Patient Assessment records today</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
    .checkup-option.selected {
        border-color: #667eea !important;
        background-color: #f0f4ff !important;
        box-shadow: 0 0 10px rgba(102, 126, 234, 0.2);
    }
    
    .checkup-option.maternity.selected {
        border-color: #764ba2 !important;
        background-color: #faf5ff !important;
        box-shadow: 0 0 10px rgba(118, 75, 162, 0.2);
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = $('#pregnantCheckupTypeModal');
    let currentVisitId = null;
    let selectedType = null;

    // Handle click on "Select Type" button
    document.querySelectorAll('.select-checkup-type-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            currentVisitId = this.dataset.visitId;
            const patientName = this.dataset.patientName;
            const patientCode = this.dataset.code;
            const weeks = this.dataset.weeks;
            const dueDate = this.dataset.dueDate;

            document.getElementById('modal-patient-name').textContent = patientName;
            document.getElementById('modal-patient-info').innerHTML = 
                patientCode + ' | Weeks: ' + weeks + ' | EDD: ' + dueDate;

            // Reset selection
            selectedType = null;
            document.getElementById('confirmCheckupTypeBtn').disabled = true;
            document.getElementById('confirmCheckupTypeBtn').innerHTML = '<i class="fas fa-arrow-right"></i> Proceed';
            document.querySelectorAll('.checkup-option').forEach(opt => opt.classList.remove('selected'));

            // Show modal
            modal.modal('show');
        });
    });

    // Handle checkup type selection
    const checkupOptions = document.querySelectorAll('.checkup-option');
    const confirmBtn = document.getElementById('confirmCheckupTypeBtn');

    checkupOptions.forEach(option => {
        option.addEventListener('click', function() {
            // Remove selected class from all options
            checkupOptions.forEach(opt => opt.classList.remove('selected'));
            
            // Add selected class to clicked option
            this.classList.add('selected');
            selectedType = this.dataset.type;
            
            // Enable confirm button
            confirmBtn.disabled = false;
            confirmBtn.style.opacity = '1';
        });
    });

    // Handle confirm button click
    confirmBtn.addEventListener('click', function() {
        if (!selectedType || !currentVisitId) return;
        
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        
        // Send AJAX request
        fetch('triage-checkup-type.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'checkup_type=' + encodeURIComponent(selectedType) + '&visit_id=' + encodeURIComponent(currentVisitId)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Close modal and redirect
                modal.modal('hide');
                setTimeout(() => {
                    window.location.href = data.redirect_url;
                }, 500);
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="fas fa-arrow-right"></i> Proceed';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fas fa-arrow-right"></i> Proceed';
        });
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
