<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'cashier']);

$pageTitle = 'Invoice Details';
$currentPage = 'invoices';

$conn = getDBConnection();
$invoiceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($invoiceId <= 0 && isset($_GET['invoice_id'])) {
    $invoiceId = (int)$_GET['invoice_id'];
}
if ($invoiceId <= 0 && isset($_GET['invoice_number'])) {
    $invoiceNumber = $conn->real_escape_string(trim($_GET['invoice_number']));
    $lookup = $conn->query("SELECT id FROM invoices WHERE invoice_number = '$invoiceNumber' LIMIT 1");
    if ($lookup && $lookup->num_rows > 0) {
        $invoiceId = (int)$lookup->fetch_assoc()['id'];
    }
}
if ($invoiceId <= 0) {
    echo "Invalid invoice specified.";
    exit;
}

$invRes = $conn->query("SELECT i.*, p.first_name, p.last_name, p.patient_code, p.address, p.contact_number FROM invoices i JOIN patients p ON i.patient_id = p.id WHERE i.id = $invoiceId LIMIT 1");
if (!$invRes || $invRes->num_rows === 0) {
    echo "Invoice not found.";
    exit;
}
$invoice = $invRes->fetch_assoc();
// Compute total from invoice items (default fallback)
$sumRes = $conn->query("SELECT COALESCE(SUM(total_price),0) as items_total FROM invoice_items WHERE invoice_id = $invoiceId");
$computedTotal = ($sumRes && $sumRes->num_rows) ? (float)$sumRes->fetch_assoc()['items_total'] : 0.0;

// AUTO-COMPUTE ALL ADMISSION CHARGES (room, lab, consultation, medicines)
$admissionId = isset($invoice['admission_id']) ? (int)$invoice['admission_id'] : 0;
if ($admissionId > 0) {
    require_once __DIR__ . '/auto_admission_charges.php';
    $chargeResult = auto_admission_charges($conn, $admissionId);
    if (isset($chargeResult['status']) && $chargeResult['status'] === 'success') {
        $sumRes = $conn->query("SELECT COALESCE(SUM(total_price),0) as items_total FROM invoice_items WHERE invoice_id = $invoiceId");
        $computedTotal = (float)$sumRes->fetch_assoc()['items_total'];
    }
}


// Apply auto discount
require_once __DIR__ . '/discounts.php';
$discRes = apply_auto_discount($conn, $invoiceId);
if (isset($discRes['status']) && $discRes['status'] === 'applied') {
    $r = $conn->query("SELECT * FROM invoices WHERE id = $invoiceId");
    if ($r && $r->num_rows) $invoice = $r->fetch_assoc();
}

// Recalculate and sync invoice record
$discount = (float)($invoice['discount_amount'] ?? 0);
$tax      = (float)($invoice['tax_amount'] ?? 0);
$paid     = (float)($invoice['paid_amount'] ?? 0);
$computedNet     = $computedTotal - $discount + $tax;
$computedBalance = $computedNet - $paid;
if (
    abs((float)$invoice['total_amount']   - $computedTotal)   > 0.001 ||
    abs((float)$invoice['net_amount']     - $computedNet)     > 0.001 ||
    abs((float)$invoice['balance_amount'] - $computedBalance) > 0.001
) {
    $uStmt = $conn->prepare("UPDATE invoices SET total_amount=?, net_amount=?, balance_amount=? WHERE id=?");
    if ($uStmt) {
        $uStmt->bind_param('dddi', $computedTotal, $computedNet, $computedBalance, $invoiceId);
        $uStmt->execute(); $uStmt->close();
        $invoice['total_amount']   = $computedTotal;
        $invoice['net_amount']     = $computedNet;
        $invoice['balance_amount'] = $computedBalance;
    }
}

// Show only this invoice's own items and totals (disable outpatient same-day grouping to avoid payment mismatch)
$allIds  = [$invoiceId];
$idsList = (string)$invoiceId;


// All items across all invoices
$itemsRes = $conn->query(
    "SELECT item_description, SUM(quantity) as quantity, unit_price, SUM(total_price) as total_price
     FROM invoice_items WHERE invoice_id IN ($idsList)
     GROUP BY item_description, unit_price
     ORDER BY MIN(id)"
);

// Aggregated totals
$totals = $conn->query(
    "SELECT SUM(total_amount) as total_amount, SUM(discount_amount) as discount_amount,
            SUM(tax_amount) as tax_amount,     SUM(net_amount) as net_amount,
            SUM(paid_amount) as paid_amount,   SUM(balance_amount) as balance_amount
     FROM invoices WHERE id IN ($idsList)"
)->fetch_assoc();

// Consolidated payment history (grouped by minute + method + cashier)
$paymentsRes = $conn->query(
    "SELECT DATE_FORMAT(p.payment_date, '%Y-%m-%d %H:%i') as pay_minute,
            SUM(p.payment_amount) as payment_amount,
            p.payment_method,
            MAX(p.payment_reference) as payment_reference,
            MAX(u.full_name) as received_by_name,
            MIN(p.payment_date) as payment_date
     FROM payments p
     LEFT JOIN users u ON p.received_by = u.id
     WHERE p.invoice_id IN ($idsList)
     GROUP BY pay_minute, p.payment_method, p.received_by
     ORDER BY payment_date DESC"
);

// Is this a combined invoice group?
$isCombined = count($allIds) > 1;

$conn->close();

include __DIR__ . '/../../includes/header.php';

?>
<div class="page-header">
    <div>
        <h1 class="page-title">Invoice Details</h1>
        <p class="page-subtitle">
            Invoice: <?php echo htmlspecialchars($invoice['invoice_number']); ?>
            <?php if ($isCombined): ?>
            <span class="badge badge-info" style="margin-left:8px; font-size:12px;">Combined Payment &mdash; <?php echo count($allIds); ?> invoices</span>
            <?php endif; ?>
        </p>
    </div>
    <div>
        <?php if ((float)($totals['balance_amount'] ?? $invoice['balance_amount']) > 0): ?>
            <a href="payment.php?invoice_id=<?php echo $invoice['id']; ?>" class="btn btn-success"><i class="fas fa-cash-register"></i> Pay</a>
        <?php endif; ?>
        <a href="receipt.php?id=<?php echo $invoice['id']; ?>" class="btn btn-secondary" target="_blank"><i class="fas fa-receipt"></i> Receipt</a>
        <a href="invoices.php" class="btn btn-light">Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
            <div>
                <h3><?php echo APP_NAME; ?></h3>
                <p><?php echo nl2br(htmlspecialchars($invoice['address'] ?? '')); ?></p>
            </div>
            <div style="text-align:right;">
                <p><strong>Invoice #: </strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                <p><strong>Date: </strong><?php echo htmlspecialchars(formatDateTime($invoice['created_at'])); ?></p>
            </div>
        </div>

        <hr>

        <div style="display:flex; justify-content:space-between;">
            <div>
                <p><strong>Patient:</strong> <?php echo htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']); ?></p>
                <p><strong>Code:</strong> <?php echo htmlspecialchars($invoice['patient_code']); ?></p>
            </div>
            <div style="text-align:right;">
                <p><strong>Contact:</strong> <?php echo htmlspecialchars($invoice['contact_number']); ?></p>
            </div>
        </div>

        <div class="table-container" style="margin-top:15px;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Qty</th>
                        <th>Unit</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($it = $itemsRes->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($it['item_description']); ?></td>
                        <td><?php echo intval($it['quantity']); ?></td>
                        <td><?php echo formatCurrency($it['unit_price']); ?></td>
                        <td><?php echo formatCurrency($it['total_price']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <div style="text-align:right; margin-top:10px;">
            <p>Subtotal: <?php echo formatCurrency($totals['total_amount'] ?? $invoice['total_amount']); ?></p>
            <?php if (($totals['discount_amount'] ?? $invoice['discount_amount']) > 0): ?>
            <p>Discount: <?php echo formatCurrency($totals['discount_amount'] ?? $invoice['discount_amount']); ?></p>
            <?php endif; ?>
            <?php if (($totals['tax_amount'] ?? $invoice['tax_amount']) > 0): ?>
            <p>Tax: <?php echo formatCurrency($totals['tax_amount'] ?? $invoice['tax_amount']); ?></p>
            <?php endif; ?>
            <p><strong>Net: <?php echo formatCurrency($totals['net_amount'] ?? $invoice['net_amount']); ?></strong></p>
            <p>Paid: <?php echo formatCurrency($totals['paid_amount'] ?? $invoice['paid_amount']); ?></p>
            <p style="font-size:18px; color:var(--primary-color);"><strong>Balance: <?php echo formatCurrency($totals['balance_amount'] ?? $invoice['balance_amount']); ?></strong></p>
        </div>

        <?php if ($paymentsRes && $paymentsRes->num_rows > 0): ?>
        <hr>
        <h4>Payment History</h4>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Received By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($p = $paymentsRes->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo formatDateTime($p['payment_date']); ?></td>
                        <td><?php echo formatCurrency($p['payment_amount']); ?></td>
                        <td><?php echo ucfirst($p['payment_method']); ?></td>
                        <td><?php echo htmlspecialchars($p['payment_reference']); ?></td>
                        <td><?php echo htmlspecialchars($p['received_by_name']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
