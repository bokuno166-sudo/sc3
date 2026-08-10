<?php
require_once 'config/config.php';
requireLogin();

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

$conn = getDBConnection();
$role = getActiveRole();

// ─── UNIVERSAL COUNTS (used across roles) ───────────────────────
$counts = getDashboardCounts();

// ─── CURRENT USER ───────────────────────────────────────────────
$userId = intval($_SESSION['user_id']);

// ─── TODAY DATE ─────────────────────────────────────────────────
$today = date('Y-m-d');
$todayLabel = date('l, F j, Y');

// ═══════════════════════════════════════════════════════════════
// ROLE-SPECIFIC DATA QUERIES
// ═══════════════════════════════════════════════════════════════

// ── STAFF / RECEPTION ──────────────────────────────────────────
if (hasRole(['staff', 'reception'])) {
    $todayQueue = $conn->query("
        SELECT v.*, p.first_name, p.last_name, p.patient_code, p.gender
        FROM patient_visits v
        JOIN patients p ON v.patient_id = p.id
        WHERE v.visit_date = CURDATE()
        ORDER BY v.created_at DESC
        LIMIT 10
    ");
    $todayRegistrations = $conn->query("
        SELECT COUNT(*) as cnt FROM patients WHERE DATE(created_at) = CURDATE()
    ");
    $todayReg = $todayRegistrations ? intval($todayRegistrations->fetch_assoc()['cnt']) : 0;

    $waitingRes = $conn->query("SELECT COUNT(*) as cnt FROM patient_visits WHERE status='waiting' AND visit_date=CURDATE()");
    $staffWaiting = $waitingRes ? intval($waitingRes->fetch_assoc()['cnt']) : 0;

    $triageRes = $conn->query("SELECT COUNT(*) as cnt FROM patient_visits WHERE status='in-triage' AND visit_date=CURDATE()");
    $staffTriage = $triageRes ? intval($triageRes->fetch_assoc()['cnt']) : 0;

    $consRes = $conn->query("SELECT COUNT(*) as cnt FROM patient_visits WHERE status='in-consultation' AND visit_date=CURDATE()");
    $staffCons = $consRes ? intval($consRes->fetch_assoc()['cnt']) : 0;

    $doneRes = $conn->query("SELECT COUNT(*) as cnt FROM patient_visits WHERE status='discharged' AND visit_date=CURDATE()");
    $staffDone = $doneRes ? intval($doneRes->fetch_assoc()['cnt']) : 0;

    $recentPatients = $conn->query("
        SELECT patient_code, first_name, last_name, gender, DATE(created_at) as reg_date
        FROM patients ORDER BY created_at DESC LIMIT 5
    ");
}

// ── NURSE ──────────────────────────────────────────────────────
if (hasRole(['nurse'])) {
    $nurseWaiting = $conn->query("
        SELECT v.*, p.first_name, p.last_name, p.patient_code, p.gender, p.date_of_birth
        FROM patient_visits v
        JOIN patients p ON v.patient_id = p.id
        WHERE v.visit_date = CURDATE() AND v.status IN ('waiting','in-triage')
        ORDER BY v.created_at ASC
        LIMIT 10
    ");
    $nurseAdmitted = $conn->query("
        SELECT a.*, p.first_name, p.last_name, p.patient_code, r.room_number,
               DATEDIFF(CURDATE(), DATE(a.admission_date)) as days_admitted
        FROM admissions a
        JOIN patients p ON a.patient_id = p.id
        LEFT JOIN rooms r ON a.room_id = r.id
        WHERE a.status = 'admitted'
        ORDER BY a.admission_date DESC
        LIMIT 8
    ");
    $vitalsDueRes = $conn->query("
        SELECT COUNT(*) as cnt FROM admissions WHERE status='admitted'
    ");
    $nurseAdmittedCount = $vitalsDueRes ? intval($vitalsDueRes->fetch_assoc()['cnt']) : 0;

    $nurseWaitingCount = $conn->query("SELECT COUNT(*) as cnt FROM patient_visits WHERE status='waiting' AND visit_date=CURDATE()");
    $nWait = $nurseWaitingCount ? intval($nurseWaitingCount->fetch_assoc()['cnt']) : 0;

    $nurseTriageCount = $conn->query("SELECT COUNT(*) as cnt FROM patient_visits WHERE status='in-triage' AND visit_date=CURDATE()");
    $nTriage = $nurseTriageCount ? intval($nurseTriageCount->fetch_assoc()['cnt']) : 0;

    $nurseTriageDoneToday = $conn->query("SELECT COUNT(*) as cnt FROM triage_records WHERE DATE(created_at) = CURDATE()");
    $nTriageDone = $nurseTriageDoneToday ? intval($nurseTriageDoneToday->fetch_assoc()['cnt']) : 0;

    $activePreg = $conn->query("SELECT COUNT(*) as cnt FROM patients WHERE is_pregnant=1 AND status='active'");
    $nPreg = $activePreg ? intval($activePreg->fetch_assoc()['cnt']) : 0;
}

// ── DOCTOR ─────────────────────────────────────────────────────
if (hasRole(['doctor'])) {
    $drConsultToday = $conn->query("
        SELECT c.*, p.first_name, p.last_name, p.patient_code, p.date_of_birth, p.gender
        FROM consultations c
        JOIN patients p ON c.patient_id = p.id
        WHERE c.doctor_id = $userId AND DATE(c.created_at) = CURDATE()
        ORDER BY c.created_at DESC
        LIMIT 8
    ");
    $drConsultCountRes = $conn->query("SELECT COUNT(*) as cnt FROM consultations WHERE doctor_id=$userId AND DATE(created_at)=CURDATE()");
    $drConsultCount = $drConsultCountRes ? intval($drConsultCountRes->fetch_assoc()['cnt']) : 0;

    $drPendingLabRes = $conn->query("
        SELECT lr.*, p.first_name, p.last_name, lt.test_name, lt.category
        FROM laboratory_requests lr
        JOIN patients p ON lr.patient_id = p.id
        JOIN laboratory_tests lt ON lr.test_id = lt.id
        WHERE lr.doctor_id = $userId AND lr.status IN ('pending','in-progress')
        ORDER BY lr.requested_at DESC
        LIMIT 6
    ");
    $drPendingLabCount = $conn->query("SELECT COUNT(*) as cnt FROM laboratory_requests WHERE doctor_id=$userId AND status IN ('pending','in-progress')");
    $drPendLab = $drPendingLabCount ? intval($drPendingLabCount->fetch_assoc()['cnt']) : 0;

    $drPrescCountRes = $conn->query("SELECT COUNT(*) as cnt FROM prescriptions WHERE doctor_id=$userId AND DATE(created_at)=CURDATE()");
    $drPrescCount = $drPrescCountRes ? intval($drPrescCountRes->fetch_assoc()['cnt']) : 0;

    $drAdmittedRes = $conn->query("SELECT COUNT(*) as cnt FROM admissions WHERE doctor_id=$userId AND status='admitted'");
    $drAdmitted = $drAdmittedRes ? intval($drAdmittedRes->fetch_assoc()['cnt']) : 0;

    $drWaitingQueue = $conn->query("
        SELECT v.*, p.first_name, p.last_name, p.patient_code, p.date_of_birth
        FROM patient_visits v
        JOIN patients p ON v.patient_id = p.id
        WHERE v.visit_date = CURDATE() AND v.status IN ('waiting','in-triage','in-consultation')
        ORDER BY v.created_at ASC
        LIMIT 8
    ");

    $drRecentCompleted = $conn->query("
        SELECT lr.*, p.first_name, p.last_name, lt.test_name, lr.status
        FROM laboratory_requests lr
        JOIN patients p ON lr.patient_id = p.id
        JOIN laboratory_tests lt ON lr.test_id = lt.id
        WHERE lr.doctor_id = $userId AND lr.status = 'completed'
        ORDER BY lr.completed_at DESC
        LIMIT 5
    ");
}

// ── LABORATORY ─────────────────────────────────────────────────
if (hasRole(['laboratory'])) {
    $labPendingRes = $conn->query("
        SELECT lr.*, p.first_name, p.last_name, p.patient_code, lt.test_name, lt.category
        FROM laboratory_requests lr
        JOIN patients p ON lr.patient_id = p.id
        JOIN laboratory_tests lt ON lr.test_id = lt.id
        WHERE lr.status = 'pending'
        ORDER BY 
            CASE lr.priority WHEN 'stat' THEN 1 WHEN 'urgent' THEN 2 ELSE 3 END ASC,
            lr.requested_at ASC
        LIMIT 10
    ");
    $labInProgRes = $conn->query("
        SELECT lr.*, p.first_name, p.last_name, lt.test_name
        FROM laboratory_requests lr
        JOIN patients p ON lr.patient_id = p.id
        JOIN laboratory_tests lt ON lr.test_id = lt.id
        WHERE lr.status = 'in-progress'
        ORDER BY lr.requested_at ASC
        LIMIT 5
    ");

    $labPendingCount = $conn->query("SELECT COUNT(*) as cnt FROM laboratory_requests WHERE status='pending'");
    $lPend = $labPendingCount ? intval($labPendingCount->fetch_assoc()['cnt']) : 0;

    $labInProgCount = $conn->query("SELECT COUNT(*) as cnt FROM laboratory_requests WHERE status='in-progress'");
    $lInProg = $labInProgCount ? intval($labInProgCount->fetch_assoc()['cnt']) : 0;

    $labDoneToday = $conn->query("SELECT COUNT(*) as cnt FROM laboratory_requests WHERE status='completed' AND DATE(completed_at)=CURDATE()");
    $lDoneToday = $labDoneToday ? intval($labDoneToday->fetch_assoc()['cnt']) : 0;

    $labStatCount = $conn->query("SELECT COUNT(*) as cnt FROM laboratory_requests WHERE status='pending' AND priority='stat'");
    $lStat = $labStatCount ? intval($labStatCount->fetch_assoc()['cnt']) : 0;

    $labUrgentCount = $conn->query("SELECT COUNT(*) as cnt FROM laboratory_requests WHERE status='pending' AND priority='urgent'");
    $lUrgent = $labUrgentCount ? intval($labUrgentCount->fetch_assoc()['cnt']) : 0;

    // Test category breakdown for today
    $labCatRes = $conn->query("
        SELECT lt.category, COUNT(*) as cnt
        FROM laboratory_requests lr
        JOIN laboratory_tests lt ON lr.test_id = lt.id
        WHERE DATE(lr.requested_at) = CURDATE()
        GROUP BY lt.category
        ORDER BY cnt DESC
    ");
    $labCatLabels = [];
    $labCatData = [];
    if ($labCatRes) {
        while ($r = $labCatRes->fetch_assoc()) {
            $labCatLabels[] = ucfirst($r['category']);
            $labCatData[] = intval($r['cnt']);
        }
    }
    if (empty($labCatLabels)) {
        $labCatLabels = ['No data'];
        $labCatData = [1];
    }
}

// ── CASHIER ────────────────────────────────────────────────────
if (hasRole(['cashier'])) {
    $cashPendingInv = $conn->query("
        SELECT i.*, p.first_name, p.last_name, p.patient_code
        FROM invoices i
        JOIN patients p ON i.patient_id = p.id
        WHERE i.status IN ('pending','partial')
        ORDER BY i.created_at DESC
        LIMIT 8
    ");
    $cashPendingCount = $conn->query("SELECT COUNT(*) as cnt FROM invoices WHERE status IN ('pending','partial')");
    $cPend = $cashPendingCount ? intval($cashPendingCount->fetch_assoc()['cnt']) : 0;

    $cashTodayRev = $conn->query("SELECT COALESCE(SUM(payment_amount),0) as total FROM payments WHERE DATE(payment_date)=CURDATE()");
    $cRev = $cashTodayRev ? floatval($cashTodayRev->fetch_assoc()['total']) : 0;

    $cashTodayTrans = $conn->query("SELECT COUNT(*) as cnt FROM payments WHERE DATE(payment_date)=CURDATE()");
    $cTrans = $cashTodayTrans ? intval($cashTodayTrans->fetch_assoc()['cnt']) : 0;

    $cashMonthRev = $conn->query("SELECT COALESCE(SUM(payment_amount),0) as total FROM payments WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())");
    $cMonthRev = $cashMonthRev ? floatval($cashMonthRev->fetch_assoc()['total']) : 0;

    $cashPaidToday = $conn->query("SELECT COUNT(*) as cnt FROM invoices WHERE status='paid' AND DATE(updated_at)=CURDATE()");
    $cPaidToday = $cashPaidToday ? intval($cashPaidToday->fetch_assoc()['cnt']) : 0;

    // Payment method breakdown
    $cashPayMethods = $conn->query("
        SELECT payment_method, SUM(payment_amount) as total, COUNT(*) as cnt
        FROM payments
        WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    $payMethodLabels = [];
    $payMethodData = [];
    if ($cashPayMethods) {
        while ($r = $cashPayMethods->fetch_assoc()) {
            $payMethodLabels[] = ucfirst($r['payment_method']);
            $payMethodData[] = floatval($r['total']);
        }
    }
    if (empty($payMethodLabels)) {
        $payMethodLabels = ['No data'];
        $payMethodData = [1];
    }

    // Revenue last 7 days
    $cashRevMap = [];
    $cashRevRes = $conn->query("SELECT DATE(payment_date) as dt, SUM(payment_amount) as total FROM payments WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(payment_date)");
    if ($cashRevRes) {
        while ($r = $cashRevRes->fetch_assoc()) $cashRevMap[$r['dt']] = floatval($r['total']);
    }
    $cashRevLabels = [];
    $cashRevData = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $cashRevLabels[] = date('D', strtotime($d));
        $cashRevData[] = $cashRevMap[$d] ?? 0;
    }

    // Recent payments
    $cashRecentPay = $conn->query("
        SELECT py.*, i.invoice_number, p.first_name, p.last_name
        FROM payments py
        JOIN invoices i ON py.invoice_id = i.id
        JOIN patients p ON i.patient_id = p.id
        ORDER BY py.payment_date DESC
        LIMIT 6
    ");
}

// ── INVENTORY ──────────────────────────────────────────────────
if (hasRole(['inventory'])) {
    $invLowStock = $conn->query("
        SELECT i.item_code, i.item_name, i.item_type, i.reorder_level, 
               COALESCE(SUM(s.quantity_in_stock),0) as total_stock
        FROM inventory_items i
        LEFT JOIN inventory_stock s ON i.id = s.item_id
        WHERE i.status = 'active'
        GROUP BY i.id
        HAVING total_stock <= i.reorder_level
        ORDER BY total_stock ASC
        LIMIT 8
    ");

    $invNearExpiry = $conn->query("
        SELECT i.item_name, i.item_type, s.batch_number, s.expiry_date,
               s.quantity_in_stock, DATEDIFF(s.expiry_date, CURDATE()) as days_left
        FROM inventory_stock s
        JOIN inventory_items i ON s.item_id = i.id
        WHERE s.expiry_date IS NOT NULL
          AND s.expiry_date <> '0000-00-00'
          AND s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND (s.quantity_in_stock - s.quantity_reserved) > 0
        ORDER BY s.expiry_date ASC
        LIMIT 8
    ");

    $invTotalItems = $conn->query("SELECT COUNT(*) as cnt FROM inventory_items WHERE status='active'");
    $invItemCount = $invTotalItems ? intval($invTotalItems->fetch_assoc()['cnt']) : 0;

    $invLowStockCount = $conn->query("
        SELECT COUNT(*) as cnt FROM (
            SELECT i.id, i.reorder_level FROM inventory_items i
            LEFT JOIN inventory_stock s ON i.id = s.item_id
            WHERE i.status='active'
            GROUP BY i.id, i.reorder_level
            HAVING COALESCE(SUM(s.quantity_in_stock),0) <= i.reorder_level
        ) t
    ");
    $invLowCount = $invLowStockCount ? intval($invLowStockCount->fetch_assoc()['cnt']) : 0;

    $invNearExpiryCount = $conn->query("
        SELECT COUNT(DISTINCT s.item_id) as cnt FROM inventory_stock s
        WHERE s.expiry_date IS NOT NULL AND s.expiry_date <> '0000-00-00'
          AND s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND (s.quantity_in_stock - s.quantity_reserved) > 0
    ");
    $invNearExp = $invNearExpiryCount ? intval($invNearExpiryCount->fetch_assoc()['cnt']) : 0;

    $invExpiredCount = $conn->query("
        SELECT COUNT(DISTINCT s.item_id) as cnt FROM inventory_stock s
        WHERE s.expiry_date IS NOT NULL AND s.expiry_date <> '0000-00-00'
          AND s.expiry_date < CURDATE()
          AND (s.quantity_in_stock - s.quantity_reserved) > 0
    ");
    $invExpCount = $invExpiredCount ? intval($invExpiredCount->fetch_assoc()['cnt']) : 0;

    $medTotalRes = $conn->query("SELECT COALESCE(SUM(s.quantity_in_stock),0) as t FROM inventory_stock s JOIN inventory_items i ON s.item_id=i.id WHERE i.status='active' AND LOWER(i.item_type)='medicine'");
    $invMedTotal = $medTotalRes ? intval($medTotalRes->fetch_assoc()['t']) : 0;

    $supTotalRes = $conn->query("SELECT COALESCE(SUM(s.quantity_in_stock),0) as t FROM inventory_stock s JOIN inventory_items i ON s.item_id=i.id WHERE i.status='active' AND LOWER(i.item_type)='supply'");
    $invSupTotal = $supTotalRes ? intval($supTotalRes->fetch_assoc()['t']) : 0;

    // Recent inventory transactions
    $invRecentTx = $conn->query("
        SELECT t.transaction_type, t.quantity, t.transaction_date, i.item_name,
               u.full_name as performed_by_name
        FROM inventory_transactions t
        JOIN inventory_items i ON t.item_id = i.id
        LEFT JOIN users u ON t.performed_by = u.id
        ORDER BY t.transaction_date DESC
        LIMIT 6
    ");

    // Stock by type (pie chart)
    $invTypeLabels = ['Medicine', 'Supply'];
    $invTypeData = [$invMedTotal, $invSupTotal];
}

// ── ADMIN (keep existing queries) ─────────────────────────────
if (hasRole(['admin'])) {
    $recentPatients = $conn->query("
        SELECT p.*, v.visit_date, v.status as visit_status, v.queue_number
        FROM patients p
        LEFT JOIN patient_visits v ON p.id = v.patient_id
        WHERE p.status = 'active'
        ORDER BY v.created_at DESC
        LIMIT 5
    ");
    $todayQueue = $conn->query("
        SELECT v.*, p.first_name, p.last_name, p.patient_code
        FROM patient_visits v
        JOIN patients p ON v.patient_id = p.id
        WHERE v.visit_date = CURDATE()
        ORDER BY v.created_at DESC
        LIMIT 10
    ");
    $todayReferrals = $conn->query("
        SELECT r.*, p.first_name, p.last_name, p.patient_code, u.full_name as doctor_name
        FROM referrals r
        JOIN patients p ON r.patient_id = p.id
        LEFT JOIN users u ON r.doctor_id = u.id
        WHERE DATE(r.created_at) = CURDATE() AND r.status IN ('pending', 'printed')
        ORDER BY 
            CASE r.urgency WHEN 'emergency' THEN 1 WHEN 'urgent' THEN 2 ELSE 3 END ASC,
            r.created_at DESC
        LIMIT 10
    ");
    $roomAvailability = $conn->query("
        SELECT r.*,
            (SELECT COUNT(*) FROM beds b WHERE b.room_id = r.id) AS total_beds,
            (SELECT COUNT(*) FROM beds b WHERE b.room_id = r.id AND b.status = 'occupied') AS occupied_beds,
            (SELECT COUNT(*) FROM admissions a WHERE a.room_id = r.id AND a.status = 'admitted') AS admitted_count
        FROM rooms r
        ORDER BY r.room_number ASC
    ");
    $allUsers = $conn->query("
        SELECT id, username, full_name, role, status
        FROM users
        ORDER BY 
            CASE status WHEN 'active' THEN 1 ELSE 2 END,
            username ASC
    ");

    // Chart data
    $days = 30;
    $visitMap = [];
    $visitTrends = $conn->query("SELECT DATE(created_at) as visit_date, COUNT(*) as count FROM patient_visits WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY) GROUP BY DATE(created_at) ORDER BY visit_date ASC");
    if ($visitTrends) { while ($row = $visitTrends->fetch_assoc()) $visitMap[$row['visit_date']] = (int)$row['count']; }
    $visitData = []; $visitLabels = [];
    for ($i = $days-1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-{$i} days")); $visitLabels[] = date('M d', strtotime($d)); $visitData[] = $visitMap[$d] ?? 0; }

    $admissionMap = []; $dischargeMap = [];
    $admissionsData = $conn->query("SELECT DATE(admission_date) as date, COUNT(*) as admissions, (SELECT COUNT(*) FROM admissions WHERE DATE(actual_discharge_date) = DATE(admission_date)) as discharges FROM admissions WHERE admission_date >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY) GROUP BY DATE(admission_date) ORDER BY date ASC");
    if ($admissionsData) { while ($row = $admissionsData->fetch_assoc()) { $admissionMap[$row['date']] = (int)$row['admissions']; $dischargeMap[$row['date']] = (int)$row['discharges']; } }
    $admData = []; $dischargeData = []; $admLabels = [];
    for ($i = $days-1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-{$i} days")); $admLabels[] = date('M d', strtotime($d)); $admData[] = $admissionMap[$d] ?? 0; $dischargeData[] = $dischargeMap[$d] ?? 0; }

    $revenueMap = [];
    $revenueData = $conn->query("SELECT DATE(payment_date) as date, SUM(payment_amount) as revenue FROM payments WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY) GROUP BY DATE(payment_date) ORDER BY date ASC");
    if ($revenueData) { while ($row = $revenueData->fetch_assoc()) $revenueMap[$row['date']] = (float)$row['revenue']; }
    $revData = []; $revLabels = [];
    for ($i = $days-1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-{$i} days")); $revLabels[] = date('M d', strtotime($d)); $revData[] = $revenueMap[$d] ?? 0; }

    $labStats = $conn->query("SELECT lt.test_name, COUNT(lr.id) as count FROM laboratory_requests lr JOIN laboratory_tests lt ON lr.test_id = lt.id WHERE MONTH(lr.requested_at) = MONTH(CURDATE()) AND YEAR(lr.requested_at) = YEAR(CURDATE()) GROUP BY lt.id, lt.test_name ORDER BY count DESC LIMIT 6");
    $labLabels = []; $labData = [];
    if ($labStats) { while ($row = $labStats->fetch_assoc()) { $labLabels[] = $row['test_name'] . ' (' . $row['count'] . ')'; $labData[] = $row['count']; } }

    $matDates = []; $matLabels = [];
    for ($i = $days-1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-{$i} days")); $matDates[] = $d; $matLabels[] = date('M d', strtotime($d)); }
    $checkupMap = []; $mcu = $conn->query("SELECT DATE(checkup_date) as date, COUNT(*) as cnt FROM maternity_checkups WHERE checkup_date >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY) GROUP BY DATE(checkup_date)");
    if ($mcu) { while ($r = $mcu->fetch_assoc()) $checkupMap[$r['date']] = (int)$r['cnt']; }
    $deliveryMap = []; $delRes = $conn->query("SELECT DATE(delivery_date) as date, COUNT(*) as cnt FROM delivery_records WHERE delivery_date >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY) GROUP BY DATE(delivery_date)");
    if ($delRes) { while ($r = $delRes->fetch_assoc()) $deliveryMap[$r['date']] = (int)$r['cnt']; }
    $matCheckupData = []; $matDeliveryData = [];
    foreach ($matDates as $d) { $matCheckupData[] = $checkupMap[$d] ?? 0; $matDeliveryData[] = $deliveryMap[$d] ?? 0; }

    $activePregRes = $conn->query("SELECT COUNT(*) as total FROM patients WHERE is_pregnant = 1 AND status = 'active'");
    $activePreg = $activePregRes ? intval($activePregRes->fetch_assoc()['total']) : 0;

    $medTotalRes = $conn->query("SELECT COALESCE(SUM(s.quantity_in_stock - s.quantity_reserved),0) as total FROM inventory_stock s JOIN inventory_items i ON s.item_id = i.id WHERE i.status='active' AND LOWER(i.item_type)='medicine'");
    $medTotal = $medTotalRes ? intval($medTotalRes->fetch_assoc()['total']) : 0;
    $supTotalRes = $conn->query("SELECT COALESCE(SUM(s.quantity_in_stock - s.quantity_reserved),0) as total FROM inventory_stock s JOIN inventory_items i ON s.item_id = i.id WHERE i.status='active' AND LOWER(i.item_type)='supply'");
    $supTotal = $supTotalRes ? intval($supTotalRes->fetch_assoc()['total']) : 0;

    // Admin today revenue
    $adminRevTodayRes = $conn->query("SELECT COALESCE(SUM(payment_amount),0) as t FROM payments WHERE DATE(payment_date)=CURDATE()");
    $adminRevToday = $adminRevTodayRes ? floatval($adminRevTodayRes->fetch_assoc()['t']) : 0;

    // Admin month revenue
    $adminRevMonthRes = $conn->query("SELECT COALESCE(SUM(payment_amount),0) as t FROM payments WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())");
    $adminRevMonth = $adminRevMonthRes ? floatval($adminRevMonthRes->fetch_assoc()['t']) : 0;
}

$conn->close();
include 'includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>

<style>
/* ════════════════════════════════════════════
   DASHBOARD GLOBAL STYLES
════════════════════════════════════════════ */
.dash-fade-in {
    max-width: 100%;
    min-width: 0;
}

.charts-grid,
.dash-grid,
.dash-grid-3,
.dash-full {
    zoom: 0.9; /* Zoomed out to 90% to fit charts and tables nicely without looking zoomed in */
}

.dash-welcome {
    background: linear-gradient(135deg, var(--primary-color) 0%, #004a9f 60%, #002d6b 100%);
    border-radius: 20px;
    padding: 28px 32px;
    color: #fff;
    margin-bottom: 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 8px 30px rgba(0,102,204,0.35);
}
.dash-welcome::before {
    content: '';
    position: absolute;
    top: -40px; right: -40px;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.07);
    border-radius: 50%;
    pointer-events: none;
}
.dash-welcome::after {
    content: '';
    position: absolute;
    bottom: -60px; right: 80px;
    width: 250px; height: 250px;
    background: rgba(255,255,255,0.04);
    border-radius: 50%;
    pointer-events: none;
}
.dash-welcome-text h1 {
    font-size: 24px;
    font-weight: 800;
    margin: 0 0 6px;
    letter-spacing: -0.5px;
}
.dash-welcome-text p { margin: 0; opacity: 0.85; font-size: 14px; }
.dash-welcome-badge {
    background: rgba(255,255,255,0.18);
    border: 1px solid rgba(255,255,255,0.25);
    border-radius: 50px;
    padding: 8px 20px;
    font-size: 13px;
    font-weight: 600;
    backdrop-filter: blur(4px);
    white-space: nowrap;
}

/* KPI Cards */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.kpi-card {
    background: #fff;
    border-radius: 16px;
    padding: 20px 22px;
    border: 1px solid var(--border-color);
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    display: flex;
    align-items: center;
    gap: 16px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    position: relative;
    overflow: hidden;
    min-width: 0;
    min-height: 96px;
}
.kpi-card::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: 16px 16px 0 0;
}
.kpi-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.12); }
.kpi-card.primary::after  { background: #0066cc; }
.kpi-card.success::after  { background: #28a745; }
.kpi-card.warning::after  { background: #ffc107; }
.kpi-card.danger::after   { background: #dc3545; }
.kpi-card.info::after     { background: #17a2b8; }
.kpi-card.purple::after   { background: #8e44ad; }
.kpi-card.orange::after   { background: #e67e22; }

.kpi-icon {
    width: 50px; height: 50px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.kpi-icon.primary  { background: rgba(0,102,204,0.12); color: #0066cc; }
.kpi-icon.success  { background: rgba(40,167,69,0.12);  color: #28a745; }
.kpi-icon.warning  { background: rgba(255,193,7,0.15);  color: #e6a800; }
.kpi-icon.danger   { background: rgba(220,53,69,0.12);  color: #dc3545; }
.kpi-icon.info     { background: rgba(23,162,184,0.12); color: #17a2b8; }
.kpi-icon.purple   { background: rgba(142,68,173,0.12); color: #8e44ad; }
.kpi-icon.orange   { background: rgba(230,126,34,0.12); color: #e67e22; }

.kpi-data {
    min-width: 0;
    width: 100%;
}
.kpi-data h2 {
    font-size: clamp(0.72rem, 1.9vw, 1.15rem);
    font-weight: 800;
    margin: 0;
    line-height: 1.03;
    color: var(--dark-color);
    word-break: break-word;
    overflow-wrap: anywhere;
    letter-spacing: -0.03em;
}
.kpi-data p  { font-size: 12px; font-weight: 500; color: var(--text-muted); margin: 4px 0 0; text-transform: uppercase; letter-spacing: 0.5px; }
.kpi-data small { font-size: 11px; color: var(--text-muted); }

/* Section Panels */
.dash-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 400px), 1fr));
    gap: 22px;
    margin-bottom: 22px;
}
.dash-grid-3 {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr));
    gap: 22px;
    margin-bottom: 22px;
}
.dash-panel {
    background: #fff;
    border-radius: 18px;
    border: 1px solid var(--border-color);
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    overflow: hidden;
}
.dash-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    padding: 18px 22px 14px;
    border-bottom: 1px solid var(--border-color);
}
.dash-panel-title {
    font-size: 15px; font-weight: 700;
    display: flex; align-items: center; gap: 10px;
    color: var(--dark-color);
}
.dash-panel-title i { color: var(--primary-color); font-size: 16px; }
.dash-panel-body { padding: 0; min-width: 0; }
.dash-panel-body.padded { padding: 20px 22px; }
.dash-panel-body:has(.dash-table) {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.chart-wrap { height: 260px; padding: 16px 20px 10px; }

/* Full-width panel */
.dash-full { margin-bottom: 22px; }

/* Priority badge */
.priority-stat  { background: #dc3545; color: #fff; }
.priority-urgent{ background: #fd7e14; color: #fff; }
.priority-routine{ background: #6c757d; color: #fff; }

/* Queue status pill */
.queue-pill {
    display: inline-flex; align-items: center;
    padding: 3px 10px; border-radius: 50px;
    font-size: 11px; font-weight: 600; gap: 4px;
}
.queue-pill.waiting   { background: #fff3cd; color: #856404; }
.queue-pill.triage    { background: #d1ecf1; color: #0c5460; }
.queue-pill.consult   { background: #d4edda; color: #155724; }
.queue-pill.admitted  { background: #cce5ff; color: #004085; }
.queue-pill.done      { background: #e2e3e5; color: #383d41; }

/* Quick Action Buttons */
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 140px), 1fr));
    gap: 14px;
    padding: 20px 22px;
}
.qa-btn {
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 10px;
    padding: 20px 12px;
    border-radius: 16px;
    border: 2px solid transparent;
    text-decoration: none;
    font-size: 13px; font-weight: 600;
    transition: all 0.22s ease;
    cursor: pointer;
    text-align: center;
}
.qa-btn i { font-size: 24px; }
.qa-btn.qa-primary   { background: rgba(0,102,204,0.08); color: #0066cc; border-color: rgba(0,102,204,0.2); }
.qa-btn.qa-success   { background: rgba(40,167,69,0.08);  color: #28a745; border-color: rgba(40,167,69,0.2); }
.qa-btn.qa-warning   { background: rgba(255,193,7,0.1);   color: #b38600; border-color: rgba(255,193,7,0.3); }
.qa-btn.qa-danger    { background: rgba(220,53,69,0.08);  color: #dc3545; border-color: rgba(220,53,69,0.2); }
.qa-btn.qa-info      { background: rgba(23,162,184,0.08); color: #117a8b; border-color: rgba(23,162,184,0.2); }
.qa-btn.qa-purple    { background: rgba(142,68,173,0.08); color: #6c3483; border-color: rgba(142,68,173,0.2); }
.qa-btn.qa-orange    { background: rgba(230,126,34,0.08); color: #a04000; border-color: rgba(230,126,34,0.2); }
.qa-btn:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(0,0,0,0.12); filter: brightness(1.05); border-color: currentColor; }

/* Table enhancements */
.dash-table { width: 100%; min-width: 520px; border-collapse: collapse; font-size: 13px; }
.dash-table thead tr { background: rgba(0,102,204,0.05); }
.dash-table th { padding: 10px 14px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; border-bottom: 1px solid var(--border-color); white-space: nowrap; }
.dash-table td { padding: 10px 14px; border-bottom: 1px solid var(--border-color); color: var(--dark-color); vertical-align: middle; }
.dash-table tbody tr:last-child td { border-bottom: none; }
.dash-table tbody tr:hover { background: rgba(0,102,204,0.04); }

/* Empty state */
.empty-state { text-align: center; padding: 36px 20px; color: var(--text-muted); }
.empty-state i { font-size: 44px; margin-bottom: 12px; opacity: 0.35; display: block; }
.empty-state p { margin: 0; font-size: 14px; }

/* Dark mode */
[data-theme="dark"] .kpi-card,
[data-theme="dark"] .dash-panel,
[data-theme="dark"] .chart-card {
    background: rgba(255,255,255,0.05) !important;
    border-color: rgba(255,255,255,0.10) !important;
}
[data-theme="dark"] .dash-welcome {
    background: linear-gradient(135deg, #0c426d 0%, #051c3a 100%) !important;
}
[data-theme="dark"] .kpi-data h2,
[data-theme="dark"] .dash-panel-title,
[data-theme="dark"] .chart-title,
[data-theme="dark"] .dash-table td { color: #eef8ff !important; }
[data-theme="dark"] .dash-table thead tr {
    background: rgba(255,255,255,0.06) !important;
}
[data-theme="dark"] .dash-table tbody tr:hover {
    background: rgba(255,255,255,0.08) !important;
}
[data-theme="dark"] .dash-table td,
[data-theme="dark"] .dash-table th {
    border-color: rgba(255,255,255,0.10) !important;
}
[data-theme="dark"] .qa-btn.qa-primary  { background: rgba(114,194,255,0.18) !important; color: #dbefff !important; }
[data-theme="dark"] .qa-btn.qa-success  { background: rgba(91,223,172,0.18) !important; color: #e5fff2 !important; }
[data-theme="dark"] .qa-btn.qa-warning  { background: rgba(243,194,99,0.18) !important; color: #fff5d9 !important; }
[data-theme="dark"] .qa-btn.qa-danger   { background: rgba(255,139,196,0.18) !important; color: #ffd1e9 !important; }
[data-theme="dark"] .qa-btn.qa-info     { background: rgba(123,199,255,0.18) !important; color: #e8f9ff !important; }
[data-theme="dark"] .dashboard-card,
[data-theme="dark"] .dash-panel,
[data-theme="dark"] .kpi-card,
[data-theme="dark"] .chart-card {
    box-shadow: 0 18px 60px rgba(0,0,0,0.24) !important;
}
[data-theme="dark"] .chart-title i {
    color: var(--primary-color) !important;
}

/* Animation */
.dash-fade-in {
    animation: dashFadeIn 0.92s cubic-bezier(0.2, 0.8, 0.2, 1) both;
    transform-origin: center top;
    will-change: transform, opacity;
}
@keyframes dashFadeIn {
    from {
        opacity: 0;
        transform: translateY(24px) scale(0.985);
    }
    55% {
        opacity: 1;
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}
@keyframes cardLift {
    0% {
        opacity: 0;
        transform: translateY(16px) scale(0.985);
    }
    55% {
        opacity: 1;
        transform: translateY(-4px) scale(1.01);
    }
    100% {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}
@keyframes chartFloat {
    0% {
        transform: translateY(0) scale(1);
    }
    100% {
        transform: translateY(0) scale(1);
    }
}
.delay-1 { animation-delay: 0.12s; }
.delay-2 { animation-delay: 0.22s; }
.delay-3 { animation-delay: 0.34s; }
.delay-4 { animation-delay: 0.46s; }
.delay-5 { animation-delay: 0.58s; }

.kpi-card,
.dash-panel,
.chart-card {
    animation: cardLift 0.9s cubic-bezier(0.2, 0.8, 0.2, 1) both;
}

.chart-container {
    overflow: visible;
}

/* Chart containers */
.chart-container { position: relative; height: clamp(180px, 35vw, 240px); }

/* Admin specific */
.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr));
    gap: 22px;
    margin-bottom: 22px;
}
.chart-card {
    background: #fff;
    border: 1px solid var(--border-color);
    border-left: 4px solid var(--primary-color);
    padding: 22px;
    border-radius: 16px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
}
.chart-title { font-size: 16px; font-weight: 700; margin-bottom: 14px; color: var(--dark-color); display: flex; align-items: center; gap: 10px; }
.chart-title i { color: var(--primary-color); }
[data-theme="dark"] .chart-card { background: rgba(255,255,255,0.05) !important; border-color: rgba(83,197,255,0.18) !important; }
[data-theme="dark"] .chart-title { color: #edf7ff !important; }

/* Role tag */
.role-tag {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 14px; border-radius: 50px;
    font-size: 12px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.role-tag.staff      { background: #cce5ff; color: #004085; }
.role-tag.nurse      { background: #d4edda; color: #155724; }
.role-tag.doctor     { background: #d1ecf1; color: #0c5460; }
.role-tag.laboratory { background: #fff3cd; color: #856404; }
.role-tag.cashier    { background: #d4edda; color: #155724; }
.role-tag.inventory  { background: #f8d7da; color: #721c24; }
.role-tag.admin      { background: linear-gradient(90deg,#0066cc,#00a3cc); color: #fff; }

/* Progress bar */
.progress-mini { height: 6px; border-radius: 3px; background: rgba(0,0,0,0.07); overflow: hidden; margin-top: 6px; }
.progress-mini-fill { height: 100%; border-radius: 3px; transition: width 1s ease; }

/* Chart legend row */
.dash-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 12px 24px;
    justify-content: center;
    margin-top: 10px;
    font-size: 13px;
}
.dash-legend-dot {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    margin-right: 5px;
    vertical-align: middle;
}

/* ── Responsive breakpoints ── */
@media (max-width: 992px) {
    .dash-welcome { padding: 22px 24px; }
    .dash-welcome-text h1 { font-size: 22px; }
    .kpi-grid { gap: 12px; }
    .dash-grid, .dash-grid-3, .charts-grid { gap: 16px; }
}

@media (max-width: 768px) {
    .dash-welcome {
        padding: 20px 18px;
        border-radius: 16px;
        margin-bottom: 20px;
    }
    .dash-welcome-text h1 { font-size: 20px; }
    .dash-welcome-text p { font-size: 13px; }
    .dash-welcome-badge { font-size: 12px; padding: 6px 14px; }

    .kpi-grid {
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 150px), 1fr));
        gap: 10px;
        margin-bottom: 18px;
    }
    .kpi-card { padding: 16px; gap: 12px; }
    .kpi-icon { width: 44px; height: 44px; font-size: 18px; border-radius: 12px; }
    .kpi-data h2 { font-size: clamp(0.9rem, 4vw, 1.35rem); }

    .dash-grid, .dash-grid-3, .charts-grid {
        grid-template-columns: 1fr;
        gap: 14px;
        margin-bottom: 14px;
    }
    .dash-panel { border-radius: 14px; }
    .dash-panel-header { padding: 14px 16px 12px; }
    .dash-panel-title { font-size: 14px; }
    .quick-actions-grid { padding: 16px; gap: 10px; }
    .qa-btn { padding: 16px 10px; font-size: 12px; }
    .qa-btn i { font-size: 20px; }

    .chart-card { padding: 16px; border-radius: 14px; }
    .chart-title { font-size: 14px; margin-bottom: 10px; }
    .chart-wrap { height: 220px; padding: 12px 14px 8px; }
    .chart-container { height: 200px; }

    .dash-table { font-size: 12px; min-width: 460px; }
    .dash-table th, .dash-table td { padding: 8px 10px; }
    .empty-state { padding: 28px 16px; }
    .empty-state i { font-size: 36px; }
}

@media (max-width: 576px) {
    .dash-welcome {
        flex-direction: column;
        align-items: flex-start;
    }
    .dash-welcome-text h1 {
        font-size: 18px;
        line-height: 1.3;
    }
    .dash-welcome-badge { align-self: flex-start; white-space: normal; text-align: center; }

    .kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .kpi-card {
        flex-direction: column;
        align-items: flex-start;
        text-align: left;
        padding: 14px;
    }
    .kpi-data h2 {
        font-size: clamp(0.78rem, 5.2vw, 1.02rem);
    }

    .quick-actions-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .dash-panel-header {
        flex-direction: column;
        align-items: flex-start;
    }
    .dash-panel-header .btn { align-self: flex-start; }

    .dash-legend {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
}

@media (max-width: 380px) {
    .kpi-grid,
    .quick-actions-grid {
        grid-template-columns: 1fr;
    }
    .dash-welcome-text h1 { font-size: 16px; }
    .dash-table { min-width: 400px; }
}

@media (min-width: 1400px) {
    .kpi-grid {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }
    .charts-grid {
        grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
    }
}
</style>

<div class="dash-fade-in">

<?php
// ══════════════════════════════════════════════════════
// ROLE WELCOME BANNER
// ══════════════════════════════════════════════════════
$roleIcons = [
    'admin' => 'fa-chart-line', 'doctor' => 'fa-stethoscope',
    'nurse' => 'fa-heartbeat', 'cashier' => 'fa-cash-register',
    'staff' => 'fa-user-tie', 'laboratory' => 'fa-microscope',
    'inventory' => 'fa-warehouse'
];
$roleGreets = [
    'admin' => 'System Overview — Full Access',
    'doctor' => 'Medical Dashboard',
    'nurse' => 'Nursing Operations',
    'cashier' => 'Billing & Payments',
    'staff' => 'Reception & Front Desk',
    'laboratory' => 'Laboratory Workstation',
    'inventory' => 'Inventory & Stock Control'
];
$icon = $roleIcons[$role] ?? 'fa-tachometer-alt';
$greet = $roleGreets[$role] ?? 'Dashboard';
$userName = isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'User';
$hour = intval(date('G'));
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>

<div class="dash-welcome">
    <div class="dash-welcome-text">
        <h1><i class="fas <?php echo $icon; ?>" style="margin-right:10px; opacity:0.9;"></i><?php echo $greeting; ?>, <?php echo $userName; ?>!</h1>
        <p><i class="fas fa-calendar-day" style="margin-right:6px; opacity:0.75;"></i><?php echo $todayLabel; ?></p>
    </div>
    <span class="dash-welcome-badge"><i class="fas <?php echo $icon; ?>" style="margin-right:6px;"></i><?php echo $greet; ?></span>
</div>

<?php
// ══════════════════════════════════════════════════════
// ── STAFF / RECEPTION DASHBOARD ──────────────────────
// ══════════════════════════════════════════════════════
if (hasRole(['staff', 'reception'])): ?>

<!-- KPI Row -->
<div class="kpi-grid dash-fade-in delay-1">
    <div class="kpi-card primary">
        <div class="kpi-icon primary"><i class="fas fa-list-ol"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($counts['today_visits']); ?></h2><p>Today's Queue</p></div>
    </div>
    <div class="kpi-card warning">
        <div class="kpi-icon warning"><i class="fas fa-clock"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($staffWaiting); ?></h2><p>Waiting</p></div>
    </div>
    <div class="kpi-card info">
        <div class="kpi-icon info"><i class="fas fa-heartbeat"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($staffTriage); ?></h2><p>In Triage</p></div>
    </div>
    <div class="kpi-card success">
        <div class="kpi-icon success"><i class="fas fa-stethoscope"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($staffCons); ?></h2><p>In Consultation</p></div>
    </div>
    <div class="kpi-card purple">
        <div class="kpi-icon purple"><i class="fas fa-user-plus"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($todayReg); ?></h2><p>New Registered</p></div>
    </div>
    <div class="kpi-card danger">
        <div class="kpi-icon danger"><i class="fas fa-user-injured"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($counts['patients']); ?></h2><p>Total Patients</p></div>
    </div>
</div>

<!-- Quick Actions -->
<div class="dash-panel dash-full dash-fade-in delay-2">
    <div class="dash-panel-header">
        <span class="dash-panel-title"><i class="fas fa-bolt"></i> Quick Actions</span>
    </div>
    <div class="quick-actions-grid">
        <a href="<?php echo BASE_URL; ?>modules/reception/patient-add.php" class="qa-btn qa-primary">
            <i class="fas fa-user-plus"></i> Register New Patient
        </a>
        <a href="<?php echo BASE_URL; ?>modules/reception/visit-add.php" class="qa-btn qa-success">
            <i class="fas fa-clipboard-list"></i> Queue for Assessment
        </a>
        <a href="<?php echo BASE_URL; ?>modules/reception/queue.php" class="qa-btn qa-info">
            <i class="fas fa-list-ol"></i> View Queue
        </a>
        <a href="<?php echo BASE_URL; ?>modules/reception/patients.php" class="qa-btn qa-warning">
            <i class="fas fa-search"></i> Find Patient
        </a>
    </div>
</div>

<!-- Today's Queue Table + Recent Patients -->
<div class="dash-grid dash-fade-in delay-3">
    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-list-ol"></i> Today's Queue</span>
            <a href="<?php echo BASE_URL; ?>modules/reception/queue.php" class="btn btn-sm btn-primary">View All</a>
        </div>
        <div class="dash-panel-body">
            <?php if ($todayQueue && $todayQueue->num_rows > 0): ?>
            <table class="dash-table">
                <thead><tr><th>Queue #</th><th>Patient</th><th>Type</th><th>Status</th><th>Time</th></tr></thead>
                <tbody>
                <?php while ($q = $todayQueue->fetch_assoc()): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($q['queue_number']); ?></strong></td>
                    <td><?php echo htmlspecialchars($q['first_name'] . ' ' . $q['last_name']); ?></td>
                    <td><span class="badge badge-secondary"><?php echo ucfirst($q['visit_type'] ?? 'walk-in'); ?></span></td>
                    <td><?php echo getStatusBadge($q['status']); ?></td>
                    <td><?php echo formatDateTime($q['created_at'], 'h:i A'); ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-inbox"></i><p>No patients in queue today</p></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-user-injured"></i> Recently Registered Patients</span>
            <a href="<?php echo BASE_URL; ?>modules/reception/patients.php" class="btn btn-sm btn-primary">View All</a>
        </div>
        <div class="dash-panel-body">
            <?php if ($recentPatients && $recentPatients->num_rows > 0): ?>
            <table class="dash-table">
                <thead><tr><th>Code</th><th>Name</th><th>Gender</th><th>Registered</th></tr></thead>
                <tbody>
                <?php while ($p = $recentPatients->fetch_assoc()): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($p['patient_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></td>
                    <td><span class="badge badge-<?php echo $p['gender'] === 'Female' ? 'danger' : 'primary'; ?>"><?php echo $p['gender']; ?></span></td>
                    <td><?php echo formatDate($p['reg_date']); ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-user-slash"></i><p>No patients registered yet</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// ══════════════════════════════════════════════════════
// ── NURSE DASHBOARD ───────────────────────────────────
// ══════════════════════════════════════════════════════
elseif (hasRole(['nurse'])): ?>

<!-- KPI Row -->
<div class="kpi-grid dash-fade-in delay-1">
    <div class="kpi-card warning">
        <div class="kpi-icon warning"><i class="fas fa-clock"></i></div>
        <div class="kpi-data"><h2><?php echo $nWait; ?></h2><p>Waiting</p></div>
    </div>
    <div class="kpi-card info">
        <div class="kpi-icon info"><i class="fas fa-heartbeat"></i></div>
        <div class="kpi-data"><h2><?php echo $nTriage; ?></h2><p>In Triage</p></div>
    </div>
    <div class="kpi-card success">
        <div class="kpi-icon success"><i class="fas fa-check-circle"></i></div>
        <div class="kpi-data"><h2><?php echo $nTriageDone; ?></h2><p>Triaged Today</p></div>
    </div>
    <div class="kpi-card primary">
        <div class="kpi-icon primary"><i class="fas fa-procedures"></i></div>
        <div class="kpi-data"><h2><?php echo $nurseAdmittedCount; ?></h2><p>Admitted Patients</p></div>
    </div>
    <div class="kpi-card danger">
        <div class="kpi-icon danger"><i class="fas fa-list-ol"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($counts['today_visits']); ?></h2><p>Total Today</p></div>
    </div>
</div>

<!-- Quick Actions -->
<div class="dash-panel dash-full dash-fade-in delay-2">
    <div class="dash-panel-header">
        <span class="dash-panel-title"><i class="fas fa-bolt"></i> Quick Actions</span>
    </div>
    <div class="quick-actions-grid">
        <a href="<?php echo BASE_URL; ?>modules/triage/triage.php" class="qa-btn qa-info">
            <i class="fas fa-heartbeat"></i> Start Assessment
        </a>
        <a href="<?php echo BASE_URL; ?>modules/admission/admissions.php" class="qa-btn qa-primary">
            <i class="fas fa-procedures"></i> Admissions
        </a>
        <a href="<?php echo BASE_URL; ?>modules/admission/monitoring.php" class="qa-btn qa-success">
            <i class="fas fa-notes-medical"></i> Patient Monitoring
        </a>
        <a href="<?php echo BASE_URL; ?>modules/reception/queue.php" class="qa-btn qa-warning">
            <i class="fas fa-list-ol"></i> View Queue
        </a>
    </div>
</div>

<!-- Waiting for Triage + Admitted Patients -->
<div class="dash-grid dash-fade-in delay-3">
    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-clock" style="color:#ffc107;"></i> Patients Awaiting Assessment</span>
            <a href="<?php echo BASE_URL; ?>modules/triage/triage.php" class="btn btn-sm btn-info">Start Triage</a>
        </div>
        <div class="dash-panel-body">
            <?php if ($nurseWaiting && $nurseWaiting->num_rows > 0): ?>
            <table class="dash-table">
                <thead><tr><th>Queue #</th><th>Patient</th><th>Age</th><th>Priority</th><th>Wait Time</th></tr></thead>
                <tbody>
                <?php while ($w = $nurseWaiting->fetch_assoc()): 
                    $age = $w['date_of_birth'] ? calculateAge($w['date_of_birth']) : 'N/A';
                    $waitMins = round((time() - strtotime($w['created_at'])) / 60);
                    $waitColor = $waitMins > 30 ? '#dc3545' : ($waitMins > 15 ? '#fd7e14' : '#28a745');
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($w['queue_number']); ?></strong></td>
                    <td><?php echo htmlspecialchars($w['first_name'] . ' ' . $w['last_name']); ?></td>
                    <td><?php echo $age; ?></td>
                    <td><?php echo getStatusBadge($w['priority'] ?? 'normal'); ?></td>
                    <td style="color:<?php echo $waitColor; ?>; font-weight:600;"><?php echo $waitMins; ?> min</td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-check-double"></i><p>No patients waiting for assessment</p></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-procedures" style="color:#0066cc;"></i> Admitted Patients</span>
            <a href="<?php echo BASE_URL; ?>modules/admission/admissions.php" class="btn btn-sm btn-primary">View All</a>
        </div>
        <div class="dash-panel-body">
            <?php if ($nurseAdmitted && $nurseAdmitted->num_rows > 0): ?>
            <table class="dash-table">
                <thead><tr><th>Patient</th><th>Room</th><th>Days</th><th>Action</th></tr></thead>
                <tbody>
                <?php while ($a = $nurseAdmitted->fetch_assoc()): ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($a['first_name'] . ' ' . $a['last_name']); ?></strong><br>
                        <small style="color:var(--text-muted);"><?php echo htmlspecialchars($a['patient_code']); ?></small>
                    </td>
                    <td><span class="badge badge-info"><?php echo htmlspecialchars($a['room_number'] ?? 'N/A'); ?></span></td>
                    <td><span class="badge badge-<?php echo $a['days_admitted'] > 7 ? 'danger' : 'success'; ?>"><?php echo $a['days_admitted']; ?>d</span></td>
                    <td><a href="<?php echo BASE_URL; ?>modules/admission/monitoring.php" class="btn btn-xs btn-primary" style="font-size:11px;padding:3px 10px;">Monitor</a></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-bed"></i><p>No patients currently admitted</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// ══════════════════════════════════════════════════════
// ── DOCTOR DASHBOARD ──────────────────────────────────
// ══════════════════════════════════════════════════════
elseif (hasRole(['doctor'])): ?>

<!-- KPI Row -->
<div class="kpi-grid dash-fade-in delay-1">
    <div class="kpi-card primary">
        <div class="kpi-icon primary"><i class="fas fa-stethoscope"></i></div>
        <div class="kpi-data"><h2><?php echo $drConsultCount; ?></h2><p>Consultations Today</p></div>
    </div>
    <div class="kpi-card warning">
        <div class="kpi-icon warning"><i class="fas fa-clock"></i></div>
        <div class="kpi-data"><h2><?php echo $counts['waiting']; ?></h2><p>Patients Waiting</p></div>
    </div>
    <div class="kpi-card danger">
        <div class="kpi-icon danger"><i class="fas fa-vials"></i></div>
        <div class="kpi-data"><h2><?php echo $drPendLab; ?></h2><p>Pending Lab Results</p></div>
    </div>
    <div class="kpi-card success">
        <div class="kpi-icon success"><i class="fas fa-prescription-bottle-alt"></i></div>
        <div class="kpi-data"><h2><?php echo $drPrescCount; ?></h2><p>Prescriptions Today</p></div>
    </div>
    <div class="kpi-card info">
        <div class="kpi-icon info"><i class="fas fa-procedures"></i></div>
        <div class="kpi-data"><h2><?php echo $drAdmitted; ?></h2><p>My Admitted Patients</p></div>
    </div>
</div>


<!-- Patient Queue + Today's Consultations -->
<div class="dash-grid dash-fade-in delay-3">
    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-list-ol" style="color:#ffc107;"></i> Today's Patient Queue</span>
            <a href="<?php echo BASE_URL; ?>modules/reception/queue.php" class="btn btn-sm btn-warning">View Queue</a>
        </div>
        <div class="dash-panel-body">
            <?php if ($drWaitingQueue && $drWaitingQueue->num_rows > 0): ?>
            <table class="dash-table">
                <thead><tr><th>Queue #</th><th>Patient</th><th>Age</th><th>Status</th></tr></thead>
                <tbody>
                <?php while ($q = $drWaitingQueue->fetch_assoc()): 
                    $age = $q['date_of_birth'] ? calculateAge($q['date_of_birth']) : 'N/A';
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($q['queue_number']); ?></strong></td>
                    <td><?php echo htmlspecialchars($q['first_name'] . ' ' . $q['last_name']); ?></td>
                    <td><?php echo $age; ?></td>
                    <td><?php echo getStatusBadge($q['status']); ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-inbox"></i><p>No patients in queue today</p></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-stethoscope" style="color:#0066cc;"></i> My Consultations Today</span>
            <a href="<?php echo BASE_URL; ?>modules/consultation/consultations.php" class="btn btn-sm btn-primary">View All</a>
        </div>
        <div class="dash-panel-body">
            <?php if ($drConsultToday && $drConsultToday->num_rows > 0): ?>
            <table class="dash-table">
                <thead><tr><th>Patient</th><th>Age</th><th>Outcome</th><th>Time</th></tr></thead>
                <tbody>
                <?php while ($c = $drConsultToday->fetch_assoc()): 
                    $age = $c['date_of_birth'] ? calculateAge($c['date_of_birth']) : 'N/A';
                ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name']); ?></strong><br>
                        <small style="color:var(--text-muted);"><?php echo htmlspecialchars($c['patient_code']); ?></small>
                    </td>
                    <td><?php echo $age; ?></td>
                    <td><?php echo getStatusBadge($c['outcome']); ?></td>
                    <td><?php echo formatDateTime($c['created_at'], 'h:i A'); ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-stethoscope"></i><p>No consultations done today</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>


<!-- Quick Actions -->
<div class="dash-panel dash-full dash-fade-in delay-2">
    <div class="dash-panel-header">
        <span class="dash-panel-title"><i class="fas fa-bolt"></i> Quick Actions</span>
    </div>
    <div class="quick-actions-grid">
        <?php if (hasRole(['admin', 'doctor'])): ?>
        <a href="<?php echo BASE_URL; ?>modules/consultation/consultations.php" class="qa-btn qa-warning">
            <i class="fas fa-stethoscope"></i> Start Consultation
        </a>
        <?php endif; ?>
        <?php if (hasRole(['admin', 'doctor', 'cashier', 'pharmacist', 'nurse', 'staff'])): ?>
        <a href="<?php echo BASE_URL; ?>modules/consultation/prescriptions.php" class="qa-btn qa-primary">
            <i class="fas fa-prescription-bottle-alt"></i> Prescriptions
        </a>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>modules/admission/admissions.php" class="qa-btn qa-success">
            <i class="fas fa-procedures"></i> Admissions
        </a>
        <a href="<?php echo BASE_URL; ?>modules/reception/queue.php" class="qa-btn qa-info">
            <i class="fas fa-list-ol"></i> Patient Queue
        </a>
        <a href="<?php echo BASE_URL; ?>modules/maternity/index.php" class="qa-btn qa-purple">
            <i class="fas fa-baby"></i> Maternity
        </a>
    </div>
</div>

<!-- Pending Lab Results -->
<div class="dash-panel dash-full dash-fade-in delay-4">
    <div class="dash-panel-header">
        <span class="dash-panel-title"><i class="fas fa-vials" style="color:#dc3545;"></i> Pending Lab Results (My Requests)</span>
        <a href="<?php echo BASE_URL; ?>modules/laboratory/requests.php" class="btn btn-sm btn-danger">View All</a>
    </div>
    <div class="dash-panel-body">
        <?php if ($drPendingLabRes && $drPendingLabRes->num_rows > 0): ?>
        <table class="dash-table">
            <thead><tr><th>Patient</th><th>Test</th><th>Category</th><th>Priority</th><th>Requested</th></tr></thead>
            <tbody>
            <?php while ($l = $drPendingLabRes->fetch_assoc()): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($l['first_name'] . ' ' . $l['last_name']); ?></strong></td>
                <td><?php echo htmlspecialchars($l['test_name']); ?></td>
                <td><span class="badge badge-secondary"><?php echo ucfirst($l['category']); ?></span></td>
                <td>
                    <?php 
                    $pClass = $l['priority'] === 'stat' ? 'badge-danger' : ($l['priority'] === 'urgent' ? 'badge-warning' : 'badge-secondary');
                    ?>
                    <span class="badge <?php echo $pClass; ?>"><?php echo strtoupper($l['priority']); ?></span>
                </td>
                <td><?php echo formatDateTime($l['requested_at'], 'M d, h:i A'); ?></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-check-circle" style="color:#28a745;"></i><p>No pending lab results</p></div>
        <?php endif; ?>
    </div>
</div>

<?php
// ══════════════════════════════════════════════════════
// ── LABORATORY DASHBOARD ──────────────────────────────
// ══════════════════════════════════════════════════════
elseif (hasRole(['laboratory'])): ?>

<!-- KPI Row -->
<div class="kpi-grid dash-fade-in delay-1">
    <div class="kpi-card danger">
        <div class="kpi-icon danger"><i class="fas fa-vials"></i></div>
        <div class="kpi-data"><h2><?php echo $lPend; ?></h2><p>Pending Tests</p></div>
    </div>
    <div class="kpi-card warning">
        <div class="kpi-icon warning"><i class="fas fa-spinner"></i></div>
        <div class="kpi-data"><h2><?php echo $lInProg; ?></h2><p>In Progress</p></div>
    </div>
    <div class="kpi-card success">
        <div class="kpi-icon success"><i class="fas fa-check-circle"></i></div>
        <div class="kpi-data"><h2><?php echo $lDoneToday; ?></h2><p>Completed Today</p></div>
    </div>
    <div class="kpi-card info">
        <div class="kpi-icon info"><i class="fas fa-microscope"></i></div>
        <div class="kpi-data"><h2><?php echo $lPend + $lInProg; ?></h2><p>Active Workload</p></div>
    </div>
</div>

<!-- Quick Actions -->
<div class="dash-panel dash-full dash-fade-in delay-2">
    <div class="dash-panel-header">
        <span class="dash-panel-title"><i class="fas fa-bolt"></i> Quick Actions</span>
    </div>
    <div class="quick-actions-grid">
        <a href="<?php echo BASE_URL; ?>modules/laboratory/requests.php" class="qa-btn qa-danger">
            <i class="fas fa-vials"></i> Pending Requests
        </a>
        <a href="<?php echo BASE_URL; ?>modules/laboratory/result-enter.php" class="qa-btn qa-primary">
            <i class="fas fa-microscope"></i> Enter Results
        </a>
        <a href="<?php echo BASE_URL; ?>modules/laboratory/results.php" class="qa-btn qa-success">
            <i class="fas fa-file-alt"></i> View Results
        </a>
        <a href="<?php echo BASE_URL; ?>modules/laboratory/tests.php" class="qa-btn qa-info">
            <i class="fas fa-flask"></i> Test Catalog
        </a>
    </div>
</div>

<div class="dash-grid dash-fade-in delay-3">
    <!-- Pending Tests -->
    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-vials" style="color:#dc3545;"></i> Pending Tests (Priority Order)</span>
            <a href="<?php echo BASE_URL; ?>modules/laboratory/requests.php" class="btn btn-sm btn-danger">Process All</a>
        </div>
        <div class="dash-panel-body">
            <?php if ($labPendingRes && $labPendingRes->num_rows > 0): ?>
            <table class="dash-table">
                <thead><tr><th>Patient</th><th>Test</th><th>Priority</th><th>Requested</th></tr></thead>
                <tbody>
                <?php while ($l = $labPendingRes->fetch_assoc()): 
                    $pBadge = $l['priority'] === 'stat' ? 'badge-danger' : ($l['priority'] === 'urgent' ? 'badge-warning' : 'badge-secondary');
                ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($l['first_name'] . ' ' . $l['last_name']); ?></strong><br>
                        <small style="color:var(--text-muted);"><?php echo htmlspecialchars($l['patient_code']); ?></small>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($l['test_name']); ?><br>
                        <small style="color:var(--text-muted);"><?php echo ucfirst($l['category']); ?></small>
                    </td>
                    <td><span class="badge <?php echo $pBadge; ?>"><?php echo strtoupper($l['priority']); ?></span></td>
                    <td><?php echo formatDateTime($l['requested_at'], 'M d h:i A'); ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-check-double" style="color:#28a745;"></i><p>No pending tests — all clear!</p></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- In Progress + Today's Category Chart -->
    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-chart-pie" style="color:#ffc107;"></i> Today's Tests by Category</span>
        </div>
        <div class="chart-wrap">
            <div class="chart-container"><canvas id="labCatChart"></canvas></div>
        </div>
        <?php if ($labInProgRes && $labInProgRes->num_rows > 0): ?>
        <div style="border-top: 1px solid var(--border-color); padding: 10px 0 0;">
            <div style="padding: 8px 18px; font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">In Progress</div>
            <?php while ($ip = $labInProgRes->fetch_assoc()): ?>
            <div style="padding: 8px 18px; border-bottom: 1px solid var(--border-color); font-size: 13px; display:flex; justify-content:space-between; align-items:center;">
                <span><?php echo htmlspecialchars($ip['first_name'] . ' ' . $ip['last_name']); ?> — <?php echo htmlspecialchars($ip['test_name']); ?></span>
                <span class="badge badge-warning">Processing</span>
            </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function(){
    var labCatLabels = <?php echo json_encode($labCatLabels); ?>;
    var labCatData   = <?php echo json_encode($labCatData); ?>;
    var colors = ['#0066cc','#28a745','#ffc107','#dc3545','#17a2b8','#8e44ad','#e67e22'];
    function isDark(){ return document.documentElement.getAttribute('data-theme')==='dark'; }
    var labChart = null;
    function createLabChart(){
        var ctx = document.getElementById('labCatChart')?.getContext('2d');
        if(!ctx) return;
        if(labChart) try{labChart.destroy()}catch(e){}
        var dark = isDark();
        labChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labCatLabels,
                datasets: [{
                    data: labCatData,
                    backgroundColor: colors.slice(0, labCatData.length),
                    borderColor: dark ? '#1a2535' : '#fff',
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: dark ? '#edf7ff' : '#16304a', font: { size: 12 } } } }
            }
        });
    }
    createLabChart();
    window.addEventListener('themeChanged', createLabChart);
})();
</script>

<?php
// ══════════════════════════════════════════════════════
// ── CASHIER DASHBOARD ─────────────────────────────────
// ══════════════════════════════════════════════════════
elseif (hasRole(['cashier'])): ?>

<!-- KPI Row -->
<div class="kpi-grid dash-fade-in delay-1">
    <div class="kpi-card success">
        <div class="kpi-icon success"><i class="fas fa-peso-sign"></i></div>
        <div class="kpi-data"><h2><?php echo '₱' . number_format($cRev, 0); ?></h2><p>Today's Revenue</p></div>
    </div>
    <div class="kpi-card primary">
        <div class="kpi-icon primary"><i class="fas fa-receipt"></i></div>
        <div class="kpi-data"><h2><?php echo $cTrans; ?></h2><p>Transactions Today</p></div>
    </div>
    <div class="kpi-card warning">
        <div class="kpi-icon warning"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="kpi-data"><h2><?php echo $cPend; ?></h2><p>Pending Invoices</p></div>
    </div>
    <div class="kpi-card info">
        <div class="kpi-icon info"><i class="fas fa-check-square"></i></div>
        <div class="kpi-data"><h2><?php echo $cPaidToday; ?></h2><p>Paid Today</p></div>
    </div>
    <div class="kpi-card purple">
        <div class="kpi-icon purple"><i class="fas fa-calendar-check"></i></div>
        <div class="kpi-data"><h2><?php echo '₱' . number_format($cMonthRev, 0); ?></h2><p>This Month</p></div>
    </div>
</div>

<!-- Quick Actions -->
<div class="dash-panel dash-full dash-fade-in delay-2">
    <div class="dash-panel-header">
        <span class="dash-panel-title"><i class="fas fa-bolt"></i> Quick Actions</span>
    </div>
    <div class="quick-actions-grid">
        <a href="<?php echo BASE_URL; ?>modules/billing/invoices.php" class="qa-btn qa-primary">
            <i class="fas fa-file-invoice"></i> All Invoices
        </a>
        <a href="<?php echo BASE_URL; ?>modules/billing/payments.php" class="qa-btn qa-success">
            <i class="fas fa-cash-register"></i> Process Payment
        </a>
        <a href="<?php echo BASE_URL; ?>modules/admin/reports.php" class="qa-btn qa-warning">
            <i class="fas fa-chart-bar"></i> Reports
        </a>
    </div>
</div>

<div class="dash-grid dash-fade-in delay-3">
    <!-- Pending Invoices -->
    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-file-invoice-dollar" style="color:#ffc107;"></i> Pending Invoices</span>
            <a href="<?php echo BASE_URL; ?>modules/billing/invoices.php" class="btn btn-sm btn-warning">View All</a>
        </div>
        <div class="dash-panel-body">
            <?php if ($cashPendingInv && $cashPendingInv->num_rows > 0): ?>
            <table class="dash-table">
                <thead><tr><th>Invoice #</th><th>Patient</th><th>Balance</th><th>Status</th></tr></thead>
                <tbody>
                <?php while ($inv = $cashPendingInv->fetch_assoc()): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($inv['invoice_number']); ?></strong></td>
                    <td>
                        <?php echo htmlspecialchars($inv['first_name'] . ' ' . $inv['last_name']); ?><br>
                        <small style="color:var(--text-muted);"><?php echo htmlspecialchars($inv['patient_code']); ?></small>
                    </td>
                    <td style="font-weight:700; color:#dc3545;">₱<?php echo number_format($inv['balance_amount'], 2); ?></td>
                    <td><?php echo getStatusBadge($inv['status']); ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-check-circle" style="color:#28a745;"></i><p>No pending invoices</p></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Revenue chart + Payment Methods -->
    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-chart-area" style="color:#28a745;"></i> Revenue — Last 7 Days</span>
        </div>
        <div class="chart-wrap">
            <div class="chart-container"><canvas id="cashRevChart"></canvas></div>
        </div>
    </div>
</div>

<div class="dash-grid dash-fade-in delay-4">
    <!-- Recent Payments -->
    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-cash-register" style="color:#28a745;"></i> Recent Payments</span>
            <a href="<?php echo BASE_URL; ?>modules/billing/payments.php" class="btn btn-sm btn-success">View All</a>
        </div>
        <div class="dash-panel-body">
            <?php if ($cashRecentPay && $cashRecentPay->num_rows > 0): ?>
            <table class="dash-table">
                <thead><tr><th>Invoice</th><th>Patient</th><th>Amount</th><th>Method</th><th>Time</th></tr></thead>
                <tbody>
                <?php while ($py = $cashRecentPay->fetch_assoc()): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($py['invoice_number']); ?></strong></td>
                    <td><?php echo htmlspecialchars($py['first_name'] . ' ' . $py['last_name']); ?></td>
                    <td style="color:#28a745; font-weight:700;">₱<?php echo number_format($py['payment_amount'], 2); ?></td>
                    <td><span class="badge badge-info"><?php echo ucfirst($py['payment_method']); ?></span></td>
                    <td><?php echo formatDateTime($py['payment_date'], 'h:i A'); ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-cash-register"></i><p>No payments recorded today</p></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Method Breakdown -->
    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-chart-pie" style="color:#0066cc;"></i> Payment Methods This Month</span>
        </div>
        <div class="chart-wrap" style="height:280px;">
            <div class="chart-container" style="height:240px;"><canvas id="cashPayChart"></canvas></div>
        </div>
    </div>
</div>

<script>
(function(){
    var revLabels = <?php echo json_encode($cashRevLabels); ?>;
    var revData   = <?php echo json_encode($cashRevData); ?>;
    var payLabels = <?php echo json_encode($payMethodLabels); ?>;
    var payData   = <?php echo json_encode($payMethodData); ?>;
    function isDark(){ return document.documentElement.getAttribute('data-theme')==='dark'; }
    var revChart = null, payChart = null;

    function createRevChart(){
        var ctx = document.getElementById('cashRevChart')?.getContext('2d');
        if(!ctx) return;
        if(revChart) try{revChart.destroy()}catch(e){}
        var dark = isDark();
        var tickColor = dark ? '#d9eaff' : '#16304a';
        revChart = new Chart(ctx, {
            type: 'bar',
            data: { labels: revLabels, datasets: [{
                label: 'Revenue (₱)',
                data: revData,
                backgroundColor: revData.map((_,i) => i === revData.length-1 ? 'rgba(40,167,69,0.9)' : 'rgba(40,167,69,0.45)'),
                borderRadius: 8
            }]},
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { color: tickColor } }, x: { ticks: { color: tickColor } } }
            }
        });
    }
    function createPayChart(){
        var ctx = document.getElementById('cashPayChart')?.getContext('2d');
        if(!ctx) return;
        if(payChart) try{payChart.destroy()}catch(e){}
        var dark = isDark();
        payChart = new Chart(ctx, {
            type: 'doughnut',
            data: { labels: payLabels, datasets: [{
                data: payData,
                backgroundColor: ['#28a745','#0066cc','#ffc107','#dc3545','#17a2b8','#8e44ad','#e67e22'],
                borderColor: dark ? '#1a2535' : '#fff',
                borderWidth: 3
            }]},
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: dark ? '#edf7ff' : '#16304a' } } }
            }
        });
    }
    createRevChart(); createPayChart();
    window.addEventListener('themeChanged', function(){ createRevChart(); createPayChart(); });
})();
</script>

<?php
// ══════════════════════════════════════════════════════
// ── INVENTORY DASHBOARD ───────────────────────────────
// ══════════════════════════════════════════════════════
elseif (hasRole(['inventory'])): ?>

<!-- KPI Row -->
<div class="kpi-grid dash-fade-in delay-1">
    <div class="kpi-card primary">
        <div class="kpi-icon primary"><i class="fas fa-boxes"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($invItemCount); ?></h2><p>Active Items</p></div>
    </div>
    <div class="kpi-card success">
        <div class="kpi-icon success"><i class="fas fa-pills"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($invMedTotal); ?></h2><p>Medicine Stock</p></div>
    </div>
    <div class="kpi-card info">
        <div class="kpi-icon info"><i class="fas fa-box-open"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($invSupTotal); ?></h2><p>Supply Stock</p></div>
    </div>
    <div class="kpi-card warning">
        <div class="kpi-icon warning"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="kpi-data"><h2><?php echo $invLowCount; ?></h2><p>Low Stock Items</p></div>
    </div>
    <div class="kpi-card orange">
        <div class="kpi-icon orange"><i class="fas fa-hourglass-half"></i></div>
        <div class="kpi-data"><h2><?php echo $invNearExp; ?></h2><p>Near Expiry (30d)</p></div>
    </div>
    <div class="kpi-card danger">
        <div class="kpi-icon danger"><i class="fas fa-skull-crossbones"></i></div>
        <div class="kpi-data"><h2><?php echo $invExpCount; ?></h2><p>Expired Stock</p></div>
    </div>
</div>

<!-- Quick Actions -->
<div class="dash-panel dash-full dash-fade-in delay-2">
    <div class="dash-panel-header">
        <span class="dash-panel-title"><i class="fas fa-bolt"></i> Quick Actions</span>
    </div>
    <div class="quick-actions-grid">
        <a href="<?php echo BASE_URL; ?>modules/inventory/items.php" class="qa-btn qa-primary">
            <i class="fas fa-box"></i> View Items
        </a>
        <a href="<?php echo BASE_URL; ?>modules/inventory/stock.php" class="qa-btn qa-info">
            <i class="fas fa-warehouse"></i> Stock Levels
        </a>
        <a href="<?php echo BASE_URL; ?>modules/inventory/item-add.php" class="qa-btn qa-success">
            <i class="fas fa-plus-circle"></i> Add New Item
        </a>
        <a href="<?php echo BASE_URL; ?>modules/inventory/stock-add.php" class="qa-btn qa-warning">
            <i class="fas fa-arrow-circle-down"></i> Add Stock
        </a>
        <a href="<?php echo BASE_URL; ?>modules/inventory/stock.php?expiry=near" class="qa-btn qa-orange">
            <i class="fas fa-hourglass-half"></i> Near Expiry
        </a>
        <a href="<?php echo BASE_URL; ?>modules/inventory/stock.php?expiry=expired" class="qa-btn qa-danger">
            <i class="fas fa-skull-crossbones"></i> Expired Stock
        </a>
    </div>
</div>

<div class="dash-grid dash-fade-in delay-3">
    <!-- Low Stock Alert -->
    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-exclamation-triangle" style="color:#ffc107;"></i> Low Stock Alert</span>
            <a href="<?php echo BASE_URL; ?>modules/inventory/stock.php" class="btn btn-sm btn-warning">View All</a>
        </div>
        <div class="dash-panel-body">
            <?php if ($invLowStock && $invLowStock->num_rows > 0): ?>
            <table class="dash-table">
                <thead><tr><th>Item</th><th>Type</th><th>In Stock</th><th>Reorder At</th><th>Status</th></tr></thead>
                <tbody>
                <?php while ($itm = $invLowStock->fetch_assoc()): 
                    $stockPct = $itm['reorder_level'] > 0 ? min(100, round(($itm['total_stock'] / $itm['reorder_level']) * 100)) : 0;
                    $barColor = $itm['total_stock'] == 0 ? '#dc3545' : '#ffc107';
                ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($itm['item_name']); ?></strong><br>
                        <small style="color:var(--text-muted);"><?php echo htmlspecialchars($itm['item_code']); ?></small>
                    </td>
                    <td><span class="badge badge-secondary"><?php echo htmlspecialchars($itm['item_type']); ?></span></td>
                    <td>
                        <strong style="color:<?php echo $itm['total_stock'] == 0 ? '#dc3545' : '#fd7e14'; ?>;"><?php echo $itm['total_stock']; ?></strong>
                        <div class="progress-mini"><div class="progress-mini-fill" style="width:<?php echo $stockPct; ?>%; background:<?php echo $barColor; ?>;"></div></div>
                    </td>
                    <td><?php echo $itm['reorder_level']; ?></td>
                    <td>
                        <?php if ($itm['total_stock'] == 0): ?>
                        <span class="badge badge-danger">OUT OF STOCK</span>
                        <?php else: ?>
                        <span class="badge badge-warning">LOW</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-check-circle" style="color:#28a745;"></i><p>All stock levels are healthy</p></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stock Type Chart + Near Expiry -->
    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-chart-pie" style="color:#0066cc;"></i> Stock Distribution</span>
        </div>
        <div class="chart-wrap" style="height:200px;">
            <div class="chart-container" style="height:180px;"><canvas id="invTypeChart"></canvas></div>
        </div>
        <div style="border-top: 1px solid var(--border-color);">
            <div class="dash-panel-header" style="border-bottom:0; padding-bottom:6px;">
                <span class="dash-panel-title" style="font-size:13px;"><i class="fas fa-hourglass-half" style="color:#e67e22;"></i> Near Expiry Items (30 Days)</span>
            </div>
            <?php if ($invNearExpiry && $invNearExpiry->num_rows > 0): ?>
            <table class="dash-table">
                <thead><tr><th>Item</th><th>Batch</th><th>Expires</th><th>Days Left</th><th>Qty</th></tr></thead>
                <tbody>
                <?php while ($ne = $invNearExpiry->fetch_assoc()): 
                    $dColor = $ne['days_left'] <= 7 ? '#dc3545' : ($ne['days_left'] <= 14 ? '#fd7e14' : '#ffc107');
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($ne['item_name']); ?></strong></td>
                    <td><small><?php echo htmlspecialchars($ne['batch_number'] ?? 'N/A'); ?></small></td>
                    <td><?php echo formatDate($ne['expiry_date']); ?></td>
                    <td style="color:<?php echo $dColor; ?>; font-weight:700;"><?php echo $ne['days_left']; ?>d</td>
                    <td><?php echo $ne['quantity_in_stock']; ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state" style="padding:20px;"><i class="fas fa-check-circle" style="font-size:28px; color:#28a745;"></i><p style="margin-top:8px;">No items expiring soon</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Transactions -->
<div class="dash-panel dash-full dash-fade-in delay-4">
    <div class="dash-panel-header">
        <span class="dash-panel-title"><i class="fas fa-history" style="color:#0066cc;"></i> Recent Inventory Transactions</span>
        <a href="<?php echo BASE_URL; ?>modules/inventory/stock.php" class="btn btn-sm btn-primary">View Stock</a>
    </div>
    <div class="dash-panel-body">
        <?php if ($invRecentTx && $invRecentTx->num_rows > 0): ?>
        <table class="dash-table">
            <thead><tr><th>Item</th><th>Type</th><th>Qty</th><th>By</th><th>Date</th></tr></thead>
            <tbody>
            <?php while ($tx = $invRecentTx->fetch_assoc()): 
                $txColor = in_array($tx['transaction_type'], ['receipt', 'return']) ? '#28a745' : '#dc3545';
                $txSign  = in_array($tx['transaction_type'], ['receipt', 'return']) ? '+' : '-';
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($tx['item_name']); ?></strong></td>
                <td><span class="badge badge-<?php echo in_array($tx['transaction_type'],['receipt','return']) ? 'success' : 'danger'; ?>"><?php echo ucfirst($tx['transaction_type']); ?></span></td>
                <td style="color:<?php echo $txColor; ?>; font-weight:700;"><?php echo $txSign . abs($tx['quantity']); ?></td>
                <td><?php echo htmlspecialchars($tx['performed_by_name'] ?? 'System'); ?></td>
                <td><?php echo formatDateTime($tx['transaction_date'], 'M d, h:i A'); ?></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-history"></i><p>No transactions recorded yet</p></div>
        <?php endif; ?>
    </div>
</div>

<script>
(function(){
    var typeLabels = <?php echo json_encode($invTypeLabels); ?>;
    var typeData   = <?php echo json_encode($invTypeData); ?>;
    function isDark(){ return document.documentElement.getAttribute('data-theme')==='dark'; }
    var typeChart = null;
    function createTypeChart(){
        var ctx = document.getElementById('invTypeChart')?.getContext('2d');
        if(!ctx) return;
        if(typeChart) try{typeChart.destroy()}catch(e){}
        var dark = isDark();
        typeChart = new Chart(ctx, {
            type: 'doughnut',
            data: { labels: typeLabels, datasets: [{
                data: typeData,
                backgroundColor: ['#0066cc','#28a745'],
                borderColor: dark ? '#1a2535' : '#fff',
                borderWidth: 3
            }]},
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: dark ? '#edf7ff' : '#16304a' } } }
            }
        });
    }
    createTypeChart();
    window.addEventListener('themeChanged', createTypeChart);
})();
</script>

<?php
// ══════════════════════════════════════════════════════
// ── ADMIN DASHBOARD ───────────────────────────────────
// ══════════════════════════════════════════════════════
elseif (hasRole(['admin'])): ?>

<!-- Admin KPI Row -->
<div class="kpi-grid dash-fade-in delay-1">
    <div class="kpi-card primary">
        <div class="kpi-icon primary"><i class="fas fa-user-injured"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($counts['patients']); ?></h2><p>Total Patients</p></div>
    </div>
    <div class="kpi-card info">
        <div class="kpi-icon info"><i class="fas fa-calendar-day"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($counts['today_visits']); ?></h2><p>Today's Queue</p></div>
    </div>
    <div class="kpi-card warning">
        <div class="kpi-icon warning"><i class="fas fa-clock"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($counts['waiting']); ?></h2><p>Waiting</p></div>
    </div>
    <div class="kpi-card success">
        <div class="kpi-icon success"><i class="fas fa-procedures"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($counts['admitted']); ?></h2><p>Admitted</p></div>
    </div>
    <div class="kpi-card danger">
        <div class="kpi-icon danger"><i class="fas fa-vials"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($counts['pending_lab']); ?></h2><p>Pending Lab</p></div>
    </div>
    <div class="kpi-card purple">
        <div class="kpi-icon purple"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($counts['pending_billing']); ?></h2><p>Pending Bills</p></div>
    </div>
    <div class="kpi-card success" style="--accent:#1ab85c;">
        <div class="kpi-icon success"><i class="fas fa-peso-sign"></i></div>
        <div class="kpi-data"><h2><?php echo '₱' . number_format($adminRevToday, 0); ?></h2><p>Today's Revenue</p></div>
    </div>
    <div class="kpi-card info">
        <div class="kpi-icon info"><i class="fas fa-calendar-alt"></i></div>
        <div class="kpi-data"><h2><?php echo '₱' . number_format($adminRevMonth, 0); ?></h2><p>Month Revenue</p></div>
    </div>
    <div class="kpi-card warning">
        <div class="kpi-icon warning"><i class="fas fa-boxes"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($counts['low_stock']); ?></h2><p>Low Stock Items</p></div>
    </div>
    <div class="kpi-card orange">
        <div class="kpi-icon orange"><i class="fas fa-baby"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($activePreg); ?></h2><p>Active Pregnancies</p></div>
    </div>
</div>

<!-- Charts Grid -->
<div class="charts-grid dash-fade-in delay-2">
    <div class="chart-card">
        <div class="chart-title"><i class="fas fa-chart-line"></i> Patient Visits — Last 30 Days</div>
        <div class="chart-container"><canvas id="visitsChart"></canvas></div>
    </div>
    <div class="chart-card">
        <div class="chart-title"><i class="fas fa-chart-bar"></i> Admissions vs Discharges</div>
        <div class="chart-container"><canvas id="admissionsChart"></canvas></div>
    </div>
    <div class="chart-card">
        <div class="chart-title"><i class="fas fa-chart-area"></i> Revenue — Last 30 Days</div>
        <div class="chart-container"><canvas id="revenueChart"></canvas></div>
    </div>
    <div class="chart-card">
        <div class="chart-title"><i class="fas fa-chart-pie"></i> Monthly Lab Tests</div>
        <div class="chart-container"><canvas id="labTestChart"></canvas></div>
    </div>
    <div class="chart-card">
        <div class="chart-title"><i class="fas fa-baby"></i> Maternity Overview (Active: <?php echo number_format($activePreg); ?>)</div>
        <div class="chart-container"><canvas id="maternityChart"></canvas></div>
    </div>
    <div class="chart-card">
        <div class="chart-title"><i class="fas fa-warehouse"></i> Inventory — Medicine vs Supply</div>
        <div class="chart-container"><canvas id="adminInvTypeChart"></canvas></div>
        <div class="dash-legend">
            <span><span class="dash-legend-dot" style="background:#0066cc;"></span>Medicine: <strong><?php echo number_format($medTotal); ?></strong></span>
            <span><span class="dash-legend-dot" style="background:#28a745;"></span>Supply: <strong><?php echo number_format($supTotal); ?></strong></span>
        </div>
    </div>
</div>

<!-- Admin Tables Row -->
<div class="dash-grid dash-fade-in delay-3">
    <!-- Today's Queue -->
    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-list-ol"></i> Today's Queue & Referrals</span>
            <div style="display: flex; gap: 10px;">
                <a href="<?php echo BASE_URL; ?>modules/reception/queue.php" class="btn btn-sm btn-primary">View Queue</a>
                <a href="<?php echo BASE_URL; ?>modules/consultation/referrals.php" class="btn btn-sm btn-info">View Referrals</a>
            </div>
        </div>
        <div class="dash-panel-body">
            <!-- Tabs -->
            <div style="display: flex; gap: 0; border-bottom: 2px solid var(--border-color); margin-bottom: 15px;">
                <button class="queue-tab-btn active" onclick="switchTab(event, 'queue-tab')" style="padding: 10px 15px; border: none; background: none; cursor: pointer; font-weight: 500; color: var(--primary-color); border-bottom: 3px solid var(--primary-color); position: relative; bottom: -2px;">
                    <i class="fas fa-clock"></i> Patient Queue
                </button>
                <button class="queue-tab-btn" onclick="switchTab(event, 'referral-tab')" style="padding: 10px 15px; border: none; background: none; cursor: pointer; font-weight: 500; color: var(--text-muted);">
                    <i class="fas fa-envelope"></i> Referrals
                    <?php if ($todayReferrals && $todayReferrals->num_rows > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 12px; padding: 2px 6px; font-size: 11px; margin-left: 5px;"><?php echo $todayReferrals->num_rows; ?></span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- Queue Tab -->
            <div id="queue-tab" class="queue-tab-content active">
                <?php if ($todayQueue && $todayQueue->num_rows > 0): ?>
                <table class="dash-table">
                    <thead><tr><th>Queue #</th><th>Patient</th><th>Status</th><th>Time</th></tr></thead>
                    <tbody>
                    <?php while ($q = $todayQueue->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($q['queue_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($q['first_name'] . ' ' . $q['last_name']); ?></td>
                        <td><?php echo getStatusBadge($q['status']); ?></td>
                        <td><?php echo formatDateTime($q['created_at'], 'h:i A'); ?></td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><p>No patients in queue today</p></div>
                <?php endif; ?>
            </div>

            <!-- Referrals Tab -->
            <div id="referral-tab" class="queue-tab-content" style="display: none;">
                <?php if ($todayReferrals && $todayReferrals->num_rows > 0): ?>
                <table class="dash-table">
                    <thead><tr><th>Code</th><th>Patient</th><th>Hospital</th><th>Doctor</th><th>Urgency</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php $todayReferrals->data_seek(0); while ($r = $todayReferrals->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($r['referral_code']); ?></strong></td>
                        <td><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['referral_hospital']); ?></td>
                        <td><small><?php echo htmlspecialchars($r['doctor_name'] ?? 'N/A'); ?></small></td>
                        <td>
                            <?php 
                            $urgencyColor = match($r['urgency']) {
                                'emergency' => '#e74c3c',
                                'urgent' => '#f39c12',
                                default => '#3498db'
                            };
                            ?>
                            <span style="background: <?php echo $urgencyColor; ?>; color: white; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">
                                <?php echo ucfirst($r['urgency']); ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            $statusColor = match($r['status']) {
                                'pending' => '#95a5a6',
                                'printed' => '#3498db',
                                default => '#27ae60'
                            };
                            ?>
                            <span style="background: <?php echo $statusColor; ?>; color: white; padding: 3px 8px; border-radius: 4px; font-size: 11px;">
                                <?php echo ucfirst(str_replace('-', ' ', $r['status'])); ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?php echo BASE_URL; ?>modules/consultation/referral-letter.php?referral_id=<?php echo $r['id']; ?>" class="btn btn-xs btn-primary" title="View Letter">
                                <i class="fas fa-file-pdf"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><p>No referrals today</p></div>
                <?php endif; ?>
            </div>

            <style>
                .queue-tab-content {
                    display: none;
                }
                .queue-tab-content.active {
                    display: block;
                }
                .queue-tab-btn {
                    transition: all 0.3s ease;
                }
                .queue-tab-btn:hover {
                    color: var(--primary-color);
                }
            </style>
            <script>
                function switchTab(e, tabId) {
                    e.preventDefault();
                    // Hide all tabs
                    document.querySelectorAll('.queue-tab-content').forEach(el => {
                        el.classList.remove('active');
                        el.style.display = 'none';
                    });
                    // Deactivate all buttons
                    document.querySelectorAll('.queue-tab-btn').forEach(btn => {
                        btn.style.color = 'var(--text-muted)';
                        btn.style.borderBottom = 'none';
                        btn.style.bottom = 'auto';
                    });
                    // Show selected tab
                    document.getElementById(tabId).classList.add('active');
                    document.getElementById(tabId).style.display = 'block';
                    // Activate button
                    e.target.closest('.queue-tab-btn').style.color = 'var(--primary-color)';
                    e.target.closest('.queue-tab-btn').style.borderBottom = '3px solid var(--primary-color)';
                    e.target.closest('.queue-tab-btn').style.bottom = '-2px';
                }
            </script>
        </div>
    </div>

    <!-- Recent Patients -->
    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-user-injured"></i> Recent Patients</span>
            <a href="<?php echo BASE_URL; ?>modules/reception/patients.php" class="btn btn-sm btn-primary">View All</a>
        </div>
        <div class="dash-panel-body">
            <?php if ($recentPatients && $recentPatients->num_rows > 0): ?>
            <table class="dash-table">
                <thead><tr><th>Code</th><th>Name</th><th>Age</th><th>Last Visit</th></tr></thead>
                <tbody>
                <?php while ($p = $recentPatients->fetch_assoc()): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($p['patient_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></td>
                    <td><?php echo calculateAge($p['date_of_birth']); ?></td>
                    <td><?php echo $p['visit_date'] ? formatDate($p['visit_date']) : 'N/A'; ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-user-slash"></i><p>No patients found</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="dash-grid dash-fade-in delay-4">
    <!-- Room Availability -->
    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-door-open"></i> Room Availability</span>
            <a href="<?php echo BASE_URL; ?>modules/admin/rooms.php" class="btn btn-sm btn-primary">View All</a>
        </div>
        <div class="dash-panel-body" style="max-height: 320px; overflow-y: auto;">
            <?php if ($roomAvailability && $roomAvailability->num_rows > 0): ?>
            <table class="dash-table">
                <thead><tr><th>Room</th><th>Type</th><th>Occupancy</th><th>Status</th></tr></thead>
                <tbody>
                <?php while ($r = $roomAvailability->fetch_assoc()): 
                    $total = intval($r['total_beds']);
                    $occupied = ($total > 0) ? intval($r['occupied_beds']) : intval($r['admitted_count']);
                    $limit = ($total > 0) ? $total : intval($r['capacity']);
                ?>
                <tr>
                    <td><strong>Room <?php echo htmlspecialchars($r['room_number']); ?></strong></td>
                    <td><span style="font-size: 13px; color: var(--text-muted);"><?php echo htmlspecialchars(ucfirst($r['room_type'])); ?></span></td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="flex-grow: 1; background: var(--border-color); height: 6px; border-radius: 3px; overflow: hidden; width: 60px;">
                                <div style="background: <?php echo ($occupied >= $limit) ? 'var(--danger-color)' : 'var(--primary-color)'; ?>; width: <?php echo ($limit > 0) ? min(100, intval($occupied / $limit * 100)) : 0; ?>%; height: 100%; border-radius: 3px;"></div>
                            </div>
                            <span style="font-size: 12px; font-weight: 600; min-width: 32px; text-align: right;"><?php echo $occupied; ?>/<?php echo $limit; ?></span>
                        </div>
                    </td>
                    <td><?php echo getStatusBadge($r['status'] ?? 'available'); ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-door-closed"></i><p>No rooms configured</p></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- System Users -->
    <div class="dash-panel">
        <div class="dash-panel-header">
            <span class="dash-panel-title"><i class="fas fa-users-cog"></i> System Users</span>
            <a href="<?php echo BASE_URL; ?>modules/admin/users.php" class="btn btn-sm btn-primary">View All</a>
        </div>
        <div class="dash-panel-body" style="max-height: 320px; overflow-y: auto;">
            <?php if ($allUsers && $allUsers->num_rows > 0): ?>
            <table class="dash-table">
                <thead><tr><th>User</th><th>Role</th><th>Status</th></tr></thead>
                <tbody>
                <?php while ($u = $allUsers->fetch_assoc()): ?>
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div class="user-avatar" style="width: 32px; height: 32px; font-size: 14px; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: var(--primary-color); color: #fff; flex-shrink: 0; overflow: hidden;">
                                <?php
                                $uId = intval($u['id']);
                                $avatarPattern = rtrim(UPLOAD_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . 'user_' . $uId . '.*';
                                $uMatches = glob($avatarPattern);
                                if ($uMatches && count($uMatches) > 0) {
                                    $uPath = $uMatches[0];
                                    $uFile = basename($uPath);
                                    $uAvatarUrl = BASE_URL . 'uploads/avatars/' . $uFile . '?v=' . @filemtime($uPath);
                                    echo '<img src="' . htmlspecialchars($uAvatarUrl) . '" alt="Avatar" style="width:100%;height:100%;object-fit:cover;display:block;">';
                                } else {
                                    echo strtoupper(substr($u['username'], 0, 1));
                                }
                                ?>
                            </div>
                            <div>
                                <strong><?php echo htmlspecialchars($u['username']); ?></strong><br>
                                <small style="color: var(--text-muted);"><?php echo htmlspecialchars($u['full_name']); ?></small>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge badge-secondary"><?php echo ucfirst(htmlspecialchars($u['role'])); ?></span>
                    </td>
                    <td>
                        <?php echo getStatusBadge($u['status']); ?>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-user-slash"></i><p>No users found</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Admin Charts Script -->
<script>
(function(){
    function isDark(){ return document.documentElement.getAttribute('data-theme')==='dark'; }
    function tc(){ var d=isDark(); return {grid: d?'rgba(255,255,255,0.12)':'rgba(188,203,236,0.06)', tick: d?'#d9eaff':'#16304a', legend: d?'#eaf6ff':'#16304a'}; }
    function scales(data){
        var c=tc();
        var max=data.length?Math.max.apply(null,data.map(Number).filter(isFinite)):1;
        return {
            y:{ beginAtZero:true, suggestedMax:Math.max(1,Math.ceil(max*1.3)), grid:{color:c.grid}, ticks:{color:c.tick,precision:0}},
            x:{ grid:{color:c.grid}, ticks:{color:c.tick,maxRotation:0,autoSkip:true}}
        };
    }

    var visitLabels=<?php echo json_encode($visitLabels); ?>;
    var visitData=<?php echo json_encode($visitData); ?>;
    var admLabels=<?php echo json_encode($admLabels); ?>;
    var admData=<?php echo json_encode($admData); ?>;
    var dischargeData=<?php echo json_encode($dischargeData); ?>;
    var revLabels=<?php echo json_encode($revLabels); ?>;
    var revData=<?php echo json_encode($revData); ?>;
    var labLabels=<?php echo json_encode($labLabels); ?>;
    var labData=<?php echo json_encode($labData); ?>;
    var matLabels=<?php echo json_encode($matLabels); ?>;
    var matCheckup=<?php echo json_encode($matCheckupData); ?>;
    var matDelivery=<?php echo json_encode($matDeliveryData); ?>;

    var invTypeLabels=['Medicine','Supply'];
    var invTypeData=[<?php echo intval($medTotal); ?>,<?php echo intval($supTotal); ?>];

    var vChart=null,aChart=null,rChart=null,sChart=null,mChart=null,itChart=null;
    var chartsInitTimer = null;

    function createCharts(){
        var dark=isDark();
        var lc=tc();
        var animationConfig = { duration: 700, easing: 'easeOutQuart' };

        var vc=document.getElementById('visitsChart')?.getContext('2d');
        if(vc){if(vChart)try{vChart.destroy()}catch(e){}vChart=new Chart(vc,{type:'line',data:{labels:visitLabels,datasets:[{label:'Visits',data:visitData,borderColor:'#0066cc',backgroundColor:'rgba(0,102,204,0.15)',borderWidth:3,fill:true,tension:0.4,pointRadius:5,pointBackgroundColor:'#0066cc',pointBorderColor:'#fff',pointBorderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,animation:{duration:700,easing:'easeOutQuart'},plugins:{legend:{display:false}},scales:scales(visitData)}});}

        var ac=document.getElementById('admissionsChart')?.getContext('2d');
        if(ac){if(aChart)try{aChart.destroy()}catch(e){}aChart=new Chart(ac,{type:'bar',data:{labels:admLabels,datasets:[{label:'Admissions',data:admData,backgroundColor:'#28a745',borderRadius:5},{label:'Discharges',data:dischargeData,backgroundColor:'#ffc107',borderRadius:5}]},options:{responsive:true,maintainAspectRatio:false,animation:{duration:700,easing:'easeOutQuart'},plugins:{legend:{position:'top',labels:{color:lc.legend}}},scales:scales(admData.concat(dischargeData))}});}

        var rc=document.getElementById('revenueChart')?.getContext('2d');
        if(rc){if(rChart)try{rChart.destroy()}catch(e){}rChart=new Chart(rc,{type:'line',data:{labels:revLabels,datasets:[{label:'Revenue',data:revData,borderColor:'#dc3545',backgroundColor:'rgba(220,53,69,0.18)',borderWidth:3,fill:true,tension:0.4,pointRadius:5,pointBackgroundColor:'#dc3545',pointBorderColor:'#fff',pointBorderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,animation:{duration:700,easing:'easeOutQuart'},plugins:{legend:{display:false}},scales:scales(revData)}});}

        var ltc=document.getElementById('labTestChart')?.getContext('2d');
        if(ltc){if(sChart)try{sChart.destroy()}catch(e){}var ll=labLabels.slice(),ld=labData.slice(),colors=['#0066cc','#28a745','#ffc107','#dc3545','#17a2b8','#8e44ad'];if(!ll.length||ld.reduce((a,b)=>a+Number(b),0)===0){ll=['No data'];ld=[1];colors=['#e9ecef'];}sChart=new Chart(ltc,{type:'doughnut',data:{labels:ll,datasets:[{data:ld,backgroundColor:colors.slice(0,ld.length),borderColor:dark?'#1a2535':'#fff',borderWidth:3}]},options:{responsive:true,maintainAspectRatio:false,animation:{duration:700,easing:'easeOutQuart'},plugins:{legend:{position:'bottom',labels:{color:lc.legend}}}}});}

        var mc=document.getElementById('maternityChart')?.getContext('2d');
        if(mc){if(mChart)try{mChart.destroy()}catch(e){}mChart=new Chart(mc,{type:'line',data:{labels:matLabels,datasets:[{label:'Checkups',data:matCheckup,borderColor:'#8e44ad',backgroundColor:'rgba(142,68,173,0.18)',borderWidth:3,fill:true,tension:0.35,pointRadius:5,pointBackgroundColor:'#8e44ad',pointBorderColor:'#fff',pointBorderWidth:2},{label:'Births',data:matDelivery,borderColor:'#e67e22',backgroundColor:'rgba(230,126,34,0.18)',borderWidth:3,fill:true,tension:0.35,pointRadius:5,pointBackgroundColor:'#e67e22',pointBorderColor:'#fff',pointBorderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,animation:{duration:700,easing:'easeOutQuart'},plugins:{legend:{position:'top',labels:{color:lc.legend}}},scales:scales(matCheckup.concat(matDelivery))}});}

        var itc=document.getElementById('adminInvTypeChart')?.getContext('2d');
        if(itc){
            if(itChart)try{itChart.destroy()}catch(e){}
            var itLabels=invTypeLabels.slice(),itData=invTypeData.slice();
            var itColors=['#0066cc','#28a745'];
            if(itData[0]===0&&itData[1]===0){itLabels=['No stock'];itData=[1];itColors=['#e9ecef'];}
            itChart=new Chart(itc,{
                type:'doughnut',
                data:{labels:itLabels,datasets:[{data:itData,backgroundColor:itColors,borderColor:dark?'#1a2535':'#fff',borderWidth:3,hoverOffset:8}]},
                options:{
                    responsive:true,maintainAspectRatio:false,
                    animation:{duration:700,easing:'easeOutQuart'},
                    plugins:{
                        legend:{position:'bottom',labels:{color:lc.legend,font:{size:12}}},
                        tooltip:{callbacks:{label:function(ctx){var total=itData.reduce(function(a,b){return a+Number(b);},0);var pct=total>0?Math.round(ctx.parsed/total*100):0;return ctx.label+': '+ctx.formattedValue+' units ('+pct+'%)';}}}  
                    },
                    cutout:'65%'
                }
            });
        }
    }

    function scheduleCharts(delay){
        if(chartsInitTimer) clearTimeout(chartsInitTimer);
        chartsInitTimer = setTimeout(function(){ createCharts(); }, delay || 600);
    }

    scheduleCharts(600);
    window.addEventListener('themeChanged', function(){ scheduleCharts(240); });

    document.querySelectorAll('.kpi-data h2').forEach(function (item, index) {
        var raw = (item.textContent || '').trim();
        var prefix = '';
        var suffix = '';
        var match = raw.match(/[-+]?\d[\d,]*(?:\.\d+)?/);

        if (!match) return;

        var numberText = match[0];
        var value = Number(numberText.replace(/,/g, ''));
        var startIndex = raw.indexOf(numberText);
        if (startIndex > 0) prefix = raw.slice(0, startIndex);
        if (startIndex + numberText.length < raw.length) suffix = raw.slice(startIndex + numberText.length);

        var duration = 1200 + (index * 40);
        var start = null;
        var finalText = raw;

        function step(timestamp) {
            if (!start) start = timestamp;
            var progress = Math.min((timestamp - start) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            var currentValue = Math.round(value * eased);
            item.textContent = prefix + currentValue.toLocaleString() + suffix;

            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                item.textContent = finalText;
            }
        }

        item.textContent = prefix + '0' + suffix;
        requestAnimationFrame(step);
    });
})();
</script>

<?php else: ?>
<!-- Fallback for any other role -->
<div class="kpi-grid dash-fade-in delay-1">
    <div class="kpi-card primary">
        <div class="kpi-icon primary"><i class="fas fa-user-injured"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($counts['patients']); ?></h2><p>Total Patients</p></div>
    </div>
    <div class="kpi-card info">
        <div class="kpi-icon info"><i class="fas fa-calendar-day"></i></div>
        <div class="kpi-data"><h2><?php echo number_format($counts['today_visits']); ?></h2><p>Today's Queue</p></div>
    </div>
</div>
<?php endif; ?>

</div><!-- .dash-fade-in -->

<?php include 'includes/footer.php'; ?>
