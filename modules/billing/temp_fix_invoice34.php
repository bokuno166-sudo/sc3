<?php
require_once __DIR__ . '/../../config/config.php';
$conn = getDBConnection();

// Target invoice (ID 34 from URL)
$targetInvoiceId = 34;
$invQ = $conn->query("SELECT * FROM invoices WHERE id = $targetInvoiceId");
if (!$invQ || $invQ->num_rows == 0) {
    die("Invoice ID 34 not found.\n");
}
$invoice = $invQ->fetch_assoc();
echo "INVOICE: " . $invoice['invoice_number'] . " | Patient ID: " . $invoice['patient_id'] . " | Net: " . $invoice['net_amount'] . " | Paid: " . $invoice['paid_amount'] . " | Balance: " . $invoice['balance_amount'] . "\n\n";

$patientQ = $conn->query("SELECT * FROM patients WHERE id = " . $invoice['patient_id']);
$patient = $patientQ->fetch_assoc();
echo "PATIENT: " . $patient['patient_code'] . " " . $patient['first_name'] . " " . $patient['last_name'] . "\n\n";

// Check items
$itemQ = $conn->query("SELECT COUNT(*) as cnt FROM invoice_items WHERE invoice_id = $targetInvoiceId");
$itemCnt = $itemQ->fetch_assoc()['cnt'];
echo "INVOICE ITEMS COUNT: $itemCnt\n";
if ($itemCnt > 0) {
    $itemsQ = $conn->query("SELECT * FROM invoice_items WHERE invoice_id = $targetInvoiceId");
    while ($it = $itemsQ->fetch_assoc()) {
        echo "- " . $it['item_description'] . " (qty " . $it['quantity'] . " @ " . $it['unit_price'] . " = " . $it['total_price'] . ")\n";
    }
} else {
    echo "NO ITEMS FOUND - LIKELY CAUSE OF ZERO BALANCE\n";
}

// Check payments
$payQ = $conn->query("SELECT COUNT(*) as cnt FROM payments WHERE invoice_id = $targetInvoiceId");
$payCnt = $payQ->fetch_assoc()['cnt'];
echo "PAYMENTS COUNT: $payCnt\n";

if ($itemCnt == 0) {
    echo "\n=== READY TO AUTO-FIX? Add standard consultation fee. Type 'YES' to proceed or 'NO' to skip.\n";
    // For automation, uncomment below:
    // Add consultation fee
    /*
    $conn->query("INSERT INTO invoice_items (invoice_id, item_description, quantity, unit_price, total_price, reference_type) VALUES 
        ($targetInvoiceId, 'General Consultation', 1, 500.00, 500.00, 'consultation'),
        ($targetInvoiceId, 'CBC Laboratory Test', 1, 350.00, 350.00, 'laboratory')");
    $conn->query("UPDATE invoices SET net_amount = 850.00, balance_amount = 850.00 WHERE id = $targetInvoiceId");
    echo "FIXED: Added consultation + lab totaling 850 PHP. Balance now 850.\n";
    */
}

echo "\nRun this output: php modules/billing/temp_fix_invoice34.php\n";
$conn->close();
?>
