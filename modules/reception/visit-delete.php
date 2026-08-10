<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'staff', 'reception']);

$conn = getDBConnection();
$visitId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$redirectUrl = sanitize($_GET['redirect'] ?? 'queue');

// Verify visit exists
$visitRes = $conn->query("
    SELECT v.*, p.first_name, p.last_name, p.patient_code
    FROM patient_visits v
    JOIN patients p ON v.patient_id = p.id
    WHERE v.id = $visitId
    LIMIT 1
");

if (!$visitRes || $visitRes->num_rows === 0) {
    setFlashMessage('error', 'Visit not found.');
    redirect('modules/reception/' . $redirectUrl . '.php');
}

$visit = $visitRes->fetch_assoc();
$patientName = $visit['first_name'] . ' ' . $visit['last_name'];
$patientCode = $visit['patient_code'];
$visitStatus = $visit['status'];

// Security: Only admin can delete active/in-process visits
// Reception staff can only delete "waiting" status visits
if (!hasRole('admin') && $visitStatus !== 'waiting') {
    setFlashMessage('error', 'Only administrators can delete active or completed visits. This visit is currently "' . ucfirst($visitStatus) . '".');
    $conn->close();
    redirect('modules/reception/' . $redirectUrl . '.php');
}

$action = isset($_GET['action']) ? sanitize($_GET['action']) : '';

if ($action === 'reject_delete') {
    if (!hasRole('admin')) {
        setFlashMessage('error', 'Only administrators can reject deletion requests.');
        $conn->close();
        redirect('modules/reception/' . $redirectUrl . '.php');
    }
    
    $stmt = $conn->prepare("UPDATE patient_visits SET delete_requested = 0, delete_requested_by = NULL, delete_reason = NULL WHERE id = ?");
    $stmt->bind_param("i", $visitId);
    if ($stmt->execute()) {
        logActivity('reject_delete', 'patient_visits', $visitId, null, json_encode([
            'patient_id' => $visit['patient_id'],
            'patient_code' => $patientCode,
            'patient_name' => $patientName
        ]));
        setFlashMessage('success', "Deletion request for {$patientName} ({$patientCode}) has been rejected. Patient remains in the queue.");
    } else {
        setFlashMessage('error', "Failed to reject deletion request.");
    }
    $stmt->close();
    $conn->close();
    redirect('modules/reception/' . $redirectUrl . '.php');
}

if ($action === 'cancel_request') {
    $stmt = $conn->prepare("UPDATE patient_visits SET delete_requested = 0, delete_requested_by = NULL, delete_reason = NULL WHERE id = ?");
    $stmt->bind_param("i", $visitId);
    if ($stmt->execute()) {
        logActivity('cancel_delete_request', 'patient_visits', $visitId, null, json_encode([
            'patient_id' => $visit['patient_id'],
            'patient_code' => $patientCode,
            'patient_name' => $patientName
        ]));
        setFlashMessage('success', "Deletion request for {$patientName} ({$patientCode}) has been cancelled.");
    } else {
        setFlashMessage('error', "Failed to cancel deletion request.");
    }
    $stmt->close();
    $conn->close();
    redirect('modules/reception/' . $redirectUrl . '.php');
}

// Handle confirmation (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hasRole('admin')) {
        $confirmDelete = isset($_POST['confirm_delete']) && $_POST['confirm_delete'] === 'yes';
        
        if (!$confirmDelete) {
            setFlashMessage('info', 'Visit deletion cancelled.');
            $conn->close();
            redirect('modules/reception/' . $redirectUrl . '.php');
        }

        // Start transaction for safe deletion
        $conn->begin_transaction();
        
        try {
            // Log the deletion before we delete
            logActivity('delete', 'patient_visits', $visitId, null, json_encode([
                'patient_id' => $visit['patient_id'],
                'patient_code' => $patientCode,
                'patient_name' => $patientName,
                'visit_status' => $visitStatus,
                'visit_date' => $visit['visit_date'],
                'chief_complaint' => $visit['chief_complaint']
            ]));

            // Delete the visit
            // Note: Cascade will automatically delete:
            // - triage_records (if any)
            // - consultations (if any)
            // - lab_requests (if any, with CASCADE)
            // - admissions (if any, with CASCADE)
            // - prescriptions (if any, may have SET NULL)
            // - other related records depending on foreign key constraints
            
            $stmt = $conn->prepare("DELETE FROM patient_visits WHERE id = ?");
            $stmt->bind_param("i", $visitId);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to delete visit: " . $stmt->error);
            }
            
            $stmt->close();

            // Commit transaction
            $conn->commit();

            setFlashMessage('success', "Visit for {$patientName} ({$patientCode}) has been removed from queue successfully.");
            
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            setFlashMessage('error', 'Error deleting visit: ' . $e->getMessage());
        }
        
        $conn->close();
        redirect('modules/reception/' . $redirectUrl . '.php');
    } else {
        $confirmRequest = isset($_POST['confirm_request']) && $_POST['confirm_request'] === 'yes';
        $deleteReason = isset($_POST['delete_reason']) ? sanitize($_POST['delete_reason']) : '';

        if (!$confirmRequest) {
            setFlashMessage('info', 'Deletion request cancelled.');
            $conn->close();
            redirect('modules/reception/' . $redirectUrl . '.php');
        }

        $stmt = $conn->prepare("UPDATE patient_visits SET delete_requested = 1, delete_requested_by = ?, delete_reason = ? WHERE id = ?");
        $userId = $_SESSION['user_id'];
        $stmt->bind_param("isi", $userId, $deleteReason, $visitId);

        if ($stmt->execute()) {
            logActivity('request_delete', 'patient_visits', $visitId, null, json_encode([
                'patient_id' => $visit['patient_id'],
                'patient_code' => $patientCode,
                'patient_name' => $patientName,
                'visit_status' => $visitStatus,
                'delete_reason' => $deleteReason
            ]));
            setFlashMessage('success', "Deletion request for {$patientName} ({$patientCode}) has been submitted to administrators.");
        } else {
            setFlashMessage('error', 'Failed to submit deletion request.');
        }

        $stmt->close();
        $conn->close();
        redirect('modules/reception/' . $redirectUrl . '.php');
    }
}

// If we reach here, show confirmation page (GET request)
$conn->close();
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo hasRole('admin') ? 'Delete Visit Confirmation' : 'Request Deletion Confirmation'; ?></h1>
        <p class="page-subtitle"><?php echo hasRole('admin') ? 'Confirm removal of patient from queue' : 'Submit deletion request to administrator'; ?></p>
    </div>
    <a href="modules/reception/<?php echo htmlspecialchars($redirectUrl); ?>.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<div style="max-width: 600px; margin: 0 auto;">
    <!-- Warning Alert -->
    <div style="background: <?php echo hasRole('admin') ? '#fff3cd' : '#e8f4fd'; ?>; border: 1px solid <?php echo hasRole('admin') ? '#ffc107' : '#b8daff'; ?>; border-radius: 8px; padding: 20px; margin-bottom: 25px;">
        <div style="display: flex; gap: 15px; align-items: flex-start;">
            <div style="font-size: 28px; color: <?php echo hasRole('admin') ? '#ff9800' : '#004085'; ?>;">
                <i class="fas <?php echo hasRole('admin') ? 'fa-exclamation-triangle' : 'fa-info-circle'; ?>"></i>
            </div>
            <div>
                <h4 style="margin: 0 0 8px; color: #333;"><?php echo hasRole('admin') ? 'Warning: Permanent Action' : 'Administrator Approval Required'; ?></h4>
                <p style="margin: 0; color: #666; line-height: 1.6;">
                    <?php if (hasRole('admin')): ?>
                        You are about to delete a patient visit record. This action <strong>cannot be undone</strong>.
                    <?php else: ?>
                        Since you are not an administrator, you cannot delete this visit immediately. Submitting this request will flag the patient in the queue for administrator confirmation.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Visit Information Card -->
    <div class="card" style="margin-bottom: 25px;">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-user-circle"></i> Patient Information</h3>
        </div>
        <div class="card-body">
            <div style="display: grid; gap: 15px;">
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 15px; align-items: start;">
                    <strong style="color: #666;">Patient Name:</strong>
                    <span style="font-size: 16px; font-weight: 500;"><?php echo htmlspecialchars($patientName); ?></span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 15px; align-items: start;">
                    <strong style="color: #666;">Patient Code:</strong>
                    <span style="font-size: 16px; font-weight: 500;"><?php echo htmlspecialchars($patientCode); ?></span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 15px; align-items: start;">
                    <strong style="color: #666;">Visit Status:</strong>
                    <span style="font-size: 16px;">
                        <?php echo getStatusBadge($visitStatus); ?>
                    </span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 15px; align-items: start;">
                    <strong style="color: #666;">Visit Date:</strong>
                    <span style="font-size: 16px;"><?php echo formatDate($visit['visit_date']); ?></span>
                </div>
                <div style="display: grid; grid-template-columns: 150px 1fr; gap: 15px; align-items: start;">
                    <strong style="color: #666;">Chief Complaint:</strong>
                    <span style="font-size: 14px; color: #555;">
                        <?php echo $visit['chief_complaint'] ? htmlspecialchars($visit['chief_complaint']) : '<em style="color: #999;">No complaint recorded</em>'; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- What Will Be Deleted -->
    <div class="card" style="margin-bottom: 25px; border-left: 4px solid #ff5252;">
        <div class="card-header" style="background: #fff5f5;">
            <h3 class="card-title" style="color: #d32f2f;">
                <i class="fas fa-trash-alt"></i> <?php echo hasRole('admin') ? 'What Will Be Deleted' : 'What Will Be Deleted (Upon Approval)'; ?>
            </h3>
        </div>
        <div class="card-body">
            <p style="color: #666; margin-bottom: 15px;">
                When this visit record is deleted, the following related records will also be automatically deleted:
            </p>
            <ul style="margin: 0; padding-left: 20px; color: #555; line-height: 1.8;">
                <li><i class="fas fa-circle" style="font-size: 6px; margin-right: 8px; color: #ff5252;"></i> This patient's <strong>visit record</strong></li>
                <li><i class="fas fa-circle" style="font-size: 6px; margin-right: 8px; color: #ff5252;"></i> Any <strong>triage assessment</strong> done for this visit</li>
                <li><i class="fas fa-circle" style="font-size: 6px; margin-right: 8px; color: #ff5252;"></i> Any <strong>consultation records</strong> associated with this visit</li>
                <li><i class="fas fa-circle" style="font-size: 6px; margin-right: 8px; color: #ff5252;"></i> Any <strong>laboratory requests</strong> from this visit</li>
                <li><i class="fas fa-circle" style="font-size: 6px; margin-right: 8px; color: #ff5252;"></i> Any <strong>admission records</strong> from this visit</li>
                <li><i class="fas fa-circle" style="font-size: 6px; margin-right: 8px; color: #ff5252;"></i> Associated <strong>medical records</strong> and notes</li>
            </ul>
            <div style="background: #f5f5f5; border-left: 4px solid #ff9800; padding: 12px; margin-top: 15px; border-radius: 4px;">
                <strong style="color: #ff6f00;">Note:</strong> <span style="color: #666;">The patient record will NOT be deleted, only this specific visit.</span>
            </div>
        </div>
    </div>

    <!-- Restrictions for This Status -->
    <?php if ($visitStatus !== 'waiting'): ?>
    <div class="card" style="margin-bottom: 25px; border-left: 4px solid #ff9800;">
        <div class="card-header" style="background: #fff8f0;">
            <h3 class="card-title" style="color: #e65100;">
                <i class="fas fa-info-circle"></i> Admin-Only Deletion
            </h3>
        </div>
        <div class="card-body">
            <p style="color: #666; margin: 0;">
                This visit is currently "<strong><?php echo ucfirst($visitStatus); ?></strong>". 
                Only <strong>Administrators</strong> can delete visits in this status. 
                Regular staff can only remove "waiting" patients from the queue.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Confirmation Form -->
    <form method="POST" action="">
        <?php if (hasRole('admin')): ?>
        <div style="background: #f5f5f5; padding: 20px; border-radius: 8px; margin-bottom: 25px;">
            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                <input type="checkbox" id="confirm_checkbox" name="confirm_delete" value="yes" 
                       style="width: 20px; height: 20px; cursor: pointer;">
                <label for="confirm_checkbox" style="cursor: pointer; color: #333; font-weight: 500; margin: 0;">
                    I understand this action will permanently delete this visit record and all associated data
                </label>
            </div>
            
            <div style="display: flex; align-items: center; gap: 15px;">
                <input type="checkbox" id="understand_checkbox" style="width: 20px; height: 20px; cursor: pointer;">
                <label for="understand_checkbox" style="cursor: pointer; color: #333; font-weight: 500; margin: 0;">
                    I confirm I want to proceed with deletion
                </label>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 15px;">
            <a href="modules/reception/<?php echo htmlspecialchars($redirectUrl); ?>.php" class="btn btn-secondary" style="flex: 1; text-align: center;">
                <i class="fas fa-times"></i> Cancel - Do Not Delete
            </a>
            <button type="submit" class="btn btn-danger" id="deleteBtn" disabled 
                    style="flex: 1; opacity: 0.5; cursor: not-allowed;">
                <i class="fas fa-trash-alt"></i> Delete This Visit
            </button>
        </div>
        <?php else: ?>
        <div style="background: #f5f5f5; padding: 20px; border-radius: 8px; margin-bottom: 25px;">
            <div class="form-group" style="margin-bottom: 15px;">
                <label for="delete_reason" style="display: block; font-weight: 500; color: #333; margin-bottom: 8px;">Reason for Deletion Request <span style="color: red;">*</span></label>
                <textarea id="delete_reason" name="delete_reason" class="form-control" rows="3" placeholder="Explain why this patient needs to be removed from the queue (e.g. duplicate entry, patient left, incorrect department)" required style="width: 100%; border: 1px solid #ccc; border-radius: 4px; padding: 10px;"></textarea>
            </div>
            <div style="display: flex; align-items: center; gap: 15px;">
                <input type="checkbox" id="confirm_request_checkbox" name="confirm_request" value="yes" 
                       style="width: 20px; height: 20px; cursor: pointer;">
                <label for="confirm_request_checkbox" style="cursor: pointer; color: #333; font-weight: 500; margin: 0;">
                    I confirm I want to request the removal of this patient from the queue
                </label>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 15px;">
            <a href="modules/reception/<?php echo htmlspecialchars($redirectUrl); ?>.php" class="btn btn-secondary" style="flex: 1; text-align: center;">
                <i class="fas fa-times"></i> Cancel
            </a>
            <button type="submit" class="btn btn-warning" id="requestBtn" disabled 
                    style="flex: 1; opacity: 0.5; cursor: not-allowed; color: #333;">
                <i class="fas fa-paper-plane"></i> Submit Deletion Request
            </button>
        </div>
        <?php endif; ?>
    </form>

    <!-- Additional Info -->
    <div style="background: #e3f2fd; border: 1px solid #90caf9; border-radius: 8px; padding: 15px; margin-top: 25px; color: #1565c0;">
        <i class="fas fa-lightbulb" style="margin-right: 8px;"></i>
        <strong>Tip:</strong> Instead of deleting, you can edit the visit to change its status to "cancelled" if you want to keep a record.
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const confirmCheckbox = document.getElementById('confirm_checkbox');
    const understandCheckbox = document.getElementById('understand_checkbox');
    const deleteBtn = document.getElementById('deleteBtn');

    const confirmRequestCheckbox = document.getElementById('confirm_request_checkbox');
    const requestBtn = document.getElementById('requestBtn');
    const deleteReason = document.getElementById('delete_reason');

    if (confirmCheckbox && understandCheckbox && deleteBtn) {
        function updateDeleteButton() {
            const allChecked = confirmCheckbox.checked && understandCheckbox.checked;
            deleteBtn.disabled = !allChecked;
            deleteBtn.style.opacity = allChecked ? '1' : '0.5';
            deleteBtn.style.cursor = allChecked ? 'pointer' : 'not-allowed';
        }

        confirmCheckbox.addEventListener('change', updateDeleteButton);
        understandCheckbox.addEventListener('change', updateDeleteButton);

        // Prevent accidental submission
        deleteBtn.addEventListener('click', function(e) {
            if (!confirmCheckbox.checked || !understandCheckbox.checked) {
                e.preventDefault();
                alert('Please confirm both checkboxes to proceed with deletion.');
            }
        });
    }

    if (confirmRequestCheckbox && requestBtn && deleteReason) {
        function updateRequestButton() {
            const isChecked = confirmRequestCheckbox.checked;
            const hasReason = deleteReason.value.trim().length > 0;
            const canSubmit = isChecked && hasReason;
            requestBtn.disabled = !canSubmit;
            requestBtn.style.opacity = canSubmit ? '1' : '0.5';
            requestBtn.style.cursor = canSubmit ? 'pointer' : 'not-allowed';
        }

        confirmRequestCheckbox.addEventListener('change', updateRequestButton);
        deleteReason.addEventListener('input', updateRequestButton);

        // Prevent accidental submission
        requestBtn.addEventListener('click', function(e) {
            if (!confirmRequestCheckbox.checked || deleteReason.value.trim().length === 0) {
                e.preventDefault();
                alert('Please provide a reason and confirm the checkbox to submit.');
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
