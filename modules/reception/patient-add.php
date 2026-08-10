<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'staff', 'reception']);

$pageTitle = 'Add New Patient';
$currentPage = 'patients';

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    $firstName = sanitize($_POST['first_name']);
    $lastName = sanitize($_POST['last_name']);
    $middleName = sanitize($_POST['middle_name']);
    $dateOfBirth = sanitize($_POST['date_of_birth']);
    $gender = sanitize($_POST['gender']);
    $civilStatus = sanitize($_POST['civil_status']);
    // Ensure patients.civil_status accepts all form options; add/modify ENUM if needed
    $desiredStatuses = ['Single','Married','Divorced','Separated','Widowed','Annulled','Cohabiting','Unknown'];
    $col = $conn->query("SHOW COLUMNS FROM patients LIKE 'civil_status'");
    if ($col && $col->num_rows > 0) {
        $row = $col->fetch_assoc();
        $type = $row['Type'] ?? '';
        if (preg_match('/^enum\((.*)\)$/i', $type, $m)) {
            $existing = str_getcsv($m[1], ',', "'");
        } else {
            $existing = [];
        }
        $missing = array_diff($desiredStatuses, $existing);
        if (!empty($missing)) {
            $all = array_values(array_unique(array_merge($existing, $desiredStatuses)));
            $escaped = array_map(function($v) use ($conn) { return $conn->real_escape_string($v); }, $all);
            $enumList = implode("','", $escaped);
            $conn->query("ALTER TABLE patients MODIFY COLUMN civil_status ENUM('" . $enumList . "') DEFAULT 'Single'");
        }
    } else {
        // column missing: add with desired values
        $escaped = array_map(function($v) use ($conn) { return $conn->real_escape_string($v); }, $desiredStatuses);
        $enumList = implode("','", $escaped);
        $conn->query("ALTER TABLE patients ADD COLUMN civil_status ENUM('" . $enumList . "') DEFAULT 'Single'");
    }
    // Address components (cascading selects)
    $address_municipality = sanitize($_POST['address_municipality'] ?? '');
    $address_barangay = sanitize($_POST['address_barangay'] ?? '');
    $address_street = sanitize($_POST['address_street'] ?? '');
    $address_full = sanitize($_POST['address_full'] ?? '');
    // Store as a single address string: "Street, Barangay, Municipality"
    if ($address_municipality === 'Other' && $address_full !== '') {
        $address = $address_full;
    } else {
        $address = trim(($address_street ? $address_street . ', ' : '') . ($address_barangay ? $address_barangay . ', ' : '') . $address_municipality);
    }
    $contactNumber = sanitize($_POST['contact_number']);
    $email = sanitize($_POST['email']);
    $emergencyContactName = '';
    $emergencyContactNumber = '';
    $bloodType = sanitize($_POST['blood_type']);
    // Height (cm) and Weight (kg)
    $height = sanitize($_POST['height'] ?? '');
    $weight = sanitize($_POST['weight'] ?? '');
    // Allergies: dropdown with "Other" free text
    $allergies_select = $_POST['allergies_select'] ?? '';
    $allergies_other = isset($_POST['allergies_other']) ? sanitize($_POST['allergies_other']) : '';
    if ($allergies_select === 'Other') {
        $allergies = $allergies_other;
    } elseif ($allergies_select === 'None' || $allergies_select === '') {
        $allergies = '';
    } else {
        $allergies = sanitize($allergies_select);
    }
    $medicalHistory = sanitize($_POST['medical_history']);
    $familyMedicalHistory = sanitize($_POST['family_medical_history'] ?? '');
    $isPregnant = isset($_POST['is_pregnant']) && $_POST['is_pregnant'] == '1' ? 1 : 0;
    // Ensure male patients cannot be marked as pregnant
    if (strtolower($gender) === 'male') {
        $isPregnant = 0;
    }
    $weeksOfPregnancy = sanitize($_POST['weeks_of_pregnancy'] ?? '');
    $expectedDueDate = sanitize($_POST['expected_due_date'] ?? '');
    
    // Calculate age
    $age = calculateAge($dateOfBirth);

    // Validate contact numbers (must be 11 digits and start with 09)
    $errors = [];
    if ($contactNumber !== '' && !preg_match('/^09\d{9}$/', $contactNumber)) {
        $errors[] = 'Contact number must be 11 digits and start with 09 (e.g., 09123456789).';
    }

    // Validate names: must contain at least one letter and must not contain digits
    $nameNoDigitsRegex = '/^[^\d]+$/u';
    $containsLetterRegex = '/\p{L}/u';
    if ($firstName === '' || !preg_match($nameNoDigitsRegex, $firstName) || !preg_match($containsLetterRegex, $firstName)) {
        $errors[] = 'First name is required and must contain letters only (no numbers).';
    }
    if ($lastName === '' || !preg_match($nameNoDigitsRegex, $lastName) || !preg_match($containsLetterRegex, $lastName)) {
        $errors[] = 'Last name is required and must contain letters only (no numbers).';
    }
    if ($middleName !== '' && (!preg_match($nameNoDigitsRegex, $middleName) || !preg_match($containsLetterRegex, $middleName))) {
        $errors[] = 'Middle name must not contain numbers.';
    }

    // Validate required fields
    if ($dateOfBirth === '' || $dateOfBirth === null) {
        $errors[] = 'Date of birth is required.';
    }
    if ($gender === '' || $gender === null) {
        $errors[] = 'Gender is required.';
    }
    if ($civilStatus === '' || $civilStatus === null) {
        $errors[] = 'Civil status is required.';
    }
    if ($bloodType === '' || $bloodType === null) {
        $errors[] = 'Blood type is required.';
    }
    if ($contactNumber === '' || $contactNumber === null) {
        $errors[] = 'Contact number is required.';
    }
    if ($address_municipality === '' || $address_municipality === null) {
        $errors[] = 'Address (municipality) is required.';
    }
    if ($height === '' || $height === null || !is_numeric($height)) {
        $errors[] = 'Height is required.';
    }
    if ($weight === '' || $weight === null || !is_numeric($weight)) {
        $errors[] = 'Weight is required.';
    }

    if (empty($errors)) {
        // Check for duplicate patient: same first, middle, last and date_of_birth
        $dupCheckSql = "SELECT id FROM patients WHERE LOWER(TRIM(first_name)) = ? AND LOWER(TRIM(middle_name)) = ? AND LOWER(TRIM(last_name)) = ? AND date_of_birth = ? LIMIT 1";
        $dupStmt = $conn->prepare($dupCheckSql);
        if ($dupStmt) {
            $fn = mb_strtolower(trim($firstName));
            $mn = mb_strtolower(trim($middleName));
            $ln = mb_strtolower(trim($lastName));
            $dupStmt->bind_param('ssss', $fn, $mn, $ln, $dateOfBirth);
            $dupStmt->execute();
            $dupRes = $dupStmt->get_result();
            $dupStmt->close();

            if ($dupRes && $dupRes->num_rows > 0) {
                setFlashMessage('error', 'Patient already exists.');
            } else {
                // Prepare insert (including weight & height)
                $insertSql = "INSERT INTO patients (patient_code, first_name, last_name, middle_name, date_of_birth, age, gender, civil_status, address, contact_number, email, emergency_contact_name, emergency_contact_number, blood_type, allergies, medical_history, family_medical_history, weight, height, is_pregnant, weeks_of_pregnancy, expected_due_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                // Ensure `weight`, `height`, and `family_medical_history` columns exist in `patients` table; add them if missing.
                $hasWeight = $conn->query("SHOW COLUMNS FROM patients LIKE 'weight'");
                $hasHeight = $conn->query("SHOW COLUMNS FROM patients LIKE 'height'");
                $hasFamilyHistory = $conn->query("SHOW COLUMNS FROM patients LIKE 'family_medical_history'");
                if (($hasWeight && $hasWeight->num_rows === 0) || ($hasHeight && $hasHeight->num_rows === 0) || ($hasFamilyHistory && $hasFamilyHistory->num_rows === 0)) {
                    // Try to add missing columns; wrap in @ to suppress warnings if another process added them concurrently
                    $alterSql = [];
                    if ($hasWeight && $hasWeight->num_rows === 0) $alterSql[] = "ADD COLUMN weight DECIMAL(5,2) DEFAULT NULL";
                    if ($hasHeight && $hasHeight->num_rows === 0) $alterSql[] = "ADD COLUMN height DECIMAL(5,2) DEFAULT NULL";
                    if ($hasFamilyHistory && $hasFamilyHistory->num_rows === 0) $alterSql[] = "ADD COLUMN family_medical_history TEXT DEFAULT NULL";
                    if (!empty($alterSql)) {
                        $conn->query('ALTER TABLE patients ' . implode(', ', $alterSql));
                    }
                }

                $stmt = $conn->prepare($insertSql);
                if (!$stmt) {
                    setFlashMessage('error', 'Failed to prepare insert statement: ' . $conn->error);
                } else {
                    // Temporary code - will be updated after insert
                    $tempCode = 'TEMP';
                    $weeksInt = is_numeric($weeksOfPregnancy) ? (int)$weeksOfPregnancy : null;
                    $weightVal = is_numeric($weight) ? (float)$weight : null;
                    $heightVal = is_numeric($height) ? (float)$height : null;
                    $stmt->bind_param("sssssisssssssssssddiis", $tempCode, $firstName, $lastName, $middleName, $dateOfBirth, $age, $gender, $civilStatus, $address, $contactNumber, $email, $emergencyContactName, $emergencyContactNumber, $bloodType, $allergies, $medicalHistory, $familyMedicalHistory, $weightVal, $heightVal, $isPregnant, $weeksInt, $expectedDueDate);

                    if ($stmt->execute()) {
                        $patientId = $stmt->insert_id;
                        // Generate and update patient code
                        $patientCode = generateCode('P', $patientId);
                        $conn->query("UPDATE patients SET patient_code = '$patientCode' WHERE id = $patientId");
                        // Log activity
                        logActivity('create', 'patients', $patientId);

                        // Notify nurses about new patient
                        try {
                            $notifStmt = $conn->prepare("INSERT INTO notifications (recipient_user_id, title, message) VALUES (?, ?, ?)");
                            $nurses = $conn->query("SELECT id FROM users WHERE role = 'nurse' AND status = 'active'");
                            $title = 'New patient registered';
                            $message = 'Patient ' . $conn->real_escape_string($firstName . ' ' . $lastName) . ' has been registered.';
                            if ($nurses) {
                                while ($n = $nurses->fetch_assoc()) {
                                    $notifStmt->bind_param('iss', $n['id'], $title, $message);
                                    $notifStmt->execute();
                                }
                                $notifStmt->close();
                            }
                        } catch (Exception $e) {
                            // ignore notification errors
                        }

                        setFlashMessage('success', 'Patient registered successfully! Patient Code: ' . $patientCode);
                        redirect('modules/reception/patients.php');
                    } else {
                        setFlashMessage('error', 'Error registering patient: ' . $stmt->error);
                    }

                    $stmt->close();
                }
            }
        } else {
            setFlashMessage('error', 'Failed to prepare duplicate-check statement: ' . $conn->error);
        }
    } else {
        setFlashMessage('error', implode('<br>', $errors));
    }
}

$conn->close();

// Prepare $patient defaults so we can reuse the edit form markup
    $patient = [
    'first_name' => $_POST['first_name'] ?? '',
    'last_name' => $_POST['last_name'] ?? '',
    'middle_name' => $_POST['middle_name'] ?? '',
    'date_of_birth' => $_POST['date_of_birth'] ?? '',
    'gender' => $_POST['gender'] ?? '',
    'civil_status' => $_POST['civil_status'] ?? '',
    'blood_type' => $_POST['blood_type'] ?? '',
    'contact_number' => $_POST['contact_number'] ?? '',
    'email' => $_POST['email'] ?? '',
    'address' => $_POST['address'] ?? '',
    'address_full' => $_POST['address_full'] ?? '',
    'address_municipality' => $_POST['address_municipality'] ?? '',
    'address_barangay' => $_POST['address_barangay'] ?? '',
    'address_street' => $_POST['address_street'] ?? '',
    'is_pregnant' => isset($_POST['is_pregnant']) ? (int)$_POST['is_pregnant'] : 0,
    'weeks_of_pregnancy' => $_POST['weeks_of_pregnancy'] ?? '',
    'allergies' => $_POST['allergies'] ?? '',
    'allergies_select' => $_POST['allergies_select'] ?? '',
    'allergies_other' => $_POST['allergies_other'] ?? '',
    'height' => $_POST['height'] ?? '',
    'weight' => $_POST['weight'] ?? '',
    'medical_history' => $_POST['medical_history'] ?? '',
    'family_medical_history' => $_POST['family_medical_history'] ?? ''
];

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Add New Patient</h1>
        <p class="page-subtitle">Register a new patient to the system</p>
    </div>
    <a href="patients.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="patient-add.php">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>First Name <span class="text-danger">*</span></label>
                    <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($patient['first_name'] ?? ''); ?>" required pattern="^[^0-9]+$" title="No numbers allowed">
                </div>
                <div class="form-group col-md-4">
                    <label>Middle Name <small class="text-muted">(optional)</small></label>
                    <input type="text" name="middle_name" class="form-control" value="<?php echo htmlspecialchars($patient['middle_name'] ?? ''); ?>" pattern="^[^0-9]*$" title="No numbers allowed">
                </div>
                <div class="form-group col-md-4">
                    <label>Last Name <span class="text-danger">*</span></label>
                    <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($patient['last_name'] ?? ''); ?>" required pattern="^[^0-9]+$" title="No numbers allowed">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Date of Birth <span class="text-danger">*</span></label>
                    <input type="date" name="date_of_birth" class="form-control" value="<?php echo htmlspecialchars($patient['date_of_birth'] ?? ''); ?>" required>
                </div>
                <div class="form-group col-md-4">
                    <label>Gender <span class="text-danger">*</span></label>
                    <select name="gender" id="gender" class="form-control" onchange="togglePregnancy()" required>
                        <option value="">Select</option>
                        <option value="Male" <?php echo ($patient['gender']=='Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo ($patient['gender']=='Female') ? 'selected' : ''; ?>>Female</option>
                        <option value="Other" <?php echo ($patient['gender']=='Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <label>Civil Status <span class="text-danger">*</span></label>
                    <select name="civil_status" class="form-control" required>
                        <option value="">Select</option>
                        <?php
                        $civilStatuses = ['Single', 'Married', 'Divorced', 'Separated', 'Widowed', 'Annulled', 'Cohabiting', 'Unknown'];
                        foreach ($civilStatuses as $cs) {
                            $sel = (isset($patient['civil_status']) && $patient['civil_status'] === $cs) ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($cs) . '" ' . $sel . '>' . htmlspecialchars($cs) . '</option>';
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-3">
                    <label>Blood Type <span class="text-danger">*</span></label>
                    <select name="blood_type" class="form-control" required>
                        <option value="">Select</option>
                        <?php
                        $bloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown'];
                        foreach ($bloodTypes as $bt) {
                            $sel = (isset($patient['blood_type']) && $patient['blood_type'] === $bt) ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($bt) . '" ' . $sel . '>' . htmlspecialchars($bt) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <label>Height (cm) <span class="text-danger">*</span></label>
                    <input type="number" step="0.1" name="height" class="form-control" value="<?php echo htmlspecialchars($patient['height'] ?? ''); ?>" placeholder="e.g. 170" required min="0">
                </div>
                <div class="form-group col-md-2">
                    <label>Weight (kg) <span class="text-danger">*</span></label>
                    <input type="number" step="0.1" name="weight" class="form-control" value="<?php echo htmlspecialchars($patient['weight'] ?? ''); ?>" placeholder="e.g. 65.5" required min="0">
                </div>
                <div class="form-group col-md-3">
                    <label>Contact Number <span class="text-danger">*</span></label>
                    <input type="text" name="contact_number" class="form-control" pattern="09[0-9]{9}" minlength="11" maxlength="11" value="<?php echo htmlspecialchars($patient['contact_number'] ?? ''); ?>" placeholder="09XXXXXXXXX" required>
                </div>
                <div class="form-group col-md-4">
                    <label>Email <small class="text-muted">(optional)</small></label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($patient['email'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Address <span class="text-danger">*</span></label>
                <div class="address-selector" data-selected-municipality="<?php echo htmlspecialchars($patient['address_municipality'] ?? ''); ?>" data-selected-barangay="<?php echo htmlspecialchars($patient['address_barangay'] ?? ''); ?>" data-selected-street="<?php echo htmlspecialchars($patient['address_street'] ?? ''); ?>">
                    <div style="display:flex;gap:20px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1;min-width:220px;"><select name="address_municipality" class="form-control" required><option value="">Select municipality</option><option value="Other">Other</option></select></div>
                        <div class="form-group" style="flex:1;min-width:220px;"><select name="address_barangay" class="form-control"></select></div>
                        <div class="form-group" style="flex:1;min-width:220px;"><select name="address_street" class="form-control"></select></div>
                    </div>
                    <div class="form-group address-full-wrapper" style="margin-top:12px; display:none;">
                        <input type="text" id="address_full" name="address_full" class="form-control" placeholder="Enter full address here(Street, Barangay, Municipality)" value="<?php echo htmlspecialchars($patient['address_full'] ?? ''); ?>">
                    </div>
                </div>
                <small class="text-muted">Select municipality → barangay → street</small>
            </div>

            <div class="form-row" id="pregnancyGroupRow">
                <div class="form-group col-md-2">
                    <label>Pregnant <small class="text-muted">(optional)</small></label>
                    <select name="is_pregnant" id="is_pregnant" class="form-control">
                        <option value="0" <?php echo (!$patient['is_pregnant']) ? 'selected' : ''; ?>>No</option>
                        <option value="1" <?php echo ($patient['is_pregnant']) ? 'selected' : ''; ?>>Yes</option>
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <label>Weeks <small class="text-muted">(optional)</small></label>
                    <input type="number" id="weeks_of_pregnancy" name="weeks_of_pregnancy" class="form-control" value="<?php echo htmlspecialchars($patient['weeks_of_pregnancy'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Allergies <small class="text-muted">(optional)</small></label>
                    <?php
                    $allergyOptions = ['None', 'Penicillin', 'Aspirin', 'Sulfa', 'Latex', 'Peanuts', 'Shellfish', 'Pollen', 'Dust', 'Other'];
                    $sel = $patient['allergies_select'] ?? ($patient['allergies'] ? (in_array($patient['allergies'], $allergyOptions) ? $patient['allergies'] : 'Other') : '');
                    $otherVal = $patient['allergies_other'] ?? ($sel === 'Other' ? $patient['allergies'] : '');
                    ?>
                    <div class="allergy-inline-row">
                        <select name="allergies_select" id="allergies_select" class="form-control">
                            <?php foreach ($allergyOptions as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($sel === $opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="allergies_other" id="allergies_other" class="form-control" placeholder="Type allergy" value="<?php echo htmlspecialchars($otherVal); ?>" style="display: <?php echo ($sel === 'Other') ? 'block' : 'none'; ?>;">
                    </div>
                    <script>
                    document.addEventListener('DOMContentLoaded', function(){
                        var sel = document.getElementById('allergies_select');
                        var other = document.getElementById('allergies_other');
                        if (!sel || !other) return;
                        sel.addEventListener('change', function(){
                            if (sel.value === 'Other') other.style.display = 'block'; else other.style.display = 'none';
                        });
                    });
                    </script>
                </div>
            </div>

            <div class="form-group">
                <label>Medical History </label>
                <textarea name="medical_history" class="form-control" placeholder="e.g. Hypertension, Diabetes — or type N/A if none"><?php echo htmlspecialchars($patient['medical_history'] ?? ''); ?></textarea>
            </div>

            <div class="form-group">
                <label>Family Medical History</label>
                <textarea name="family_medical_history" class="form-control" placeholder="e.g. Family history of Diabetes, Heart Disease, Hypertension — or type N/A if none"><?php echo htmlspecialchars($patient['family_medical_history'] ?? ''); ?></textarea>
            </div>

            <div class="form-group text-right">
                <button type="submit" class="btn btn-primary">Register Patient</button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePregnancy() {
    const genderEl = document.getElementById('gender');
    const gender = genderEl ? genderEl.value : '';
    const groupRow = document.getElementById('pregnancyGroupRow');
    const isPregnantSelect = document.getElementById('is_pregnant');
    const weeksEl = document.getElementById('weeks_of_pregnancy');
    const weeksGroup = weeksEl ? weeksEl.closest('.form-group') : null;

    if (groupRow) {
        if (gender === 'Male') {
            groupRow.style.display = 'none';
            if (isPregnantSelect) isPregnantSelect.value = '0';
            if (weeksEl) weeksEl.value = '';
            if (weeksGroup) weeksGroup.style.display = 'none';
        } else {
            groupRow.style.display = '';
            const shouldShowWeeks = isPregnantSelect && isPregnantSelect.value === '1';
            if (weeksGroup) {
                weeksGroup.style.display = shouldShowWeeks ? '' : 'none';
            }
            if (weeksEl && !shouldShowWeeks) {
                weeksEl.value = '';
            }
        }
    } else {
        const pregnancySection = document.getElementById('pregnancySection');
        if (gender === 'Male') {
            if (pregnancySection) pregnancySection.style.display = 'none';
            if (isPregnantSelect) isPregnantSelect.value = '0';
            if (weeksEl) weeksEl.value = '';
            if (weeksGroup) weeksGroup.style.display = 'none';
        } else {
            if (pregnancySection) pregnancySection.style.display = '';
            const shouldShowWeeks = isPregnantSelect && isPregnantSelect.value === '1';
            if (weeksGroup) {
                weeksGroup.style.display = shouldShowWeeks ? '' : 'none';
            }
            if (weeksEl && !shouldShowWeeks) {
                weeksEl.value = '';
            }
        }
    }
}

function updateAge() {
    // Age is calculated server-side, but we could add client-side validation here
}

// Initialize visibility on page load and respond to changes
window.addEventListener('DOMContentLoaded', function() {
    const gender = document.getElementById('gender');
    const isPregnant = document.getElementById('is_pregnant');

    function handlePregnancyVisibility() {
        togglePregnancy();
    }

    if (gender) {
        gender.addEventListener('change', handlePregnancyVisibility);
    }

    if (isPregnant) {
        isPregnant.addEventListener('change', handlePregnancyVisibility);
    }

    handlePregnancyVisibility();
});
</script>

<script>window.BASE_URL = '<?php echo BASE_URL; ?>';</script>
<script src="<?php echo BASE_URL; ?>assets/js/address-selector.js?v=2"></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
