<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'staff', 'reception', 'nurse']);

$pageTitle = 'Edit Visit';
$currentPage = 'reception';

$conn = getDBConnection();
$visitId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($visitId <= 0) {
    setFlashMessage('error', 'Invalid visit selected.');
    redirect('modules/reception/visits.php');
}

// Load visit
$res = $conn->query("SELECT v.*, p.first_name, p.last_name, p.patient_code FROM patient_visits v JOIN patients p ON v.patient_id = p.id WHERE v.id = $visitId LIMIT 1");
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Visit not found.');
    $conn->close();
    redirect('modules/reception/visits.php');
}
$visit = $res->fetch_assoc();

// Security check: Only admin can edit non-waiting visits
if (!hasRole('admin') && $visit['status'] !== 'waiting') {
    setFlashMessage('error', 'Only administrators can edit active or completed queue visits.');
    $conn->close();
    redirect('modules/reception/queue.php');
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $visit_type = sanitize($_POST['visit_type'] ?? '');
    $visit_date = sanitize($_POST['visit_date'] ?? '');
    $status = sanitize($_POST['status'] ?? 'waiting');
    $priority = sanitize($_POST['priority'] ?? 'normal');
    $chief_complaint = sanitize($_POST['chief_complaint'] ?? '');
    $queue_number = sanitize($_POST['queue_number'] ?? '');
    $redirect_param = sanitize($_GET['redirect'] ?? '');

    if (empty(trim($chief_complaint))) {
        setFlashMessage('error', 'Chief Complaint / Reason for Assessment is required.');
    } else {
        $stmt = $conn->prepare("UPDATE patient_visits SET visit_type = ?, visit_date = ?, status = ?, priority = ?, chief_complaint = ?, queue_number = ? WHERE id = ?");
        $stmt->bind_param('ssssssi', $visit_type, $visit_date, $status, $priority, $chief_complaint, $queue_number, $visitId);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            logActivity('update', 'patient_visits', $visitId, null, json_encode($_POST));
            setFlashMessage('success', 'Visit updated successfully.');
            $conn->close();
            if ($redirect_param === 'queue') {
                redirect('modules/reception/queue.php');
            } else {
                redirect('modules/reception/visit-view.php?id=' . $visitId);
            }
        } else {
            setFlashMessage('error', 'Failed to update visit.');
        }
    }
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Edit Visit</h1>
        <p class="page-subtitle">Visit #<?php echo $visit['id']; ?> — <?php echo htmlspecialchars($visit['first_name'] . ' ' . $visit['last_name']); ?></p>
    </div>
    <div>
        <a href="<?php echo isset($_GET['redirect']) && $_GET['redirect'] === 'queue' ? 'queue.php' : 'visit-view.php?id=' . $visitId; ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="visit-edit.php?id=<?php echo $visitId; ?><?php echo isset($_GET['redirect']) ? '&redirect=' . urlencode($_GET['redirect']) : ''; ?>">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Visit Type</label>
                    <select name="visit_type" class="form-control" required>
                        <option value="walk-in" <?php echo ($visit['visit_type'] ?? '')=='walk-in' ? 'selected' : ''; ?>>Walk-in</option>
                        <option value="appointment" <?php echo ($visit['visit_type'] ?? '')=='appointment' ? 'selected' : ''; ?>>Appointment</option>
                        <option value="emergency" <?php echo ($visit['visit_type'] ?? '')=='emergency' ? 'selected' : ''; ?>>Emergency</option>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <label>Visit Date</label>
                    <input type="datetime-local" name="visit_date" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($visit['visit_date'])); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="waiting" <?php echo ($visit['status'] ?? '')=='waiting' ? 'selected' : ''; ?>>Waiting</option>
                        <option value="in-triage" <?php echo ($visit['status'] ?? '')=='in-triage' ? 'selected' : ''; ?>>In Assessment</option>
                        <option value="in-consultation" <?php echo ($visit['status'] ?? '')=='in-consultation' ? 'selected' : ''; ?>>In Consultation</option>
                        <option value="in-laboratory" <?php echo ($visit['status'] ?? '')=='in-laboratory' ? 'selected' : ''; ?>>In Laboratory</option>
                        <option value="in-treatment" <?php echo ($visit['status'] ?? '')=='in-treatment' ? 'selected' : ''; ?>>In Treatment</option>
                        <option value="admitted" <?php echo ($visit['status'] ?? '')=='admitted' ? 'selected' : ''; ?>>Admitted</option>
                        <option value="discharged" <?php echo ($visit['status'] ?? '')=='discharged' ? 'selected' : ''; ?>>Discharged</option>
                        <option value="cancelled" <?php echo ($visit['status'] ?? '')=='cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Queue Number</label>
                    <input type="text" name="queue_number" class="form-control" value="<?php echo htmlspecialchars($visit['queue_number'] ?? ''); ?>" required>
                </div>
                <div class="form-group col-md-4">
                    <label>Priority</label>
                    <select name="priority" class="form-control" required>
                        <option value="low" <?php echo ($visit['priority'] ?? '')=='low' ? 'selected' : ''; ?>>Low</option>
                        <option value="normal" <?php echo ($visit['priority'] ?? '')=='normal' ? 'selected' : ''; ?>>Normal</option>
                        <option value="high" <?php echo ($visit['priority'] ?? '')=='high' ? 'selected' : ''; ?>>High</option>
                        <option value="emergency" <?php echo ($visit['priority'] ?? '')=='emergency' ? 'selected' : ''; ?>>Emergency</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Chief Complaint / Reason for Assessment</label>
                <textarea name="chief_complaint" class="form-control" rows="3"><?php echo htmlspecialchars($visit['chief_complaint'] ?? ''); ?></textarea>
            </div>

            <div class="form-group text-right">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
