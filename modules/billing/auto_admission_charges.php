<?php
/**
 * Auto Admission Charges Helper
 * Computes and adds ALL charges to the admission invoice:
 *   1. Room & Board  – days admitted × daily rate
 *   2. Consultation  – fee per consultation linked to the visit
 *   3. Laboratory    – price per completed lab test linked to the visit
 *   4. Medications   – inventory items dispensed for this visit/admission
 *
 * Call with: auto_admission_charges($conn, $admission_id)
 */

function auto_admission_charges($conn, $admission_id) {
    $admission_id = (int)$admission_id;

    // ── 1. Load admission + room details ─────────────────────────────────────
    $adm = $conn->query(
        "SELECT a.*, p.id as patient_id, r.daily_rate, r.room_number
         FROM admissions a
         JOIN patients p ON a.patient_id = p.id
         LEFT JOIN rooms r ON a.room_id = r.id
         WHERE a.id = $admission_id"
    )->fetch_assoc();

    if (!$adm) {
        return ['status' => 'error', 'message' => 'Admission not found'];
    }

    $patientId  = (int)$adm['patient_id'];
    $visitId    = (int)($adm['visit_id'] ?? 0);
    $daily_rate = (float)($adm['daily_rate'] ?? 800.00);
    $room_number = $adm['room_number'] ?? 'Unassigned';

    // ── 2. Calculate days admitted ────────────────────────────────────────────
    $admit_dt = new DateTime($adm['admission_date']);
    $disch_dt = !empty($adm['actual_discharge_date'])
        ? new DateTime($adm['actual_discharge_date'])
        : new DateTime();
    $days = max(1, (int)$disch_dt->diff($admit_dt)->days + 1);

    // ── 3. Find or create invoice ─────────────────────────────────────────────
    $invQ = $conn->query(
        "SELECT id, status, total_amount FROM invoices
         WHERE admission_id = $admission_id
         LIMIT 1"
    );
    if ($invQ && $invQ->num_rows) {
        $invRow = $invQ->fetch_assoc();
        $invoice_id = (int)$invRow['id'];
        $status = $invRow['status'];
        if ($status === 'paid' || $status === 'cancelled' || $status === 'refunded') {
            return [
                'status'     => 'success',
                'message'    => "Invoice #$invoice_id is already $status. No changes made.",
                'invoice_id' => $invoice_id,
                'total'      => (float)$invRow['total_amount'],
                'breakdown'  => []
            ];
        }
    } else {
        $vIdSql  = $visitId > 0 ? $visitId : 'NULL';
        $createdBy = (int)($_SESSION['user_id'] ?? 0);
        $insSql  = "INSERT INTO invoices
                    (invoice_number, patient_id, visit_id, admission_id,
                     total_amount, discount_amount, tax_amount, net_amount,
                     paid_amount, balance_amount, status, created_by)
                    VALUES ('TBD', $patientId, $vIdSql, $admission_id,
                            0, 0, 0, 0, 0, 0, 'pending', $createdBy)";
        if (!$conn->query($insSql)) {
            return ['status' => 'error', 'message' => 'Failed to create invoice: ' . $conn->error];
        }
        $invoice_id   = (int)$conn->insert_id;
        $inv_number   = 'INV' . date('Y') . str_pad($invoice_id, 8, '0', STR_PAD_LEFT);
        $conn->query("UPDATE invoices SET invoice_number = '$inv_number' WHERE id = $invoice_id");
    }

    $added = [];

    // ── 4. Room & Board ───────────────────────────────────────────────────────
    // Remove ALL existing room charge rows for this invoice (by type OR by description pattern)
    $conn->query(
        "DELETE FROM invoice_items
         WHERE invoice_id = $invoice_id
           AND (reference_type IN ('room','room_charge')
                OR item_description LIKE 'Room & Board%')"
    );
    $room_total = $daily_rate * $days;
    $room_desc  = $conn->real_escape_string(
        "Room & Board (Room $room_number, $days day" . ($days > 1 ? 's' : '') .
        " @ ₱" . number_format($daily_rate, 2) . "/day)"
    );
    $conn->query(
        "INSERT INTO invoice_items
         (invoice_id, item_description, quantity, unit_price, total_price, reference_type, reference_id)
         VALUES ($invoice_id, '$room_desc', $days, $daily_rate, $room_total, 'room', $admission_id)"
    );
    $added[] = "Room & Board: ₱" . number_format($room_total, 2) . " ($days days)";

    // ── 5. Consultation Fee ───────────────────────────────────────────────────
    if ($visitId > 0) {
        // Remove old consultation charge items for this invoice
        $conn->query(
            "DELETE FROM invoice_items
             WHERE invoice_id = $invoice_id AND reference_type = 'consultation'"
        );

        // Get all consultations for the visit
        $consQ = $conn->query(
            "SELECT c.id, u.full_name as doctor_name
             FROM consultations c
             JOIN users u ON c.doctor_id = u.id
             WHERE c.visit_id = $visitId
             ORDER BY c.created_at ASC"
        );
        $cons_fee   = 500.00; // default consultation fee
        $cons_count = 0;
        if ($consQ && $consQ->num_rows > 0) {
            while ($con = $consQ->fetch_assoc()) {
                $cons_count++;
                $con_desc = $conn->real_escape_string(
                    "Consultation Fee (Dr. " . $con['doctor_name'] . ")"
                );
                $conn->query(
                    "INSERT INTO invoice_items
                     (invoice_id, item_description, quantity, unit_price, total_price, reference_type, reference_id)
                     VALUES ($invoice_id, '$con_desc', 1, $cons_fee, $cons_fee, 'consultation', " . (int)$con['id'] . ")"
                );
            }
            if ($cons_count > 0) {
                $added[] = "Consultation: ₱" . number_format($cons_fee * $cons_count, 2) . " ($cons_count session(s))";
            }
        }
    }

    // ── 6. Laboratory Tests ───────────────────────────────────────────────────
    if ($visitId > 0) {
        // Remove old lab charge items for this invoice
        $conn->query(
            "DELETE FROM invoice_items
             WHERE invoice_id = $invoice_id AND reference_type = 'laboratory'"
        );

        $labQ = $conn->query(
            "SELECT lr.id, lt.test_name, lt.price
             FROM laboratory_requests lr
             JOIN laboratory_tests lt ON lr.test_id = lt.id
             WHERE lr.visit_id = $visitId
               AND lr.status = 'completed'"
        );
        $lab_total = 0;
        if ($labQ && $labQ->num_rows > 0) {
            while ($lab = $labQ->fetch_assoc()) {
                $price    = (float)$lab['price'];
                $lab_desc = $conn->real_escape_string("Laboratory: " . $lab['test_name']);
                $conn->query(
                    "INSERT INTO invoice_items
                     (invoice_id, item_description, quantity, unit_price, total_price, reference_type, reference_id)
                     VALUES ($invoice_id, '$lab_desc', 1, $price, $price, 'laboratory', " . (int)$lab['id'] . ")"
                );
                $lab_total += $price;
            }
            if ($lab_total > 0) {
                $added[] = "Laboratory: ₱" . number_format($lab_total, 2) . " (" . $labQ->num_rows . " test(s))";
            }
        }
    }

    // ── 7. Medicines / Inventory dispensed ───────────────────────────────────
    // Remove old medication charge items
    $conn->query(
        "DELETE FROM invoice_items
         WHERE invoice_id = $invoice_id AND reference_type = 'medication'"
    );

    // Look for inventory_transactions of type 'issue' linked to this patient/visit/admission
    $medQ = $conn->query(
        "SELECT it.id, ii.item_name, ii.selling_price, it.quantity, it.reference_id
         FROM inventory_transactions it
         JOIN inventory_items ii ON it.item_id = ii.id
         WHERE it.transaction_type = 'issue'
           AND it.reference_type = 'patient'
           AND it.reference_id IN (
               SELECT id FROM patient_visits WHERE patient_id = $patientId
           )
         ORDER BY it.transaction_date ASC"
    );
    $med_total = 0;
    if ($medQ && $medQ->num_rows > 0) {
        while ($med = $medQ->fetch_assoc()) {
            $unit_price = (float)$med['selling_price'];
            $qty        = (int)$med['quantity'];
            $line_total = $unit_price * $qty;
            $med_desc   = $conn->real_escape_string($med['item_name']);
            $conn->query(
                "INSERT INTO invoice_items
                 (invoice_id, item_description, quantity, unit_price, total_price, reference_type, reference_id)
                 VALUES ($invoice_id, '$med_desc', $qty, $unit_price, $line_total, 'medication', " . (int)$med['id'] . ")"
            );
            $med_total += $line_total;
        }
        if ($med_total > 0) {
            $added[] = "Medications: ₱" . number_format($med_total, 2);
        }
    }

    // Also check prescriptions linked to this visit for medicines not yet in inventory_transactions
    if ($visitId > 0) {
        $presQ = $conn->query(
            "SELECT pr.id, pr.medication_name, pr.quantity
             FROM prescriptions pr
             JOIN consultations c ON pr.consultation_id = c.id
             WHERE c.visit_id = $visitId
               AND pr.status = 'dispensed'
               AND NOT EXISTS (
                 SELECT 1 FROM invoice_items ii2
                 WHERE ii2.invoice_id = $invoice_id
                   AND ii2.reference_type = 'medication'
                   AND ii2.item_description = pr.medication_name
               )"
        );
        if ($presQ && $presQ->num_rows > 0) {
            $pres_total = 0;
            while ($pres = $presQ->fetch_assoc()) {
                // No price in prescriptions table — use inventory selling price if available
                $priceQ = $conn->query(
                    "SELECT selling_price FROM inventory_items
                     WHERE item_name LIKE '%" . $conn->real_escape_string($pres['medication_name']) . "%'
                     LIMIT 1"
                );
                $unit_price = 0;
                if ($priceQ && $priceQ->num_rows) {
                    $unit_price = (float)$priceQ->fetch_assoc()['selling_price'];
                }
                $qty        = max(1, (int)($pres['quantity'] ?? 1));
                $line_total = $unit_price * $qty;
                if ($line_total > 0) {
                    $med_desc = $conn->real_escape_string($pres['medication_name']);
                    $conn->query(
                        "INSERT INTO invoice_items
                         (invoice_id, item_description, quantity, unit_price, total_price, reference_type, reference_id)
                         VALUES ($invoice_id, '$med_desc', $qty, $unit_price, $line_total, 'medication', " . (int)$pres['id'] . ")"
                    );
                    $pres_total += $line_total;
                }
            }
            if ($pres_total > 0) {
                $added[] = "Prescription medicines: ₱" . number_format($pres_total, 2);
            }
        }
    }

    // ── 8. Recalculate invoice totals ─────────────────────────────────────────
    $sum = (float)$conn->query(
        "SELECT COALESCE(SUM(total_price), 0) as total FROM invoice_items WHERE invoice_id = $invoice_id"
    )->fetch_assoc()['total'];

    // Preserve existing paid_amount and discount
    $invRow = $conn->query(
        "SELECT paid_amount, discount_amount, tax_amount FROM invoices WHERE id = $invoice_id"
    )->fetch_assoc();
    $paid     = (float)($invRow['paid_amount'] ?? 0);
    $discount = (float)($invRow['discount_amount'] ?? 0);
    $tax      = (float)($invRow['tax_amount'] ?? 0);
    $net      = $sum - $discount + $tax;
    $balance  = $net - $paid;

    $conn->query(
        "UPDATE invoices
         SET total_amount = $sum, net_amount = $net, balance_amount = $balance
         WHERE id = $invoice_id"
    );

    return [
        'status'     => 'success',
        'message'    => "Invoice #$invoice_id updated. Charges: " . implode('; ', $added),
        'invoice_id' => $invoice_id,
        'total'      => $sum,
        'breakdown'  => $added,
    ];
}

// ── Quick test endpoint (admin only) ─────────────────────────────────────────
if (isset($_GET['test_admission_id'])) {
    require_once __DIR__ . '/../../config/config.php';
    requireRole(['admin']);
    $conn   = getDBConnection();
    $result = auto_admission_charges($conn, (int)$_GET['test_admission_id']);
    echo "<pre>" . print_r($result, true) . "</pre>";
    $conn->close();
}
