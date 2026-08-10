<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse', 'staff', 'reception']);

$pageTitle = 'Visit Details';
$currentPage = 'reception';

$conn = getDBConnection();
$visitId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($visitId <= 0) {
    setFlashMessage('error', 'Invalid visit selected.');
    redirect('modules/reception/visits.php');
}

$sql = "
    SELECT v.*, p.first_name, p.last_name, p.patient_code, p.date_of_birth, p.gender
    FROM patient_visits v
    JOIN patients p ON v.patient_id = p.id
    WHERE v.id = $visitId
    LIMIT 1
";
$res = $conn->query($sql);
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Visit not found.');
    $conn->close();
    redirect('modules/reception/visits.php');
}
$visit = $res->fetch_assoc();

// optional: load notes or attached consultations if tables exist
$notes = null;
$tbl = $conn->query("SHOW TABLES LIKE 'visit_notes'");
if ($tbl && $tbl->num_rows > 0) {
    $nRes = $conn->query("SELECT * FROM visit_notes WHERE visit_id = $visitId ORDER BY created_at DESC");
    $notes = $nRes;
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Visit Details</h1>
        <p class="page-subtitle">Visit #<?php echo $visit['id']; ?> — <?php echo htmlspecialchars($visit['first_name'] . ' ' . $visit['last_name']); ?></p>
    </div>
    <div>
        <a href="visits.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
        <a href="visit-edit.php?id=<?php echo $visitId; ?>" class="btn btn-warning"><i class="fas fa-edit"></i> Edit</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
            <div>
                <h4>Patient</h4>
                <p>
                    <strong><?php echo htmlspecialchars($visit['first_name'] . ' ' . $visit['last_name']); ?></strong><br>
                    <small class="text-muted"><?php echo htmlspecialchars($visit['patient_code']); ?></small><br>
                    <small class="text-muted"><?php echo calculateAge($visit['date_of_birth']); ?> yrs | <?php echo htmlspecialchars($visit['gender']); ?></small>
                </p>
            </div>

            <div>
                <h4>Visit Info</h4>
                <p>
                    <strong>Date:</strong> <?php echo formatDateTime($visit['visit_date']); ?><br>
                    <strong>Type:</strong> <?php echo htmlspecialchars($visit['visit_type'] ?? 'General'); ?><br>
                    <strong>Status:</strong> <?php echo ucwords(str_replace(['_', '-'], ' ', $visit['status'])); ?><br>
                    <strong>Queue #:</strong> <?php echo htmlspecialchars($visit['queue_number'] ?? '—'); ?>
                </p>
            </div>
        </div>

        <h4>Chief Complaint / Reason for Assessment</h4>
        <div style="padding:10px; background:#f8f9fa; border-radius:6px;">
            <?php echo nl2br(htmlspecialchars($visit['chief_complaint'] ?? 'No notes provided.')); ?>
        </div>

        <?php if ($notes && $notes->num_rows > 0): ?>
        <hr>
        <h4>Visit Notes</h4>
        <div>
            <?php while ($note = $notes->fetch_assoc()): ?>
                <div style="padding:10px; border-bottom:1px solid #eee;">
                    <small class="text-muted"><?php echo formatDateTime($note['created_at']); ?> by <?php echo htmlspecialchars($note['created_by'] ?? 'System'); ?></small>
                    <div style="margin-top:6px;"><?php echo nl2br(htmlspecialchars($note['note'])); ?></div>
                </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
