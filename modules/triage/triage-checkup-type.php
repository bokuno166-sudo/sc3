<?php
/**
 * Select Checkup Type for Pregnant Patients
 * This modal allows staff to specify whether a pregnant patient needs:
 * - Consultation (basic check)
 * - Maternity check-up
 */

require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'staff', 'reception', 'nurse']);

$visitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;
$conn = getDBConnection();

// Get visit and patient info
$visitResult = $conn->query("
    SELECT v.*, p.*, 
           (SELECT COUNT(*) FROM maternity_checkups WHERE patient_id = p.id) as checkup_count
    FROM patient_visits v
    JOIN patients p ON v.patient_id = p.id
    WHERE v.id = $visitId
");

if ($visitResult->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Visit not found']);
    exit;
}

$visit = $visitResult->fetch_assoc();

// Verify patient is pregnant
if (!$visit['is_pregnant']) {
    http_response_code(400);
    echo json_encode(['error' => 'Patient is not marked as pregnant']);
    exit;
}

// Handle AJAX request for type selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkup_type'])) {
    $checkupType = sanitize($_POST['checkup_type']);
    
    // Validate checkup type
    if (!in_array($checkupType, ['consultation', 'maternity'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid checkup type']);
        exit;
    }
    
    // Store checkup type in visit record (we'll add this column)
    $stmt = $conn->prepare("
        UPDATE patient_visits 
        SET checkup_type = ? 
        WHERE id = ?
    ");
    
    if (!$stmt) {
        // Table might not have checkup_type column yet, add it
        $conn->query("ALTER TABLE patient_visits ADD COLUMN IF NOT EXISTS checkup_type VARCHAR(50) NULL");
        $stmt = $conn->prepare("
            UPDATE patient_visits 
            SET checkup_type = ? 
            WHERE id = ?
        ");
    }
    
    $stmt->bind_param("si", $checkupType, $visitId);
    
    if ($stmt->execute()) {
        logActivity('assign', 'visit_checkup_type', $visitId, null, json_encode([
            'checkup_type' => $checkupType,
            'patient_id' => $visit['patient_id']
        ]));
        
        // Return redirect URL based on type
        $redirectUrl = ($checkupType === 'maternity') 
            ? 'modules/maternity/checkup-add.php?visit_id=' . $visitId . '&patient_id=' . $visit['patient_id']
            : 'modules/triage/triage-assess.php?visit_id=' . $visitId;
        
        echo json_encode([
            'success' => true,
            'checkup_type' => $checkupType,
            'redirect_url' => $redirectUrl,
            'message' => 'Checkup type assigned successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error assigning checkup type']);
    }
    
    $stmt->close();
    $conn->close();
    exit;
}

$conn->close();

// If GET request, return HTML for modal
header('Content-Type: text/html; charset=utf-8');
?>

<div class="modal fade" id="pregnantCheckupTypeModal" tabindex="-1" role="dialog" aria-labelledby="pregnantCheckupTypeLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none;">
                <h5 class="modal-title" id="pregnantCheckupTypeLabel">
                    <i class="fas fa-baby"></i> Select Check-up Type
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: white;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="patient-info-in-modal" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <h6 style="margin: 0 0 10px; color: #333;">
                        <?php echo htmlspecialchars($visit['first_name'] . ' ' . $visit['last_name']); ?>
                    </h6>
                    <small class="text-muted">
                        <?php echo htmlspecialchars($visit['patient_code']); ?> | 
                        Weeks: <?php echo intval($visit['weeks_of_pregnancy']); ?> |
                        EDD: <?php echo formatDate($visit['expected_due_date']); ?>
                    </small>
                </div>

                <p style="color: #555; margin-bottom: 25px;">
                    This is a <strong>pregnant patient</strong>. Please select the type of check-up required:
                </p>

                <div class="checkup-type-options">
                    <!-- Consultation Option -->
                    <div class="checkup-option" data-type="consultation" style="
                        border: 2px solid #ddd; 
                        padding: 20px; 
                        margin-bottom: 15px; 
                        border-radius: 8px; 
                        cursor: pointer;
                        transition: all 0.3s ease;
                    " onmouseover="this.style.borderColor='#667eea'; this.style.backgroundColor='#f0f4ff';" 
                       onmouseout="this.style.borderColor='#ddd'; this.style.backgroundColor='white';">
                        <div style="display: flex; align-items: start; gap: 15px;">
                            <div style="font-size: 32px; color: #667eea;">
                                <i class="fas fa-stethoscope"></i>
                            </div>
                            <div style="flex: 1;">
                                <h6 style="margin: 0 0 5px; color: #333; font-weight: 600;">
                                    General Consultation (Basic Check)
                                </h6>
                                <p style="margin: 0; font-size: 13px; color: #666; line-height: 1.5;">
                                    Routine assessment of vital signs, general health status, and basic complaint evaluation. Follow standard triage procedures.
                                </p>
                                <div style="margin-top: 10px; font-size: 12px; color: #888;">
                                    <i class="fas fa-check-circle"></i> Blood Pressure, Heart Rate, Temperature
                                    <br><i class="fas fa-check-circle"></i> Pain Assessment
                                    <br><i class="fas fa-check-circle"></i> General Symptoms Review
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Maternity Check-up Option -->
                    <div class="checkup-option" data-type="maternity" style="
                        border: 2px solid #ddd; 
                        padding: 20px; 
                        margin-bottom: 15px; 
                        border-radius: 8px; 
                        cursor: pointer;
                        transition: all 0.3s ease;
                    " onmouseover="this.style.borderColor='#764ba2'; this.style.backgroundColor='#faf5ff';" 
                       onmouseout="this.style.borderColor='#ddd'; this.style.backgroundColor='white';">
                        <div style="display: flex; align-items: start; gap: 15px;">
                            <div style="font-size: 32px; color: #764ba2;">
                                <i class="fas fa-heart"></i>
                            </div>
                            <div style="flex: 1;">
                                <h6 style="margin: 0 0 5px; color: #333; font-weight: 600;">
                                    Maternity Check-up (Prenatal)
                                </h6>
                                <p style="margin: 0; font-size: 13px; color: #666; line-height: 1.5;">
                                    Specialized prenatal assessment focusing on pregnancy-related health status and fetal development monitoring.
                                </p>
                                <div style="margin-top: 10px; font-size: 12px; color: #888;">
                                    <i class="fas fa-check-circle"></i> Fetal Heartbeat Monitoring
                                    <br><i class="fas fa-check-circle"></i> Fundal Height Measurement
                                    <br><i class="fas fa-check-circle"></i> Pregnancy-Specific Assessment
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="background: #f8f9fa; border-top: 1px solid #ddd;">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" id="confirmCheckupTypeBtn" disabled style="min-width: 150px;">
                    <i class="fas fa-arrow-right"></i> Proceed
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    .checkup-option.selected {
        border-color: #667eea !important;
        background-color: #f0f4ff !important;
        box-shadow: 0 0 10px rgba(102, 126, 234, 0.2);
    }
    
    .checkup-option.maternity.selected {
        border-color: #764ba2 !important;
        background-color: #faf5ff !important;
        box-shadow: 0 0 10px rgba(118, 75, 162, 0.2);
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const checkupOptions = document.querySelectorAll('.checkup-option');
    const confirmBtn = document.getElementById('confirmCheckupTypeBtn');
    let selectedType = null;

    checkupOptions.forEach(option => {
        option.addEventListener('click', function() {
            // Remove selected class from all options
            checkupOptions.forEach(opt => opt.classList.remove('selected'));
            
            // Add selected class to clicked option
            this.classList.add('selected');
            selectedType = this.dataset.type;
            
            // Enable confirm button
            confirmBtn.disabled = false;
            confirmBtn.style.opacity = '1';
        });
    });

    confirmBtn.addEventListener('click', function() {
        if (!selectedType) return;
        
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        
        // Send AJAX request
        fetch('triage-checkup-type.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'checkup_type=' + encodeURIComponent(selectedType) + '&visit_id=<?php echo $visitId; ?>'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Redirect to appropriate page
                window.location.href = data.redirect_url;
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="fas fa-arrow-right"></i> Proceed';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fas fa-arrow-right"></i> Proceed';
        });
    });
});
</script>
