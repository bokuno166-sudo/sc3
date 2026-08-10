<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'doctor']);

$pageTitle = 'Create Referral';
$currentPage = 'consultations';

$conn = getDBConnection();

// Get consultation ID from query or post
$consultationId = isset($_GET['consultation_id']) ? (int)$_GET['consultation_id'] : (isset($_POST['consultation_id']) ? (int)$_POST['consultation_id'] : 0);

if (!$consultationId) {
    setFlashMessage('error', 'Consultation ID missing.');
    redirect('modules/consultation/consultations.php');
}

// Fetch consultation details with patient and doctor info
$stmt = $conn->prepare(
    "SELECT c.*, p.first_name, p.last_name, p.patient_code, p.date_of_birth, p.gender, p.blood_type, 
            p.allergies, p.medical_history, v.queue_number, v.visit_date,
            u.full_name AS doctor_name, u.id AS doctor_id
     FROM consultations c
     LEFT JOIN patients p ON c.patient_id = p.id
     LEFT JOIN patient_visits v ON c.visit_id = v.id
     LEFT JOIN users u ON c.doctor_id = u.id
     WHERE c.id = ? LIMIT 1"
);
$stmt->bind_param('i', $consultationId);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Consultation not found.');
    redirect('modules/consultation/consultations.php');
}
$consult = $res->fetch_assoc();
$stmt->close();

$patientId = $consult['patient_id'];
$visitId = $consult['visit_id'];
$doctorId = $consult['doctor_id'];

// Handle referral form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_referral') {
    $referralHospital = trim($_POST['referral_hospital'] ?? '');
    $referralDepartment = trim($_POST['referral_department'] ?? '');
    $reasonForReferral = trim($_POST['reason_for_referral'] ?? '');
    $clinicalSummary = trim($_POST['clinical_summary'] ?? '');
    $relevantInvestigations = trim($_POST['relevant_investigations'] ?? '');
    $recommendations = trim($_POST['recommendations'] ?? '');
    $urgency = trim($_POST['urgency'] ?? 'routine');
    $followUpDate = !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;
    $followUpInstructions = trim($_POST['follow_up_instructions'] ?? '');
    
    // Validate required fields
    if (empty($referralHospital) || empty($reasonForReferral)) {
        setFlashMessage('error', 'Hospital name and reason for referral are required.');
    } else {
        // Generate referral code
        $referralCode = generateCode('REF', $consultationId);
        
        // Insert referral record
        $insertStmt = $conn->prepare(
            "INSERT INTO referrals (referral_code, consultation_id, patient_id, doctor_id, visit_id, 
                                    referral_hospital, referral_department, reason_for_referral, 
                                    clinical_summary, relevant_investigations, recommendations, urgency, 
                                    follow_up_date, follow_up_instructions, status) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        );
        
        if ($insertStmt) {
            $insertStmt->bind_param(
                'siiiissssssiss',
                $referralCode, $consultationId, $patientId, $doctorId, $visitId,
                $referralHospital, $referralDepartment, $reasonForReferral,
                $clinicalSummary, $relevantInvestigations, $recommendations,
                $urgency, $followUpDate, $followUpInstructions
            );
            
            if ($insertStmt->execute()) {
                $referralId = $conn->insert_id;
                $insertStmt->close();
                
                // Update consultation outcome to 'referral' if not already set
                $updateConsult = $conn->prepare("UPDATE consultations SET outcome = 'referral' WHERE id = ? AND outcome IS NULL");
                if ($updateConsult) {
                    $updateConsult->bind_param('i', $consultationId);
                    $updateConsult->execute();
                    $updateConsult->close();
                }
                
                // Update visit status
                $visitUpdate = $conn->prepare("UPDATE patient_visits SET status = 'discharged' WHERE id = ?");
                if ($visitUpdate) {
                    $visitUpdate->bind_param('i', $visitId);
                    $visitUpdate->execute();
                    $visitUpdate->close();
                }
                
                setFlashMessage('success', 'Referral created successfully! Redirecting to referral letter...');
                redirect('modules/consultation/referral-letter.php?referral_id=' . $referralId);
            } else {
                setFlashMessage('error', 'Error creating referral: ' . $insertStmt->error);
                $insertStmt->close();
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Create Referral Letter</h1>
        <p class="page-subtitle">Patient: <?php echo htmlspecialchars($consult['first_name'] . ' ' . $consult['last_name']); ?> (<?php echo htmlspecialchars($consult['patient_code']); ?>)</p>
    </div>
    <a href="consultations.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<div class="card">
    <div class="card-body">
        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <h5 style="margin-top: 0;">Patient Summary</h5>
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; font-size: 14px;">
                <div><strong>Name:</strong> <?php echo htmlspecialchars($consult['first_name'] . ' ' . $consult['last_name']); ?></div>
                <div><strong>Patient Code:</strong> <?php echo htmlspecialchars($consult['patient_code']); ?></div>
                <div><strong>Age/Gender:</strong> <?php echo calculateAge($consult['date_of_birth']); ?> / <?php echo htmlspecialchars($consult['gender']); ?></div>
                <div><strong>Blood Type:</strong> <?php echo htmlspecialchars($consult['blood_type']); ?></div>
                <div><strong>Allergies:</strong> <?php echo htmlspecialchars($consult['allergies'] ?: 'None recorded'); ?></div>
                <div><strong>Consulting Doctor:</strong> <?php echo htmlspecialchars($consult['doctor_name']); ?></div>
            </div>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="action" value="create_referral">
            <input type="hidden" name="consultation_id" value="<?php echo $consultationId; ?>">

            <div class="form-group">
                <label class="form-label">Referring Hospital/Facility <span style="color: red;">*</span></label>
                <input type="text" name="referral_hospital" class="form-control" placeholder="Name of the hospital or facility" required>
            </div>

            <div class="form-group">
                <label class="form-label">Department/Specialty</label>
                <select name="referral_department" class="form-control">
                    <option value="">-- Select Department --</option>
                    <option value="Cardiology">Cardiology</option>
                    <option value="Neurology">Neurology</option>
                    <option value="Orthopedics">Orthopedics</option>
                    <option value="Gastroenterology">Gastroenterology</option>
                    <option value="Pulmonology">Pulmonology</option>
                    <option value="Nephrology">Nephrology</option>
                    <option value="Oncology">Oncology</option>
                    <option value="Endocrinology">Endocrinology</option>
                    <option value="General Surgery">General Surgery</option>
                    <option value="Pediatrics">Pediatrics</option>
                    <option value="Obstetrics/Gynecology">Obstetrics/Gynecology</option>
                    <option value="Psychiatry">Psychiatry</option>
                    <option value="Other">Other (Specify in reason)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Reason for Referral <span style="color: red;">*</span></label>
                <textarea name="reason_for_referral" class="form-control" rows="3" placeholder="Explain why the patient is being referred..." required></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Clinical Summary & Diagnosis</label>
                <textarea name="clinical_summary" class="form-control" rows="4" placeholder="Summarize the patient's clinical condition and diagnosis...">Diagnosis: <?php echo htmlspecialchars($consult['diagnosis'] ?? ''); ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Relevant Investigations/Results</label>
                <textarea name="relevant_investigations" class="form-control" rows="3" placeholder="List relevant lab results, imaging, or other investigations..."></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Recommendations/Treatment Plan</label>
                <textarea name="recommendations" class="form-control" rows="3" placeholder="Describe the recommended investigations or treatment...">Treatment Plan: <?php echo htmlspecialchars($consult['treatment_plan'] ?? ''); ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Urgency Level <span style="color: red;">*</span></label>
                <select name="urgency" class="form-control" required>
                    <option value="routine">Routine (within 2-4 weeks)</option>
                    <option value="urgent">Urgent (within 1-2 weeks)</option>
                    <option value="emergency">Emergency (within 24-48 hours)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Follow-up Date</label>
                <input type="date" name="follow_up_date" class="form-control">
            </div>

            <div class="form-group">
                <label class="form-label">Follow-up Instructions</label>
                <textarea name="follow_up_instructions" class="form-control" rows="2" placeholder="Any special instructions for follow-up care..."></textarea>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <a href="consultations.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-envelope"></i> Create & Generate Letter
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$conn->close();
include __DIR__ . '/../../includes/footer.php';
?>
