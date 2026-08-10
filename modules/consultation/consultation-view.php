<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'doctor']);

$pageTitle = 'View Consultation';
$currentPage = 'consultations';

$conn = getDBConnection();

// Handle saving treatment outcome
$canEditConsultation = hasRole(['doctor']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_outcome') {
    $consultationIdPost = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$canEditConsultation) {
        setFlashMessage('error', 'Admin users can only view consultation details.');
        redirect('modules/consultation/consultation-view.php?id=' . $consultationIdPost);
    }
    if ($consultationIdPost) {
        // fetch visit_id for this consultation
        $tmpStmt = $conn->prepare("SELECT visit_id FROM consultations WHERE id = ? LIMIT 1");
        if ($tmpStmt) {
            $tmpStmt->bind_param('i', $consultationIdPost);
            $tmpStmt->execute();
            $tmpRes = $tmpStmt->get_result();
            $tmpRow = $tmpRes && $tmpRes->num_rows ? $tmpRes->fetch_assoc() : null;
            $visitIdForUpdate = $tmpRow['visit_id'] ?? null;
            $tmpStmt->close();
        } else {
            $visitIdForUpdate = null;
        }

        $outcome = trim($_POST['outcome'] ?? '');
        $follow_up_date = !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;
        $follow_up_instructions = trim($_POST['follow_up_instructions'] ?? '');
        $notes = trim($_POST['outcome_notes'] ?? '');

        $updateStmt = $conn->prepare("UPDATE consultations SET outcome = ?, follow_up_date = ?, follow_up_instructions = ?, notes = ? WHERE id = ?");
        if ($updateStmt) {
            $updateStmt->bind_param('ssssi', $outcome, $follow_up_date, $follow_up_instructions, $notes, $consultationIdPost);
            $updateStmt->execute();
            $updateStmt->close();
        }

        // Map outcome to visit status and update visit if applicable
        $svcRes = $conn->query("SELECT price FROM services WHERE service_code IN ('CONSULT','CONSULTATION') OR service_name LIKE '%consult%' LIMIT 1");
        $consultFee = 0.00;
        if ($svcRes && $svcRes->num_rows > 0) {
            $consultFee = (float)$svcRes->fetch_assoc()['price'];
        }

        $visitStatus = 'discharged';
        switch ($outcome) {
            case 'admission': $visitStatus = 'admitted'; break;
            case 'transfer': $visitStatus = 'transferred'; break;
            case 'laboratory-request': $visitStatus = 'in-laboratory'; break;
            case 'surgery': 
            case 'emergency-operation': 
                $visitStatus = 'in-treatment'; 
                break;
            case 'discharge':
            case 'prescription-only':
            case 'outpatient':
            default: 
                $visitStatus = ($consultFee > 0) ? 'ready-for-discharge' : 'discharged'; 
                break;
        }

        if (!empty($visitIdForUpdate)) {
            $vup = $conn->prepare("UPDATE patient_visits SET status = ? WHERE id = ?");
            if ($vup) {
                $vup->bind_param('si', $visitStatus, $visitIdForUpdate);
                $vup->execute();
                $vup->close();
            }

            // After updating visit, if there are completed lab results viewed by this doctor,
            // create/update a pending invoice (consultation + lab items) and redirect to payment.
            try {
                $doctorId = (int)($_SESSION['user_id'] ?? 0);
                $vId = (int)$visitIdForUpdate;

                $labCheck = $conn->prepare(
                    "SELECT lr.id FROM laboratory_requests lr " .
                    "JOIN laboratory_results lres ON lres.request_id = lr.id " .
                    "JOIN doctor_lab_views dlv ON dlv.request_id = lr.id AND dlv.doctor_id = ? " .
                    "WHERE lr.visit_id = ? AND lr.status = 'completed' LIMIT 1"
                );
                if ($labCheck) {
                    $labCheck->bind_param('ii', $doctorId, $vId);
                    $labCheck->execute();
                    $labRes = $labCheck->get_result();
                    $hasCompletedViewedLab = $labRes && $labRes->num_rows > 0;
                    $labCheck->close();
                } else {
                    $hasCompletedViewedLab = false;
                }

                if ($hasCompletedViewedLab) {
                    // find or create pending invoice for this visit
                    $pendingInvId = null;
                    $invQ = $conn->query("SELECT id FROM invoices WHERE visit_id = $vId AND status IN ('pending','partial') LIMIT 1");
                    if ($invQ && $invQ->num_rows > 0) {
                        $pendingInvId = (int)$invQ->fetch_assoc()['id'];
                    } else {
                        // get patient_id from visit
                        $pv = $conn->query("SELECT patient_id FROM patient_visits WHERE id = $vId LIMIT 1");
                        $patientIdForInv = ($pv && $pv->num_rows) ? (int)$pv->fetch_assoc()['patient_id'] : 0;
                        $createdBy = $doctorId;
                        if ($patientIdForInv) {
                            $insSql = "INSERT INTO invoices (invoice_number, patient_id, visit_id, admission_id, total_amount, discount_amount, tax_amount, net_amount, paid_amount, balance_amount, status, created_by) VALUES ('TBD', $patientIdForInv, $vId, NULL, 0, 0, 0, 0, 0, 0, 'pending', $createdBy)";
                            if ($conn->query($insSql)) {
                                $pendingInvId = (int)$conn->insert_id;
                                $invoiceNumber = generateCode('INV', $pendingInvId);
                                $conn->query("UPDATE invoices SET invoice_number = '" . $conn->real_escape_string($invoiceNumber) . "' WHERE id = $pendingInvId");
                            }
                        }
                    }

                    if ($pendingInvId) {
                        // Add consultation fee if missing
                        $ci = $conn->query("SELECT COUNT(1) AS cnt FROM invoice_items WHERE invoice_id = $pendingInvId AND reference_type = 'consultation' AND reference_id = " . intval($consultationIdPost));
                        $hasConsult = ($ci && $ci->num_rows) ? (int)$ci->fetch_assoc()['cnt'] > 0 : false;
                        if (!$hasConsult) {
                            $consultFee = 0.00;
                            $svcRes = $conn->query("SELECT id, price FROM services WHERE service_code IN ('CONSULT','CONSULTATION') OR service_name LIKE '%consult%' LIMIT 1");
                            $serviceId = 'NULL';
                            if ($svcRes && $svcRes->num_rows > 0) {
                                $sRow = $svcRes->fetch_assoc();
                                $serviceId = (int)$sRow['id'];
                                $consultFee = (float)$sRow['price'];
                            }
                            if ($consultFee > 0) {
                                $desc = $conn->real_escape_string('Consultation Fee');
                                $conn->query("INSERT INTO invoice_items (invoice_id, service_id, item_description, quantity, unit_price, total_price, reference_type, reference_id) VALUES ($pendingInvId, " . ($serviceId === 'NULL' ? 'NULL' : intval($serviceId)) . ", '$desc', 1, $consultFee, $consultFee, 'consultation', " . intval($consultationIdPost) . ")");
                            }
                        }

                        // Add lab items for completed requests viewed by doctor
                        $lrQ = $conn->prepare("SELECT lr.id, lt.test_name, lt.price FROM laboratory_requests lr LEFT JOIN laboratory_tests lt ON lr.test_id = lt.id JOIN doctor_lab_views dlv ON dlv.request_id = lr.id AND dlv.doctor_id = ? WHERE lr.visit_id = ? AND lr.status = 'completed'");
                        if ($lrQ) {
                            $lrQ->bind_param('ii', $doctorId, $vId);
                            $lrQ->execute();
                            $lrs = $lrQ->get_result();
                            while ($lr = $lrs->fetch_assoc()) {
                                $rid = (int)$lr['id'];
                                $exists = $conn->query("SELECT COUNT(1) AS cnt FROM invoice_items WHERE invoice_id = $pendingInvId AND reference_type LIKE '%lab%' AND reference_id = $rid");
                                $hasItem = ($exists && $exists->num_rows) ? (int)$exists->fetch_assoc()['cnt'] > 0 : false;
                                if (!$hasItem) {
                                    $price = (float)($lr['price'] ?? 0);
                                    $tname = $conn->real_escape_string($lr['test_name'] ?? 'Lab Test');
                                    $conn->query("INSERT INTO invoice_items (invoice_id, service_id, item_description, quantity, unit_price, total_price, reference_type, reference_id) VALUES ($pendingInvId, NULL, 'Laboratory: $tname', 1, $price, $price, 'laboratory', $rid)");
                                }
                            }
                            $lrQ->close();
                        }

                        // Recalculate invoice totals
                        $sum = $conn->query("SELECT COALESCE(SUM(total_price),0) AS total FROM invoice_items WHERE invoice_id = $pendingInvId");
                        $totalAmt = ($sum && $sum->num_rows) ? (float)$sum->fetch_assoc()['total'] : 0.00;
                        $conn->query("UPDATE invoices SET total_amount = $totalAmt, net_amount = $totalAmt, balance_amount = $totalAmt WHERE id = $pendingInvId");

                        // Redirect to payment page
                        setFlashMessage('success', 'Outcome saved — redirecting to payment for cashier.');
                        redirect('modules/billing/payment.php?invoice_id=' . $pendingInvId);
                    }
                }
            } catch (Throwable $e) {
                // ignore and continue
            }
        }

        setFlashMessage('success', 'Treatment outcome saved.');
        redirect('modules/consultation/consultation-view.php?id=' . $consultationIdPost);
    }
}

// Get consultation id
$consultationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$consultationId) {
    setFlashMessage('error', 'Consultation ID missing.');
    redirect('modules/consultation/consultations.php');
}

// Fetch consultation with patient and doctor info
$stmt = $conn->prepare(
    "SELECT c.*, v.queue_number, p.first_name, p.last_name, p.patient_code, u.full_name AS doctor_name
     FROM consultations c
     LEFT JOIN patient_visits v ON c.visit_id = v.id
     LEFT JOIN patients p ON c.patient_id = p.id
     LEFT JOIN users u ON c.doctor_id = u.id
     WHERE c.id = ? LIMIT 1"
);
$stmt->bind_param('i', $consultationId);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Consultation not found.');
    redirect('modules/consultation/consultations.php');
}
$consult = $res->fetch_assoc();
$stmt->close();

// Fetch prescriptions
$prescStmt = $conn->prepare(
    "SELECT * FROM prescriptions WHERE consultation_id = ? ORDER BY id"
);
$prescStmt->bind_param('i', $consultationId);
$prescStmt->execute();
$prescRes = $prescStmt->get_result();
$prescriptions = $prescRes ? $prescRes->fetch_all(MYSQLI_ASSOC) : [];
$prescStmt->close();

// Fetch laboratory requests for this visit (if any)
$labRequests = [];
if (!empty($consult['visit_id'])) {
    $labStmt = $conn->prepare(
        "SELECT lr.*, lt.test_name FROM laboratory_requests lr
         LEFT JOIN laboratory_tests lt ON lr.test_id = lt.id
         WHERE lr.visit_id = ? ORDER BY lr.requested_at DESC"
    );
    $labStmt->bind_param('i', $consult['visit_id']);
    $labStmt->execute();
    $labRes = $labStmt->get_result();
    $labRequests = $labRes ? $labRes->fetch_all(MYSQLI_ASSOC) : [];
    $labStmt->close();
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Consultation Details</h1>
        <p class="page-subtitle"><?php echo htmlspecialchars($consult['first_name'] . ' ' . $consult['last_name']); ?> — <?php echo htmlspecialchars($consult['patient_code']); ?></p>
    </div>
    <a href="consultations.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<div class="card">
    <div class="card-body">
        <h4>Summary</h4>
        <p><strong>Doctor:</strong> <?php echo htmlspecialchars($consult['doctor_name'] ?? 'N/A'); ?></p>
        <p><strong>Visit Queue:</strong> <?php echo htmlspecialchars($consult['queue_number'] ?? 'N/A'); ?></p>
        <p><strong>Date:</strong> <?php echo htmlspecialchars($consult['created_at'] ?? ''); ?></p>
        <p><strong>Outcome:</strong> <?php echo htmlspecialchars($consult['outcome'] ?? ''); ?></p>
        <?php if (!empty($consult['transfer_destination'])): ?>
            <p><strong>Transfer To:</strong> <?php echo htmlspecialchars($consult['transfer_destination']); ?></p>
        <?php endif; ?>

        <h4>Clinical</h4>
        <p><strong>Physical Examination:</strong><br><?php echo nl2br(htmlspecialchars($consult['physical_examination'] ?? '')); ?></p>
        <p><strong>Diagnosis:</strong><br><?php echo nl2br(htmlspecialchars($consult['diagnosis'] ?? '')); ?></p>
        <p><strong>Treatment Plan:</strong><br><?php echo nl2br(htmlspecialchars($consult['treatment_plan'] ?? '')); ?></p>
        <p><strong>Follow-up:</strong><br><?php echo nl2br(htmlspecialchars($consult['follow_up_instructions'] ?? '')); ?></p>

        <h4>Prescriptions</h4>
        <?php if (count($prescriptions) === 0): ?>
            <p class="text-muted">No prescriptions recorded.</p>
        <?php else: ?>
            <ul class="list-group">
                <?php foreach ($prescriptions as $p): ?>
                    <li class="list-group-item">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <strong><?php echo htmlspecialchars($p['medication_name']); ?></strong>
                                <div><?php echo htmlspecialchars($p['dosage'] ?? ''); ?> — <?php echo htmlspecialchars($p['frequency'] ?? ''); ?> — <?php echo htmlspecialchars($p['duration'] ?? ''); ?></div>
                                <div><small><?php echo htmlspecialchars($p['instructions'] ?? ''); ?></small></div>
                            </div>
                            <div>
                                <?php if (hasRole(['pharmacist', 'nurse', 'cashier', 'staff'])): ?>
                                    <a href="../prescription/prescription-print.php?id=<?php echo $p['id']; ?>" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-print"></i> Print</a>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:12px;">View only</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h4>Laboratory Requests</h4>
        <?php if (count($labRequests) === 0): ?>
            <p class="text-muted">No laboratory requests recorded.</p>
        <?php else: ?>
            <ul class="list-group">
                <?php foreach ($labRequests as $lr): ?>
                    <li class="list-group-item">
                        <strong><?php echo htmlspecialchars($lr['test_name'] ?? $lr['test_id']); ?></strong>
                        <div>Code: <?php echo htmlspecialchars($lr['request_code']); ?> — Priority: <?php echo htmlspecialchars($lr['priority']); ?></div>
                        <div><small><?php echo htmlspecialchars($lr['notes'] ?? ''); ?></small></div>
                        <?php
                        // Fetch latest laboratory result for this request (if any)
                        $resStmt = $conn->prepare(
                            "SELECT lr.*, u.full_name AS tech_name, ru.full_name AS reviewer_name FROM laboratory_results lr " .
                            "LEFT JOIN users u ON lr.technician_id = u.id LEFT JOIN users ru ON lr.reviewed_by = ru.id " .
                            "WHERE lr.request_id = ? ORDER BY lr.created_at DESC LIMIT 1"
                        );
                        if ($resStmt) {
                            $resStmt->bind_param('i', $lr['id']);
                            $resStmt->execute();
                            $rres = $resStmt->get_result();
                            $labResult = ($rres && $rres->num_rows) ? $rres->fetch_assoc() : null;
                            $resStmt->close();
                            // record that the current doctor viewed this lab result (create tracking table if needed)
                            $docId = (int)($_SESSION['user_id'] ?? 0);
                            if ($labResult && $docId) {
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
                                    $viewStmt->bind_param('iii', $lr['visit_id'], $lr['id'], $docId);
                                    $viewStmt->execute();
                                    $viewStmt->close();
                                }
                            }
                        } else {
                            $labResult = null;
                        }

                        if ($labResult): ?>
                            <div style="margin-top:10px; padding:10px; background:#f9f9f9; border-radius:4px;">
                                <h5 style="margin:0 0 6px 0;">Result</h5>
                                <div><?php echo nl2br(htmlspecialchars($labResult['result_value'])); ?></div>
                                <?php if (!empty($labResult['interpretation'])): ?>
                                    <div style="margin-top:6px;"><strong>Interpretation:</strong> <?php echo nl2br(htmlspecialchars($labResult['interpretation'])); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($labResult['remarks'])): ?>
                                    <div style="margin-top:6px;"><strong>Remarks:</strong> <?php echo nl2br(htmlspecialchars($labResult['remarks'])); ?></div>
                                <?php endif; ?>
                                <div style="margin-top:8px; font-size:12px; color:#555;">
                                    <em>Status:</em> <?php echo htmlspecialchars($labResult['status']); ?>
                                    <?php if (!empty($labResult['tech_name'])): ?> — <em>By:</em> <?php echo htmlspecialchars($labResult['tech_name']); ?><?php endif; ?>
                                    <?php if (!empty($labResult['reviewer_name'])): ?> — <em>Reviewed by:</em> <?php echo htmlspecialchars($labResult['reviewer_name']); ?><?php endif; ?>
                                    <?php if (!empty($labResult['reviewed_at'])): ?> — <em>At:</em> <?php echo htmlspecialchars($labResult['reviewed_at']); ?><?php endif; ?>
                                </div>
                                <?php
                                $attachmentPath = trim((string)($labResult['attachment_path'] ?? ''));
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
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

    </div>
</div>
<?php
$conn->close();
include __DIR__ . '/../../includes/footer.php';
?>
