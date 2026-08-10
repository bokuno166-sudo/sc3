<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse', 'doctor']);

$pageTitle = 'Maternity & Lying-In';
$currentPage = 'maternity';

$conn = getDBConnection();

// Check and auto-create maternity_checkups table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS maternity_checkups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    checkup_date DATE NOT NULL,
    weeks_of_pregnancy INT NOT NULL,
    weight DECIMAL(5,2) NULL,
    blood_pressure VARCHAR(20) NULL,
    fetal_heartbeat INT NULL,
    fundal_height DECIMAL(4,1) NULL,
    presentation VARCHAR(50) NULL,
    notes TEXT NULL,
    prescribed_vitamins TEXT NULL,
    next_appointment_date DATE NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
)");

// Handle Registering a Pregnancy
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_pregnancy'])) {
    $patientId = (int)$_POST['patient_id'];
    $edd = sanitize($_POST['expected_due_date']);
    $weeks = (int)$_POST['weeks_of_pregnancy'];
    
    if ($patientId > 0 && !empty($edd) && $weeks > 0) {
        $stmt = $conn->prepare("UPDATE patients SET is_pregnant = 1, weeks_of_pregnancy = ?, expected_due_date = ? WHERE id = ?");
        $stmt->bind_param("isi", $weeks, $edd, $patientId);
        if ($stmt->execute()) {
            logActivity("Register Pregnancy", "patients", $patientId, null, json_encode(['weeks' => $weeks, 'edd' => $edd]));
            setFlashMessage('success', 'Pregnancy registered successfully!');
        } else {
            setFlashMessage('error', 'Failed to register pregnancy.');
        }
        $stmt->close();
    } else {
        setFlashMessage('error', 'Please fill all required fields.');
    }
    redirect('modules/maternity/index.php');
}

// Handle Resolving/Delivering a Pregnancy manually
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_pregnancy'])) {
    $patientId = (int)$_POST['patient_id'];
    if ($patientId > 0) {
        $stmt = $conn->prepare("UPDATE patients SET is_pregnant = 0, weeks_of_pregnancy = NULL, expected_due_date = NULL WHERE id = ?");
        $stmt->bind_param("i", $patientId);
        if ($stmt->execute()) {
            logActivity("Resolve Pregnancy", "patients", $patientId);
            setFlashMessage('success', 'Pregnancy resolved successfully.');
        } else {
            setFlashMessage('error', 'Failed to resolve pregnancy.');
        }
        $stmt->close();
    }
    redirect('modules/maternity/index.php');
}

// Get all currently pregnant patients
$pregnantPatients = $conn->query("
    SELECT p.*, 
           (SELECT checkup_date FROM maternity_checkups mc WHERE mc.patient_id = p.id ORDER BY checkup_date DESC LIMIT 1) as last_checkup_date,
           (SELECT fetal_heartbeat FROM maternity_checkups mc WHERE mc.patient_id = p.id ORDER BY checkup_date DESC LIMIT 1) as last_fetal_heartbeat,
           (SELECT weight FROM maternity_checkups mc WHERE mc.patient_id = p.id ORDER BY checkup_date DESC LIMIT 1) as last_weight,
           (SELECT blood_pressure FROM maternity_checkups mc WHERE mc.patient_id = p.id ORDER BY checkup_date DESC LIMIT 1) as last_blood_pressure
    FROM patients p
    LEFT JOIN (
        SELECT pv1.patient_id, pv1.checkup_type
        FROM patient_visits pv1
        JOIN (
            SELECT patient_id, MAX(created_at) as max_created
            FROM patient_visits
            WHERE visit_date = CURDATE()
            GROUP BY patient_id
        ) pv2 ON pv1.patient_id = pv2.patient_id AND pv1.created_at = pv2.max_created
    ) latest_visit ON latest_visit.patient_id = p.id
    WHERE p.is_pregnant = 1 AND p.status = 'active'
      AND (latest_visit.checkup_type IS NULL OR latest_visit.checkup_type = 'maternity')
    ORDER BY p.expected_due_date ASC
");

// Get female patients who are not currently registered as pregnant
$femalePatients = $conn->query("
    SELECT id, first_name, last_name, patient_code, date_of_birth
    FROM patients 
    WHERE gender = 'Female' AND (is_pregnant = 0 OR is_pregnant IS NULL) AND status = 'active'
    ORDER BY last_name ASC, first_name ASC
");

// Collect patients into array for stats
$patientRows = [];
if ($pregnantPatients && $pregnantPatients->num_rows > 0) {
    while ($row = $pregnantPatients->fetch_assoc()) {
        $patientRows[] = $row;
    }
}

$totalActive    = count($patientRows);
$dueSoon        = 0;
$noCheckup      = 0;
$thirdTrimester = 0;
$today          = new DateTime();

foreach ($patientRows as $p) {
    if (!empty($p['expected_due_date']) && $p['expected_due_date'] !== '0000-00-00') {
        $eddDate = new DateTime($p['expected_due_date']);
        $diff = (int)$today->diff($eddDate)->format('%r%a');
        if ($diff >= 0 && $diff <= 14) $dueSoon++;
    }
    if (empty($p['last_checkup_date'])) $noCheckup++;
    if (intval($p['weeks_of_pregnancy']) >= 28) $thirdTrimester++;
}

include __DIR__ . '/../../includes/header.php';
?>

<style>
/* =========================================================
   Maternity Dashboard – Premium Redesign
   ========================================================= */

/* Page Header */
.mat-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 28px;
}
.mat-page-header-left h1 {
    font-size: 1.7rem;
    font-weight: 800;
    color: var(--text-color);
    margin: 0 0 4px;
    letter-spacing: -0.5px;
}
.mat-page-header-left p {
    font-size: 0.875rem;
    color: var(--text-muted);
    margin: 0;
}
.mat-header-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, rgba(147,51,234,0.12), rgba(236,72,153,0.12));
    color: #1428ddff;
    border: 1px solid rgba(147,51,234,0.25);
    border-radius: 50px;
    padding: 7px 16px;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.3px;
}
[data-theme="dark"] .mat-header-badge {
    color: #1428ddff;
    border-color: rgba(147,51,234,0.4);
}

/* ── Stats Row ── */
.mat-stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 28px;
}
@media (max-width: 900px) { .mat-stats-row { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 500px)  { .mat-stats-row { grid-template-columns: 1fr; } }

.mat-stat-card {
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 20px 22px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: var(--box-shadow);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    position: relative;
    overflow: hidden;
}
.mat-stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: 16px 16px 0 0;
}
.mat-stat-card:hover { transform: translateY(-3px); box-shadow: var(--box-shadow-lg); }
.mat-stat-card.purple::before { background: linear-gradient(90deg, #9333ea, #ec4899); }
.mat-stat-card.pink::before   { background: linear-gradient(90deg, #ec4899, #f97316); }
.mat-stat-card.amber::before  { background: linear-gradient(90deg, #f59e0b, #ef4444); }
.mat-stat-card.teal::before   { background: linear-gradient(90deg, #14b8a6, #3b82f6); }

.mat-stat-icon {
    width: 50px; height: 50px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
}
.mat-stat-card.purple .mat-stat-icon { background: rgba(147,51,234,0.12); color: #9333ea; }
.mat-stat-card.pink   .mat-stat-icon { background: rgba(236,72,153,0.12); color: #ec4899; }
.mat-stat-card.amber  .mat-stat-icon { background: rgba(245,158,11,0.12); color: #f59e0b; }
.mat-stat-card.teal   .mat-stat-icon { background: rgba(20,184,166,0.12); color: #14b8a6; }

.mat-stat-info .value {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1;
    color: var(--text-color);
    letter-spacing: -1px;
}
.mat-stat-info .label {
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--text-muted);
    margin-top: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ── Main Grid ── */
.mat-main-grid {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 24px;
    align-items: start;
}
@media (max-width: 1024px) { .mat-main-grid { grid-template-columns: 1fr; } }

/* ── Panel Shell ── */
.mat-panel {
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    border-radius: 18px;
    box-shadow: var(--box-shadow);
    overflow: hidden;
}
.mat-panel-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.mat-panel-header h3 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-color);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 9px;
}
.mat-panel-icon {
    width: 34px; height: 34px;
    border-radius: 10px;
    background: rgba(147,51,234,0.12);
    color: #9333ea;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.9rem;
}
.mat-count-pill {
    background: linear-gradient(135deg, #9333ea, #ec4899);
    color: #fff;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 50px;
}

/* ── Patient Card List ── */
.mat-patient-list {
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    max-height: 560px;
    overflow-y: auto;
}
.mat-patient-list::-webkit-scrollbar { width: 4px; }
.mat-patient-list::-webkit-scrollbar-track { background: transparent; }
.mat-patient-list::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 4px; }

.mat-patient-card {
    background: var(--surface-muted);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 13px;
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
}
.mat-patient-card:hover {
    border-color: rgba(147,51,234,0.3);
    box-shadow: 0 4px 20px rgba(147,51,234,0.08);
}
.mat-patient-card.due-soon { border-left: 3px solid #f59e0b; }
.mat-patient-card.third-tri { border-left: 3px solid #ec4899; }

.mat-patient-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}
.mat-patient-info {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    min-width: 0;
}
.mat-avatar {
    width: 44px; height: 44px;
    border-radius: 13px;
    background: linear-gradient(135deg, #9333ea, #ec4899);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
    font-weight: 700;
    flex-shrink: 0;
    letter-spacing: -1px;
}
.mat-patient-name {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--text-color);
    text-decoration: none;
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.mat-patient-name:hover { color: #9333ea; }
.mat-patient-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 3px;
}
.mat-code-badge {
    font-family: monospace;
    font-size: 0.7rem;
    background: var(--border-color);
    color: var(--text-muted);
    padding: 1px 7px;
    border-radius: 4px;
}
.mat-age-text {
    font-size: 0.72rem;
    color: var(--text-muted);
    font-weight: 500;
}
.mat-weeks-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.78rem;
    font-weight: 700;
    padding: 5px 11px;
    border-radius: 50px;
    white-space: nowrap;
    flex-shrink: 0;
}
.mat-weeks-pill.t1 { background: #dbeafe; color: #1d4ed8; }
.mat-weeks-pill.t2 { background: #dcfce7; color: #166534; }
.mat-weeks-pill.t3 { background: #fce7f3; color: #9d174d; }
[data-theme="dark"] .mat-weeks-pill.t1 { background: rgba(30,58,138,0.4); color: #93c5fd; }
[data-theme="dark"] .mat-weeks-pill.t2 { background: rgba(20,83,45,0.4);  color: #86efac; }
[data-theme="dark"] .mat-weeks-pill.t3 { background: rgba(131,24,67,0.6); color: #f9a8d4; }

/* Progress bar */
.mat-progress-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
}
.mat-progress-bar {
    flex: 1;
    height: 6px;
    background: var(--border-color);
    border-radius: 50px;
    overflow: hidden;
}
.mat-progress-fill {
    height: 100%;
    border-radius: 50px;
    transition: width 0.6s ease;
}
.mat-progress-fill.t1 { background: linear-gradient(90deg, #3b82f6, #06b6d4); }
.mat-progress-fill.t2 { background: linear-gradient(90deg, #22c55e, #10b981); }
.mat-progress-fill.t3 { background: linear-gradient(90deg, #ec4899, #9333ea); }
.mat-progress-label {
    font-size: 0.7rem;
    color: var(--text-muted);
    font-weight: 500;
    white-space: nowrap;
}

/* Detail chips */
.mat-patient-details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.mat-chip {
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 9px 12px;
}
.mat-chip-label {
    font-size: 0.63rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}
.mat-chip-value {
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--text-color);
    display: flex;
    align-items: center;
    gap: 5px;
    flex-wrap: wrap;
}
.mat-chip-value.muted {
    color: var(--text-muted);
    font-style: italic;
    font-weight: 400;
}
.mat-edd-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #e0f2fe;
    color: #0369a1;
    border-radius: 6px;
    padding: 2px 7px;
    font-size: 0.78rem;
    font-weight: 600;
}
.mat-edd-badge.urgent {
    background: #fef3c7;
    color: #92400e;
    animation: pulseEdd 2s infinite;
}
[data-theme="dark"] .mat-edd-badge       { background: rgba(7,89,133,0.5);  color: #7dd3fc; }
[data-theme="dark"] .mat-edd-badge.urgent { background: rgba(146,64,14,0.5); color: #fcd34d; }

@keyframes pulseEdd {
    0%, 100% { box-shadow: 0 0 0 0 rgba(245,158,11,0.5); }
    50%       { box-shadow: 0 0 0 5px rgba(245,158,11,0); }
}

/* Action row */
.mat-action-row {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-wrap: wrap;
}
.mat-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.78rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    border: none;
    transition: all 0.18s ease;
    white-space: nowrap;
    font-family: inherit;
}
.mat-btn:hover { transform: translateY(-1px); filter: brightness(1.06); }
.mat-btn-primary { background: linear-gradient(135deg,#9333ea,#7c3aed); color:#fff; box-shadow:0 3px 10px rgba(147,51,234,0.3); }
.mat-btn-success { background: linear-gradient(135deg,#059669,#10b981); color:#fff; box-shadow:0 3px 10px rgba(5,150,105,0.25); }
.mat-btn-info    { background: linear-gradient(135deg,#0284c7,#06b6d4); color:#fff; box-shadow:0 3px 10px rgba(2,132,199,0.25); }
.mat-btn-ghost   { background: var(--surface-color); color: var(--text-muted); border: 1px solid var(--border-color); }
.mat-btn-ghost:hover { border-color: #ef4444; color: #ef4444; background: #fef2f2; }

/* Empty state */
.mat-empty-state {
    padding: 60px 30px;
    text-align: center;
}
.mat-empty-icon {
    width: 80px; height: 80px;
    border-radius: 20px;
    background: rgba(147,51,234,0.08);
    color: rgba(147,51,234,0.35);
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem;
    margin: 0 auto 20px;
}
.mat-empty-title { font-size: 1rem; font-weight: 700; color: var(--text-color); margin: 0 0 6px; }
.mat-empty-sub   { font-size: 0.82rem; color: var(--text-muted); }

/* ── Register Panel ── */
.mat-register-panel {
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    border-radius: 18px;
    box-shadow: var(--box-shadow);
    overflow: hidden;
    position: sticky;
    top: 88px;
}
.mat-register-header {
    padding: 20px 24px 16px;
    background: linear-gradient(135deg, rgba(147,51,234,0.05), rgba(236,72,153,0.05));
    border-bottom: 1px solid var(--border-color);
}
.mat-register-header h3 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-color);
    margin: 0 0 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.mat-register-header h3 i { color: #9333ea; }
.mat-register-header p {
    font-size: 0.78rem;
    color: var(--text-muted);
    margin: 0;
}
.mat-register-body { padding: 22px; }

.mat-field { margin-bottom: 18px; }
.mat-label {
    display: block;
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--text-color);
    margin-bottom: 7px;
    letter-spacing: 0.2px;
}
.mat-label .req { color: #ec4899; margin-left: 2px; }
.mat-input {
    width: 100%;
    padding: 10px 13px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 0.875rem;
    color: var(--text-color);
    background: var(--surface-muted);
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
    box-sizing: border-box;
    font-family: inherit;
}
.mat-input:focus {
    outline: none;
    border-color: #9333ea;
    box-shadow: 0 0 0 3px rgba(147,51,234,0.12);
    background: var(--surface-color);
}
.mat-input::placeholder { color: var(--text-muted); opacity: 0.7; }
[data-theme="dark"] .mat-input { color: var(--text-color); background: var(--surface-muted); }

.mat-hint {
    font-size: 0.7rem;
    color: var(--text-muted);
    margin-top: 5px;
    display: flex;
    align-items: center;
    gap: 4px;
}
.mat-weeks-feedback {
    margin-top: 8px;
    background: rgba(147,51,234,0.08);
    border: 1px solid rgba(147,51,234,0.2);
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 0.78rem;
    color: #9333ea;
    font-weight: 600;
    display: none;
    align-items: center;
    gap: 6px;
}
[data-theme="dark"] .mat-weeks-feedback { color: #c084fc; background: rgba(147,51,234,0.15); border-color: rgba(147,51,234,0.3); }

.mat-divider { border: none; border-top: 1px solid var(--border-color); margin: 18px 0; }

.mat-submit-btn {
    width: 100%;
    padding: 13px;
    background: linear-gradient(135deg, #9333ea, #ec4899);
    color: #fff;
    border: none;
    border-radius: 11px;
    font-size: 0.9rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 4px 14px rgba(147,51,234,0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-family: inherit;
    letter-spacing: 0.2px;
}
.mat-submit-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(147,51,234,0.45); }
.mat-submit-btn:active { transform: translateY(0); }

.mat-no-patient {
    text-align: center;
    padding: 30px 20px;
    color: var(--text-muted);
    font-size: 0.85rem;
}
.mat-no-patient i { font-size: 2.2rem; color: var(--border-color); margin-bottom: 12px; display: block; }
</style>

<!-- ────────────────────────────────────────
     Page Header
     ──────────────────────────────────────── -->
<div class="mat-page-header">
    <div class="mat-page-header-left">
        <h1>Maternity &amp; Lying-In</h1>
        <p>Monitor prenatal check-ups, manage active pregnancies, and record births</p>
    </div>
    <span class="mat-header-badge">
        <i class="fas fa-circle" style="font-size:7px; color:#22c55e; animation: pulseEdd 2s infinite;"></i>
        <?php echo $totalActive; ?> Active Case<?php echo $totalActive !== 1 ? 's' : ''; ?>
    </span>
</div>

<!-- ────────────────────────────────────────
     Statistics Row
     ──────────────────────────────────────── -->
<div class="mat-stats-row">
    <div class="mat-stat-card purple">
        <div class="mat-stat-icon"><i class="fas fa-baby"></i></div>
        <div class="mat-stat-info">
            <div class="value"><?php echo $totalActive; ?></div>
            <div class="label">Active Pregnancies</div>
        </div>
    </div>
    <div class="mat-stat-card pink">
        <div class="mat-stat-icon"><i class="fas fa-calendar-check"></i></div>
        <div class="mat-stat-info">
            <div class="value"><?php echo $dueSoon; ?></div>
            <div class="label">Due Within 14 Days</div>
        </div>
    </div>
    <div class="mat-stat-card amber">
        <div class="mat-stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="mat-stat-info">
            <div class="value"><?php echo $noCheckup; ?></div>
            <div class="label">No Check-up Yet</div>
        </div>
    </div>
    <div class="mat-stat-card teal">
        <div class="mat-stat-icon"><i class="fas fa-heartbeat"></i></div>
        <div class="mat-stat-info">
            <div class="value"><?php echo $thirdTrimester; ?></div>
            <div class="label">Third Trimester</div>
        </div>
    </div>
</div>

<!-- ────────────────────────────────────────
     Main Grid
     ──────────────────────────────────────── -->
<div class="mat-main-grid">

    <!-- ── Active Pregnancies Panel ── -->
    <div class="mat-panel">
        <div class="mat-panel-header">
            <h3>
                <span class="mat-panel-icon"><i class="fas fa-baby"></i></span>
                Active Pregnancies
            </h3>
            <?php if ($totalActive > 0): ?>
            <span class="mat-count-pill"><?php echo $totalActive; ?> patient<?php echo $totalActive !== 1 ? 's' : ''; ?></span>
            <?php endif; ?>
        </div>

        <?php if ($totalActive > 0): ?>
        <div class="mat-patient-list">
            <?php foreach ($patientRows as $p):
                $weeks     = intval($p['weeks_of_pregnancy']);
                $progress  = min(round(($weeks / 40) * 100), 100);
                $trimClass = $weeks < 14 ? 't1' : ($weeks < 28 ? 't2' : 't3');
                $trimLabel = $weeks < 14 ? '1st Trimester' : ($weeks < 28 ? '2nd Trimester' : '3rd Trimester');

                $eddStr   = (!empty($p['expected_due_date']) && $p['expected_due_date'] !== '0000-00-00')
                              ? formatDate($p['expected_due_date']) : 'N/A';
                $isUrgent = false;
                $daysLeft = null;
                if (!empty($p['expected_due_date']) && $p['expected_due_date'] !== '0000-00-00') {
                    $eddDate  = new DateTime($p['expected_due_date']);
                    $daysLeft = (int)$today->diff($eddDate)->format('%r%a');
                    $isUrgent = ($daysLeft >= 0 && $daysLeft <= 14);
                }

                $initials  = strtoupper(substr($p['first_name'],0,1) . substr($p['last_name'],0,1));
                $age       = calculateAge($p['date_of_birth']);
                $cardClass = $isUrgent ? 'due-soon' : ($weeks >= 28 ? 'third-tri' : '');
            ?>
            <div class="mat-patient-card <?php echo $cardClass; ?>">

                <!-- Top: Avatar + name + weeks pill -->
                <div class="mat-patient-top">
                    <div class="mat-patient-info">
                        <div class="mat-avatar"><?php echo $initials; ?></div>
                        <div style="min-width:0;">
                            <a href="../reception/patient-view.php?id=<?php echo $p['id']; ?>" class="mat-patient-name">
                                <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                            </a>
                            <div class="mat-patient-meta">
                                <span class="mat-code-badge"><?php echo htmlspecialchars($p['patient_code']); ?></span>
                                <span class="mat-age-text"><?php echo $age; ?> yrs</span>
                            </div>
                        </div>
                    </div>
                    <span class="mat-weeks-pill <?php echo $trimClass; ?>">
                        <i class="fas fa-seedling" style="font-size:0.63rem;"></i>
                        <?php echo $weeks; ?> wks
                    </span>
                </div>

                <!-- Gestational progress bar -->
                <div class="mat-progress-wrap">
                    <div class="mat-progress-bar">
                        <div class="mat-progress-fill <?php echo $trimClass; ?>" style="width:<?php echo $progress; ?>%;"></div>
                    </div>
                    <span class="mat-progress-label"><?php echo $trimLabel; ?> &bull; <?php echo $progress; ?>%</span>
                </div>

                <!-- Detail chips -->
                <div class="mat-patient-details">
                    <div class="mat-chip">
                        <div class="mat-chip-label"><i class="fas fa-calendar-alt"></i> Due Date (EDD)</div>
                        <div class="mat-chip-value">
                            <?php if ($eddStr !== 'N/A'): ?>
                                <span class="mat-edd-badge <?php echo $isUrgent ? 'urgent' : ''; ?>">
                                    <?php if ($isUrgent): ?><i class="fas fa-clock" style="font-size:0.62rem;"></i><?php endif; ?>
                                    <?php echo $eddStr; ?>
                                </span>
                                <?php if ($daysLeft !== null && $daysLeft >= 0): ?>
                                    <span style="font-size:0.68rem; color:var(--text-muted); font-weight:500;"><?php echo $daysLeft; ?>d left</span>
                                <?php elseif ($daysLeft !== null && $daysLeft < 0): ?>
                                    <span style="font-size:0.68rem; color:#ef4444; font-weight:600;">Overdue</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">Not set</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mat-chip">
                        <div class="mat-chip-label"><i class="fas fa-stethoscope"></i> Last Check-up</div>
                        <div class="mat-chip-value">
                            <?php if ($p['last_checkup_date']): ?>
                                <div>
                                    <div style="font-size:0.82rem; font-weight:600;"><?php echo formatDate($p['last_checkup_date']); ?></div>
                                    <div style="font-size:0.68rem; color:var(--text-muted); margin-top:2px;">
                                        BP: <?php echo htmlspecialchars($p['last_blood_pressure'] ?: '--'); ?>
                                        &bull; HR: <?php echo $p['last_fetal_heartbeat'] ? $p['last_fetal_heartbeat'] . ' bpm' : '--'; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="muted"><i class="fas fa-info-circle" style="font-size:0.72rem;"></i> Not logged</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="mat-action-row">
                    <a href="checkup-add.php?patient_id=<?php echo $p['id']; ?>" class="mat-btn mat-btn-primary">
                        <i class="fas fa-plus"></i> Check-up
                    </a>
                    <a href="delivery-add.php?patient_id=<?php echo $p['id']; ?>" class="mat-btn mat-btn-success">
                        <i class="fas fa-baby-carriage"></i> Birth
                    </a>
                    <a href="timeline.php?patient_id=<?php echo $p['id']; ?>" class="mat-btn mat-btn-info">
                        <i class="fas fa-history"></i> Timeline
                    </a>
                    <form method="POST" action="" style="margin:0;"
                          onsubmit="return confirm('Remove pregnancy record for <?php echo htmlspecialchars(addslashes($p['first_name'] . ' ' . $p['last_name'])); ?>?\nThis will NOT create a birth log.');">
                        <input type="hidden" name="patient_id" value="<?php echo $p['id']; ?>">
                        <button type="submit" name="resolve_pregnancy" class="mat-btn mat-btn-ghost" title="Mark as resolved / delivered">
                            <i class="fas fa-check-circle"></i> Resolve
                        </button>
                    </form>
                </div>

            </div>
            <?php endforeach; ?>
        </div>

        <?php else: ?>
        <div class="mat-empty-state">
            <div class="mat-empty-icon"><i class="fas fa-baby"></i></div>
            <p class="mat-empty-title">No Active Pregnancies</p>
            <p class="mat-empty-sub">Register a new prenatal case using the form on the right.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Register Pregnancy Form ── -->
    <div class="mat-register-panel">
        <div class="mat-register-header">
            <h3><i class="fas fa-plus-circle"></i> Register Pregnancy</h3>
            <p>Add a new prenatal case to the active list</p>
        </div>
        <div class="mat-register-body">

            <?php if ($femalePatients && $femalePatients->num_rows > 0): ?>
            <form method="POST" action="" id="matRegisterForm">
                <input type="hidden" name="register_pregnancy" value="1">

                <div class="mat-field">
                    <label class="mat-label" for="patient_id">Select Patient <span class="req">*</span></label>
                    <select name="patient_id" id="patient_id" class="mat-input" required>
                        <option value="">— Choose a patient —</option>
                        <?php
                        $savedFemale = [];
                        while ($fp = $femalePatients->fetch_assoc()) { $savedFemale[] = $fp; }
                        foreach ($savedFemale as $fp):
                        ?>
                        <option value="<?php echo $fp['id']; ?>">
                            <?php echo htmlspecialchars($fp['last_name'] . ', ' . $fp['first_name'] . ' (' . $fp['patient_code'] . ') · Age ' . calculateAge($fp['date_of_birth'])); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mat-hint"><i class="fas fa-info-circle"></i> Only unregistered female patients shown</p>
                </div>

                <div class="mat-field">
                    <label class="mat-label" for="weeks_of_pregnancy">Gestational Age (Weeks) <span class="req">*</span></label>
                    <input type="number" name="weeks_of_pregnancy" id="weeks_of_pregnancy"
                           class="mat-input" min="1" max="45" required placeholder="e.g. 12"
                           oninput="matWeeksFeedback(this.value)">
                    <div class="mat-weeks-feedback" id="matWeeksFeedback">
                        <i class="fas fa-seedling"></i>
                        <span id="matWeeksText"></span>
                    </div>
                </div>

                <div class="mat-field">
                    <label class="mat-label" for="expected_due_date">Expected Due Date (EDD) <span class="req">*</span></label>
                    <input type="date" name="expected_due_date" id="expected_due_date"
                           class="mat-input" required>
                    <p class="mat-hint"><i class="fas fa-calendar"></i> Nagele's rule: LMP + 280 days</p>
                </div>

                <hr class="mat-divider">

                <button type="submit" class="mat-submit-btn">
                    <i class="fas fa-save"></i> Register Case
                </button>
            </form>

            <?php else: ?>
            <div class="mat-no-patient">
                <i class="fas fa-user-slash"></i>
                <strong>No eligible patients found.</strong><br>
                All female patients may already be registered in the system.
                <br><br>
                <a href="../reception/patient-add.php" class="mat-btn mat-btn-primary" style="display:inline-flex; margin:0 auto;">
                    <i class="fas fa-plus"></i> Add New Patient
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.mat-main-grid -->

<script>
// Live trimester feedback below the weeks input
function matWeeksFeedback(val) {
    const el  = document.getElementById('matWeeksFeedback');
    const txt = document.getElementById('matWeeksText');
    const w   = parseInt(val);
    if (!w || w < 1 || w > 45) { el.style.display = 'none'; return; }
    const tri = w < 14 ? '1st Trimester' : (w < 28 ? '2nd Trimester' : '3rd Trimester');
    const pct = Math.min(Math.round((w / 40) * 100), 100);
    txt.textContent = `Week ${w} of 40 \u00b7 ${tri} \u00b7 ${pct}% complete`;
    el.style.display = 'flex';
}

// Auto-suggest EDD based on weeks entered (only if field is empty)
document.getElementById('weeks_of_pregnancy')?.addEventListener('input', function () {
    const w = parseInt(this.value);
    if (!w || w < 1 || w > 45) return;
    const eddField = document.getElementById('expected_due_date');
    if (eddField.value) return; // don't override a manually set date
    const msPerDay = 86400000;
    const lmpApprox = new Date(Date.now() - w * 7 * msPerDay);
    const edd = new Date(lmpApprox.getTime() + 280 * msPerDay);
    eddField.value = edd.toISOString().split('T')[0];
});
</script>

<?php 
$conn->close();
include __DIR__ . '/../../includes/footer.php'; 
?>
