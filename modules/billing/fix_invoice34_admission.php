<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin']);

$conn = getDBConnection();
$invoiceId = 34; // INV202600034

echo "<h2>FIX ZERO PAYMENT - INV202600034 (Admission Patient)</h2>";

// 1. Get invoice & patient
$invQ = $conn->query("SELECT * FROM invoices i JOIN patients p ON i.patient_id = p.id WHERE i.id = $invoiceId");
if ($invQ->num_rows == 0) die("Invoice not found");
$inv = $invQ->fetch_assoc();
echo "<p><strong>Invoice:</strong> " . $inv['invoice_number'] . "<br>";
echo "<strong>Patient:</strong> " . $inv['first_name'] . " " . $inv['last_name'] . " (" . $inv['patient_code'] . ")</p>";

// 2. Check admission & days stayed
$admQ = $conn->query("SELECT * FROM admissions WHERE patient_id = " . $inv['patient_id']);
$days = 1; $roomRate = 800.00;
if ($admQ && $admQ->num_rows > 0) {
    $adm = $admQ->fetch_assoc();
    $admitDt = new DateTime($adm['admission_date']);
    $dischDt = $adm['actual_discharge_date'] ? new DateTime($adm['actual_discharge_date']) : new DateTime();
    $days = max(1, $dischDt->diff($admitDt)->days);
    echo "<p><strong>Admission:</strong> " . $adm['admission_code'] . ", Days: $days</p>";
}

// 3. Check existing items
$itemCnt = $conn->query("SELECT COUNT(*) c FROM invoice_items WHERE invoice_id = $invoiceId")->fetch_assoc()['c'];
echo "<p>Current invoice_items: $itemCnt</p>";

if ($itemCnt == 0) {
    // ADD admission-related items
    $items = [
        ['Admission - Room & Board (' . $days . ' days)', $days, $roomRate, $days * $roomRate],
        ['Admission Fee', 1, 1000.00, 1000.00],
        ['General Consultation (Admitting)', 1, 500.00, 500.00],
    ];
    
    foreach ($items as $it) {
        $desc = $conn->real_escape_string($it[0]);
        $qty = $it[1];
        $unit = $it[2];
        $total = $it[3];
        $conn->query("INSERT INTO invoice_items (invoice_id, item_description, quantity, unit_price, total_price, reference_type) 
                      VALUES ($invoiceId, '$desc', $qty, $unit, $total, 'admission')");
        echo "<p>Added: " . $it[0] . " = ₱" . number_format($total,2) . "</p>";
    }
    
    // Recalculate totals
    $sumTotal = $conn->query("SELECT SUM(total_price) t FROM invoice_items WHERE invoice_id = $invoiceId")->fetch_assoc()['t'];
    $conn->query("UPDATE invoices SET total_amount = $sumTotal, net_amount = $sumTotal, balance_amount = $sumTotal WHERE id = $invoiceId");
    echo "<p><strong>TOTAL FIXED: ₱" . number_format($sumTotal,2) . " BALANCE NOW AVAILABLE FOR PAYMENT</strong></p>";
} else {
    echo "<p>Items already exist. Check <a href='invoices.php'>Invoices page</a></p>";
}

echo "<p><a href='invoices.php'>← Back to Invoices</a> | <a href='invoice-view.php?id=$invoiceId'>View Invoice</a></p>";
$conn->close();
?>

