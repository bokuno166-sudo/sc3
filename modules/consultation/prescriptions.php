<?php
require_once __DIR__ . '/../../config/config.php';
// Allow staff to view prescription list and print prescriptions without edit/manage access.
requireRole(['admin', 'doctor', 'cashier', 'pharmacist', 'nurse', 'staff']);

$pageTitle = 'Prescriptions';
$currentPage = 'consultations';

$conn = getDBConnection();

// Handle actions: dispense or cancel
$isAdmin = hasRole(['admin']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    if ($isAdmin) {
        setFlashMessage('error', 'Admin users can only view prescriptions.');
        redirect('modules/consultation/prescriptions.php');
    }

    $action = $_POST['action'];
    $id = (int)$_POST['id'];

    if ($action === 'dispense') {
        $stmt = $conn->prepare("UPDATE prescriptions SET status = 'dispensed' WHERE id = ?");
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            logActivity('update', 'prescriptions', $id, null, json_encode(['status'=>'dispensed']));
            setFlashMessage('success', 'Prescription marked as dispensed.');
        } else {
            setFlashMessage('error', 'Failed to update prescription: ' . $stmt->error);
        }
        $stmt->close();
    }

    if ($action === 'cancel') {
        $stmt = $conn->prepare("UPDATE prescriptions SET status = 'cancelled' WHERE id = ?");
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            logActivity('update', 'prescriptions', $id, null, json_encode(['status'=>'cancelled']));
            setFlashMessage('success', 'Prescription cancelled.');
        } else {
            setFlashMessage('error', 'Failed to cancel prescription: ' . $stmt->error);
        }
        $stmt->close();
    }

    // Delete prescription (hard delete)
    if ($action === 'delete') {
        $stmt = $conn->prepare("DELETE FROM prescriptions WHERE id = ?");
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            logActivity('delete', 'prescriptions', $id);
            setFlashMessage('success', 'Prescription deleted.');
        } else {
            setFlashMessage('error', 'Failed to delete prescription: ' . $stmt->error);
        }
        $stmt->close();
    }

    redirect('modules/consultation/prescriptions.php');
}

// Filters
$statusFilter = isset($_GET['status']) ? sanitize($_GET['status']) : '';

$query = "
    SELECT pr.*, p.first_name, p.last_name, p.patient_code, u.full_name as doctor_name, c.id as consultation_id, c.created_at as prescribed_at
    FROM prescriptions pr
    JOIN patients p ON pr.patient_id = p.id
    LEFT JOIN users u ON pr.doctor_id = u.id
    LEFT JOIN consultations c ON pr.consultation_id = c.id
";

if ($statusFilter) {
    $query .= " WHERE pr.status = '" . $conn->real_escape_string($statusFilter) . "'";
}

$query .= " ORDER BY pr.id DESC";

$prescResults = $conn->query($query);

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Prescriptions</h1>
        <p class="page-subtitle">Manage prescriptions and dispensing</p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Prescriptions</h3>
        <div style="margin-left:auto; display:flex; gap:8px; align-items:center;">
            <form method="GET" style="display:flex; gap:8px; align-items:center;">
                <select name="status" class="form-control">
                    <option value="">All</option>
                    <option value="pending" <?php echo $statusFilter==='pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="printed" <?php echo $statusFilter==='printed' ? 'selected' : ''; ?>>Printed</option>
                    <option value="dispensed" <?php echo $statusFilter==='dispensed' ? 'selected' : ''; ?>>Dispensed</option>
                    <option value="cancelled" <?php echo $statusFilter==='cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
                <button class="btn btn-primary">Filter</button>
            </form>
            <?php if (hasRole(['doctor'])): ?>
                <button id="createForVisitBtn" class="btn btn-success">Create Prescription for Visit</button>
            <?php endif; ?>
        </div>
        </div>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if ($prescResults && $prescResults->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Medication</th>
                        <th>Dosage / Frequency</th>
                        <th>Qty</th>
                        <th>Prescribed By</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($pr = $prescResults->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $pr['first_name'] . ' ' . $pr['last_name']; ?> <br><small class="text-muted"><?php echo $pr['patient_code']; ?></small></td>
                        <td><?php echo htmlspecialchars($pr['medication_name']); ?></td>
                        <td><?php echo htmlspecialchars($pr['dosage']) . ' / ' . htmlspecialchars($pr['frequency']); ?></td>
                        <td><?php echo (int)$pr['quantity']; ?></td>
                        <td><?php echo htmlspecialchars($pr['doctor_name']); ?></td>
                        <td><?php echo formatDateTime($pr['created_at'], 'M d, Y h:i A'); ?></td>
                        <td><?php
                            $badgeMap = [
                                'pending'   => 'warning',
                                'printed'   => 'info',
                                'dispensed' => 'success',
                                'cancelled' => 'secondary',
                            ];
                            $badgeClass = $badgeMap[$pr['status']] ?? 'secondary';
                            echo '<span class="badge badge-'.$badgeClass.'">' . ucfirst($pr['status']) . '</span>';
                        ?></td>
                        <td class="table-actions">
                            <?php if (!$isAdmin && hasRole(['doctor'])): ?>
                                <a href="../prescription/prescription-edit.php?id=<?php echo $pr['id']; ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if (!$isAdmin && hasRole(['pharmacist', 'nurse', 'cashier', 'staff'])): ?>
                                <a href="../prescription/prescription-print.php?id=<?php echo $pr['id']; ?>" target="_blank" class="btn btn-sm btn-primary" title="Print"><i class="fas fa-print"></i></a>
                            <?php endif; ?>

                            <?php if (!$isAdmin && $pr['status'] === 'pending' && hasRole(['doctor', 'pharmacist'])): ?>
                                <form method="POST" style="display:inline; margin-left:6px;">
                                    <input type="hidden" name="id" value="<?php echo $pr['id']; ?>">
                                    <input type="hidden" name="action" value="dispense">
                                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Mark as dispensed?')"><i class="fas fa-check"></i></button>
                                </form>
                                <form method="POST" style="display:inline; margin-left:6px;">
                                    <input type="hidden" name="id" value="<?php echo $pr['id']; ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Cancel this prescription?')"><i class="fas fa-times"></i></button>
                                </form>
                            <?php endif; ?>

                            <?php if (!$isAdmin && hasRole(['doctor'])): ?>
                                <span class="text-muted">View only</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding:30px; text-align:center; color:#999;">No prescriptions found</div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
<script>
var createBtn = document.getElementById('createForVisitBtn');
if (createBtn) {
    createBtn.addEventListener('click', function(){
        var vid = prompt('Enter Visit ID to create prescription for (e.g. 4):');
        if (!vid) return;
        vid = parseInt(vid, 10);
        if (isNaN(vid) || vid <= 0) { alert('Invalid visit id'); return; }
        window.location.href = '../prescription/prescription-create.php?visit_id=' + vid;
    });
}
</script>
