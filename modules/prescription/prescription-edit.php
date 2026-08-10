<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['doctor']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$conn = getDBConnection();

if ($id <= 0) {
    setFlashMessage('error', 'Invalid prescription selected.');
    redirect('modules/consultation/prescriptions.php');
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $medication = sanitize($_POST['medication'] ?? '');
    $dosage = sanitize($_POST['dosage'] ?? '');
    $frequency = sanitize($_POST['frequency'] ?? '');
    $duration = sanitize($_POST['duration'] ?? '');
    $instructions = sanitize($_POST['instructions'] ?? '');
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;
    $status = sanitize($_POST['status'] ?? 'pending');

    $stmt = $conn->prepare("UPDATE prescriptions SET medication_name = ?, dosage = ?, frequency = ?, duration = ?, instructions = ?, quantity = ?, status = ? WHERE id = ?");
    $stmt->bind_param('sssssisi', $medication, $dosage, $frequency, $duration, $instructions, $quantity, $status, $id);
    if ($stmt->execute()) {
        logActivity('update', 'prescriptions', $id, null, json_encode($_POST));
        setFlashMessage('success', 'Prescription updated.');
        $stmt->close();
        $conn->close();
        redirect('modules/consultation/prescriptions.php');
    } else {
        setFlashMessage('error', 'Failed to update prescription: ' . $stmt->error);
    }
    $stmt->close();
}

$res = $conn->query("SELECT pr.*, p.first_name, p.last_name, p.patient_code FROM prescriptions pr JOIN patients p ON pr.patient_id = p.id WHERE pr.id = $id LIMIT 1");
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Prescription not found.');
    $conn->close();
    redirect('modules/consultation/prescriptions.php');
}
$presc = $res->fetch_assoc();

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Edit Prescription</h1>
        <p class="page-subtitle">Edit prescription for <?php echo htmlspecialchars($presc['first_name'].' '.$presc['last_name']); ?></p>
    </div>
    <div>
        <a href="../consultation/prescriptions.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="">
            <div class="form-row">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Medication Name</label>
                    <input type="text" name="medication" class="form-control" required value="<?php echo htmlspecialchars($presc['medication_name']); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Dosage</label>
                    <input type="text" name="dosage" class="form-control" value="<?php echo htmlspecialchars($presc['dosage']); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Frequency</label>
                    <input type="text" name="frequency" class="form-control" value="<?php echo htmlspecialchars($presc['frequency']); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Duration</label>
                    <input type="text" name="duration" class="form-control" value="<?php echo htmlspecialchars($presc['duration']); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Quantity</label>
                    <input type="number" name="quantity" class="form-control" value="<?php echo (int)$presc['quantity']; ?>">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Instructions</label>
                    <input type="text" name="instructions" class="form-control" value="<?php echo htmlspecialchars($presc['instructions']); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="pending" <?php echo ($presc['status']==='pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="dispensed" <?php echo ($presc['status']==='dispensed') ? 'selected' : ''; ?>>Dispensed</option>
                        <option value="cancelled" <?php echo ($presc['status']==='cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
            </div>
            <div style="text-align:right; margin-top:12px;">
                <a href="../consultation/prescriptions.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php';
