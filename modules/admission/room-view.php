<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse', 'doctor']);

$pageTitle = 'Room Details';
$currentPage = 'admissions';

$conn = getDBConnection();

$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
if ($room_id <= 0) {
    setFlashMessage('error', 'Invalid room id.');
    redirect('modules/admission/admissions.php');
}

// Load room info
$stmt = $conn->prepare('SELECT * FROM rooms WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $room_id);
$stmt->execute();
$roomRes = $stmt->get_result();
if (!$roomRes || $roomRes->num_rows === 0) {
    setFlashMessage('error', 'Room not found.');
    $stmt->close();
    $conn->close();
    redirect('modules/admission/admissions.php');
}
$room = $roomRes->fetch_assoc();
$stmt->close();

// Get admissions for this room (currently admitted)
$query = "
    SELECT a.*, p.first_name, p.last_name, p.patient_code, b.bed_number, u.full_name as doctor_name
    FROM admissions a
    JOIN patients p ON a.patient_id = p.id
    LEFT JOIN beds b ON a.bed_id = b.id
    JOIN users u ON a.doctor_id = u.id
    WHERE a.room_id = ? AND a.status = 'admitted'
    ORDER BY b.bed_number ASC, a.admission_date DESC
";
$stmt2 = $conn->prepare($query);
stmt2_check:
if (!$stmt2) {
    setFlashMessage('error', 'Failed to prepare admissions query: ' . $conn->error);
    $conn->close();
    redirect('modules/admission/admissions.php');
}
$stmt2->bind_param('i', $room_id);
$stmt2->execute();
$admissions = $stmt2->get_result();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Room <?php echo htmlspecialchars($room['room_number']); ?> — <?php echo htmlspecialchars(ucfirst($room['room_type'])); ?></h1>
        <p class="page-subtitle">Admitted patients in this room</p>
    </div>
    <div>
        <a href="admissions.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <?php if ($admissions && $admissions->num_rows > 0): ?>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Admission Code</th>
                    <th>Patient</th>
                    <th>Bed</th>
                    <th>Doctor</th>
                    <th>Admission Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($a = $admissions->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($a['admission_code'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($a['first_name'] . ' ' . $a['last_name']); ?><br><small class="text-muted"><?php echo htmlspecialchars($a['patient_code']); ?></small></td>
                    <td><?php echo $a['bed_number'] ? htmlspecialchars($a['bed_number']) : '<span class="text-muted">Not assigned</span>'; ?></td>
                    <td><?php echo htmlspecialchars($a['doctor_name'] ?? ''); ?></td>
                    <td><?php echo formatDateTime($a['admission_date']); ?></td>
                    <td>
                        <a href="admission-view.php?id=<?php echo intval($a['id']); ?>" class="btn btn-sm btn-info"><i class="fas fa-eye"></i></a>
                        <a href="monitoring-add.php?admission_id=<?php echo intval($a['id']); ?>" class="btn btn-sm btn-success"><i class="fas fa-notes-medical"></i></a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div style="padding:30px;text-align:center;color:#666;">No patients currently admitted in this room.</div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php';
