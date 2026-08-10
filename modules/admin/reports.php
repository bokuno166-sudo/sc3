<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin','cashier']);

$pageTitle = 'Analytics';
$currentPage = 'reports';

$conn = getDBConnection();

// Date range filter
$rangePreset = isset($_GET['range']) ? $_GET['range'] : '30';
$customFrom  = isset($_GET['from'])  ? $_GET['from']  : '';
$customTo    = isset($_GET['to'])    ? $_GET['to']    : '';

switch ($rangePreset) {
    case '7':   $dateFrom = date('Y-m-d', strtotime('-7 days'));   $dateTo = date('Y-m-d'); break;
    case '30':  $dateFrom = date('Y-m-d', strtotime('-30 days'));  $dateTo = date('Y-m-d'); break;
    case '90':  $dateFrom = date('Y-m-d', strtotime('-90 days'));  $dateTo = date('Y-m-d'); break;
    case '365': $dateFrom = date('Y-m-d', strtotime('-365 days')); $dateTo = date('Y-m-d'); break;
    case 'custom':
        $dateFrom = $customFrom ?: date('Y-m-d', strtotime('-30 days'));
        $dateTo   = $customTo   ?: date('Y-m-d');
        break;
    default:    $dateFrom = date('Y-m-d', strtotime('-30 days')); $dateTo = date('Y-m-d'); break;
}

$diffDays = max(1, (int)((strtotime($dateTo) - strtotime($dateFrom)) / 86400));
$prevFrom = date('Y-m-d', strtotime($dateFrom) - $diffDays * 86400);
$prevTo   = date('Y-m-d', strtotime($dateFrom) - 86400);
$today    = date('Y-m-d');

// Table checks
$hasAdmissions = $conn->query("SHOW TABLES LIKE 'admissions'")->num_rows > 0;
$hasPayments   = $conn->query("SHOW TABLES LIKE 'payments'")->num_rows > 0;
$hasLab        = $conn->query("SHOW TABLES LIKE 'laboratory_requests'")->num_rows > 0;
$hasInventory  = $conn->query("SHOW TABLES LIKE 'inventory_stock'")->num_rows > 0;
$hasVisits     = $conn->query("SHOW TABLES LIKE 'patient_visits'")->num_rows > 0;
$hasPatients   = $conn->query("SHOW TABLES LIKE 'patients'")->num_rows > 0;
$hasInvoices   = $conn->query("SHOW TABLES LIKE 'invoices'")->num_rows > 0;

function qi($conn, $sql) { $r = $conn->query($sql); return $r ? (int)$r->fetch_row()[0] : 0; }
function qf($conn, $sql) { $r = $conn->query($sql); return $r ? (float)$r->fetch_row()[0] : 0.0; }

// KPIs – current period
$kpiAdmissions  = $hasAdmissions ? qi($conn, "SELECT COUNT(*) FROM admissions WHERE DATE(admission_date) BETWEEN '$dateFrom' AND '$dateTo'") : 0;
$kpiRevenue     = $hasPayments   ? qf($conn, "SELECT COALESCE(SUM(payment_amount),0) FROM payments WHERE DATE(payment_date) BETWEEN '$dateFrom' AND '$dateTo'") : 0.0;
$kpiPatients    = $hasPatients   ? qi($conn, "SELECT COUNT(DISTINCT id) FROM patients WHERE DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'") : 0;
$kpiVisits      = $hasVisits     ? qi($conn, "SELECT COUNT(*) FROM patient_visits WHERE DATE(visit_date) BETWEEN '$dateFrom' AND '$dateTo'") : 0;
$kpiLab         = $hasLab        ? qi($conn, "SELECT COUNT(*) FROM laboratory_requests WHERE DATE(requested_at) BETWEEN '$dateFrom' AND '$dateTo'") : 0;
$kpiPendingLab  = $hasLab        ? qi($conn, "SELECT COUNT(*) FROM laboratory_requests WHERE status='pending'") : 0;
$kpiLowStock    = $hasInventory  ? qi($conn, "SELECT COUNT(DISTINCT i.id) FROM inventory_items i JOIN inventory_stock s ON i.id=s.item_id WHERE s.quantity_in_stock<=i.reorder_level") : 0;
$kpiPendingBill = $hasInvoices   ? qi($conn, "SELECT COUNT(*) FROM invoices WHERE status IN ('pending','partial')") : 0;
$kpiAdmittedNow = $hasAdmissions ? qi($conn, "SELECT COUNT(*) FROM admissions WHERE status='admitted'") : 0;
$kpiAdmToday    = $hasAdmissions ? qi($conn, "SELECT COUNT(*) FROM admissions WHERE DATE(admission_date)='$today'") : 0;

// KPIs – previous period
$prevAdmissions = $hasAdmissions ? qi($conn, "SELECT COUNT(*) FROM admissions WHERE DATE(admission_date) BETWEEN '$prevFrom' AND '$prevTo'") : 0;
$prevRevenue    = $hasPayments   ? qf($conn, "SELECT COALESCE(SUM(payment_amount),0) FROM payments WHERE DATE(payment_date) BETWEEN '$prevFrom' AND '$prevTo'") : 0.0;
$prevPatients   = $hasPatients   ? qi($conn, "SELECT COUNT(DISTINCT id) FROM patients WHERE DATE(created_at) BETWEEN '$prevFrom' AND '$prevTo'") : 0;
$prevVisits     = $hasVisits     ? qi($conn, "SELECT COUNT(*) FROM patient_visits WHERE DATE(visit_date) BETWEEN '$prevFrom' AND '$prevTo'") : 0;

function pctChange($cur, $prev) {
    if ($prev == 0) return $cur > 0 ? 100 : 0;
    return round((($cur - $prev) / $prev) * 100, 1);
}
$trendAdm = pctChange($kpiAdmissions, $prevAdmissions);
$trendRev = pctChange($kpiRevenue,    $prevRevenue);
$trendPat = pctChange($kpiPatients,   $prevPatients);
$trendVis = pctChange($kpiVisits,     $prevVisits);

// Daily 30-day data
$dailyRevArr = []; $dailyAdmArr = []; $dailyLabels = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $dailyLabels[] = date('M d', strtotime($d));
    $dailyRevArr[$d] = 0.0;
    $dailyAdmArr[$d] = 0;
}
if ($hasPayments) {
    $r = $conn->query("SELECT DATE(payment_date) as d, COALESCE(SUM(payment_amount),0) as t FROM payments WHERE DATE(payment_date) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY d");
    if ($r) while ($row = $r->fetch_assoc()) $dailyRevArr[$row['d']] = (float)$row['t'];
}
if ($hasAdmissions) {
    $r = $conn->query("SELECT DATE(admission_date) as d, COUNT(*) as c FROM admissions WHERE DATE(admission_date) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY d");
    if ($r) while ($row = $r->fetch_assoc()) $dailyAdmArr[$row['d']] = (int)$row['c'];
}

// Monthly 12-month data
$mLabels=[]; $mRevenue=[]; $mAdmissions=[]; $mVisits=[]; $mPatients=[]; $monthKeys=[];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-{$i} months"));
    $monthKeys[] = $m;
    $mLabels[]   = date('M Y', strtotime($m.'-01'));
    $mRevenue[$m]=0.0; $mAdmissions[$m]=0; $mVisits[$m]=0; $mPatients[$m]=0;
}
$inM = "'".implode("','", $monthKeys)."'";
if ($hasPayments) {
    $r = $conn->query("SELECT DATE_FORMAT(payment_date,'%Y-%m') as ym, SUM(payment_amount) as t FROM payments WHERE DATE_FORMAT(payment_date,'%Y-%m') IN ($inM) GROUP BY ym");
    if ($r) while ($row=$r->fetch_assoc()) $mRevenue[$row['ym']]=(float)$row['t'];
}
if ($hasAdmissions) {
    $r = $conn->query("SELECT DATE_FORMAT(admission_date,'%Y-%m') as ym, COUNT(*) as c FROM admissions WHERE DATE_FORMAT(admission_date,'%Y-%m') IN ($inM) GROUP BY ym");
    if ($r) while ($row=$r->fetch_assoc()) $mAdmissions[$row['ym']]=(int)$row['c'];
}
if ($hasVisits) {
    $r = $conn->query("SELECT DATE_FORMAT(visit_date,'%Y-%m') as ym, COUNT(*) as c FROM patient_visits WHERE DATE_FORMAT(visit_date,'%Y-%m') IN ($inM) GROUP BY ym");
    if ($r) while ($row=$r->fetch_assoc()) $mVisits[$row['ym']]=(int)$row['c'];
}
if ($hasPatients) {
    $r = $conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') as ym, COUNT(*) as c FROM patients WHERE DATE_FORMAT(created_at,'%Y-%m') IN ($inM) GROUP BY ym");
    if ($r) while ($row=$r->fetch_assoc()) $mPatients[$row['ym']]=(int)$row['c'];
}

// Top Lab Tests
$topLabTests = [];
if ($hasLab) {
    $r = $conn->query("SELECT lt.test_name, COUNT(*) as cnt FROM laboratory_requests lr JOIN laboratory_tests lt ON lr.test_id=lt.id GROUP BY lr.test_id ORDER BY cnt DESC LIMIT 7");
    if ($r) while ($row=$r->fetch_assoc()) $topLabTests[]=$row;
}

// Revenue by Cashier
$cashierData = [];
if ($hasPayments) {
    $r = $conn->query("SELECT COALESCE(u.full_name,u.username,'Unknown') as name, SUM(p.payment_amount) as total FROM payments p LEFT JOIN users u ON p.received_by=u.id WHERE DATE(p.payment_date) BETWEEN '$dateFrom' AND '$dateTo' GROUP BY p.received_by ORDER BY total DESC LIMIT 8");
    if ($r) while ($row=$r->fetch_assoc()) $cashierData[]=$row;
}

// Visit status today
$visitStatusData = [];
if ($hasVisits) {
    $r = $conn->query("SELECT status, COUNT(*) as cnt FROM patient_visits WHERE visit_date=CURDATE() GROUP BY status ORDER BY cnt DESC");
    if ($r) while ($row=$r->fetch_assoc()) $visitStatusData[]=$row;
}

// Admissions vs Discharges (last 6 months)
$admDischargeLabels=[]; $admittedArr=[]; $dischargedArr=[];
for ($i=5; $i>=0; $i--) {
    $m = date('Y-m', strtotime("-{$i} months"));
    $admDischargeLabels[] = date('M Y', strtotime($m.'-01'));
    $admittedArr[$m]=0; $dischargedArr[$m]=0;
}
$in6 = "'".implode("','",array_keys($admittedArr))."'";
if ($hasAdmissions) {
    $r = $conn->query("SELECT DATE_FORMAT(admission_date,'%Y-%m') as ym, COUNT(*) as c FROM admissions WHERE DATE_FORMAT(admission_date,'%Y-%m') IN ($in6) GROUP BY ym");
    if ($r) while ($row=$r->fetch_assoc()) $admittedArr[$row['ym']]=(int)$row['c'];
    $r = $conn->query("SELECT DATE_FORMAT(actual_discharge_date,'%Y-%m') as ym, COUNT(*) as c FROM admissions WHERE actual_discharge_date IS NOT NULL AND DATE_FORMAT(actual_discharge_date,'%Y-%m') IN ($in6) GROUP BY ym");
    if ($r) while ($row=$r->fetch_assoc()) $dischargedArr[$row['ym']]=(int)$row['c'];
}

// Payment methods
$payMethodData = [];
if ($hasPayments) {
    $hasMethod = $conn->query("SHOW COLUMNS FROM payments LIKE 'payment_method'")->num_rows > 0;
    if ($hasMethod) {
        $r = $conn->query("SELECT payment_method, COUNT(*) as cnt, SUM(payment_amount) as total FROM payments WHERE DATE(payment_date) BETWEEN '$dateFrom' AND '$dateTo' GROUP BY payment_method ORDER BY total DESC");
        if ($r) while ($row=$r->fetch_assoc()) $payMethodData[]=$row;
    }
}

// Revenue mini (today / week / month)
$revToday = $hasPayments ? qf($conn, "SELECT COALESCE(SUM(payment_amount),0) FROM payments WHERE DATE(payment_date)='$today'") : 0.0;
$revWeek  = $hasPayments ? qf($conn, "SELECT COALESCE(SUM(payment_amount),0) FROM payments WHERE YEARWEEK(payment_date,1)=YEARWEEK(CURDATE(),1)") : 0.0;
$revMonth = $hasPayments ? qf($conn, "SELECT COALESCE(SUM(payment_amount),0) FROM payments WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())") : 0.0;

// Inventory extras
$nearExpiry   = $hasInventory ? qi($conn,"SELECT COUNT(DISTINCT s.item_id) FROM inventory_stock s JOIN inventory_items i ON s.item_id=i.id WHERE s.expiry_date IS NOT NULL AND s.expiry_date<>'0000-00-00' AND s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND (s.quantity_in_stock-s.quantity_reserved)>0") : 0;
$expiredStock = $hasInventory ? qi($conn,"SELECT COUNT(DISTINCT s.item_id) FROM inventory_stock s JOIN inventory_items i ON s.item_id=i.id WHERE s.expiry_date IS NOT NULL AND s.expiry_date<>'0000-00-00' AND s.expiry_date<CURDATE() AND (s.quantity_in_stock-s.quantity_reserved)>0") : 0;

$conn->close();

// JSON exports
$jDailyLabels   = json_encode($dailyLabels);
$jDailyRev      = json_encode(array_values($dailyRevArr));
$jDailyAdm      = json_encode(array_values($dailyAdmArr));
$jMLabels       = json_encode($mLabels);
$jMRevenue      = json_encode(array_values($mRevenue));
$jMAdmissions   = json_encode(array_values($mAdmissions));
$jMVisits       = json_encode(array_values($mVisits));
$jMPatients     = json_encode(array_values($mPatients));
$jTopLabLabels  = json_encode(array_map(fn($r)=>$r['test_name'], $topLabTests));
$jTopLabCounts  = json_encode(array_map(fn($r)=>(int)$r['cnt'],  $topLabTests));
$jCashierNames  = json_encode(array_map(fn($r)=>$r['name'],  $cashierData));
$jCashierTotals = json_encode(array_map(fn($r)=>(float)$r['total'], $cashierData));
$jVStatusLabels = json_encode(array_map(fn($r)=>ucfirst($r['status']), $visitStatusData));
$jVStatusCounts = json_encode(array_map(fn($r)=>(int)$r['cnt'], $visitStatusData));
$jAdmLabels     = json_encode($admDischargeLabels);
$jAdmittedArr   = json_encode(array_values($admittedArr));
$jDischargedArr = json_encode(array_values($dischargedArr));
$jPayMethods    = json_encode(array_map(fn($r)=>ucfirst($r['payment_method']?:'Other'), $payMethodData));
$jPayTotals     = json_encode(array_map(fn($r)=>(float)$r['total'], $payMethodData));

include __DIR__ . '/../../includes/header.php';
?>
<style>
.analytics-wrap{padding:0 2px;}
.anly-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:28px;}
.anly-header-left .page-title{margin:0;}
.anly-header-left .page-subtitle{margin:4px 0 0;color:var(--text-muted);font-size:14px;}
.anly-header-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.range-tabs{display:flex;background:var(--surface-muted);border:1px solid var(--border-color);border-radius:10px;padding:4px;gap:2px;}
.range-tab{padding:6px 14px;border-radius:7px;font-size:13px;font-weight:500;color:var(--text-muted);cursor:pointer;border:none;background:transparent;transition:all .2s;text-decoration:none;line-height:1.4;}
.range-tab:hover{color:var(--text-color);}
.range-tab.active{background:var(--primary-color);color:#fff;box-shadow:0 2px 8px rgba(15,94,168,.3);}
.cdate-form{display:flex;align-items:center;gap:8px;background:var(--surface-muted);border:1px solid var(--border-color);border-radius:10px;padding:6px 12px;}
.cdate-form input[type="date"]{border:none;background:transparent;color:var(--text-color);font-size:13px;font-family:inherit;outline:none;}
.cdate-sep{color:var(--text-muted);font-size:13px;}
.rev-mini-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px;}
.rev-mini{background:var(--surface-color);border:1px solid var(--border-color);border-radius:12px;padding:14px 18px;text-align:center;}
.rmc-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);}
.rmc-value{font-size:20px;font-weight:700;color:var(--text-color);margin-top:4px;}
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;}
.kpi-card{background:var(--surface-color);border:1px solid var(--border-color);border-radius:16px;padding:20px;position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s;}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--kc,var(--primary-color));border-radius:16px 16px 0 0;}
.kpi-card:hover{transform:translateY(-3px);box-shadow:var(--box-shadow-lg);}
.kpi-top{display:flex;align-items:flex-start;justify-content:space-between;}
.kpi-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;background:var(--kib,var(--primary-light));color:var(--kc,var(--primary-color));flex-shrink:0;}
.kpi-badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;}
.kb-up{background:rgba(29,122,69,.12);color:var(--success-color);}
.kb-dn{background:rgba(198,40,40,.12);color:var(--danger-color);}
.kb-fl{background:var(--surface-muted);color:var(--text-muted);}
.kpi-val{font-size:30px;font-weight:800;color:var(--text-color);line-height:1;margin:14px 0 4px;letter-spacing:-.5px;animation:countUp .5s ease forwards;}
.kpi-lbl{font-size:13px;color:var(--text-muted);font-weight:500;}
.kpi-sub{font-size:11px;color:var(--text-muted);margin-top:4px;}
@keyframes countUp{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
.row-2-1{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px;}
.row-3col{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:20px;}
.row-2eq{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;margin-bottom:20px;}
.ccrd{background:var(--surface-color);border:1px solid var(--border-color);border-radius:16px;overflow:hidden;}
.ccrd-hdr{display:flex;align-items:center;justify-content:space-between;padding:18px 22px 14px;border-bottom:1px solid var(--border-color);}
.ccrd-title{font-size:15px;font-weight:700;color:var(--text-color);margin:0;display:flex;align-items:center;gap:8px;}
.ccrd-title i{color:var(--primary-color);font-size:14px;}
.ccrd-sub{font-size:12px;color:var(--text-muted);margin-top:2px;}
.ccrd-body{padding:18px 22px 22px;}
.chart-wrap{position:relative;}
.inv-row{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:24px;}
.inv-item{display:flex;align-items:center;gap:14px;background:var(--surface-color);border:1px solid var(--border-color);border-radius:12px;padding:14px 18px;}
.inv-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.inv-count{font-size:24px;font-weight:800;line-height:1;}
.inv-lbl{font-size:12px;color:var(--text-muted);margin-top:2px;}
.ct{width:100%;border-collapse:collapse;font-size:13px;}
.ct th{text-align:left;padding:8px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);border-bottom:1px solid var(--border-color);font-weight:600;}
.ct td{padding:10px 12px;border-bottom:1px solid var(--border-color);}
.ct tr:last-child td{border-bottom:none;}
.ct td:last-child{font-weight:700;color:var(--success-color);text-align:right;}
.ct th:last-child{text-align:right;}
.bar-m{height:5px;border-radius:4px;background:var(--border-color);overflow:hidden;margin-top:4px;}
.bar-f{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--primary-color),var(--accent-color));transition:width 1s ease;}
.lab-list{list-style:none;padding:0;margin:0;}
.lab-list li{display:flex;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-color);font-size:13px;}
.lab-list li:last-child{border-bottom:none;}
.lab-rank{width:24px;height:24px;border-radius:50%;background:var(--primary-light);color:var(--primary-color);font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-right:10px;}
.lab-name{flex:1;color:var(--text-color);}
.lab-cnt{font-weight:700;color:var(--text-color);}
.dleg{display:flex;flex-wrap:wrap;gap:8px 16px;margin-top:14px;}
.dleg-item{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted);}
.dleg-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--text-muted);gap:8px;padding:40px 0;}
.empty-state i{font-size:36px;opacity:.3;}
.empty-state p{margin:0;font-size:13px;}
@media(max-width:1100px){.kpi-grid{grid-template-columns:repeat(2,1fr)}.row-2-1,.row-3col,.row-2eq{grid-template-columns:1fr}}
@media(max-width:680px){.kpi-grid,.rev-mini-row,.inv-row{grid-template-columns:1fr}.anly-header{flex-direction:column}}
@media print{.anly-header-right{display:none!important}}
</style>

<div class="analytics-wrap">

<!-- Header -->
<div class="anly-header">
  <div class="anly-header-left">
    <h1 class="page-title" style="display:flex;align-items:center;gap:10px;">
      <i class="fas fa-chart-line" style="color:var(--primary-color);font-size:26px;"></i>Analytics
    </h1>
    <p class="page-subtitle">
      Showing <strong><?php echo date('M d, Y', strtotime($dateFrom)); ?></strong> &mdash; <strong><?php echo date('M d, Y', strtotime($dateTo)); ?></strong>
    </p>
  </div>
  <div class="anly-header-right">
    <form method="GET" id="rangeForm" style="display:contents;">
      <div class="range-tabs">
        <?php foreach(['7'=>'7D','30'=>'30D','90'=>'90D','365'=>'1Y'] as $v=>$label): ?>
        <button type="submit" name="range" value="<?php echo $v;?>" class="range-tab <?php echo $rangePreset===$v?'active':'';?>"><?php echo $label;?></button>
        <?php endforeach;?>
      </div>
      <div class="cdate-form">
        <input type="date" name="from" value="<?php echo htmlspecialchars($rangePreset==='custom'?$customFrom:$dateFrom);?>"
               onchange="document.getElementById('rph').value='custom';this.form.submit();" max="<?php echo $today;?>" style="color-scheme:light dark;">
        <span class="cdate-sep">&rarr;</span>
        <input type="date" name="to" value="<?php echo htmlspecialchars($rangePreset==='custom'?$customTo:$dateTo);?>"
               onchange="document.getElementById('rph').value='custom';this.form.submit();" max="<?php echo $today;?>" style="color-scheme:light dark;">
        <input type="hidden" name="range" id="rph" value="<?php echo htmlspecialchars($rangePreset);?>">
      </div>
      <button type="button" onclick="window.print()" class="btn btn-secondary" style="white-space:nowrap;"><i class="fas fa-print"></i> Print</button>
    </form>
  </div>
</div>

<!-- Revenue mini-cards -->
<?php if($hasPayments):?>
<div class="rev-mini-row">
  <div class="rev-mini"><div class="rmc-label"><i class="fas fa-calendar-day"></i> Today</div><div class="rmc-value"><?php echo formatCurrency($revToday);?></div></div>
  <div class="rev-mini"><div class="rmc-label"><i class="fas fa-calendar-week"></i> This Week</div><div class="rmc-value"><?php echo formatCurrency($revWeek);?></div></div>
  <div class="rev-mini"><div class="rmc-label"><i class="fas fa-calendar-alt"></i> This Month</div><div class="rmc-value"><?php echo formatCurrency($revMonth);?></div></div>
</div>
<?php endif;?>

<?php
function trendBadge($v){
    if($v>0) return '<span class="kpi-badge kb-up"><i class="fas fa-arrow-up"></i> '.$v.'%</span>';
    if($v<0) return '<span class="kpi-badge kb-dn"><i class="fas fa-arrow-down"></i> '.abs($v).'%</span>';
    return '<span class="kpi-badge kb-fl"><i class="fas fa-minus"></i> 0%</span>';
}
?>

<!-- KPI Cards -->
<div class="kpi-grid">
  <!-- Revenue -->
  <div class="kpi-card" style="--kc:var(--success-color);--kib:rgba(29,122,69,.1);">
    <div class="kpi-top"><div class="kpi-icon"><i class="fas fa-peso-sign"></i></div><?php echo trendBadge($trendRev);?></div>
    <div class="kpi-val"><?php echo formatCurrency($kpiRevenue);?></div>
    <div class="kpi-lbl">Total Revenue</div>
    <div class="kpi-sub">prev. period: <?php echo formatCurrency($prevRevenue);?></div>
  </div>
  <!-- Admissions -->
  <div class="kpi-card">
    <div class="kpi-top"><div class="kpi-icon"><i class="fas fa-procedures"></i></div><?php echo trendBadge($trendAdm);?></div>
    <div class="kpi-val"><?php echo number_format($kpiAdmissions);?></div>
    <div class="kpi-lbl">Admissions</div>
    <div class="kpi-sub">Currently admitted: <strong><?php echo $kpiAdmittedNow;?></strong></div>
  </div>
  <!-- New Patients -->
  <div class="kpi-card" style="--kc:var(--accent-color);--kib:var(--accent-soft);">
    <div class="kpi-top"><div class="kpi-icon"><i class="fas fa-user-injured"></i></div><?php echo trendBadge($trendPat);?></div>
    <div class="kpi-val"><?php echo number_format($kpiPatients);?></div>
    <div class="kpi-lbl">New Patients</div>
    <div class="kpi-sub">prev. period: <?php echo number_format($prevPatients);?></div>
  </div>
  <!-- Visits -->
  <div class="kpi-card" style="--kc:#7c5cbf;--kib:rgba(124,92,191,.1);">
    <div class="kpi-top"><div class="kpi-icon"><i class="fas fa-clipboard-list"></i></div><?php echo trendBadge($trendVis);?></div>
    <div class="kpi-val"><?php echo number_format($kpiVisits);?></div>
    <div class="kpi-lbl">Patient Visits</div>
    <div class="kpi-sub">prev. period: <?php echo number_format($prevVisits);?></div>
  </div>
  <!-- Lab -->
  <div class="kpi-card" style="--kc:var(--info-color);--kib:rgba(15,118,110,.1);">
    <div class="kpi-top"><div class="kpi-icon"><i class="fas fa-vials"></i></div><span></span></div>
    <div class="kpi-val"><?php echo number_format($kpiLab);?></div>
    <div class="kpi-lbl">Lab Requests</div>
    <div class="kpi-sub">Pending: <strong><?php echo $kpiPendingLab;?></strong></div>
  </div>
  <!-- Pending Invoices -->
  <div class="kpi-card" style="--kc:var(--warning-color);--kib:rgba(183,106,0,.1);">
    <div class="kpi-top"><div class="kpi-icon"><i class="fas fa-file-invoice-dollar"></i></div><span></span></div>
    <div class="kpi-val"><?php echo number_format($kpiPendingBill);?></div>
    <div class="kpi-lbl">Pending Invoices</div>
    <div class="kpi-sub">Unpaid / Partial</div>
  </div>
  <!-- Low Stock -->
  <div class="kpi-card" style="--kc:var(--danger-color);--kib:rgba(198,40,40,.1);">
    <div class="kpi-top"><div class="kpi-icon"><i class="fas fa-box-open"></i></div><span></span></div>
    <div class="kpi-val"><?php echo number_format($kpiLowStock);?></div>
    <div class="kpi-lbl">Low Stock Items</div>
    <div class="kpi-sub">Below reorder level</div>
  </div>
  <!-- Admissions Today -->
  <div class="kpi-card" style="--kc:#e85d04;--kib:rgba(232,93,4,.1);">
    <div class="kpi-top"><div class="kpi-icon"><i class="fas fa-hospital-user"></i></div><span></span></div>
    <div class="kpi-val"><?php echo number_format($kpiAdmToday);?></div>
    <div class="kpi-lbl">Admissions Today</div>
    <div class="kpi-sub"><?php echo date('l, M d');?></div>
  </div>
</div>

<!-- Row: Revenue area + Visit status donut -->
<div class="row-2-1">
  <div class="ccrd">
    <div class="ccrd-hdr">
      <div><h3 class="ccrd-title"><i class="fas fa-chart-area"></i> Revenue Trend</h3><p class="ccrd-sub">Daily revenue – last 30 days</p></div>
      <select id="revChartView" class="form-control" style="width:auto;font-size:12px;padding:5px 10px;" onchange="switchRevView(this.value)">
        <option value="daily">Daily (30d)</option><option value="monthly">Monthly (12m)</option>
      </select>
    </div>
    <div class="ccrd-body"><div class="chart-wrap" style="height:260px;"><canvas id="revenueChart"></canvas></div></div>
  </div>
  <div class="ccrd">
    <div class="ccrd-hdr"><div><h3 class="ccrd-title"><i class="fas fa-circle-notch"></i> Today's Queue</h3><p class="ccrd-sub">Visit status breakdown</p></div></div>
    <div class="ccrd-body">
      <?php if(!empty($visitStatusData)):?>
      <div class="chart-wrap" style="height:190px;"><canvas id="visitStatusChart"></canvas></div>
      <div class="dleg" id="visitStatusLegend"></div>
      <?php else:?>
      <div class="empty-state"><i class="fas fa-inbox"></i><p>No visits today</p></div>
      <?php endif;?>
    </div>
  </div>
</div>

<!-- Row: Admit/Discharge + Lab Tests + Payment Methods -->
<div class="row-3col">
  <div class="ccrd">
    <div class="ccrd-hdr"><div><h3 class="ccrd-title"><i class="fas fa-exchange-alt"></i> Admit vs Discharge</h3><p class="ccrd-sub">Last 6 months</p></div></div>
    <div class="ccrd-body"><div class="chart-wrap" style="height:240px;"><canvas id="admDischargeChart"></canvas></div></div>
  </div>
  <div class="ccrd">
    <div class="ccrd-hdr"><div><h3 class="ccrd-title"><i class="fas fa-flask"></i> Top Lab Tests</h3><p class="ccrd-sub">Most requested procedures</p></div></div>
    <div class="ccrd-body" style="padding-top:8px;">
      <?php if(!empty($topLabTests)):?>
      <ul class="lab-list">
        <?php foreach($topLabTests as $i=>$t):?>
        <li>
          <span class="lab-rank"><?php echo $i+1;?></span>
          <span class="lab-name"><?php echo htmlspecialchars($t['test_name']);?></span>
          <span class="lab-cnt"><?php echo number_format((int)$t['cnt']);?></span>
        </li>
        <?php endforeach;?>
      </ul>
      <?php else:?>
      <div class="empty-state"><i class="fas fa-flask"></i><p>No lab requests yet.</p></div>
      <?php endif;?>
    </div>
  </div>
  <div class="ccrd">
    <div class="ccrd-hdr"><div><h3 class="ccrd-title"><i class="fas fa-credit-card"></i> Payment Methods</h3><p class="ccrd-sub">Revenue split by method</p></div></div>
    <div class="ccrd-body">
      <?php if(!empty($payMethodData)):?>
      <div class="chart-wrap" style="height:180px;"><canvas id="payMethodChart"></canvas></div>
      <div class="dleg" id="payMethodLegend"></div>
      <?php else:?>
      <div class="empty-state"><i class="fas fa-credit-card"></i><p>No payment data</p></div>
      <?php endif;?>
    </div>
  </div>
</div>

<!-- Row: Monthly line + Cashier table -->
<div class="row-2eq">
  <div class="ccrd">
    <div class="ccrd-hdr">
      <div><h3 class="ccrd-title"><i class="fas fa-chart-line"></i> Monthly Overview</h3><p class="ccrd-sub">Last 12 months</p></div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <button class="range-tab active" id="btnMultiAdm" onclick="toggleML('admissions')">Admissions</button>
        <button class="range-tab" id="btnMultiVis" onclick="toggleML('visits')">Visits</button>
        <button class="range-tab" id="btnMultiPat" onclick="toggleML('patients')">Patients</button>
      </div>
    </div>
    <div class="ccrd-body"><div class="chart-wrap" style="height:250px;"><canvas id="multiLineChart"></canvas></div></div>
  </div>
  <div class="ccrd">
    <div class="ccrd-hdr"><div><h3 class="ccrd-title"><i class="fas fa-cash-register"></i> Revenue by Cashier</h3><p class="ccrd-sub">Selected period</p></div></div>
    <div class="ccrd-body" style="padding:0 0 8px;">
      <?php if(!empty($cashierData)): $maxC=max(array_column($cashierData,'total'));?>
      <table class="ct">
        <thead><tr><th>Cashier</th><th>Revenue</th></tr></thead>
        <tbody>
          <?php foreach($cashierData as $c): $pct=$maxC>0?round(($c['total']/$maxC)*100):0;?>
          <tr>
            <td><div style="font-weight:600;"><?php echo htmlspecialchars($c['name']);?></div><div class="bar-m"><div class="bar-f" style="width:<?php echo $pct;?>%;"></div></div></td>
            <td><?php echo formatCurrency($c['total']);?></td>
          </tr>
          <?php endforeach;?>
        </tbody>
      </table>
      <?php else:?>
      <div class="empty-state"><i class="fas fa-cash-register"></i><p>No cashier data for this period.</p></div>
      <?php endif;?>
    </div>
  </div>
</div>

<!-- Full-width dual-axis combo -->
<div class="ccrd" style="margin-bottom:20px;">
  <div class="ccrd-hdr"><div><h3 class="ccrd-title"><i class="fas fa-chart-bar"></i> Revenue vs Admissions (Monthly)</h3><p class="ccrd-sub">Dual-axis overview · last 12 months</p></div></div>
  <div class="ccrd-body"><div class="chart-wrap" style="height:280px;"><canvas id="admRevComboChart"></canvas></div></div>
</div>

<!-- Inventory alerts -->
<?php if(hasRole(['admin','inventory'])&&$hasInventory):?>
<div class="inv-row">
  <div class="inv-item">
    <div class="inv-icon" style="background:rgba(183,106,0,.12);color:var(--warning-color);"><i class="fas fa-exclamation-triangle"></i></div>
    <div><div class="inv-count" style="color:var(--warning-color);"><?php echo $kpiLowStock;?></div><div class="inv-lbl">Low Stock Items</div></div>
    <a href="<?php echo BASE_URL;?>modules/inventory/stock.php" class="btn btn-sm btn-secondary" style="margin-left:auto;">View Stock</a>
  </div>
  <div class="inv-item">
    <div class="inv-icon" style="background:rgba(198,40,40,.12);color:var(--danger-color);"><i class="fas fa-skull-crossbones"></i></div>
    <div><div class="inv-count" style="color:var(--danger-color);"><?php echo $expiredStock;?></div><div class="inv-lbl">Expired Stock</div></div>
    <div style="margin-left:auto;text-align:right;"><div style="font-size:12px;color:var(--text-muted);">Near expiry (30d):</div><div style="font-size:18px;font-weight:700;color:var(--warning-color);"><?php echo $nearExpiry;?></div></div>
  </div>
</div>
<?php endif;?>

</div><!-- /analytics-wrap -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
function cv(v){return getComputedStyle(document.documentElement).getPropertyValue(v).trim();}
function pal(){return{p:cv('--primary-color')||'#0f5ea8',a:cv('--accent-color')||'#15897b',s:cv('--success-color')||'#1d7a45',w:cv('--warning-color')||'#b76a00',d:cv('--danger-color')||'#c62828',m:cv('--text-muted')||'#5f738d',b:cv('--border-color')||'#d8e2ee',t:cv('--text-color')||'#16304a',f:cv('--surface-color')||'#ffffff',v:'#7c5cbf',o:'#e85d04'};}
Chart.defaults.font.family="'Inter',sans-serif";Chart.defaults.font.size=12;
function bsc(){const p=pal();return{x:{ticks:{color:p.m,maxRotation:30},grid:{color:'transparent'},border:{color:p.b}},y:{beginAtZero:true,ticks:{color:p.m},grid:{color:p.b+'55'},border:{color:p.b}}};}
const DC=['#0f5ea8','#15897b','#7c5cbf','#e85d04','#b76a00','#c62828','#1d7a45','#a8bdd8'];
const dL=<?php echo $jDailyLabels;?>;
const dR=<?php echo $jDailyRev;?>;
const mL=<?php echo $jMLabels;?>;
const mR=<?php echo $jMRevenue;?>;
const mA=<?php echo $jMAdmissions;?>;
const mV=<?php echo $jMVisits;?>;
const mP=<?php echo $jMPatients;?>;
const vsL=<?php echo $jVStatusLabels;?>;
const vsC=<?php echo $jVStatusCounts;?>;
const adL=<?php echo $jAdmLabels;?>;
const adA=<?php echo $jAdmittedArr;?>;
const adD=<?php echo $jDischargedArr;?>;
const pmL=<?php echo $jPayMethods;?>;
const pmT=<?php echo $jPayTotals;?>;

// 1. Revenue area
const rCtx=document.getElementById('revenueChart');
let rChart;
function buildRC(view){
  if(rChart)rChart.destroy();
  const p=pal(),lbs=view==='daily'?dL:mL,dt=view==='daily'?dR:mR;
  rChart=new Chart(rCtx,{type:'line',data:{labels:lbs,datasets:[{label:'Revenue',data:dt,borderColor:p.s,backgroundColor:ctx=>{const g=ctx.chart.ctx.createLinearGradient(0,0,0,220);g.addColorStop(0,p.s+'50');g.addColorStop(1,p.s+'00');return g;},fill:true,tension:0.45,pointRadius:3,pointHoverRadius:6,borderWidth:2.5,pointBackgroundColor:p.s}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{display:false},tooltip:{backgroundColor:p.f,titleColor:p.t,bodyColor:p.m,borderColor:p.b,borderWidth:1,callbacks:{label:c=>' \u20B1'+c.parsed.y.toLocaleString('en-PH',{minimumFractionDigits:2})}}},scales:bsc()}});
}
buildRC('daily');
window.switchRevView=v=>buildRC(v);

// 2. Visit status donut
<?php if(!empty($visitStatusData)):?>
const vsCtx=document.getElementById('visitStatusChart');
new Chart(vsCtx,{type:'doughnut',data:{labels:vsL,datasets:[{data:vsC,backgroundColor:DC,borderWidth:2,hoverOffset:8}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>' '+c.label+': '+c.parsed}}}}});
const vsLeg=document.getElementById('visitStatusLegend');
vsL.forEach((l,i)=>{const d=document.createElement('div');d.className='dleg-item';d.innerHTML=`<span class="dleg-dot" style="background:${DC[i]||'#ccc'}"></span>${l}: <strong>${vsC[i]}</strong>`;vsLeg.appendChild(d);});
<?php endif;?>

// 3. Admit vs Discharge
const ad0=pal();
new Chart(document.getElementById('admDischargeChart'),{type:'bar',data:{labels:adL,datasets:[{label:'Admitted',data:adA,backgroundColor:ad0.p+'cc',borderRadius:5,borderSkipped:false},{label:'Discharged',data:adD,backgroundColor:ad0.s+'cc',borderRadius:5,borderSkipped:false}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'top',labels:{color:ad0.m,boxWidth:10,padding:14,font:{size:11}}}},scales:bsc()}});

// 4. Payment methods donut
<?php if(!empty($payMethodData)):?>
const pm0=pal();
new Chart(document.getElementById('payMethodChart'),{type:'doughnut',data:{labels:pmL,datasets:[{data:pmT,backgroundColor:DC,borderWidth:2,hoverOffset:8}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>' '+c.label+': \u20B1'+c.parsed.toLocaleString('en-PH',{minimumFractionDigits:2})}}}}});
const pmLeg=document.getElementById('payMethodLegend');
pmL.forEach((l,i)=>{const d=document.createElement('div');d.className='dleg-item';d.innerHTML=`<span class="dleg-dot" style="background:${DC[i]||'#ccc'}"></span>${l}`;pmLeg.appendChild(d);});
<?php endif;?>

// 5. Multi-line monthly
const ml0=pal();
const mlDefs={admissions:{label:'Admissions',data:mA,color:ml0.p},visits:{label:'Visits',data:mV,color:ml0.v},patients:{label:'Patients',data:mP,color:ml0.a}};
let mlChart=new Chart(document.getElementById('multiLineChart'),{type:'line',data:{labels:mL,datasets:Object.entries(mlDefs).map(([k,ds])=>({label:ds.label,data:ds.data,borderColor:ds.color,backgroundColor:ds.color+'22',fill:true,tension:0.4,pointRadius:4,pointHoverRadius:7,borderWidth:2.5,hidden:k!=='admissions'}))},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{display:false},tooltip:{backgroundColor:ml0.f,titleColor:ml0.t,bodyColor:ml0.m,borderColor:ml0.b,borderWidth:1}},scales:bsc()}});
window.toggleML=function(key){
  const idx=Object.keys(mlDefs).indexOf(key);if(idx<0)return;
  const ds=mlChart.data.datasets[idx];ds.hidden=!ds.hidden;
  document.querySelectorAll('#btnMultiAdm,#btnMultiVis,#btnMultiPat').forEach(b=>b.classList.remove('active'));
  if(!ds.hidden){const m={admissions:'btnMultiAdm',visits:'btnMultiVis',patients:'btnMultiPat'};const b=document.getElementById(m[key]);if(b)b.classList.add('active');}
  mlChart.update();
};

// 6. Dual-axis combo
const ar0=pal();
new Chart(document.getElementById('admRevComboChart'),{type:'bar',data:{labels:mL,datasets:[{type:'line',label:'Revenue (\u20B1)',data:mR,borderColor:ar0.s,backgroundColor:ar0.s+'22',fill:true,tension:0.4,pointRadius:4,borderWidth:2.5,yAxisID:'yR',order:1},{type:'bar',label:'Admissions',data:mA,backgroundColor:ar0.p+'b0',borderRadius:5,borderSkipped:false,yAxisID:'yA',order:2}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'top',labels:{color:ar0.m,boxWidth:12,padding:16,font:{size:12}}},tooltip:{backgroundColor:ar0.f,titleColor:ar0.t,bodyColor:ar0.m,borderColor:ar0.b,borderWidth:1}},scales:{x:{ticks:{color:ar0.m},grid:{color:'transparent'},border:{color:ar0.b}},yA:{position:'left',beginAtZero:true,ticks:{color:ar0.p},grid:{color:ar0.b+'55'},border:{color:ar0.b},title:{display:true,text:'Admissions',color:ar0.p}},yR:{position:'right',beginAtZero:true,ticks:{color:ar0.s,callback:v=>'\u20B1'+v.toLocaleString()},grid:{drawOnChartArea:false},border:{color:ar0.b},title:{display:true,text:'Revenue (\u20B1)',color:ar0.s}}}}});

window.addEventListener('themeChanged',()=>{[rChart,mlChart].forEach(c=>{if(c)c.update();});});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
