<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'cashier', 'doctor']);

$pageTitle = 'Payment Receipt';
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

// Show only this invoice's own items and totals (disable outpatient same-day grouping to avoid payment mismatch)
$invoiceIds = [$invoiceId];
$idsList = (string)$invoiceId;


$itemsRes = $conn->query("SELECT item_description, SUM(quantity) as quantity, unit_price, SUM(total_price) as total_price FROM invoice_items WHERE invoice_id IN ($idsList) GROUP BY item_description, unit_price");

// Aggregate payments into grouped rows (combined payment = same minute + method + cashier)
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

// Aggregate totals for the receipt
$totals = $conn->query("SELECT SUM(total_amount) as total_amount, SUM(discount_amount) as discount_amount, SUM(tax_amount) as tax_amount, SUM(net_amount) as net_amount, SUM(paid_amount) as paid_amount, SUM(balance_amount) as balance_amount FROM invoices WHERE id IN ($idsList)")->fetch_assoc();

// Apply auto discount here as well so receipt reflects any senior/PWD discount
require_once __DIR__ . '/discounts.php';
$discRes = apply_auto_discount($conn, $invoiceId);
if (isset($discRes['status']) && $discRes['status'] === 'applied') {
    // refresh invoice after discount applied
    $r = $conn->query("SELECT * FROM invoices WHERE id = $invoiceId");
    if ($r && $r->num_rows) $invoice = $r->fetch_assoc();
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Payment Receipt</h1>
        <p class="page-subtitle">Invoice: <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
    </div>
    <div>
        <button onclick="window.print();" class="btn btn-primary"><i class="fas fa-print"></i> Print</button>
        <a href="invoices.php" class="btn btn-secondary">Back</a>
    </div>
</div>

<div class="card" style="max-width:800px; margin: 0 auto;">
    <div class="card-body">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:72px; height:72px; display:flex; align-items:center; justify-content:center;">
                    <?php if (file_exists(__DIR__ . '/../../assets/logo.png')): ?>
                        <img src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/logo.png" alt="logo" style="max-width:72px; max-height:72px; display:block;">
                    <?php elseif (file_exists(__DIR__ . '/../../assets/logo.svg')): ?>
                        <img src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/logo.svg" alt="logo" style="max-width:72px; max-height:72px; display:block;">
                    <?php else: ?>
                        <div style="font-weight:700; color:var(--primary-color);">SC</div>
                    <?php endif; ?>
                </div>
                <div>
                    <h2 style="margin:0;">Saint Claire</h2>
                </div>
            </div>
            <div style="text-align:right;">
                <p><strong>Receipt #: </strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                <p><strong>Date: </strong><?php echo htmlspecialchars(formatDateTime($invoice['updated_at'] ?: $invoice['created_at'])); ?></p>
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

        <div style="margin-top:25px; display:flex; justify-content:space-between; align-items:center;">
            <div>
                <p class="text-muted">Thank you for your payment.</p>
            </div>
            <div style="text-align:center;">
                <p>__________________________</p>
                <p>Cashier / Authorized Signatory</p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
