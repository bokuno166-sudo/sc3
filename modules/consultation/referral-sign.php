<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'doctor']);

$pageTitle = 'Referral Letter with Signature';
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

// Handle signature saving
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_signature') {
    $signatureData = $_POST['signature_data'] ?? '';
    
    if (!empty($signatureData)) {
        // Remove data:image/png;base64, prefix
        $signatureData = preg_replace('#^data:image/\w+;base64,#i', '', $signatureData);
        $signatureBinary = base64_decode($signatureData, true);
        
        if ($signatureBinary) {
            // Save signature to database
            $updateStmt = $conn->prepare("UPDATE referrals SET doctor_signature = ?, signature_timestamp = NOW() WHERE id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param('si', $signatureBinary, $referralId);
                $updateStmt->execute();
                $updateStmt->close();
                
                // Update status to printed if it was pending
                $statusStmt = $conn->prepare("UPDATE referrals SET status = 'printed' WHERE id = ? AND status = 'pending'");
                if ($statusStmt) {
                    $statusStmt->bind_param('i', $referralId);
                    $statusStmt->execute();
                    $statusStmt->close();
                }
                
                setFlashMessage('success', 'Signature saved successfully! Referral marked as printed.');
                redirect('modules/consultation/referral-letter.php?referral_id=' . $referralId);
            }
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Referral Letter - <?php echo htmlspecialchars($referral['referral_code']); ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card-body {
            padding: 30px;
        }
        
        .card-header {
            background: #f8f9fa;
            padding: 20px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .card-title {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .signature-box {
            border: 2px solid #ddd;
            border-radius: 8px;
            background: #fafafa;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .signature-label {
            font-weight: bold;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .signature-canvas {
            border: 2px solid #3498db;
            border-radius: 4px;
            background: white;
            cursor: crosshair;
            display: block;
            margin: 0 auto;
            width: 100%;
            max-width: 500px;
            height: 200px;
        }
        
        .signature-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 15px;
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
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        
        .info-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .info-row {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 20px;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .info-label {
            font-weight: bold;
            color: #2c3e50;
        }
    </style>
</head>
<body>

<div class="page-header">
    <div>
        <h1 style="margin: 0;">Sign Referral Letter</h1>
        <p style="margin: 5px 0 0 0; color: #7f8c8d;">Patient: <?php echo htmlspecialchars($referral['first_name'] . ' ' . $referral['last_name']); ?> - <?php echo htmlspecialchars($referral['referral_code']); ?></p>
    </div>
    <div>
        <a href="referral-letter.php?referral_id=<?php echo $referralId; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Letter
        </a>
    </div>
</div>

<div class="container">
    <!-- Patient and Referral Info -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Referral Information</h3>
        </div>
        <div class="card-body">
            <div class="info-section">
                <div class="info-row">
                    <span class="info-label">Patient:</span>
                    <span><?php echo htmlspecialchars($referral['first_name'] . ' ' . $referral['last_name']); ?> (<?php echo htmlspecialchars($referral['patient_code']); ?>)</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Referral To:</span>
                    <span><?php echo htmlspecialchars($referral['referral_hospital']); ?> — <?php echo htmlspecialchars($referral['referral_department'] ?: 'General'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Reason:</span>
                    <span><?php echo htmlspecialchars($referral['reason_for_referral']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Urgency:</span>
                    <span style="text-transform: uppercase; font-weight: bold; color: #e74c3c;"><?php echo ucfirst($referral['urgency']); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Digital Signature Section -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Doctor's Digital Signature</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="" id="signatureForm">
                <input type="hidden" name="action" value="save_signature">
                <input type="hidden" name="signature_data" id="signature_data">
                
                <div class="signature-box">
                    <div class="signature-label">
                        <i class="fas fa-pen"></i> Please sign in the box below
                    </div>
                    <canvas id="signatureCanvas" class="signature-canvas"></canvas>
                    <div class="signature-actions">
                        <button type="button" class="btn btn-secondary" onclick="clearSignature()">
                            <i class="fas fa-redo"></i> Clear
                        </button>
                    </div>
                </div>
                
                <div style="background: #e8f4f8; padding: 15px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #3498db;">
                    <p style="margin: 0; font-size: 14px; color: #2c3e50;">
                        <strong>Signing Doctor:</strong> <?php echo htmlspecialchars($referral['doctor_name']); ?><br>
                        <strong>Timestamp:</strong> <?php echo date('F d, Y H:i:s'); ?>
                    </p>
                </div>
                
                <div class="btn-group">
                    <a href="referral-letter.php?referral_id=<?php echo $referralId; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="button" class="btn btn-danger" onclick="clearSignature()">
                        <i class="fas fa-trash"></i> Clear Signature
                    </button>
                    <button type="button" class="btn btn-primary" onclick="submitSignature()">
                        <i class="fas fa-check"></i> Save Signature & Print
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const canvas = document.getElementById('signatureCanvas');
const ctx = canvas.getContext('2d');
let isDrawing = false;

// Set canvas size
function resizeCanvas() {
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width;
    canvas.height = rect.height;
}
resizeCanvas();
window.addEventListener('resize', resizeCanvas);

// Drawing functions
canvas.addEventListener('mousedown', (e) => {
    isDrawing = true;
    const rect = canvas.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;
    ctx.beginPath();
    ctx.moveTo(x, y);
});

canvas.addEventListener('mousemove', (e) => {
    if (!isDrawing) return;
    const rect = canvas.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#333';
    ctx.lineTo(x, y);
    ctx.stroke();
});

canvas.addEventListener('mouseup', () => {
    isDrawing = false;
});

canvas.addEventListener('mouseleave', () => {
    isDrawing = false;
});

// Touch support for mobile devices
canvas.addEventListener('touchstart', (e) => {
    e.preventDefault();
    const touch = e.touches[0];
    const rect = canvas.getBoundingClientRect();
    const x = touch.clientX - rect.left;
    const y = touch.clientY - rect.top;
    ctx.beginPath();
    ctx.moveTo(x, y);
});

canvas.addEventListener('touchmove', (e) => {
    e.preventDefault();
    const touch = e.touches[0];
    const rect = canvas.getBoundingClientRect();
    const x = touch.clientX - rect.left;
    const y = touch.clientY - rect.top;
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#333';
    ctx.lineTo(x, y);
    ctx.stroke();
});

canvas.addEventListener('touchend', () => {
    ctx.closePath();
});

function clearSignature() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
}

function submitSignature() {
    // Check if signature is empty
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const data = imageData.data;
    let hasDrawing = false;
    
    for (let i = 0; i < data.length; i += 4) {
        if (data[i + 3] > 128) { // Check alpha channel
            hasDrawing = true;
            break;
        }
    }
    
    if (!hasDrawing) {
        alert('Please sign the document before submitting.');
        return;
    }
    
    // Get signature data
    const signatureData = canvas.toDataURL('image/png');
    document.getElementById('signature_data').value = signatureData;
    
    // Show confirmation
    if (confirm('Save this signature and mark referral as printed?')) {
        document.getElementById('signatureForm').submit();
    }
}
</script>

</body>
</html>
