<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['doctor', 'admin']);

$pageTitle = 'Discharge Patient';
$currentPage = 'admissions';

$conn = getDBConnection();
$admissionId = isset($_GET['admission_id']) ? (int)$_GET['admission_id'] : 0;

if ($admissionId <= 0) {
    setFlashMessage('error', 'Invalid admission selected.');
    redirect('modules/admission/admissions.php');
}

// Load admission
$stmt = $conn->prepare("SELECT a.*, p.first_name, p.last_name, p.patient_code, a.visit_id FROM admissions a JOIN patients p ON a.patient_id = p.id WHERE a.id = ? LIMIT 1");
$stmt->bind_param('i', $admissionId);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    $stmt->close();
    $conn->close();
    setFlashMessage('error', 'Admission not found.');
    redirect('modules/admission/admissions.php');
}
$admission = $res->fetch_assoc();
$stmt->close();

// Preconditions: check laboratory requests for the related visit
$visitId = intval($admission['visit_id']);
$labPending = 0;
if ($visitId > 0) {
    $r = $conn->query("SELECT COUNT(*) as cnt FROM laboratory_requests WHERE visit_id = $visitId AND status IN ('pending','in-progress')");
    if ($r) $labPending = (int)$r->fetch_assoc()['cnt'];
}

// Check for completed lab results that the current doctor has not yet viewed
$unviewedLabResults = 0;
$currentDoctorId = (int)($_SESSION['user_id'] ?? 0);
if ($visitId > 0 && $currentDoctorId > 0) {
    $q = "SELECT COUNT(*) as cnt FROM laboratory_requests lr JOIN laboratory_results lres ON lres.request_id = lr.id WHERE lr.visit_id = $visitId AND lres.id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM doctor_lab_views v WHERE v.request_id = lr.id AND v.doctor_id = $currentDoctorId)";
    $r2 = $conn->query($q);
    if ($r2) $unviewedLabResults = (int)$r2->fetch_assoc()['cnt'];
}

// Preconditions: check invoices/payments for this admission
$unpaidCount = 0;
$r2 = $conn->query("SELECT COUNT(*) as cnt FROM invoices WHERE admission_id = " . intval($admissionId) . " AND (status != 'paid' OR balance_amount > 0)");
if ($r2) $unpaidCount = (int)$r2->fetch_assoc()['cnt'];

// If form submitted (doctor confirms discharge)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-check preconditions in POST to avoid race conditions
    $labPending = 0;
    if ($visitId > 0) {
        $r = $conn->query("SELECT COUNT(*) as cnt FROM laboratory_requests WHERE visit_id = $visitId AND status IN ('pending','in-progress')");
        if ($r) $labPending = (int)$r->fetch_assoc()['cnt'];
    }
    // Re-check unviewed lab results by current user
    $unviewedLabResults = 0;
    $currentDoctorId = (int)($_SESSION['user_id'] ?? 0);
    if ($visitId > 0 && $currentDoctorId > 0) {
        $q = "SELECT COUNT(*) as cnt FROM laboratory_requests lr JOIN laboratory_results lres ON lres.request_id = lr.id WHERE lr.visit_id = $visitId AND lres.id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM doctor_lab_views v WHERE v.request_id = lr.id AND v.doctor_id = $currentDoctorId)";
        $r2 = $conn->query($q);
        if ($r2) $unviewedLabResults = (int)$r2->fetch_assoc()['cnt'];
    }
    $unpaidCount = 0;
    $r2 = $conn->query("SELECT COUNT(*) as cnt FROM invoices WHERE admission_id = " . intval($admissionId) . " AND (status != 'paid' OR balance_amount > 0)");
    if ($r2) $unpaidCount = (int)$r2->fetch_assoc()['cnt'];

    if ($labPending > 0) {
        setFlashMessage('error', 'Cannot discharge: there are pending laboratory requests.');
        redirect('modules/admission/admission-view.php?id=' . $admissionId);
    }
    if ($unviewedLabResults > 0) {
        // Find consultation id for this visit so we can redirect the doctor to review results
        $consultId = 0;
        $cres = $conn->query("SELECT id FROM consultations WHERE visit_id = " . intval($visitId) . " ORDER BY created_at DESC LIMIT 1");
        if ($cres && $cres->num_rows > 0) $consultId = (int)$cres->fetch_assoc()['id'];

        setFlashMessage('error', 'Cannot discharge: there are completed laboratory results you have not reviewed. Review them first.');
        if ($consultId) {
            redirect('modules/consultation/consultation-view.php?id=' . $consultId);
        } else {
            redirect('modules/admission/admission-view.php?id=' . $admissionId);
        }
    }
    // If there are unpaid invoices, allow discharge but inform cashier will need to settle payment.

    $finalDiagnosis = isset($_POST['final_diagnosis']) ? sanitize($_POST['final_diagnosis']) : '';
    $summary = isset($_POST['discharge_summary']) ? sanitize($_POST['discharge_summary']) : '';
    $meds = isset($_POST['medications_on_discharge']) ? sanitize($_POST['medications_on_discharge']) : '';

    // Insert discharge record
    $ins = $conn->prepare("INSERT INTO discharge_records (admission_id, patient_id, discharge_date, final_diagnosis, discharge_summary, medications_on_discharge, discharge_checked_by, discharge_approved_by) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?)");
    $docId = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
    $ins->bind_param('iisssii', $admissionId, $admission['patient_id'], $finalDiagnosis, $summary, $meds, $docId, $docId);
        if ($ins->execute()) {
        $dischargeId = $ins->insert_id;
        $ins->close();

        // Update admission status
        $u = $conn->prepare("UPDATE admissions SET status = 'discharged', actual_discharge_date = NOW() WHERE id = ?");
        $u->bind_param('i', $admissionId);
        $u->execute();
        $u->close();

        logActivity('discharge', 'admissions', $admissionId, null, json_encode(['discharge_id'=>$dischargeId]));
        setFlashMessage('success', 'Patient discharged successfully. Proceed to cashier to settle admission charges.');

        // Ensure there is a pending invoice for this admission and redirect cashier to it
        $invoiceId = null;
        $invRes = $conn->query("SELECT id FROM invoices WHERE admission_id = " . intval($admissionId) . " AND status IN ('pending','partial') LIMIT 1");
        if ($invRes && $invRes->num_rows > 0) {
            $invoiceId = (int)$invRes->fetch_assoc()['id'];
        } else {
            // create blank pending invoice for admission so payment.php can populate room/med charges
            $pId = (int)$admission['patient_id'];
            $vId = isset($admission['visit_id']) && $admission['visit_id'] ? (int)$admission['visit_id'] : 'NULL';
            $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
            $insSql = "INSERT INTO invoices (invoice_number, patient_id, visit_id, admission_id, total_amount, discount_amount, tax_amount, net_amount, paid_amount, balance_amount, status, created_by) VALUES ('TBD', $pId, " . ($vId === 'NULL' ? 'NULL' : $vId) . ", " . intval($admissionId) . ", 0, 0, 0, 0, 0, 0, 'pending', $createdBy)";
            if ($conn->query($insSql)) {
                $invoiceId = (int)$conn->insert_id;
                $invoiceNumber = generateCode('INV', $invoiceId);
                $conn->query("UPDATE invoices SET invoice_number = '" . $conn->real_escape_string($invoiceNumber) . "' WHERE id = $invoiceId");
            }
        }

        // Route based on user role - use canonical session key
        $userRole = $_SESSION['user_role'] ?? '';
        if ($userRole === 'doctor') {
            setFlashMessage('success', 'Patient discharged. Cashier will handle billing.');
            redirect('modules/admission/admissions.php');
        } else {
            $conn->close();
            if ($invoiceId) {
                redirect('modules/billing/payment.php?invoice_id=' . $invoiceId);
            } else {
                redirect('modules/billing/invoices.php');
            }
        }
    } else {
        $err = $conn->error;
        if (isset($ins) && $ins) $ins->close();
        setFlashMessage('error', 'Failed to record discharge: ' . $err);
        redirect('modules/admission/admission-view.php?id=' . $admissionId);
    }
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Discharge Patient</h1>
        <p class="page-subtitle">Admission #<?php echo htmlspecialchars($admission['admission_code'] ?? $admission['id']); ?> — <?php echo htmlspecialchars($admission['first_name'] . ' ' . $admission['last_name']); ?></p>
    </div>
    <div>
        <a href="admission-view.php?id=<?php echo $admissionId; ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <?php if ($labPending > 0): ?>
            <div class="alert alert-warning">There are <strong><?php echo $labPending; ?></strong> pending laboratory request(s). Complete them before discharge.</div>
        <?php endif; ?>
        <?php if ($unpaidCount > 0): ?>
            <div class="alert alert-warning">There are <strong><?php echo $unpaidCount; ?></strong> unpaid invoice(s). Settle payment before discharge.</div>
        <?php endif; ?>

        <?php if ($labPending === 0): ?>
            <?php if ($unpaidCount > 0): ?>
                <div class="alert alert-warning">There are <strong><?php echo $unpaidCount; ?></strong> unpaid invoice(s). The patient may be discharged but must proceed to the cashier to settle bills. Confirm discharge to create/assign the pending invoice and continue to payment.</div>
            <?php endif; ?>
            <form method="post" action="discharge.php?admission_id=<?php echo $admissionId; ?>">
                <div class="form-group">
                    <label>Final Diagnosis</label>
                    <textarea name="final_diagnosis" class="form-control" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label>Discharge Summary / Instructions</label>
                    <textarea name="discharge_summary" class="form-control" rows="4"></textarea>
                </div>
                <div class="form-group">
                    <label>Medications on Discharge</label>
                    <textarea name="medications_on_discharge" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group text-right">
                    <button type="submit" class="btn btn-warning"><i class="fas fa-sign-out-alt"></i> Confirm Discharge</button>
                </div>
            </form>
        <?php else: ?>
            <div class="text-right">
                <a href="admission-view.php?id=<?php echo $admissionId; ?>" class="btn btn-secondary">Back</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
