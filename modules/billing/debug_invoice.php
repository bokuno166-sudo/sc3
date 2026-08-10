<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin']);

$conn = getDBConnection();

$invoiceNumber = isset($_GET['invoice_number']) ? $conn->real_escape_string($_GET['invoice_number']) : null;
$patientCode = isset($_GET['patient_code']) ? $conn->real_escape_string($_GET['patient_code']) : null;

$invoice = null;
if ($invoiceNumber) {
    $r = $conn->query("SELECT * FROM invoices WHERE invoice_number = '" . $invoiceNumber . "' LIMIT 1");
    if ($r && $r->num_rows) $invoice = $r->fetch_assoc();
} elseif ($patientCode) {
    $r = $conn->query("SELECT i.* FROM invoices i JOIN patients p ON i.patient_id = p.id WHERE p.patient_code = '" . $patientCode . "' ORDER BY i.created_at DESC LIMIT 1");
    if ($r && $r->num_rows) $invoice = $r->fetch_assoc();
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Debug Invoice</title>
    <style>body{font-family:Arial,Helvetica,sans-serif;padding:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:8px;text-align:left}</style>
</head>
<body>
<h2>Debug Invoice</h2>
<p>Provide <strong>invoice_number</strong> or <strong>patient_code</strong> as query param.</p>
<?php if (!$invoice): ?>
    <div style="color:darkred">Invoice not found. Try ?patient_code=P202600021 or ?invoice_number=INV202600034</div>
<?php else: ?>
    <h3>Invoice</h3>
    <table>
        <thead><tr><th>Field</th><th>Value</th></tr></thead>
        <tbody>
        <?php foreach ($invoice as $k => $v): ?>
            <tr><td><?php echo htmlspecialchars($k); ?></td><td><?php echo htmlspecialchars((string)$v); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3 style="margin-top:20px">Invoice Items</h3>
    <?php
    $items = [];
    $ir = $conn->query("SELECT * FROM invoice_items WHERE invoice_id = " . (int)$invoice['id'] . " ORDER BY id ASC");
    if ($ir && $ir->num_rows) {
        while ($row = $ir->fetch_assoc()) $items[] = $row;
    }
    ?>
    <?php if (empty($items)): ?>
        <div style="color:darkorange">No invoice_items rows for this invoice.</div>
    <?php else: ?>
        <table style="margin-top:10px">
            <thead><tr><th>id</th><th>description</th><th>quantity</th><th>unit_price</th><th>total_price</th><th>reference_type</th><th>reference_id</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><?php echo htmlspecialchars($it['id']); ?></td>
                    <td><?php echo htmlspecialchars($it['item_description']); ?></td>
                    <td><?php echo htmlspecialchars($it['quantity']); ?></td>
                    <td><?php echo htmlspecialchars($it['unit_price']); ?></td>
                    <td><?php echo htmlspecialchars($it['total_price']); ?></td>
                    <td><?php echo htmlspecialchars($it['reference_type']); ?></td>
                    <td><?php echo htmlspecialchars($it['reference_id']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
<?php $conn->close(); ?>
