<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['doctor', 'pharmacist', 'nurse', 'cashier', 'staff']);

$prescId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$prescId) {
    echo "Invalid prescription ID.";
    exit;
}

$conn = getDBConnection();
$stmt = $conn->prepare(
    "SELECT pr.*, p.first_name, p.last_name, p.patient_code, p.date_of_birth, u.full_name AS doctor_name, c.id AS consultation_id, c.visit_id
     FROM prescriptions pr
     LEFT JOIN patients p ON pr.patient_id = p.id
     LEFT JOIN users u ON pr.doctor_id = u.id
     LEFT JOIN consultations c ON pr.consultation_id = c.id
     WHERE pr.id = ? LIMIT 1"
);
$stmt->bind_param('i', $prescId);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    echo "Prescription not found.";
    exit;
}
$pr = $res->fetch_assoc();
$stmt->close();

// Mark as printed if accessed by a non-doctor role (staff/nurse/etc)
$currentUserId = $_SESSION['user_id'] ?? 0;
$currentRole   = $_SESSION['role'] ?? '';
$isDoctor      = ($currentRole === 'doctor');

if (!$isDoctor && $currentUserId) {
    // Ensure printed_at and printed_by columns exist
    $conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS printed_by INT DEFAULT NULL");
    $conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS printed_at DATETIME DEFAULT NULL");
    // Update status to 'printed' and record who printed it
    $upd = $conn->prepare("UPDATE prescriptions SET status = 'printed', printed_by = ?, printed_at = NOW() WHERE id = ? AND (status = 'pending' OR status IS NULL OR status = '')");
    if ($upd) {
        $upd->bind_param('ii', $currentUserId, $prescId);
        $upd->execute();
        $affectedRows = $upd->affected_rows; // save before close()
        $upd->close();
        // Refresh status in the fetched row
        if ($affectedRows > 0 || isset($pr)) {
            $pr['status'] = 'printed';
        }
    }
    // Fetch printer name for display
    $printerName = '';
    $pusr = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
    if ($pusr) {
        $pusr->bind_param('i', $currentUserId);
        $pusr->execute();
        $pusr->bind_result($printerName);
        $pusr->fetch();
        $pusr->close();
    }
}

$conn->close();

$patientName = htmlspecialchars($pr['first_name'] . ' ' . $pr['last_name']);
$patientCode = htmlspecialchars($pr['patient_code'] ?? '');
$doctorName = htmlspecialchars($pr['doctor_name'] ?? '');
$med = htmlspecialchars($pr['medication_name'] ?? '');
$dosage = htmlspecialchars($pr['dosage'] ?? '');
$frequency = htmlspecialchars($pr['frequency'] ?? '');
$duration = htmlspecialchars($pr['duration'] ?? '');
$instructions = nl2br(htmlspecialchars($pr['instructions'] ?? ''));
$qty = (int)($pr['quantity'] ?? 0);
$date = htmlspecialchars($pr['created_at'] ?? '');

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Prescription for <?php echo $patientName; ?> #<?php echo $prescId; ?></title>
    <style>
        :root { --muted: #666; --accent: #2c6fb7; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; margin: 18px; color: #222; }
        .wrap { max-width: 760px; margin: 0 auto; border: 1px solid #e4e4e4; padding: 18px; border-radius: 6px; }
        .head { display:flex; align-items:center; gap:12px; }
        .logo { width:72px; height:72px; background:#f2f6fb; display:flex; align-items:center; justify-content:center; border-radius:6px; font-weight:700; color:var(--accent); }
        .clinic { flex:1; }
        .clinic h1 { margin:0; font-size:18px; color:var(--accent); }
        .clinic .meta { font-size:12px; color:var(--muted); }
        .presc-meta { text-align:right; font-size:13px; color:var(--muted); }

        .patient-box { display:flex; justify-content:space-between; margin-top:14px; gap:12px; }
        .patient-name-banner { margin-top:14px; padding:10px 12px; background:#f5f9ff; border:1px solid #d8e7fb; border-radius:4px; font-weight:700; color:#174b8a; }
        .patient-box .left, .patient-box .right { flex:1; }
        .box { background:#fbfbfb; border:1px solid #f0f0f0; padding:10px; border-radius:4px; font-size:13px; }

        .med-table { width:100%; border-collapse: collapse; margin-top:14px; }
        .med-table th, .med-table td { text-align:left; padding:8px 10px; border-bottom:1px dashed #eaeaea; }
        .med-table th { background:#fafafa; font-weight:600; font-size:13px; }

        .signature { margin-top:26px; display:flex; justify-content:space-between; align-items:flex-end; }
        .sig-block { width:48%; text-align:left; }
        .sig-line { border-top:1px solid #ccc; margin-top:36px; width:80%; }
        .footer { margin-top:18px; font-size:12px; color:var(--muted); text-align:center; }

        .no-print { margin-top:20px; display:flex; align-items:center; justify-content:center; gap:10px; flex-wrap:wrap; }

        /* ── Button base ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 22px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            border: none;
            border-radius: 7px;
            cursor: pointer;
            text-decoration: none;
            letter-spacing: 0.02em;
            transition: background 0.18s ease, box-shadow 0.18s ease, transform 0.12s ease;
            user-select: none;
            white-space: nowrap;
        }
        .btn:active { transform: scale(0.97); }

        /* Print — deep teal/blue */
        .btn-print {
            background: linear-gradient(135deg, #1a73e8 0%, #0d5bbf 100%);
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(26,115,232,0.35);
        }
        .btn-print:hover {
            background: linear-gradient(135deg, #1664d0 0%, #0a4da8 100%);
            box-shadow: 0 4px 14px rgba(26,115,232,0.45);
        }

        /* Close — neutral slate */
        .btn-close {
            background: linear-gradient(135deg, #4b5563 0%, #374151 100%);
            color: #f3f4f6;
            box-shadow: 0 2px 8px rgba(55,65,81,0.30);
        }
        .btn-close:hover {
            background: linear-gradient(135deg, #374151 0%, #1f2937 100%);
            box-shadow: 0 4px 14px rgba(55,65,81,0.40);
        }

        .printed-by { font-size:12px; color:#555; width:100%; text-align:center; margin-top:4px; }
        .printed-by strong { color:#333; }

        @page {
            size: 6.5in 4.25in;
            margin: 0.1in;
        }

        @media print {
            body { margin: 0; }
            .no-print { display:none; }
            .wrap {
                max-width: none;
                width: 6.5in;
                min-height: 4.25in;
                border: none;
                padding: 0.12in;
                box-sizing: border-box;
                border-radius: 0;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="head">
            <div class="logo">
                <!-- Optional logo: place file at assets/logo.png or assets/logo.svg to show here -->
                <?php if (file_exists(__DIR__ . '/../../assets/logo.png')): ?>
                    <img src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/logo.png" alt="logo" style="max-width:72px; max-height:72px; display:block;">
                <?php elseif (file_exists(__DIR__ . '/../../assets/logo.svg')): ?>
                    <img src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/logo.svg" alt="logo" style="max-width:72px; max-height:72px; display:block;">
                <?php else: ?>
                    SCH
                <?php endif; ?>
            </div>
            <div class="clinic">
                <h1>Saint Claire Hospital</h1>
                <div class="meta">123 Health St., Citytown • Tel: (02) 1234-5678 • outpatient@stclaire.example</div>
            </div>
            <div class="presc-meta">
                <div>Prescription ID: <?php echo $prescId; ?></div>
                <div>Date: <?php echo $date; ?></div>
            </div>
        </div>

        <div class="patient-name-banner">
            <strong>Patient Name:</strong> <?php echo $patientName; ?>
        </div>

        <div class="patient-box">
            <div class="left box">
                <strong>Patient</strong><br>
                <?php echo $patientName; ?> <small style="color:var(--muted);">(<?php echo $patientCode; ?>)</small><br>
                DOB: <?php echo htmlspecialchars($pr['date_of_birth'] ?? ''); ?>
            </div>
            <div class="right box">
                <strong>Prescribing Doctor</strong><br>
                <?php echo $doctorName; ?><br>
                <small style="color:var(--muted);">Consultation ID: <?php echo htmlspecialchars($pr['consultation_id'] ?? ''); ?></small>
            </div>
        </div>

        <table class="med-table">
            <thead>
                <tr>
                    <th>Medicine</th>
                    <th>Dosage / Frequency</th>
                    <th>Duration</th>
                    <th style="width:80px;">Qty</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong><?php echo $med; ?></strong><div style="color:var(--muted); font-size:12px; margin-top:4px;"><?php echo $instructions ?: ''; ?></div></td>
                    <td><?php echo $dosage ?: '—'; ?> <br><small style="color:var(--muted);"><?php echo $frequency ?: '—'; ?></small></td>
                    <td><?php echo $duration ?: '—'; ?></td>
                    <td><?php echo $qty ?: '—'; ?></td>
                </tr>
            </tbody>
        </table>

        <div class="signature">
            <div class="sig-block">
                <div class="sig-line"></div>
                <div style="font-size:13px; color:var(--muted);">Physician's signature</div>
            </div>
            <div class="sig-block" style="text-align:right;">
                <div style="font-size:12px; color:var(--muted);">Clinic stamp</div>
            </div>
        </div>

        <div class="footer">Printed from Saint Claire HMS — <?php echo date('Y'); ?>. Verify patient identity before dispensing.</div>
    </div>

    <div class="no-print">
        <?php if (!$isDoctor): ?>
            <button class="btn btn-print" onclick="window.print();">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                    <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/>
                    <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2H5zm7 2v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1z"/>
                </svg>
                Print Prescription
            </button>
            <?php if (!empty($printerName)): ?>
                <p class="printed-by">Printed by: <strong><?php echo htmlspecialchars($printerName); ?></strong></p>
            <?php endif; ?>
        <?php else: ?>
            <p style="font-size:13px; color:#888; margin:0;"><i>Printing is handled by staff/nurse.</i></p>
        <?php endif; ?>
        <a href="javascript:window.close();" class="btn btn-close">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                <path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8 2.146 2.854z"/>
            </svg>
            Close
        </a>
    </div>
</body>
</html>
