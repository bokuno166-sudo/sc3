<?php
/**
 * Apply auto discount for eligible invoices (senior, child, PWD)
 * Returns array with status and applied amount
 */
function apply_auto_discount($conn, $invoiceId) {
    $invoiceId = (int)$invoiceId;
    if ($invoiceId <= 0) return ['status'=>'error','message'=>'invalid invoice id'];

    // ensure column exists
    $col = $conn->query("SHOW COLUMNS FROM invoices LIKE 'auto_discount_applied'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE invoices ADD COLUMN auto_discount_applied TINYINT(1) DEFAULT 0");
    }

    // fetch invoice + patient
    $q = "SELECT i.*, p.date_of_birth, p.age as patient_age, p.is_pwd FROM invoices i JOIN patients p ON i.patient_id = p.id WHERE i.id = $invoiceId LIMIT 1";
    $res = $conn->query($q);
    if (!$res || $res->num_rows === 0) return ['status'=>'error','message'=>'invoice not found'];
    $inv = $res->fetch_assoc();

    $already = (int)($inv['auto_discount_applied'] ?? 0) === 1;
    // determine age
    $age = null;
    if (!empty($inv['patient_age'])) $age = (int)$inv['patient_age'];
    elseif (!empty($inv['date_of_birth'])) {
        $age = (int)floor((time() - strtotime($inv['date_of_birth'])) / (365.25*24*60*60));
    }
    $isPwd = isset($inv['is_pwd']) ? (int)$inv['is_pwd'] : 0;

    $eligible = false;
    if ($age !== null) {
        if ($age >= 60 || $age <= 17) $eligible = true;
    }
    if ($isPwd) $eligible = true;

    if (!$eligible || $already) return ['status'=>'skipped','message'=>'not eligible or already applied'];

    // apply 20% discount on current net_amount
    $currentNet = (float)($inv['net_amount'] ?? $inv['total_amount']);
    $discountToApply = round($currentNet * 0.20, 2);
    if ($discountToApply <= 0) return ['status'=>'skipped','message'=>'no amount to discount'];

    $newDiscount = (float)($inv['discount_amount'] ?? 0) + $discountToApply;
    $newNet = $currentNet - $discountToApply;
    $paid = (float)($inv['paid_amount'] ?? 0);
    $newBalance = $newNet - $paid;

    $ok = $conn->query("UPDATE invoices SET discount_amount = $newDiscount, net_amount = $newNet, balance_amount = $newBalance, auto_discount_applied = 1 WHERE id = $invoiceId");
    if ($ok) return ['status'=>'applied','discount'=>$discountToApply];
    return ['status'=>'error','message'=>'failed update'];
}

?>
