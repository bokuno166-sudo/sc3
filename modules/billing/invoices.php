<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['cashier']);

$pageTitle = 'Invoices & Billing';
$currentPage = 'invoices';

$conn = getDBConnection();

// Get pending invoices (exclude invoices that contain laboratory items with incomplete requests)
$pendingInvoices = $conn->query("
        SELECT i.*, p.first_name, p.last_name, p.patient_code
        FROM invoices i
        JOIN patients p ON i.patient_id = p.id
        WHERE i.status IN ('pending', 'partial')
            AND NOT EXISTS (
                    SELECT 1 FROM invoice_items ii
                    JOIN laboratory_requests lr ON ii.reference_id = lr.id
                    WHERE ii.invoice_id = i.id AND (ii.reference_type = 'laboratory' OR ii.reference_type LIKE '%lab%') AND lr.status != 'completed'
            )
        ORDER BY i.created_at DESC
");

// Get paid invoices today — listed individually to keep amount consistent with receipts
$paidToday = $conn->query("
    SELECT
        i.id,
        i.invoice_number,
        p.first_name, p.last_name, p.patient_code,
        i.paid_amount,
        i.payment_method,
        i.updated_at,
        1 as invoice_count
    FROM invoices i
    JOIN patients p ON i.patient_id = p.id
    WHERE i.status = 'paid' AND DATE(i.updated_at) = CURDATE()
    ORDER BY i.updated_at DESC
");

// collect paid rows into array for controlled display (so we can show first N and toggle)
$paidRows = [];
if ($paidToday && $paidToday->num_rows > 0) {
    while ($r = $paidToday->fetch_assoc()) { $paidRows[] = $r; }
}
$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Invoices & Billing</h1>
        <p class="page-subtitle">Manage patient billing and payments</p>
    </div>
</div>

<!-- Pending Invoices -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-file-invoice-dollar"></i> Pending Invoices</h3>
        <span class="badge badge-warning"><?php echo $pendingInvoices->num_rows; ?> Pending</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if ($pendingInvoices && $pendingInvoices->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Patient</th>
                        <th>Total Amount</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($invoice = $pendingInvoices->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo $invoice['invoice_number']; ?></strong></td>
                        <td>
                            <?php echo $invoice['first_name'] . ' ' . $invoice['last_name']; ?><br>
                            <small class="text-muted"><?php echo $invoice['patient_code']; ?></small>
                        </td>
                        <td><?php echo formatCurrency($invoice['net_amount']); ?></td>
                        <td><?php echo formatCurrency($invoice['paid_amount']); ?></td>
                        <td><strong><?php echo formatCurrency($invoice['balance_amount']); ?></strong></td>
                        <td><?php echo getStatusBadge($invoice['status']); ?></td>
                        <td><?php echo formatDate($invoice['created_at']); ?></td>
                        <td class="table-actions">
                            <a href="payment.php?invoice_id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-success">
                                <i class="fas fa-cash-register"></i> Pay
                            </a>
                            <a href="invoice-view.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-info">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding: 40px; text-align: center; color: #999;">
            <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 15px;"></i>
            <p>No pending invoices</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Paid Today (show up to 4, toggle to show all) -->
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:12px;">
            <h3 class="card-title" style="margin:0;"><i class="fas fa-check-circle"></i> Paid Today</h3>
            <span class="badge badge-success"><?php echo count($paidRows); ?> Paid</span>
        </div>
        <div>
            <button id="showAllPaid" class="btn btn-sm btn-link">Show all</button>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (!empty($paidRows)): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Patient</th>
                        <th>Amount Paid</th>
                        <th>Payment Method</th>
                        <th>Time</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="paidRowsTbody">
                    <?php foreach ($paidRows as $idx => $invoice): ?>
                    <tr class="paid-row" data-idx="<?php echo $idx; ?>" style="<?php echo $idx >= 4 ? 'display:none;' : ''; ?>">
                        <td>
                            <strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong>
                            <?php if ((int)$invoice['invoice_count'] > 1): ?>
                            <br><small class="text-muted"><?php echo $invoice['invoice_count']; ?> invoices combined</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']); ?><br>
                            <small class="text-muted"><?php echo htmlspecialchars($invoice['patient_code']); ?></small>
                        </td>
                        <td><?php echo formatCurrency($invoice['paid_amount']); ?></td>
                        <td><?php echo ucfirst(htmlspecialchars($invoice['payment_method'])); ?></td>
                        <td><?php echo formatDateTime($invoice['updated_at'], 'h:i A'); ?></td>
                        <td class="table-actions">
                            <a href="invoice-view.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-info">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="receipt.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-secondary" target="_blank">
                                <i class="fas fa-receipt"></i> Receipt
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding: 40px; text-align: center; color: #999;">
            <i class="fas fa-info-circle" style="font-size: 48px; margin-bottom: 15px;"></i>
            <p>No payments received today</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('showAllPaid').addEventListener('click', function(){
    var rows = document.querySelectorAll('.paid-row');
    var hidden = Array.prototype.slice.call(rows).filter(function(r){ return r.style.display === 'none' || getComputedStyle(r).display === 'none'; });
    if (hidden.length > 0) {
        // show all
        rows.forEach(function(r){ r.style.display = ''; });
        this.innerText = 'Show less';
    } else {
        // collapse back to 4
        rows.forEach(function(r, i){ r.style.display = i < 4 ? '' : 'none'; });
        this.innerText = 'Show all';
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
