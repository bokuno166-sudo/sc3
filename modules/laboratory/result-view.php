<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'laboratory', 'doctor']);

$pageTitle = 'View Lab Result';
$currentPage = 'lab-results';

$conn = getDBConnection();

$requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
$resultId = isset($_GET['result_id']) ? (int)$_GET['result_id'] : 0;
// allow legacy 'id' parameter too
if (!$requestId && isset($_GET['id'])) $requestId = (int)$_GET['id'];

// if only a result id was provided, resolve to the request id
if (!$requestId && $resultId) {
    $tmp = $conn->query("SELECT request_id FROM laboratory_results WHERE id = " . (int)$resultId . " LIMIT 1");
    if ($tmp && $tmp->num_rows) {
        $rrow = $tmp->fetch_assoc();
        $requestId = (int)$rrow['request_id'];
    }
}

if (!$requestId) {
    setFlashMessage('error', 'Lab request not specified.');
    redirect('modules/laboratory/results.php');
}

$requestResult = $conn->query(
    "SELECT lr.*, r.request_code, r.visit_id as visit_id, p.*, lt.test_name, lt.category, lt.normal_range, u.full_name as doctor_name"
    . " FROM laboratory_requests r"
    . " JOIN patients p ON r.patient_id = p.id"
    . " JOIN laboratory_tests lt ON r.test_id = lt.id"
    . " LEFT JOIN users u ON r.doctor_id = u.id"
    . " LEFT JOIN laboratory_results lr ON lr.request_id = r.id"
    . " WHERE r.id = " . (int)$requestId
);

if (!$requestResult || $requestResult->num_rows === 0) {
    setFlashMessage('error', 'Lab request/result not found.');
    redirect('modules/laboratory/results.php');
}

$row = $requestResult->fetch_assoc();

// If current user is a doctor, record that they viewed this lab request/result
$docId = (int)($_SESSION['user_id'] ?? 0);
if ($docId && hasRole(['doctor'])) {
    // ensure tracking table exists
    $conn->query("CREATE TABLE IF NOT EXISTS doctor_lab_views (
        id INT AUTO_INCREMENT PRIMARY KEY,
        visit_id INT NOT NULL,
        request_id INT NOT NULL,
        doctor_id INT NOT NULL,
        viewed_at DATETIME NOT NULL,
        UNIQUE KEY uq_req_doc (request_id, doctor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $viewStmt = $conn->prepare("INSERT INTO doctor_lab_views (visit_id, request_id, doctor_id, viewed_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE viewed_at = NOW()");
    if ($viewStmt) {
        $visitIdForView = isset($row['visit_id']) ? (int)$row['visit_id'] : 0;
        $viewStmt->bind_param('iii', $visitIdForView, $requestId, $docId);
        $viewStmt->execute();
        $viewStmt->close();
    }
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Laboratory Result</h1>
        <p class="page-subtitle"><?php echo htmlspecialchars($row['test_name'] . ' for ' . ($row['first_name'] . ' ' . $row['last_name'])); ?></p>
    </div>
    <div>
        <a href="results.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
        <?php if (!empty($row['visit_id'])): ?>
            <a href="../consultation/consultation-start.php?visit_id=<?php echo (int)$row['visit_id']; ?>" class="btn btn-primary" style="margin-left:8px;"><i class="fas fa-stethoscope"></i> View in Consultation</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Result Details</h3>
    </div>
    <div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Patient</label>
                <p><strong><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></strong></p>
                <p class="text-muted"><?php echo htmlspecialchars($row['patient_code']); ?></p>
            </div>
            <div class="form-group">
                <label class="form-label">Test</label>
                <p><strong><?php echo htmlspecialchars($row['test_name']); ?></strong></p>
                <p class="text-muted"><?php echo htmlspecialchars(ucfirst($row['category'])); ?></p>
            </div>
            <div class="form-group">
                <label class="form-label">Requested By</label>
                <p><?php echo htmlspecialchars($row['doctor_name'] ?? ''); ?></p>
            </div>
            <div class="form-group">
                <label class="form-label">Request Code</label>
                <p><?php echo htmlspecialchars($row['request_code'] ?? ''); ?></p>
            </div>
        </div>

        <?php if (!empty($row['result_value'])): ?>
            <hr>
            <h4>Result</h4>
            <div><?php echo nl2br(htmlspecialchars($row['result_value'])); ?></div>
            <?php if (!empty($row['reference_range'])): ?>
                <div style="margin-top:8px;"><strong>Reference Range:</strong> <?php echo nl2br(htmlspecialchars($row['reference_range'])); ?></div>
            <?php endif; ?>
            <?php if (!empty($row['interpretation'])): ?>
                <div style="margin-top:8px;"><strong>Interpretation:</strong> <?php echo htmlspecialchars($row['interpretation']); ?></div>
            <?php endif; ?>
            <?php if (!empty($row['remarks'])): ?>
                <div style="margin-top:8px;"><strong>Remarks:</strong> <?php echo nl2br(htmlspecialchars($row['remarks'])); ?></div>
            <?php endif; ?>
            <?php
            $attachmentPath = trim((string)($row['attachment_path'] ?? ''));
            $attachmentUrl = '';
            if ($attachmentPath !== '') {
                $attachmentUrl = (strpos($attachmentPath, 'http://') === 0 || strpos($attachmentPath, 'https://') === 0)
                    ? $attachmentPath
                    : (BASE_URL . ltrim($attachmentPath, '/'));
            }
            $attachmentExt = strtolower(pathinfo($attachmentPath, PATHINFO_EXTENSION));
            $isImageAttachment = in_array($attachmentExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
            $isPdfAttachment = $attachmentExt === 'pdf';
            ?>
            <?php if ($attachmentPath !== ''): ?>
                <div style="margin-top:10px;">
                    <strong>Attachment:</strong>
                    <div style="margin-top:6px;">
                        <a href="<?php echo htmlspecialchars($attachmentUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                            <i class="fas fa-paperclip"></i> <?php echo htmlspecialchars(basename($attachmentPath), ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </div>
                    <?php if ($isImageAttachment): ?>
                        <img src="<?php echo htmlspecialchars($attachmentUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Laboratory attachment" style="max-width:100%; max-height:360px; margin-top:8px; border:1px solid #ddd; border-radius:4px;" />
                    <?php elseif ($isPdfAttachment): ?>
                        <iframe src="<?php echo htmlspecialchars($attachmentUrl, ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; min-height:500px; margin-top:8px; border:1px solid #ddd; border-radius:4px;"></iframe>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-info">No result recorded yet for this request.</div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php';
?>
