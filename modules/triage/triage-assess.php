<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse']);

$pageTitle = 'Patient Assessment';
$currentPage = 'triage';

$conn = getDBConnection();

// Get visit details
$visitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;
$visitResult = $conn->query("
    SELECT v.*, p.*
    FROM patient_visits v
    JOIN patients p ON v.patient_id = p.id
    WHERE v.id = $visitId
");

if ($visitResult->num_rows === 0) {
    setFlashMessage('error', 'Visit not found.');
    redirect('modules/triage/triage.php');
}

$visit = $visitResult->fetch_assoc();

// Check if triage already exists
$existingTriage = $conn->query("SELECT * FROM triage_records WHERE visit_id = $visitId");
$triageData = $existingTriage->num_rows > 0 ? $existingTriage->fetch_assoc() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bloodPressure = sanitize($_POST['blood_pressure']);
    $heartRate = (int)$_POST['heart_rate'];
    $temperature = (float)$_POST['temperature'];
    $respiratoryRate = !empty($_POST['respiratory_rate']) ? (int)$_POST['respiratory_rate'] : null;
    $oxygenSaturation = !empty($_POST['oxygen_saturation']) ? (int)$_POST['oxygen_saturation'] : null;
    // weight (kg) and height (cm) from staff input
    $weight = isset($_POST['weight']) ? (float)$_POST['weight'] : null;
    $height = isset($_POST['height']) ? (float)$_POST['height'] : null;
    $painScale = (int)$_POST['pain_scale'];
    $symptoms = sanitize($_POST['symptoms']);
    $medicalHistoryNotes = sanitize($_POST['medical_history_notes']);
    $familyMedicalHistory = sanitize($_POST['family_medical_history'] ?? '');
    
    // For pregnant patients
    $fetalHeartbeat = !empty($_POST['fetal_heartbeat']) ? (int)$_POST['fetal_heartbeat'] : null;
    $weeksOfPregnancy = !empty($_POST['weeks_of_pregnancy']) ? (int)$_POST['weeks_of_pregnancy'] : null;
    $contractions = sanitize($_POST['contractions']);
    $cervixDilation = !empty($_POST['cervix_dilation']) ? (float)$_POST['cervix_dilation'] : null;
    
    $notes = sanitize($_POST['notes']);

    $bmi = null;
    if ($weight && $height) {
        $height_m = $height / 100.0;
        if ($height_m > 0) {
            $bmi = round($weight / ($height_m * $height_m), 1);
        }
    }

    // Ensure triage_records table has weight, height, and bmi columns
    $col = $conn->query("SHOW COLUMNS FROM triage_records LIKE 'weight'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE triage_records ADD COLUMN weight DECIMAL(6,2) NULL");
    }
    $col = $conn->query("SHOW COLUMNS FROM triage_records LIKE 'height'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE triage_records ADD COLUMN height DECIMAL(6,2) NULL");
    }
    $col = $conn->query("SHOW COLUMNS FROM triage_records LIKE 'bmi'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE triage_records ADD COLUMN bmi DECIMAL(5,1) NULL");
    }

    // Ensure patients table has weight and height columns so we can sync
    $col = $conn->query("SHOW COLUMNS FROM patients LIKE 'weight'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE patients ADD COLUMN weight DECIMAL(6,2) NULL");
    }
    $col = $conn->query("SHOW COLUMNS FROM patients LIKE 'height'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE patients ADD COLUMN height DECIMAL(6,2) NULL");
    }
    
    if ($triageData) {
        // Update existing triage (include respiratory rate, oxygen saturation, weight, height, bmi)
        $stmt = $conn->prepare("UPDATE triage_records SET blood_pressure = ?, heart_rate = ?, temperature = ?, respiratory_rate = ?, oxygen_saturation = ?, pain_scale = ?, symptoms = ?, medical_history_notes = ?, fetal_heartbeat = ?, weeks_of_pregnancy = ?, contractions = ?, cervix_dilation = ?, weight = ?, height = ?, bmi = ?, notes = ? WHERE id = ?");
        // bind as strings to simplify types (MySQL will coerce as needed)
        $stmt->bind_param(str_repeat('s', 17), $bloodPressure, $heartRate, $temperature, $respiratoryRate, $oxygenSaturation, $painScale,
            $symptoms, $medicalHistoryNotes, $fetalHeartbeat, $weeksOfPregnancy, $contractions, $cervixDilation, $weight, $height, $bmi, $notes, $triageData['id']);
    } else {
        // Insert new triage (include respiratory rate, oxygen saturation, weight, height, bmi)
        $stmt = $conn->prepare("INSERT INTO triage_records (visit_id, patient_id, nurse_id, blood_pressure, heart_rate, temperature, respiratory_rate, oxygen_saturation, pain_scale, symptoms, medical_history_notes, fetal_heartbeat, weeks_of_pregnancy, contractions, cervix_dilation, weight, height, bmi, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(str_repeat('s', 19), $visitId, $visit['patient_id'], $_SESSION['user_id'],
            $bloodPressure, $heartRate, $temperature, $respiratoryRate, $oxygenSaturation, $painScale, $symptoms, $medicalHistoryNotes, $fetalHeartbeat,
            $weeksOfPregnancy, $contractions, $cervixDilation, $weight, $height, $bmi, $notes);
    }
    
    if ($stmt->execute()) {
        // Sync assessment values back to patients table so patient profile shows the latest record
        if ($weight !== null || $height !== null || $familyMedicalHistory !== '') {
            $upd = $conn->prepare("UPDATE patients SET weight = ?, height = ?, family_medical_history = ? WHERE id = ?");
            if ($upd) {
                $pid = (int)$visit['patient_id'];
                $upd->bind_param('ddsi', $weight, $height, $familyMedicalHistory, $pid);
                $upd->execute();
                $upd->close();
            }
        }

        // Update visit status to in-consultation so patient is removed from waiting-for-assessment list
        $conn->query("UPDATE patient_visits SET status = 'in-consultation' WHERE id = " . intval($visitId));

        logActivity($triageData ? 'update' : 'create', 'triage_records', $stmt->insert_id ?: $triageData['id']);
        setFlashMessage('success', 'Assessment saved successfully!');
        redirect('modules/triage/triage.php');
    } else {
        setFlashMessage('error', 'Error saving assessment: ' . $stmt->error);
    }
    
    $stmt->close();
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Patient Assessment</h1>
        <p class="page-subtitle">Patient: <?php echo $visit['first_name'] . ' ' . $visit['last_name']; ?></p>
    </div>
    <a href="triage.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<!-- Patient Info Card -->
<div class="patient-info-card">
    <div class="patient-info-header">
        <h2><?php echo $visit['first_name'] . ' ' . $visit['last_name']; ?></h2>
        <span class="patient-code"><?php echo $visit['patient_code']; ?></span>
    </div>
    <div class="patient-info-grid">
        <div class="patient-info-item">
            <label>Queue Number</label>
            <span><?php echo $visit['queue_number']; ?></span>
        </div>
        <div class="patient-info-item">
            <label>Age</label>
            <span><?php echo calculateAge($visit['date_of_birth']); ?> years old</span>
        </div>
        <div class="patient-info-item">
            <label>Gender</label>
            <span><?php echo $visit['gender']; ?></span>
        </div>
        <div class="patient-info-item">
            <label>Blood Type</label>
            <span><?php echo $visit['blood_type']; ?></span>
        </div>
        <?php if ($visit['is_pregnant']): ?>
        <div class="patient-info-item" style="background: rgba(255,193,7,0.3);">
            <label><i class="fas fa-baby"></i> Pregnant</label>
            <span><?php echo $visit['weeks_of_pregnancy'] ? $visit['weeks_of_pregnancy'] . ' weeks' : 'Pregnancy confirmed'; ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<form method="POST" action="">
    <!-- Vital Signs -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-heartbeat"></i> Vital Signs</h3>
        </div>
        <div class="card-body">
            <div class="vitals-grid">
                <div class="vital-card">
                    <div class="vital-icon"><i class="fas fa-tachometer-alt"></i></div>
                    <div class="vital-value">
                        <input type="text" name="blood_pressure" class="form-control" placeholder="120/80" 
                               value="<?php echo $triageData ? $triageData['blood_pressure'] : ''; ?>" style="text-align: center;">
                    </div>
                    <div class="vital-label">Blood Pressure (mmHg)</div>
                </div>
                
                <div class="vital-card">
                    <div class="vital-icon"><i class="fas fa-heart"></i></div>
                    <div class="vital-value">
                        <input type="number" name="heart_rate" class="form-control" placeholder="72" 
                               value="<?php echo $triageData ? $triageData['heart_rate'] : ''; ?>" style="text-align: center;">
                    </div>
                    <div class="vital-label">Heart Rate (bpm)</div>
                </div>
                
                <div class="vital-card">
                    <div class="vital-icon"><i class="fas fa-thermometer-half"></i></div>
                    <div class="vital-value">
                        <input type="number" step="0.1" name="temperature" class="form-control" placeholder="37.0" 
                               value="<?php echo $triageData ? $triageData['temperature'] : ''; ?>" style="text-align: center;">
                    </div>
                    <div class="vital-label">Temperature (°C)</div>
                </div>

                <div class="vital-card">
                    <div class="vital-icon"><i class="fas fa-lungs"></i></div>
                    <div class="vital-value">
                        <input type="number" name="respiratory_rate" class="form-control" placeholder="18" 
                               value="<?php echo $triageData ? $triageData['respiratory_rate'] : ''; ?>" style="text-align: center;">
                    </div>
                    <div class="vital-label">Respiratory Rate (breaths/min)</div>
                </div>

                <div class="vital-card">
                    <div class="vital-icon"><i class="fas fa-wind"></i></div>
                    <div class="vital-value">
                        <input type="number" name="oxygen_saturation" class="form-control" placeholder="98" 
                               value="<?php echo $triageData ? $triageData['oxygen_saturation'] : ''; ?>" style="text-align: center;">
                    </div>
                    <div class="vital-label">Oxygen Saturation (%)</div>
                </div>
                
                <div class="vital-card">
                    <div class="vital-icon"><i class="fas fa-weight"></i></div>
                    <div class="vital-value">
                           <input type="number" step="0.1" name="weight" id="weight" class="form-control" placeholder="70.0" 
                               value="<?php echo isset($triageData['weight']) ? $triageData['weight'] : (isset($visit['weight']) ? $visit['weight'] : ''); ?>" style="text-align: center;">
                    </div>
                    <div class="vital-label">Weight (kg)</div>
                </div>

                <div class="vital-card">
                    <div class="vital-icon"><i class="fas fa-ruler-vertical"></i></div>
                    <div class="vital-value">
                           <input type="number" step="0.1" name="height" id="height" class="form-control" placeholder="170.0" 
                               value="<?php echo isset($triageData['height']) ? $triageData['height'] : (isset($visit['height']) ? $visit['height'] : ''); ?>" style="text-align: center;">
                    </div>
                    <div class="vital-label">Height (cm)</div>
                </div>

                <div class="vital-card">
                    <div class="vital-icon"><i class="fas fa-calculator"></i></div>
                    <div class="vital-value">
                           <input type="text" name="bmi" id="bmi" class="form-control" readonly placeholder="BMI" 
                               value="<?php echo isset($triageData['bmi']) ? $triageData['bmi'] : ''; ?>" style="text-align: center;">
                    </div>
                    <div class="vital-label">BMI</div>
                </div>
            </div>
            
            <div class="form-row" style="margin-top: 20px;">
                <div class="form-group">
                    <label class="form-label">Pain Scale (0-10)</label>
                          <input id="pain_scale" type="range" name="pain_scale" min="0" max="10" class="form-control" 
                              value="<?php echo $triageData ? $triageData['pain_scale'] : '0'; ?>">
                          <span id="pain_scale_value" style="margin-left:12px; font-weight:600; color:#222;"><?php echo $triageData ? htmlspecialchars($triageData['pain_scale']) : '0'; ?></span>
                    <div style="margin-top:8px; font-size:14px; color:#333;">
                        <strong>Chief Complaint:</strong>
                        <span id="chief_complaint_display" style="margin-left:8px; color:#555;"><?php echo $triageData ? htmlspecialchars($triageData['symptoms'] ?: $visit['chief_complaint']) : htmlspecialchars($visit['chief_complaint'] ?? ''); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 12px; color: #666;">
                        <span>No Pain (0)</span>
                        <span>Moderate (5)</span>
                        <span>Severe (10)</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Assessment -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-clipboard-list"></i> Assessment</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Symptoms / Chief Complaint Details</label>
                    <select id="symptom_choice" class="form-control">
                        <option value="">-- Select symptom --</option>
                        <option value="Fever">Fever</option>
                        <option value="Cough">Cough</option>
                        <option value="Abdominal pain">Abdominal pain</option>
                        <option value="Headache">Headache</option>
                        <option value="Injury">Injury</option>
                        <option value="Other">Other (specify)</option>
                    </select>
                    <textarea name="symptoms" id="symptoms" class="form-control" rows="3" placeholder="Detailed description of symptoms..."><?php echo $triageData ? $triageData['symptoms'] : ''; ?></textarea>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Medical History Notes</label>
                    <select id="history_choice" class="form-control">
                        <option value="">-- Select medical history --</option>
                        <option value="None">None</option>
                        <option value="Hypertension">Hypertension</option>
                        <option value="Diabetes">Diabetes</option>
                        <option value="Asthma">Asthma</option>
                        <option value="Heart disease">Heart disease</option>
                        <option value="Allergies">Allergies</option>
                        <option value="Surgery">Surgery</option>
                        <option value="Other">Other (specify)</option>
                    </select>
                    <textarea name="medical_history_notes" id="medical_history_notes" class="form-control" rows="2" placeholder="Relevant medical history..."><?php echo $triageData ? $triageData['medical_history_notes'] : ''; ?></textarea>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Family Medical History</label>
                    <textarea name="family_medical_history" class="form-control" rows="3" placeholder="e.g. Family history of Diabetes, Heart Disease, Hypertension — or type N/A if none"><?php echo htmlspecialchars($visit['family_medical_history'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <?php if ($visit['is_pregnant']): ?>
    <!-- Pregnancy Assessment (for pregnant patients) -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-baby"></i> Obstetric Assessment</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Fetal Heartbeat (bpm)</label>
                    <input type="number" name="fetal_heartbeat" class="form-control" 
                           value="<?php echo $triageData ? $triageData['fetal_heartbeat'] : ''; ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Weeks of Pregnancy</label>
                    <input type="number" name="weeks_of_pregnancy" class="form-control" 
                           value="<?php echo $triageData ? $triageData['weeks_of_pregnancy'] : $visit['weeks_of_pregnancy']; ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Contractions</label>
                    <input type="text" name="contractions" class="form-control" placeholder="e.g., Every 5 mins"
                           value="<?php echo $triageData ? $triageData['contractions'] : ''; ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Cervix Dilation (cm)</label>
                    <input type="number" step="0.5" name="cervix_dilation" class="form-control" 
                           value="<?php echo $triageData ? $triageData['cervix_dilation'] : ''; ?>">
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Additional Notes -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-notes-medical"></i> Additional Notes</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group" style="grid-column: span 2;">
                    <textarea name="notes" class="form-control" rows="3" placeholder="Any additional observations or notes..."><?php echo $triageData ? $triageData['notes'] : ''; ?></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <div class="form-group" style="text-align: right;">
        <a href="triage.php" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Save Assessment
        </button>
    </div>
</form>

<script>
function calculateBMI() {
    var w = parseFloat(document.getElementById('weight').value) || 0;
    var h = parseFloat(document.getElementById('height').value) || 0;
    var bmiField = document.getElementById('bmi');
    if (w > 0 && h > 0) {
        var hm = h / 100.0;
        var bmi = w / (hm * hm);
        bmiField.value = Math.round(bmi * 10) / 10; // one decimal
    } else {
        bmiField.value = '';
    }
}

document.getElementById('weight').addEventListener('input', calculateBMI);
document.getElementById('height').addEventListener('input', calculateBMI);

// calculate initially if values are present
calculateBMI();

// Live update numeric pain scale value
(function(){
    var pain = document.getElementById('pain_scale');
    var painVal = document.getElementById('pain_scale_value');
    if (!pain || !painVal) return;
    function upd() { painVal.textContent = pain.value; }
    pain.addEventListener('input', upd);
    // initialize
    upd();
})();

// Symptoms dropdown + custom text handling
(function() {
    var symptomChoice = document.getElementById('symptom_choice');
    var symptomsField = document.getElementById('symptoms');
    var existing = <?php echo json_encode($triageData ? $triageData['symptoms'] : ($visit['chief_complaint'] ?? '')); ?>;

    function sync() {
        var val = symptomChoice.value;
        if (val === 'Other') {
            // show editable textarea for custom input
            symptomsField.style.display = '';
            symptomsField.readOnly = false;
            // if there's an existing custom value, keep it
            if (!symptomsField.value && existing) {
                symptomsField.value = existing;
            }
        } else if (val === '') {
            // no selection: hide textarea and clear only if no existing custom
            symptomsField.style.display = 'none';
            symptomsField.readOnly = false;
            if (!existing) symptomsField.value = '';
            if (val !== '' && val !== 'Other') symptomsField.value = val;
        } else {
            // predefined choice selected: hide textarea but set its value so POST contains the choice
            symptomsField.style.display = 'none';
            symptomsField.value = val;
            symptomsField.readOnly = true;
        }
    }

    // Initialize select based on existing triage value
    if (existing && ["Fever","Cough","Abdominal pain","Headache","Injury"].indexOf(existing) !== -1) {
        symptomChoice.value = existing;
    } else if (existing && existing !== '') {
        symptomChoice.value = 'Other';
    } else {
        symptomChoice.value = '';
    }

    symptomChoice.addEventListener('change', sync);
    // run initial sync
    sync();
    // Update chief complaint display when symptoms change or textarea edited
    function updateChiefComplaintDisplay() {
        var disp = document.getElementById('chief_complaint_display');
        if (!disp) return;
        var val = symptomChoice.value;
        if (val === 'Other') {
            disp.textContent = symptomsField.value || '(Other)';
        } else if (val === '') {
            disp.textContent = symptomsField.value || '(None)';
        } else {
            disp.textContent = val;
        }
    }
    symptomChoice.addEventListener('change', updateChiefComplaintDisplay);
    symptomsField.addEventListener('input', updateChiefComplaintDisplay);
    updateChiefComplaintDisplay();
})();

// Medical history dropdown + custom text handling
(function() {
    var historyChoice = document.getElementById('history_choice');
    var historyField = document.getElementById('medical_history_notes');
    var existingHistory = <?php echo json_encode($triageData ? $triageData['medical_history_notes'] : ''); ?>;

    function syncHistory() {
        var val = historyChoice.value;
        if (val === 'Other') {
            historyField.style.display = '';
            historyField.readOnly = false;
            if (!historyField.value && existingHistory) historyField.value = existingHistory;
        } else if (val === '') {
            historyField.style.display = 'none';
            if (!existingHistory) historyField.value = '';
        } else {
            // predefined selected: hide textarea but set value so POST contains it
            historyField.style.display = 'none';
            historyField.value = val;
            historyField.readOnly = true;
        }
    }

    if (existingHistory && ["None","Hypertension","Diabetes","Asthma","Heart disease","Allergies","Surgery"].indexOf(existingHistory) !== -1) {
        historyChoice.value = existingHistory;
    } else if (existingHistory && existingHistory !== '') {
        historyChoice.value = 'Other';
    } else {
        historyChoice.value = '';
    }

    historyChoice.addEventListener('change', syncHistory);
    syncHistory();
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
