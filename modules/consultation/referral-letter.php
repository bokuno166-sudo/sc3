<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'doctor']);

$pageTitle = 'Referral Letter';
$currentPage = 'consultations';

$conn = getDBConnection();

// Get referral ID
$referralId = isset($_GET['referral_id']) ? (int)$_GET['referral_id'] : 0;

if (!$referralId) {
    setFlashMessage('error', 'Referral ID missing.');
    redirect('modules/consultation/consultations.php');
}

// Fetch referral with related data
$stmt = $conn->prepare(
    "SELECT r.*, 
            p.first_name, p.last_name, p.patient_code, p.date_of_birth, p.gender, p.blood_type, 
            p.address, p.contact_number, p.allergies, p.medical_history,
            u.full_name AS doctor_name, u.email AS doctor_email, u.phone AS doctor_phone,
            c.diagnosis, c.treatment_plan, c.follow_up_instructions
     FROM referrals r
     LEFT JOIN patients p ON r.patient_id = p.id
     LEFT JOIN users u ON r.doctor_id = u.id
     LEFT JOIN consultations c ON r.consultation_id = c.id
     WHERE r.id = ? LIMIT 1"
);
$stmt->bind_param('i', $referralId);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Referral not found.');
    redirect('modules/consultation/consultations.php');
}
$referral = $res->fetch_assoc();
$stmt->close();

// Track this view/print action
$userId = (int)($_SESSION['user_id'] ?? 0);
$action = isset($_GET['action']) && $_GET['action'] === 'print' ? 'printed' : 'viewed';
if ($userId) {
    $trackStmt = $conn->prepare(
        "INSERT INTO referral_letter_views (referral_id, user_id, action, viewed_at) VALUES (?, ?, ?, NOW())"
    );
    if ($trackStmt) {
        $trackStmt->bind_param('iis', $referralId, $userId, $action);
        $trackStmt->execute();
        $trackStmt->close();
    }
}

// Update status to 'printed' if this is a print request
if (isset($_GET['action']) && $_GET['action'] === 'print') {
    $updateStmt = $conn->prepare("UPDATE referrals SET status = 'printed' WHERE id = ?");
    if ($updateStmt) {
        $updateStmt->bind_param('i', $referralId);
        $updateStmt->execute();
        $updateStmt->close();
    }
}

$printMode = isset($_GET['print']) && $_GET['print'] === '1';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral Letter - <?php echo htmlspecialchars($referral['referral_code']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .print-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            line-height: 1.6;
            color: #333;
        }
        
        @media print {
            body {
                background: none;
                padding: 0;
            }
            .print-container {
                box-shadow: none;
                max-width: 100%;
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
        }
        
        .header {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 20px;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #dfe6e9;
        }
        
        .logo-box {
            width: 120px;
            min-height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            background: #fdfdfd;
            border: 1px solid #dfe6e9;
            padding: 12px;
        }
        
        .logo-box img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        
        .hospital-name {
            font-size: 26px;
            font-weight: 800;
            color: #2c3e50;
            margin-bottom: 6px;
            letter-spacing: 0.3px;
        }
        
        .header-subtitle {
            font-size: 13px;
            color: #7f8c8d;
            margin-bottom: 8px;
        }
        
        .header-contact {
            font-size: 13px;
            color: #57606f;
            line-height: 1.7;
        }
        
        .letter-title {
            font-size: 28px;
            font-weight: 800;
            color: #2d3436;
            text-align: center;
            margin: 30px 0 20px;
            letter-spacing: 1px;
        }
        
        .recipient-block,
        .letter-body,
        .letter-signature,
        .footer {
            margin-bottom: 24px;
        }
        
        .recipient-block {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 20px;
        }
        
        .recipient-address {
            font-size: 14px;
            color: #444;
            line-height: 1.8;
        }
        
        .recipient-address strong {
            display: block;
            margin-bottom: 6px;
            color: #2d3436;
        }
        
        .letter-body p {
            margin-bottom: 18px;
            color: #3a3f47;
            font-size: 15px;
            line-height: 1.9;
        }
        
        .letter-body p:last-child {
            margin-bottom: 0;
        }
        
        .letter-strong {
            font-weight: 700;
            color: #2d3436;
        }
        
        .signature-line {
            border-top: 1px solid #2d3436;
            width: 260px;
            margin: 0 auto 8px;
            height: 1px;
        }
        
        .letter-signature {
            text-align: center;
            margin-top: 20px;
        }
        
        .letter-signature p {
            margin-bottom: 6px;
            color: #444;
        }
        
        .footer {
            font-size: 12px;
            color: #7f8c8d;
            border-top: 1px solid #dfe6e9;
            padding-top: 16px;
        }
        
        .footer small {
            display: block;
            margin-top: 6px;
        }
        
        .watermark {
            display: none;
        }
        
        .content-section {
            margin-bottom: 20px;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: bold;
            color: #2c3e50;
            background: #ecf0f1;
            padding: 8px 12px;
            margin-bottom: 10px;
            border-left: 4px solid #3498db;
        }
        
        .section-content {
            margin-left: 20px;
            line-height: 1.8;
        }
        
        .info-row {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 20px;
            margin-bottom: 8px;
        }
        
        .info-label {
            font-weight: bold;
            color: #2c3e50;
        }
        
        .info-value {
            color: #555;
            word-break: break-word;
        }
        
        .watermark {
            display: none;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            justify-content: center;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
        }
        
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #7f8c8d;
        }
        
        .btn-print {
            background: #27ae60;
            color: white;
        }
        
        .btn-print:hover {
            background: #229954;
        }
        
        .urgency-badge {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 12px;
            text-transform: uppercase;
            margin-left: 10px;
        }
        
        .urgency-routine {
            background: #d5f4e6;
            color: #27ae60;
        }
        
        .urgency-urgent {
            background: #fdeaea;
            color: #e74c3c;
        }
        
        .urgency-emergency {
            background: #fadbd8;
            color: #c0392b;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #bdc3c7;
            text-align: center;
            font-size: 11px;
            color: #95a5a6;
        }
        
        .referral-code {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 10px;
            background: #ecf0f1;
            padding: 5px 10px;
            display: inline-block;
            border-radius: 3px;
        }
    </style>
</head>
<body>

<div class="no-print">
    <div class="button-group">
        <a href="referral-sign.php?referral_id=<?php echo $referralId; ?>" class="btn btn-primary">
            <i class="fas fa-pen"></i> Sign Document
        </a>
        <button class="btn btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> Print Letter
        </button>
        <a href="referral-letter.php?referral_id=<?php echo $referralId; ?>&action=print" class="btn btn-secondary">
            <i class="fas fa-download"></i> Mark as Printed
        </a>
        <a href="consultations.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<div class="print-container">
        <div class="header">
            <div class="logo-box">
                <?php if (file_exists(__DIR__ . '/../../assets/logo.png')): ?>
                    <img src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/logo.png" alt="Hospital Logo">
                <?php elseif (file_exists(__DIR__ . '/../../assets/logo.svg')): ?>
                    <img src="<?php echo rtrim(BASE_URL, '/'); ?>/assets/logo.svg" alt="Hospital Logo">
                <?php else: ?>
                    <strong>SCH</strong>
                <?php endif; ?>
            </div>
            <div>
                <div class="hospital-name">Saint Claire Hospital</div>
                <div class="header-subtitle">Official Medical Referral Letter</div>
                <div class="header-contact">123 Health St., Citytown • Tel: (02) 1234-5678 • info@stclaire.example</div>
            </div>
        </div>

        <div class="letter-title">Medical Referral Letter</div>

        <?php $referralContact = !empty($referral['referral_contact_name']) ? $referral['referral_contact_name'] : null; ?>
        <div class="recipient-block">
            <div class="recipient-address">
                <strong><?php echo htmlspecialchars($referralContact ?: $referral['referral_hospital']); ?></strong>
                <?php if (!empty($referral['referral_address'])): ?>
                    <?php echo nl2br(htmlspecialchars($referral['referral_address'])); ?><br>
                <?php endif; ?>
                <?php echo htmlspecialchars($referral['referral_hospital']); ?><br>
            </div>
            <div class="recipient-address" style="text-align:right;">
                Date: <?php echo date('F d, Y'); ?><br>
                Referral Code: <?php echo htmlspecialchars($referral['referral_code']); ?>
            </div>
        </div>
        <div class="letter-body">
            <p>Dear <?php echo htmlspecialchars($referralContact ?: 'Sir/Madam'); ?>,</p>
            <p>We are referring <span class="letter-strong"><?php echo htmlspecialchars($referral['first_name'] . ' ' . $referral['last_name']); ?></span>, patient code <span class="letter-strong"><?php echo htmlspecialchars($referral['patient_code']); ?></span>, for further evaluation and management.</p>
            <p>The patient has been assessed and requires referral to <span class="letter-strong"><?php echo htmlspecialchars($referral['referral_hospital']); ?></span><?php echo !empty($referral['referral_department']) ? ' (' . htmlspecialchars($referral['referral_department']) . ')' : ''; ?> for the following reason:</p>
            <p><?php echo nl2br(htmlspecialchars($referral['reason_for_referral'])); ?></p>
            <?php if (!empty($referral['diagnosis'])): ?>
                <p><span class="letter-strong">Diagnosis:</span><br><?php echo nl2br(htmlspecialchars($referral['diagnosis'])); ?></p>
            <?php endif; ?>
            <?php if (!empty($referral['clinical_summary'])): ?>
                <p><span class="letter-strong">Clinical Summary:</span><br><?php echo nl2br(htmlspecialchars($referral['clinical_summary'])); ?></p>
            <?php endif; ?>
            <p>Please provide the patient with the most appropriate care and treatment based on their current clinical needs.</p>
            <p>If you require further information, please do not hesitate to contact our office.</p>
        </div>
    
    <!-- Referring Doctor Information -->
    <div class="content-section">
        <div class="section-title">REFERRING PHYSICIAN</div>
        <div class="section-content">
            <div class="info-row">
                <span class="info-label">Name:</span>
                <span class="info-value"><?php echo htmlspecialchars($referral['doctor_name']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Email:</span>
                <span class="info-value"><?php echo htmlspecialchars($referral['doctor_email'] ?: 'N/A'); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Phone:</span>
                <span class="info-value"><?php echo htmlspecialchars($referral['doctor_phone'] ?: 'N/A'); ?></span>
            </div>
        </div>
    </div>
    
    <!-- Signature Section -->
        <div class="letter-signature">
            <div class="signature-line"></div>
            <p><strong><?php echo htmlspecialchars($referral['doctor_name']); ?></strong></p>
            <p>Referring Physician</p>
            <p>Saint Claire Hospital</p>
        </div>

        <div class="footer">
            <p>This referral letter is issued by Saint Claire Hospital.</p>
            <small>Patient should present this letter upon arrival at the receiving facility.</small>
            <small>Referral Code: <?php echo htmlspecialchars($referral['referral_code']); ?></small>
        </div>
</div>

</body>
</html>

<?php
$conn->close();
?>
