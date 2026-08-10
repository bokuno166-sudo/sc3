<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'cashier']);

$pageTitle = 'Process Payment';
$currentPage = 'invoices';

$conn = getDBConnection();

// Get invoice details
$invoiceId = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
// If numeric id not provided, allow lookup by invoice_number (e.g. P202600021)
if ($invoiceId <= 0 && isset($_GET['invoice_number'])) {
    $invNum = $conn->real_escape_string($_GET['invoice_number']);
    $lookup = $conn->query("SELECT id FROM invoices WHERE invoice_number = '$invNum' LIMIT 1");
    if ($lookup && $lookup->num_rows > 0) {
        $invoiceId = (int)$lookup->fetch_assoc()['id'];
    }
}
$invoiceResult = $conn->query(
    "SELECT i.*, p.first_name, p.last_name, p.patient_code, p.address, p.contact_number, p.age, p.date_of_birth
    FROM invoices i
    JOIN patients p ON i.patient_id = p.id
    WHERE i.id = $invoiceId
"
);

if ($invoiceResult->num_rows === 0) {
    setFlashMessage('error', 'Invoice not found.');
    redirect('modules/billing/invoices.php');
}

$invoice = $invoiceResult->fetch_assoc();

// Recalculate totals from invoice_items (keep in sync with invoice-view.php logic)
$sumRes = $conn->query("SELECT COALESCE(SUM(total_price),0) as items_total FROM invoice_items WHERE invoice_id = $invoiceId");
if ($sumRes && $sumRes->num_rows) {
    $computedTotal = (float)$sumRes->fetch_assoc()['items_total'];
} else {
    $computedTotal = 0.0;
}
$discount = isset($invoice['discount_amount']) ? (float)$invoice['discount_amount'] : 0.0;
$tax = isset($invoice['tax_amount']) ? (float)$invoice['tax_amount'] : 0.0;
$paid = isset($invoice['paid_amount']) ? (float)$invoice['paid_amount'] : 0.0;
$computedNet = $computedTotal - $discount + $tax;
$computedBalance = $computedNet - $paid;
$needsUpdate = false;
if (abs((float)$invoice['total_amount'] - $computedTotal) > 0.001) $needsUpdate = true;
if (abs((float)$invoice['net_amount'] - $computedNet) > 0.001) $needsUpdate = true;
if (abs((float)$invoice['balance_amount'] - $computedBalance) > 0.001) $needsUpdate = true;
if ($needsUpdate) {
    $uSql = "UPDATE invoices SET total_amount = ?, net_amount = ?, balance_amount = ? WHERE id = ?";
    $uStmt = $conn->prepare($uSql);
    if ($uStmt) {
        $uStmt->bind_param('dddi', $computedTotal, $computedNet, $computedBalance, $invoiceId);
        $uStmt->execute();
        $uStmt->close();
        // refresh invoice values for page
        $invoice['total_amount'] = $computedTotal;
        $invoice['net_amount'] = $computedNet;
        $invoice['balance_amount'] = $computedBalance;
    }
}

// AUTO-COMPUTE ALL ADMISSION CHARGES (room, lab, consultation, medicines)
// Always recompute for admission invoices so nothing is missed
$admissionId = isset($invoice['admission_id']) ? (int)$invoice['admission_id'] : 0;
if ($admissionId > 0) {
    require_once __DIR__ . '/auto_admission_charges.php';
    $chargeResult = auto_admission_charges($conn, $admissionId);
    if (isset($chargeResult['status']) && $chargeResult['status'] === 'success') {
        // Refresh computed totals after auto-charge
        $sumRes = $conn->query("SELECT COALESCE(SUM(total_price),0) as items_total FROM invoice_items WHERE invoice_id = $invoiceId");
        $computedTotal = (float)$sumRes->fetch_assoc()['items_total'];
        $computedNet = $computedTotal - $discount + $tax;
        $computedBalance = $computedNet - $paid;
        $invoice['total_amount'] = $computedTotal;
        $invoice['net_amount'] = $computedNet;
        $invoice['balance_amount'] = $computedBalance;
    }
}

// APPLY AUTO DISCOUNT FOR ELIGIBLE PATIENTS (e.g., seniors) ON PAGE LOAD
// Ensure support column exists
$col = $conn->query("SHOW COLUMNS FROM invoices LIKE 'auto_discount_applied'");
if (!$col || $col->num_rows === 0) {
    $conn->query("ALTER TABLE invoices ADD COLUMN auto_discount_applied TINYINT(1) DEFAULT 0");
}

$applyAutoDiscount = false;
$age = null;
if (isset($invoice['age']) && $invoice['age']) {
    $age = (int)$invoice['age'];
} elseif (!empty($invoice['date_of_birth'])) {
    $dob = $invoice['date_of_birth'];
    $age = (int)floor((time() - strtotime($dob)) / (365.25*24*60*60));
}
// fetch patient's is_pwd flag
$isPwd = 0;
$pid = (int)($invoice['patient_id'] ?? 0);
if ($pid > 0) {
    $pRes = $conn->query("SELECT is_pwd FROM patients WHERE id = $pid");
    if ($pRes && $pRes->num_rows > 0) {
        $isPwd = (int)$pRes->fetch_assoc()['is_pwd'];
    }
}

if ($age !== null && ($age >= 60 || $age <= 17)) $applyAutoDiscount = true;
if ($isPwd) $applyAutoDiscount = true;

if ($applyAutoDiscount) {
    $appliedRes = $conn->query("SELECT auto_discount_applied, net_amount, balance_amount, discount_amount FROM invoices WHERE id = $invoiceId LIMIT 1");
    if ($appliedRes && $appliedRes->num_rows > 0) {
        $apRow = $appliedRes->fetch_assoc();
        $already = (int)($apRow['auto_discount_applied'] ?? 0) === 1;
        if (!$already) {
            $currentNet = (float)($apRow['net_amount'] ?? $computedNet);
            $discountToApply = round($currentNet * 0.20, 2);
            if ($discountToApply > 0) {
                $newDiscount = (float)($apRow['discount_amount'] ?? 0) + $discountToApply;
                $newNet = $currentNet - $discountToApply;
                // adjust balance as well (preserve paid_amount)
                $paidAmt = (float)$invoice['paid_amount'];
                $newBalance = $newNet - $paidAmt;
                $conn->query("UPDATE invoices SET discount_amount = $newDiscount, net_amount = $newNet, balance_amount = $newBalance, auto_discount_applied = 1 WHERE id = $invoiceId");
                // refresh invoice in memory
                $r = $conn->query("SELECT * FROM invoices WHERE id = $invoiceId");
                if ($r && $r->num_rows) $invoice = $r->fetch_assoc();
                // update computed vars used on page
                $computedNet = (float)$invoice['net_amount'];
                $computedBalance = (float)$invoice['balance_amount'];
                setFlashMessage('info', 'A 20% senior/eligible discount was applied to this invoice.');
            }
        }
    }
}

// Diagnostic: if computed total is zero, collect invoice_items for debugging
$debugInvoiceItems = [];
if (abs($computedTotal) < 0.001) {
    $di = $conn->query("SELECT * FROM invoice_items WHERE invoice_id = $invoiceId ORDER BY id ASC");
    if ($di && $di->num_rows > 0) {
        while ($r = $di->fetch_assoc()) $debugInvoiceItems[] = $r;
    }
}


// Prepare display data early so it's always available for the template
$itemsResult = $conn->query("SELECT * FROM invoice_items WHERE invoice_id = $invoiceId ORDER BY id ASC");
if (!$itemsResult) {
    $itemsResult = $conn->query("SELECT * FROM invoice_items WHERE 0");
}

$paymentsResult = $conn->query("SELECT p.*, COALESCE(u.full_name, u.username) as received_by_name FROM payments p LEFT JOIN users u ON p.received_by = u.id WHERE p.invoice_id = $invoiceId ORDER BY p.payment_date DESC");
if (!$paymentsResult) {
    $paymentsResult = $conn->query("SELECT * FROM payments WHERE 0");
}

$labPendingRes = $conn->query("SELECT COUNT(*) as cnt FROM invoice_items ii JOIN laboratory_requests lr ON ii.reference_id = lr.id WHERE ii.invoice_id = $invoiceId AND ii.reference_type = 'laboratory' AND lr.status != 'completed'");
$labItemsPending = false;
if ($labPendingRes && $labPendingRes->num_rows) {
    $labItemsPending = ((int)$labPendingRes->fetch_assoc()['cnt'] > 0);
}

// Find other pending/partial invoices for the same patient created today
$otherInvoices = [];
$combinedTotal = 0.0;
$patientIdForInvoices = isset($invoice['patient_id']) ? (int)$invoice['patient_id'] : 0;
if ($patientIdForInvoices > 0) {
    $oq = $conn->query("SELECT id, invoice_number, total_amount, net_amount, balance_amount, status, created_at FROM invoices WHERE patient_id = $patientIdForInvoices AND id != $invoiceId AND status IN ('pending','partial') AND DATE(created_at) = CURDATE() ORDER BY id ASC");
    if ($oq) {
        while ($or = $oq->fetch_assoc()) {
            $otherInvoices[] = $or;
            $combinedTotal += (float)($or['balance_amount'] ?? 0.0);
        }
    }
}

// Ensure admission room charges and consumables used during admission
$admissionId = isset($invoice['admission_id']) ? (int)$invoice['admission_id'] : 0;
$visitId = isset($invoice['visit_id']) ? (int)$invoice['visit_id'] : 0;
if ($admissionId > 0) {
    $aRes = $conn->query("SELECT * FROM admissions WHERE id = $admissionId LIMIT 1");
    if ($aRes && $aRes->num_rows > 0) {
        $ad = $aRes->fetch_assoc();
        $admitDate = $ad['admission_date'];
        $dischDate = !empty($ad['actual_discharge_date']) ? $ad['actual_discharge_date'] : date('Y-m-d H:i:s');
        $days = 1;
        if (!empty($admitDate)) {
            $diff = strtotime($dischDate) - strtotime($admitDate);
            $days = max(1, (int)floor($diff / (24*60*60)));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Prevent payment if there are laboratory items on this invoice with incomplete requests
    $labPendingRes = $conn->query("SELECT COUNT(*) as cnt FROM invoice_items ii JOIN laboratory_requests lr ON ii.reference_id = lr.id WHERE ii.invoice_id = $invoiceId AND ii.reference_type = 'laboratory' AND lr.status != 'completed'");
    if ($labPendingRes && $labPendingRes->num_rows) {
        $labPendingCnt = (int)$labPendingRes->fetch_assoc()['cnt'];
        if ($labPendingCnt > 0) {
            setFlashMessage('error', 'Payment cannot be processed: there are laboratory items on this invoice with pending or incomplete results. Please complete the laboratory tests before accepting payment.');
            redirect('modules/billing/payment.php?invoice_id=' . $invoiceId);
            exit;
        }
    }
    // Ensure support columns exist
    $col = $conn->query("SHOW COLUMNS FROM patients LIKE 'is_pwd'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE patients ADD COLUMN is_pwd TINYINT(1) DEFAULT 0");
    }
    $col = $conn->query("SHOW COLUMNS FROM invoices LIKE 'auto_discount_applied'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE invoices ADD COLUMN auto_discount_applied TINYINT(1) DEFAULT 0");
    }

    $paymentAmount = (float)$_POST['payment_amount'];
    $paymentMethod = sanitize($_POST['payment_method']);
    $paymentReference = sanitize($_POST['payment_reference']);
    $notes = sanitize($_POST['notes']);

    // If payment method is cash, clear reference; for non-cash, require reference number
    if ($paymentMethod === 'cash') {
        $paymentReference = '';
    } else {
        if (trim($paymentReference) === '') {
            setFlashMessage('error', 'Reference number is required for non-cash payments.');
            redirect('modules/billing/payment.php?invoice_id=' . $invoiceId);
        }
    }
    
    // Determine discount eligibility (senior >=60, kids <=17, or is_pwd)
    $age = null;
    if (isset($invoice['age']) && $invoice['age']) {
        $age = (int)$invoice['age'];
    } elseif (!empty($invoice['date_of_birth'])) {
        $dob = $invoice['date_of_birth'];
        $age = (int)floor((time() - strtotime($dob)) / (365.25*24*60*60));
    }
    // fetch patient's is_pwd flag now that we've ensured the column exists
    $isPwd = 0;
    $pid = (int)($invoice['patient_id'] ?? 0);
    if ($pid > 0) {
        $pRes = $conn->query("SELECT is_pwd FROM patients WHERE id = $pid");
        if ($pRes && $pRes->num_rows > 0) {
            $isPwd = (int)$pRes->fetch_assoc()['is_pwd'];
        }
    }
    $eligibleForDiscount = false;
    if ($age !== null) {
        if ($age >= 60 || $age <= 17) $eligibleForDiscount = true;
    }
    if ($isPwd) $eligibleForDiscount = true;

    // Apply a one-time 20% discount if eligible and not yet applied
    if ($eligibleForDiscount) {
        $appliedCol = $conn->query("SELECT auto_discount_applied FROM invoices WHERE id = $invoiceId");
        $alreadyApplied = false;
        if ($appliedCol && $appliedCol->num_rows) {
            $alreadyApplied = (int)$appliedCol->fetch_assoc()['auto_discount_applied'] === 1;
        }
        if (!$alreadyApplied) {
            $discountPercent = 0.20;
            $discountToApply = round($invoice['net_amount'] * $discountPercent, 2);
            if ($discountToApply > 0) {
                // update invoice values
                $newDiscount = $invoice['discount_amount'] + $discountToApply;
                $newNet = $invoice['net_amount'] - $discountToApply;
                $newBalance = $invoice['balance_amount'] - $discountToApply;
                $conn->query("UPDATE invoices SET discount_amount = $newDiscount, net_amount = $newNet, balance_amount = $newBalance, auto_discount_applied = 1 WHERE id = $invoiceId");
                // refresh invoice in memory
                $r = $conn->query("SELECT * FROM invoices WHERE id = $invoiceId");
                if ($r && $r->num_rows) $invoice = $r->fetch_assoc();
            }
        }
    }

    if ($paymentAmount <= 0) {
        setFlashMessage('error', 'Payment amount must be greater than zero.');
    } else {
        // Allow combined payment when same-day and same-patient invoices exist
        $doCombine = isset($_POST['combine_today']) && $_POST['combine_today'] === '1' && !empty($otherInvoices);
        if ($doCombine) {
            $allInvoices = array_merge([$invoice], $otherInvoices);
            $totalDue = 0.0;

            // Start transaction and validate each invoice
            $conn->begin_transaction();
            foreach ($allInvoices as $invCheck) {
                $iid = (int)$invCheck['id'];
                $cur = $conn->query("SELECT id, balance_amount FROM invoices WHERE id = $iid FOR UPDATE");
                if (!$cur || $cur->num_rows === 0) {
                    $conn->rollback();
                    setFlashMessage('error', 'Invoice not found during combined payment.');
                    redirect('modules/billing/payment.php?invoice_id=' . $invoiceId);
                    exit;
                }
                $rowInv = $cur->fetch_assoc();
                if ((float)$rowInv['balance_amount'] <= 0) {
                    $conn->rollback();
                    setFlashMessage('error', 'One of the selected invoices is already paid. Aborting combined payment.');
                    redirect('modules/billing/payment.php?invoice_id=' . $invoiceId);
                    exit;
                }
                // check for lab pending items on each invoice
                $labPendingCheck = $conn->query("SELECT ii.id FROM invoice_items ii JOIN laboratory_requests lr ON ii.reference_id = lr.id WHERE ii.invoice_id = $iid AND ii.reference_type = 'laboratory' AND lr.status != 'completed' LIMIT 1");
                if ($labPendingCheck && $labPendingCheck->num_rows > 0) {
                    $conn->rollback();
                    setFlashMessage('error', 'One of the selected invoices has pending laboratory items. Complete labs first.');
                    redirect('modules/billing/payment.php?invoice_id=' . $invoiceId);
                    exit;
                }
                $totalDue += (float)$rowInv['balance_amount'];
            }

            if (abs($paymentAmount - $totalDue) > 0.01) {
                $conn->rollback();
                setFlashMessage('error', 'Payment amount does not match the combined due amount (' . formatCurrency($totalDue) . ').');
                redirect('modules/billing/payment.php?invoice_id=' . $invoiceId);
                exit;
            }

            // Process payments for each invoice
            $okAll = true;
            foreach ($allInvoices as $invProc) {
                $iid = (int)$invProc['id'];
                $cur2 = $conn->query("SELECT balance_amount, net_amount, paid_amount FROM invoices WHERE id = $iid LIMIT 1");
                $r2 = $cur2->fetch_assoc();
                $bal = (float)$r2['balance_amount'];
                if ($bal <= 0) { $okAll = false; break; }

                $stmt = $conn->prepare("INSERT INTO payments (invoice_id, payment_amount, payment_method, payment_reference, received_by, notes) VALUES (?, ?, ?, ?, ?, ?)");
                if (!$stmt) { $okAll = false; break; }
                $stmt->bind_param('idssis', $iid, $bal, $paymentMethod, $paymentReference, $_SESSION['user_id'], $notes);
                if (!$stmt->execute()) { $okAll = false; $stmt->close(); break; }
                $stmt->close();

                // update invoice
                $newPaid = (float)$r2['paid_amount'] + $bal;
                $newBalance = (float)$r2['net_amount'] - $newPaid;
                $newStatus = $newBalance <= 0 ? 'paid' : 'partial';
                $u = $conn->query("UPDATE invoices SET paid_amount = $newPaid, balance_amount = $newBalance, status = '$newStatus', payment_method = '$paymentMethod' WHERE id = $iid");
                if (!$u) { $okAll = false; break; }
            }

            if ($okAll) {
                $conn->commit();
                logActivity('payment', 'invoices', $invoiceId);
                setFlashMessage('success', 'Combined payment processed successfully!');
                redirect('modules/billing/receipt.php?id=' . $invoiceId);
                exit;
            } else {
                $conn->rollback();
                setFlashMessage('error', 'Failed to process combined payment. No changes were made.');
                redirect('modules/billing/payment.php?invoice_id=' . $invoiceId);
                exit;
            }
        }

        // Fallback: single-invoice payment
        if ($paymentAmount > $invoice['balance_amount']) {
            setFlashMessage('error', 'Payment amount cannot exceed the balance.');
        } else {
            $stmt = $conn->prepare("INSERT INTO payments (invoice_id, payment_amount, payment_method, payment_reference, received_by, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("idssis", $invoiceId, $paymentAmount, $paymentMethod, $paymentReference, $_SESSION['user_id'], $notes);
            
            if ($stmt->execute()) {
                $newPaidAmount = $invoice['paid_amount'] + $paymentAmount;
                $newBalance = $invoice['net_amount'] - $newPaidAmount;
                $newStatus = $newBalance <= 0 ? 'paid' : 'partial';

                $conn->query("UPDATE invoices SET paid_amount = $newPaidAmount, balance_amount = $newBalance, status = '$newStatus', payment_method = '$paymentMethod' WHERE id = $invoiceId");

                if ($newStatus === 'paid') {
                    $visitId = isset($invoice['visit_id']) ? (int)$invoice['visit_id'] : 0;
                    if ($visitId > 0) {
                        $r = $conn->query("SELECT COUNT(*) as cnt FROM invoices WHERE visit_id = $visitId AND (status != 'paid' OR balance_amount > 0)");
                        if ($r) {
                            $cnt = (int)$r->fetch_assoc()['cnt'];
                            if ($cnt === 0) {
                                $hasUpdatedByCol = false;
                                $colRes = $conn->query("SHOW COLUMNS FROM patient_visits LIKE 'updated_by'");
                                if ($colRes && $colRes->num_rows > 0) $hasUpdatedByCol = true;
                                $uid = (int)($_SESSION['user_id'] ?? 0);
                                if ($hasUpdatedByCol) {
                                    $conn->query("UPDATE patient_visits SET status = 'discharged', updated_by = $uid WHERE id = $visitId");
                                } else {
                                    $conn->query("UPDATE patient_visits SET status = 'discharged' WHERE id = $visitId");
                                }
                                logActivity('update', 'patient_visits', $visitId, null, json_encode(['status'=>'discharged']));
                            }
                        }
                    }
                }

                logActivity('payment', 'invoices', $invoiceId);
                setFlashMessage('success', 'Payment of ' . formatCurrency($paymentAmount) . ' processed successfully!');
                redirect('modules/billing/receipt.php?id=' . $invoiceId);
            } else {
                setFlashMessage('error', 'Error processing payment: ' . $stmt->error);
            }

            $stmt->close();
        }
    }
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Process Payment</h1>
        <p class="page-subtitle">Invoice: <?php echo $invoice['invoice_number']; ?></p>
    </div>
    <a href="invoices.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
    <!-- Invoice Details -->
    <div>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-file-invoice"></i> Invoice Details</h3>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Patient</label>
                                        <p><strong><?php echo htmlspecialchars(($invoice['first_name'] ?? '') . ' ' . ($invoice['last_name'] ?? '')); ?></strong></p>
                                        <p class="text-muted"><?php echo htmlspecialchars($invoice['patient_code'] ?? ''); ?></p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contact</label>
                        <p><?php echo htmlspecialchars($invoice['contact_number'] ?? ''); ?></p>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Address</label>
                        <p><?php echo nl2br(htmlspecialchars($invoice['address'] ?? '')); ?></p>
                    </div>
                </div>
                
                <hr style="margin: 20px 0;">
                
                <h4 style="margin-bottom: 15px;">Invoice Items</h4>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($item = $itemsResult->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $item['item_description']; ?></td>
                                <td><?php echo $item['quantity']; ?></td>
                                <td><?php echo formatCurrency($item['unit_price']); ?></td>
                                <td><?php echo formatCurrency($item['total_price']); ?></td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if (empty($debugInvoiceItems) && abs($computedTotal) < 0.001): ?>
                                <tr><td colspan="4"><div class="alert alert-warning">No invoice items found for this invoice (computed total is zero).</div></td></tr>
                            <?php elseif (!empty($debugInvoiceItems)): ?>
                                <tr><td colspan="4">
                                    <div class="alert alert-danger"><strong>Debug:</strong> Found <?php echo count($debugInvoiceItems); ?> invoice_items rows. Check DB.</div>
                                </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($otherInvoices)): ?>
                <div style="margin-top:12px; padding:12px 15px; background:#f0f7ff; border:1px solid #b3d0f5; border-radius:6px;">
                    <p style="margin:0 0 8px; font-weight:600; color:#1565c0;"><i class="fas fa-info-circle"></i> This patient has <?php echo count($otherInvoices); ?> other pending invoice(s) from today:</p>
                    <ul style="margin:0 0 10px; padding-left:20px; color:#444; font-size:14px;">
                        <?php foreach ($otherInvoices as $oi): ?>
                        <li><?php echo htmlspecialchars($oi['invoice_number']); ?> — <?php echo formatCurrency($oi['balance_amount']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <hr style="margin: 20px 0;">
                
                <div style="text-align: right;">
                    <p>Total: <strong><?php echo formatCurrency($invoice['total_amount']); ?></strong></p>
                    <?php if ((float)($invoice['discount_amount'] ?? 0) > 0): ?>
                    <p>Discount: <?php echo formatCurrency($invoice['discount_amount']); ?></p>
                    <?php endif; ?>
                    <?php if ((float)($invoice['tax_amount'] ?? 0) > 0): ?>
                    <p>Tax: <?php echo formatCurrency($invoice['tax_amount']); ?></p>
                    <?php endif; ?>
                    <p>Net Amount: <strong><?php echo formatCurrency($invoice['net_amount']); ?></strong></p>
                    <p>Paid: <?php echo formatCurrency($invoice['paid_amount']); ?></p>
                    <p style="font-size: 18px; color: var(--primary-color);">
                        Balance: <strong><?php echo formatCurrency($invoice['balance_amount']); ?></strong>
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Payment History -->
        <?php if ($paymentsResult->num_rows > 0): ?>
        <div class="card" style="margin-top: 25px;">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-history"></i> Payment History</h3>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Received By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($payment = $paymentsResult->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo formatDateTime($payment['payment_date']); ?></td>
                                <td><?php echo formatCurrency($payment['payment_amount']); ?></td>
                                <td><?php echo ucfirst($payment['payment_method']); ?></td>
                                <td><?php echo $payment['received_by_name']; ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Payment Form -->
    <div>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-cash-register"></i> Payment</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <?php if (!empty($otherInvoices)): ?>
                    <div class="form-group" style="background:#fffbe6; border:1px solid #ffe082; border-radius:6px; padding:12px 15px; margin-bottom:15px;">
                        <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:600; color:#7b5e00;">
                            <input type="checkbox" id="combine_today_chk" name="combine_today" value="1" style="width:18px; height:18px; cursor:pointer;">
                            Also pay <?php echo count($otherInvoices); ?> other pending invoice(s) from today
                            <span style="font-weight:400; color:#888; font-size:13px;">(<?php echo formatCurrency($combinedTotal); ?> additional)</span>
                        </label>
                        <p style="margin:6px 0 0 28px; font-size:13px; color:#666;">Check this box to settle all of this patient's today's invoices in one transaction.</p>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label">Payment Amount <span style="color: red;">*</span></label>
                        <div style="position: relative;">
                            <span style="position: absolute; left: 15px; top: 50%; transform: translateY(-50%);">₱</span>
                            <input type="number" step="0.01" name="payment_amount" class="form-control" 
                                max="<?php echo !empty($otherInvoices) ? ($combinedTotal + ($invoice['balance_amount'] ?? 0.0)) : $invoice['balance_amount']; ?>" 
                                value="<?php echo !empty($otherInvoices) ? ($combinedTotal + ($invoice['balance_amount'] ?? 0.0)) : $invoice['balance_amount']; ?>"
                                style="padding-left: 30px;" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Payment Method <span style="color: red;">*</span></label>
                        <select id="payment_method" name="payment_method" class="form-control" required>
                            <option value="cash">Cash</option>
                            <option value="gcash">GCash</option>
                        </select>
                    </div>

                    <div class="form-group" id="payment-reference-group">
                        <label class="form-label">Reference Number <span id="ref-required" style="color: red; display:none;">*</span></label>
                        <input type="text" id="payment_reference" name="payment_reference" class="form-control" 
                               placeholder="Check number, transaction ID, etc.">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Any additional notes..."></textarea>
                    </div>
                    
                    <?php if ($labItemsPending): ?>
                        <div class="alert alert-warning">There are laboratory items on this invoice with pending or incomplete results. Complete the lab results before processing payment.</div>
                        <button type="button" class="btn btn-success btn-block" disabled style="padding: 15px; font-size: 16px; opacity:0.7;">
                            <i class="fas fa-check-circle"></i> Process Payment
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-success btn-block" style="padding: 15px; font-size: 16px;">
                            <i class="fas fa-check-circle"></i> Process Payment
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var pm = document.getElementById('payment_method');
    var refGroup = document.getElementById('payment-reference-group');
    var refInput = document.getElementById('payment_reference');
    var refReq = document.getElementById('ref-required');

    function updateRefVisibility() {
        if (!pm) return;
        if (pm.value === 'cash') {
            // hide reference for cash
            if (refGroup) refGroup.style.display = 'none';
            if (refInput) { refInput.removeAttribute('required'); refInput.value = ''; }
            if (refReq) refReq.style.display = 'none';
        } else {
            if (refGroup) refGroup.style.display = '';
            if (refInput) refInput.setAttribute('required', 'required');
            if (refReq) refReq.style.display = '';
        }
    }

    if (pm) {
        pm.addEventListener('change', updateRefVisibility);
        updateRefVisibility();
    }
    // Payment amount logic: default = current invoice balance; updates when combine checkbox toggled
    var paymentAmountInput = document.querySelector('input[name="payment_amount"]');
    var combineChk = document.getElementById('combine_today_chk');
    var singleBalance = <?php echo json_encode((float)($invoice['balance_amount'] ?? 0.0)); ?>;
    var fullCombinedBalance = <?php echo json_encode((float)($combinedTotal ?? 0) + (float)($invoice['balance_amount'] ?? 0.0)); ?>;

    function updatePaymentAmount() {
        if (!paymentAmountInput) return;
        if (combineChk && combineChk.checked) {
            paymentAmountInput.value = parseFloat(fullCombinedBalance).toFixed(2);
            paymentAmountInput.max = parseFloat(fullCombinedBalance).toFixed(2);
        } else {
            paymentAmountInput.value = parseFloat(singleBalance).toFixed(2);
            paymentAmountInput.max = parseFloat(singleBalance).toFixed(2);
        }
    }

    if (combineChk) {
        combineChk.addEventListener('change', updatePaymentAmount);
    }
    updatePaymentAmount();
});
</script>
