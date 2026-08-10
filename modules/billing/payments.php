<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'cashier']);

$pageTitle = 'Payments';
$currentPage = 'invoices';

$conn = getDBConnection();

// Filters
$method = isset($_GET['method']) ? sanitize($_GET['method']) : '';
$from = isset($_GET['from']) ? sanitize($_GET['from']) : '';
$to = isset($_GET['to']) ? sanitize($_GET['to']) : '';

$where = [];
if ($method) $where[] = "p.payment_method = '" . $conn->real_escape_string($method) . "'";
if ($from) $where[] = "p.payment_date >= '" . $conn->real_escape_string($from) . " 00:00:00'";
if ($to) $where[] = "p.payment_date <= '" . $conn->real_escape_string($to) . " 23:59:59'";

$sql = "
    SELECT p.*, u.full_name as received_by_name, i.invoice_number, i.patient_id, pt.first_name, pt.last_name
    FROM payments p
    JOIN users u ON p.received_by = u.id
    JOIN invoices i ON p.invoice_id = i.id
    JOIN patients pt ON i.patient_id = pt.id
";

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY p.payment_date DESC';

$results = $conn->query($sql);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Payments</h1>
        <p class="page-subtitle">Payments received and history</p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Filter</h3>
        <div style="margin-left:auto;">
            <form method="GET" style="display:flex; gap:8px; align-items:center;">
                <select name="method" class="form-control">
                    <option value=" "> All methods </option>
                    <option value="cash" <?php echo $method==='cash' ? 'selected' : ''; ?>>Cash</option>
                    <option value="gcash" <?php echo $method==='gcash' ? 'selected' : ''; ?>>GCash</option>
                 </select>
                    <input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($from); ?>">
                    <input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($to); ?>">
                    <button class="btn btn-primary">Apply</button>
            </form>
        </div>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if ($results && $results->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Patient</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Received By</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($r = $results->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo formatDateTime($r['payment_date']); ?></td>
                        <td>
                            <a href="receipt.php?id=<?php echo $r['invoice_id']; ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo htmlspecialchars($r['invoice_number']); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></td>
                        <td><?php echo formatCurrency($r['payment_amount']); ?></td>
                        <td><?php echo ucfirst($r['payment_method']); ?></td>
                        <td><?php echo htmlspecialchars($r['payment_reference']); ?></td>
                        <td><?php echo htmlspecialchars($r['received_by_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['notes']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding:30px; text-align:center; color:#999;">No payments found.</div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
