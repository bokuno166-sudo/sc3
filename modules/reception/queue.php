<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'staff', 'reception', 'nurse', 'doctor']);

$pageTitle = 'Patient Queue';
$currentPage = 'queue';

$conn = getDBConnection();

// Add checkup_type column to patient_visits if it doesn't exist
$checkColumn = $conn->query("SHOW COLUMNS FROM patient_visits LIKE 'checkup_type'");
if (!$checkColumn || $checkColumn->num_rows === 0) {
    $conn->query("ALTER TABLE patient_visits ADD COLUMN checkup_type VARCHAR(50) NULL DEFAULT NULL");
}

// Get all active pregnancies and their current queue status/priority
$activeMaternityQueue = $conn->query("
    SELECT p.id, p.first_name, p.last_name, p.patient_code, p.gender, p.date_of_birth,
           p.is_pregnant, p.weeks_of_pregnancy, p.expected_due_date, p.status,
           latest_visit.queue_number, latest_visit.priority, latest_visit.status AS visit_status,
           latest_visit.checkup_type, latest_visit.visit_date, latest_visit.created_at
    FROM patients p
    LEFT JOIN (
        SELECT pv1.patient_id, pv1.queue_number, pv1.priority, pv1.status, pv1.checkup_type, pv1.visit_date, pv1.created_at
        FROM patient_visits pv1
        JOIN (
            SELECT patient_id, MAX(created_at) AS max_created
            FROM patient_visits
            GROUP BY patient_id
        ) pv2 ON pv1.patient_id = pv2.patient_id AND pv1.created_at = pv2.max_created
    ) latest_visit ON latest_visit.patient_id = p.id
    WHERE p.is_pregnant = 1 AND p.status = 'active'
    ORDER BY p.expected_due_date ASC,
        CASE COALESCE(latest_visit.priority, 'normal')
            WHEN 'emergency' THEN 4
            WHEN 'high' THEN 3
            WHEN 'normal' THEN 2
            WHEN 'low' THEN 1
            ELSE 0
        END DESC,
        p.last_name ASC, p.first_name ASC
");

$waitingQueue = $conn->query("
    SELECT v.*, p.first_name, p.last_name, p.patient_code, p.gender, p.date_of_birth, 
           p.is_pregnant, p.weeks_of_pregnancy, p.expected_due_date,
           u.full_name as requester_name
    FROM patient_visits v
    JOIN patients p ON v.patient_id = p.id
    LEFT JOIN users u ON v.delete_requested_by = u.id
    WHERE v.visit_date = CURDATE() AND v.status = 'waiting'
    ORDER BY p.is_pregnant DESC, v.priority DESC, v.created_at ASC
");

$inTriage = $conn->query("
    SELECT v.*, p.first_name, p.last_name, p.patient_code, p.gender, p.date_of_birth,
           p.is_pregnant, p.weeks_of_pregnancy
    FROM patient_visits v
    JOIN patients p ON v.patient_id = p.id
    WHERE v.visit_date = CURDATE() AND v.status = 'in-triage'
    ORDER BY v.created_at ASC
");

$inConsultation = $conn->query("
        SELECT v.*, p.first_name, p.last_name, p.patient_code, p.gender, p.date_of_birth,
            u.full_name as doctor_name, p.is_pregnant
        FROM patient_visits v
        JOIN patients p ON v.patient_id = p.id
        /* Consultations table links visit -> doctor; use it to get current doctor */
        LEFT JOIN consultations c ON c.visit_id = v.id
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE v.visit_date = CURDATE() AND v.status = 'in-consultation'
        ORDER BY v.created_at ASC
");

$inLaboratory = $conn->query("
    SELECT v.*, p.first_name, p.last_name, p.patient_code, p.gender, p.date_of_birth
    FROM patient_visits v
    JOIN patients p ON v.patient_id = p.id
    WHERE v.visit_date = CURDATE() AND v.status = 'in-laboratory'
    ORDER BY v.created_at ASC
");

$admittedQueue = $conn->query("
    SELECT a.id AS admission_id, a.admission_code, a.admission_date,
           p.first_name, p.last_name, p.patient_code, p.gender, p.date_of_birth,
           r.room_number, v.queue_number, v.id AS id
    FROM admissions a
    JOIN patients p ON a.patient_id = p.id
    LEFT JOIN patient_visits v ON a.visit_id = v.id
    LEFT JOIN rooms r ON a.room_id = r.id
    WHERE a.status = 'admitted'
    ORDER BY a.admission_date DESC
");

$completed = $conn->query("
    SELECT v.*, p.first_name, p.last_name, p.patient_code
    FROM patient_visits v
    JOIN patients p ON v.patient_id = p.id
    WHERE v.visit_date = CURDATE() AND v.status IN ('discharged', 'ready-for-discharge')
    ORDER BY v.updated_at DESC
    LIMIT 10
");

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Patient Queue</h1>
        <p class="page-subtitle">Today's patient flow management</p>
    </div>
    <?php if (hasRole(['admin', 'reception', 'staff'])): ?>
    <a href="visit-add.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> New Visit
    </a>
    <?php endif; ?>
</div>

<div class="queue-display">

    <!-- Waiting Queue -->
    <div class="queue-section">
        <div class="queue-header" style="background: var(--info-color);">
            <h3><i class="fas fa-clock"></i> Waiting</h3>
            <span class="queue-count"><?php echo $waitingQueue->num_rows; ?></span>
        </div>
        <div class="queue-list">
            <?php if ($waitingQueue && $waitingQueue->num_rows > 0): ?>
                <?php while ($patient = $waitingQueue->fetch_assoc()): ?>
                <div class="queue-item" style="<?php 
                    if ($patient['delete_requested']) {
                        echo 'border-left: 4px solid #ff5252; background: #fff5f5;';
                    } elseif ($patient['is_pregnant']) {
                        echo 'border-left: 4px solid #ff9800; background: #fff8f0;';
                    } else {
                        echo '';
                    }
                ?>">
                    <div class="queue-item-info">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px; flex-wrap: wrap;">
                            <h4 style="margin: 0;"><?php echo $patient['first_name'] . ' ' . $patient['last_name']; ?></h4>
                            <?php if ($patient['is_pregnant']): ?>
                            <span class="badge badge-warning" style="font-size: 10px;">
                                <i class="fas fa-baby"></i> PREGNANT
                            </span>
                            <?php endif; ?>
                            <?php if ($patient['delete_requested']): ?>
                            <span class="badge badge-danger" style="font-size: 10px; background-color: #ff5252; color: white;">
                                <i class="fas fa-exclamation-triangle"></i> PENDING DELETION
                            </span>
                            <?php endif; ?>
                        </div>
                        <p>
                            <i class="fas fa-hashtag"></i> <?php echo $patient['queue_number']; ?> | 
                            <i class="fas fa-user"></i> <?php echo $patient['patient_code']; ?> |
                            <i class="fas fa-venus-mars"></i> <?php echo $patient['gender']; ?>
                        </p>
                        <?php if ($patient['is_pregnant'] && $patient['weeks_of_pregnancy']): ?>
                        <p style="font-size: 12px; color: #666;">
                            <i class="fas fa-calendar"></i> Weeks of pregnancy: <?php echo intval($patient['weeks_of_pregnancy']); ?> |
                            <i class="fas fa-calendar-alt"></i> EDD: <?php echo formatDate($patient['expected_due_date']); ?>
                        </p>
                        <?php endif; ?>
                        <?php if ($patient['chief_complaint']): ?>
                        <p style="font-style: italic; color: var(--primary-color);">
                            <i class="fas fa-comment-medical"></i> <?php echo substr($patient['chief_complaint'], 0, 40); ?><?php echo strlen($patient['chief_complaint']) > 40 ? '...' : ''; ?>
                        </p>
                        <?php endif; ?>

                        <?php if ($patient['delete_requested']): ?>
                        <div style="background: #fff0f0; border-left: 3px solid #ff5252; padding: 8px 12px; margin-top: 8px; margin-bottom: 8px; border-radius: 4px; font-size: 12px; line-height: 1.4;">
                            <strong style="color: #c62828;">Delete Requested By:</strong> <?php echo htmlspecialchars($patient['requester_name'] ?? 'Staff'); ?><br>
                            <strong style="color: #c62828;">Reason:</strong> <span style="font-style: italic; color: #555;"><?php echo htmlspecialchars($patient['delete_reason']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (hasRole(['admin', 'staff', 'reception', 'nurse'])): ?>
                        <div class="queue-item-actions" style="margin-top: 8px; display: flex; gap: 8px; flex-wrap: wrap;">
                            <?php if ($patient['delete_requested']): ?>
                                <?php if (hasRole('admin')): ?>
                                    <a href="visit-delete.php?id=<?php echo $patient['id']; ?>" class="btn btn-danger" style="padding: 11px 8px; font-size: 11px; line-height: 1;">
                                        <i class="fas fa-check"></i> Confirm Delete
                                    </a>
                                    <a href="visit-delete.php?id=<?php echo $patient['id']; ?>&action=reject_delete" class="btn btn-secondary" style="padding: 11px 8px; font-size: 11px; line-height: 1; background: #6c757d; color: white; border: none; border-radius: 4px; text-decoration: none;">
                                        <i class="fas fa-times"></i> Reject Request
                                    </a>
                                <?php else: ?>
                                    <a href="visit-delete.php?id=<?php echo $patient['id']; ?>&action=cancel_request" class="btn btn-secondary" style="padding: 11px 8px; font-size: 11px; line-height: 1; background: #6c757d; color: white; border: none; border-radius: 4px; text-decoration: none;">
                                        <i class="fas fa-undo"></i> Cancel Request
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="visit-edit.php?id=<?php echo $patient['id']; ?>&redirect=queue" class="btn btn-warning" style="padding: 11px 8px; font-size: 11px; line-height: 1;">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <a href="visit-delete.php?id=<?php echo $patient['id']; ?>" class="btn btn-danger" style="padding: 11px 8px; font-size: 11px; line-height: 1;" onclick="return confirm('<?php echo hasRole('admin') ? 'Are you sure you want to permanently delete this patient from the queue?' : 'Are you sure you want to request deletion for this patient?'; ?>');">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="queue-number"><?php echo $patient['queue_number']; ?></div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="padding: 30px; text-align: center; color: #999;">
                    <i class="fas fa-check-circle" style="font-size: 36px; margin-bottom: 10px;"></i>
                    <p>No waiting patients</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- In Assessment -->
    <div class="queue-section">
        <div class="queue-header" style="background: var(--warning-color); color: #333;">
            <h3><i class="fas fa-heartbeat"></i> In Assessment</h3>
            <span class="queue-count"><?php echo $inTriage->num_rows; ?></span>
        </div>
        <div class="queue-list">
            <?php if ($inTriage && $inTriage->num_rows > 0): ?>
                <?php while ($patient = $inTriage->fetch_assoc()): ?>
                <div class="queue-item">
                    <div class="queue-item-info">
                        <h4><?php echo $patient['first_name'] . ' ' . $patient['last_name']; ?></h4>
                        <p>
                            <i class="fas fa-hashtag"></i> <?php echo $patient['queue_number']; ?> | 
                            <i class="fas fa-user"></i> <?php echo $patient['patient_code']; ?>
                        </p>
                        <?php if (hasRole(['admin'])): ?>
                        <div class="queue-item-actions" style="margin-top: 10px; display: flex; gap: 8px;">
                            <a href="visit-edit.php?id=<?php echo $patient['id']; ?>&redirect=queue" class="btn btn-warning" style="padding: 11px 8px; font-size: 11px; line-height: 1;">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="visit-delete.php?id=<?php echo $patient['id']; ?>" class="btn btn-danger" style="padding: 11px 8px; font-size: 11px; line-height: 1;" onclick="return confirm('Are you sure you want to remove this patient from the queue?');">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="queue-number"><?php echo $patient['queue_number']; ?></div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="padding: 30px; text-align: center; color: #999;">
                    <i class="fas fa-info-circle" style="font-size: 36px; margin-bottom: 10px;"></i>
                    <p>No patients in assessment</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- In Consultation -->
    <div class="queue-section">
        <div class="queue-header" style="background: var(--primary-color);">
            <h3><i class="fas fa-stethoscope"></i> In Consultation</h3>
            <span class="queue-count"><?php echo $inConsultation->num_rows; ?></span>
        </div>
        <div class="queue-list">
            <?php if ($inConsultation && $inConsultation->num_rows > 0): ?>
                <?php while ($patient = $inConsultation->fetch_assoc()): ?>
                <div class="queue-item">
                    <div class="queue-item-info">
                        <h4><?php echo $patient['first_name'] . ' ' . $patient['last_name']; ?></h4>
                        <p>
                            <i class="fas fa-hashtag"></i> <?php echo $patient['queue_number']; ?> | 
                            <i class="fas fa-user-md"></i> <?php echo $patient['doctor_name'] ?: 'Pending'; ?>
                        </p>
                        <?php if (hasRole(['admin'])): ?>
                        <div class="queue-item-actions" style="margin-top: 10px; display: flex; gap: 8px;">
                            <a href="visit-edit.php?id=<?php echo $patient['id']; ?>&redirect=queue" class="btn btn-warning" style="padding: 11px 8px; font-size: 11px; line-height: 1;">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="visit-delete.php?id=<?php echo $patient['id']; ?>" class="btn btn-danger" style="padding: 11px 8px; font-size: 11px; line-height: 1;" onclick="return confirm('Are you sure you want to remove this patient from the queue?');">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="queue-number"><?php echo $patient['queue_number']; ?></div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="padding: 30px; text-align: center; color: #999;">
                    <i class="fas fa-info-circle" style="font-size: 36px; margin-bottom: 10px;"></i>
                    <p>No patients in consultation</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- In Laboratory -->
    <div class="queue-section">
        <div class="queue-header" style="background: var(--secondary-color);">
            <h3><i class="fas fa-vials"></i> In Laboratory</h3>
            <span class="queue-count"><?php echo $inLaboratory->num_rows; ?></span>
        </div>
        <div class="queue-list">
            <?php if ($inLaboratory && $inLaboratory->num_rows > 0): ?>
                <?php while ($patient = $inLaboratory->fetch_assoc()): ?>
                <div class="queue-item">
                    <div class="queue-item-info">
                        <h4><?php echo $patient['first_name'] . ' ' . $patient['last_name']; ?></h4>
                        <p>
                            <i class="fas fa-hashtag"></i> <?php echo $patient['queue_number']; ?> | 
                            <i class="fas fa-user"></i> <?php echo $patient['patient_code']; ?>
                        </p>
                        <?php if (hasRole(['admin'])): ?>
                        <div class="queue-item-actions" style="margin-top: 8px; display: flex; gap: 8px;">
                            <a href="visit-edit.php?id=<?php echo $patient['id']; ?>&redirect=queue" class="btn btn-warning" style="padding: 11px 8px; font-size: 11px; line-height: 1;">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="visit-delete.php?id=<?php echo $patient['id']; ?>" class="btn btn-danger" style="padding: 11px 8px; font-size: 11px; line-height: 1;" onclick="return confirm('Are you sure you want to remove this patient from the queue?');">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="queue-number"><?php echo $patient['queue_number']; ?></div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="padding: 30px; text-align: center; color: #999;">
                    <i class="fas fa-info-circle" style="font-size: 36px; margin-bottom: 10px;"></i>
                    <p>No patients in laboratory</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Admitted Patients -->
    <div class="queue-section">
        <div class="queue-header" style="background: var(--accent-color);">
            <h3><i class="fas fa-bed"></i> Admitted Patients</h3>
            <span class="queue-count"><?php echo $admittedQueue->num_rows; ?></span>
        </div>
        <div class="queue-list">
            <?php if ($admittedQueue && $admittedQueue->num_rows > 0): ?>
                <?php while ($patient = $admittedQueue->fetch_assoc()): ?>
                <div class="queue-item" style="border-left: 4px solid var(--accent-color); background: rgba(21, 137, 123, 0.03);">
                    <div class="queue-item-info">
                        <h4 style="margin: 0 0 8px;"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h4>
                        <p>
                            <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($patient['queue_number'] ?: 'N/A'); ?> | 
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($patient['patient_code']); ?> |
                            <i class="fas fa-venus-mars"></i> <?php echo htmlspecialchars($patient['gender']); ?>
                        </p>
                        <p style="font-size: 12px; color: var(--text-muted); margin-top: 6px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <span><i class="fas fa-door-open" style="margin-right: 4px;"></i> Room: <?php echo $patient['room_number'] ? '<strong>' . htmlspecialchars($patient['room_number']) . '</strong>' : '<span style="color: var(--warning-color); font-style: italic;">Not Assigned</span>'; ?></span>
                            <?php if ($patient['admission_code']): ?>
                                <span><i class="fas fa-file-invoice" style="margin-right: 4px;"></i> Code: <strong><?php echo htmlspecialchars($patient['admission_code']); ?></strong></span>
                            <?php endif; ?>
                        </p>
                        
                        <?php if (hasRole(['admin', 'doctor', 'nurse'])): ?>
                        <div class="queue-item-actions" style="margin-top: 10px; display: flex; gap: 8px;">
                            <?php if ($patient['admission_id']): ?>
                                <a href="../admission/admission-view.php?id=<?php echo $patient['admission_id']; ?>" class="btn btn-sm btn-primary" style="padding: 6px 12px; font-size: 11px; line-height: 1.2; text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; background-color: var(--primary-color); border: none; color: white;">
                                    <i class="fas fa-eye"></i> View Detail
                                </a>
                            <?php endif; ?>
                            <?php if (hasRole(['admin'])): ?>
                                <a href="visit-edit.php?id=<?php echo $patient['id']; ?>&redirect=queue" class="btn btn-sm btn-warning" style="padding: 6px 12px; font-size: 11px; line-height: 1.2; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="queue-number"><?php echo htmlspecialchars($patient['queue_number'] ?: 'N/A'); ?></div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="padding: 30px; text-align: center; color: #999;">
                    <i class="fas fa-info-circle" style="font-size: 36px; margin-bottom: 10px;"></i>
                    <p>No patients currently admitted</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Completed -->
    <div class="queue-section">
        <div class="queue-header" style="background: var(--success-color);">
            <h3><i class="fas fa-check-circle"></i> Completed Today</h3>
            <span class="queue-count"><?php echo $completed->num_rows; ?></span>
        </div>
        <div class="queue-list">
            <?php if ($completed && $completed->num_rows > 0): ?>
                <?php while ($patient = $completed->fetch_assoc()): ?>
                <div class="queue-item">
                    <div class="queue-item-info">
                        <h4><?php echo $patient['first_name'] . ' ' . $patient['last_name']; ?></h4>
                        <p>
                            <i class="fas fa-hashtag"></i> <?php echo $patient['queue_number']; ?> | 
                            <?php echo getStatusBadge($patient['status']); ?>
                        </p>
                        <?php if (hasRole(['admin'])): ?>
                        <div class="queue-item-actions" style="margin-top: 8px; display: flex; gap: 8px;">
                            <a href="visit-edit.php?id=<?php echo $patient['id']; ?>&redirect=queue" class="btn btn-warning" style="padding: 4px 8px; font-size: 11px; line-height: 1;">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="visit-delete.php?id=<?php echo $patient['id']; ?>" class="btn btn-danger" style="padding: 4px 8px; font-size: 11px; line-height: 1;" onclick="return confirm('Are you sure you want to remove this patient from the queue?');">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="queue-number"><?php echo $patient['queue_number']; ?></div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="padding: 30px; text-align: center; color: #999;">
                    <i class="fas fa-info-circle" style="font-size: 36px; margin-bottom: 10px;"></i>
                    <p>No completed visits yet</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
     <!-- Active Pregnancies -->
    <div class="queue-section active-pregnancy-section" style="grid-column: 1 / -1;">
        <div class="queue-header" style="background: linear-gradient(135deg, #ff9800, #e53935);">
            <h3><i class="fas fa-baby"></i> Active Pregnancies</h3>
            <span class="queue-count"><?php echo $activeMaternityQueue->num_rows; ?></span>
        </div>
        <div class="queue-list active-pregnancy-list">
            <?php if ($activeMaternityQueue && $activeMaternityQueue->num_rows > 0): ?>
                <?php while ($patient = $activeMaternityQueue->fetch_assoc()): ?>
                <?php
                    $priorityClassMap = [
                        'emergency' => 'danger',
                        'high' => 'warning',
                        'normal' => 'primary',
                        'low' => 'secondary'
                    ];
                    $priorityClass = $priorityClassMap[$patient['priority']] ?? 'secondary';
                    $queueStatus = $patient['visit_status'] ?? 'not-in-queue';
                    $queueNumber = $patient['queue_number'] ?? 'N/A';
                ?>
                <div class="queue-item" style="border-left: 4px solid #ff9800; background: #fff8f0;">
                    <div class="queue-item-info">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px; flex-wrap: wrap;">
                            <h4 style="margin: 0;"><?php echo $patient['first_name'] . ' ' . $patient['last_name']; ?></h4>
                            <span class="badge badge-warning" style="font-size: 10px;">
                                <i class="fas fa-baby"></i> ACTIVE PREGNANCY
                            </span>
                            <?php echo getStatusBadge($queueStatus); ?>
                        </div>
                        <p>
                            <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($queueNumber); ?> | 
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($patient['patient_code']); ?> |
                            <i class="fas fa-venus-mars"></i> <?php echo htmlspecialchars($patient['gender']); ?>
                        </p>
                        <p style="font-size: 12px; color: #666;">
                            <i class="fas fa-calendar"></i> Weeks pregnant: <strong><?php echo intval($patient['weeks_of_pregnancy']); ?></strong> |
                            <i class="fas fa-calendar-alt"></i> EDD: <?php echo formatDate($patient['expected_due_date']); ?>
                        </p>
                        <p style="font-size: 12px; color: #666;">
                            <i class="fas fa-exclamation-circle"></i> Priority:
                            <span class="badge badge-<?php echo $priorityClass; ?>" style="text-transform: uppercase; font-size: 10px;">
                                <?php echo htmlspecialchars(strtoupper($patient['priority'] ?? 'normal')); ?>
                            </span>
                        </p>
                    </div>
                    <div class="queue-number"><?php echo htmlspecialchars($queueNumber); ?></div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="padding: 30px; text-align: center; color: #999;">
                    <i class="fas fa-baby" style="font-size: 36px; margin-bottom: 10px;"></i>
                    <p>No active pregnancies found</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>



<?php include __DIR__ . '/../../includes/footer.php'; ?>
