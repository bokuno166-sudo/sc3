<?php
require_once __DIR__ . '/../../config/config.php';
// Doctors are not permitted to edit patient details.
requireRole(['admin', 'staff', 'reception', 'nurse']);

$pageTitle = 'Edit Patient';
$currentPage = 'patients';

$conn = getDBConnection();
$patientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($patientId <= 0) {
    setFlashMessage('error', 'Invalid patient selected.');
    redirect('modules/reception/patients.php');
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Prevent doctors from submitting edits even if they reach this page
    if (hasRole(['doctor'])) {
        setFlashMessage('error', 'You do not have permission to edit patient details.');
        redirect('modules/reception/patient-view.php?id=' . $patientId);
    }
    $first_name = sanitize($_POST['first_name'] ?? '');
    $middle_name = sanitize($_POST['middle_name'] ?? '');
    $last_name = sanitize($_POST['last_name'] ?? '');
    $date_of_birth = sanitize($_POST['date_of_birth'] ?? '');
    $gender = sanitize($_POST['gender'] ?? '');
    $civil_status = sanitize($_POST['civil_status'] ?? '');
    $blood_type = sanitize($_POST['blood_type'] ?? '');
    $contact_number = sanitize($_POST['contact_number'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $weight = isset($_POST['weight']) && $_POST['weight'] !== '' ? (float)$_POST['weight'] : null;
    $height = isset($_POST['height']) && $_POST['height'] !== '' ? (float)$_POST['height'] : null;
    // Address components (cascading selects)
    $address_municipality = sanitize($_POST['address_municipality'] ?? '');
    $address_barangay = sanitize($_POST['address_barangay'] ?? '');
    $address_street = sanitize($_POST['address_street'] ?? '');
    $address = trim(($address_street ? $address_street . ', ' : '') . ($address_barangay ? $address_barangay . ', ' : '') . $address_municipality);
    $is_pregnant = isset($_POST['is_pregnant']) && $_POST['is_pregnant'] == '1' ? 1 : 0;
    // enforce: males cannot be pregnant
    if (strtolower($gender) === 'male') {
        $is_pregnant = 0;
        $weeks_of_pregnancy = null;
    }
    // store weeks as integer or null
    $weeks_of_pregnancy = isset($_POST['weeks_of_pregnancy']) && $_POST['weeks_of_pregnancy'] !== '' ? (int)$_POST['weeks_of_pregnancy'] : null;
    // Allergies: dropdown with Other option
    $allergies_select = $_POST['allergies_select'] ?? '';
    $allergies_other = isset($_POST['allergies_other']) ? sanitize($_POST['allergies_other']) : '';
    if ($allergies_select === 'Other') {
        $allergies = $allergies_other;
    } elseif ($allergies_select === 'None' || $allergies_select === '') {
        $allergies = '';
    } else {
        $allergies = sanitize($allergies_select);
    }
    $medical_history = sanitize($_POST['medical_history'] ?? '');
    $family_medical_history = sanitize($_POST['family_medical_history'] ?? '');
    $emergency_contact_name = sanitize($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_number = sanitize($_POST['emergency_contact_number'] ?? '');

    // Validate contact numbers: must be 11 digits and start with 09
    $errors = [];
    if ($contact_number !== '' && !preg_match('/^09\d{9}$/', $contact_number)) {
        $errors[] = 'Contact number must be 11 digits and start with 09.';
    }
    if ($emergency_contact_number !== '' && !preg_match('/^09\d{9}$/', $emergency_contact_number)) {
        $errors[] = 'Emergency contact number must be 11 digits and start with 09.';
    }

    // Validate names: ensure no digits and contain at least one letter
    $nameNoDigitsRegex = '/^[^\d]+$/u';
    $containsLetterRegex = '/\p{L}/u';
    if ($first_name === '' || !preg_match($nameNoDigitsRegex, $first_name) || !preg_match($containsLetterRegex, $first_name)) {
        $errors[] = 'First name is required and must contain letters only (no numbers).';
    }
    if ($last_name === '' || !preg_match($nameNoDigitsRegex, $last_name) || !preg_match($containsLetterRegex, $last_name)) {
        $errors[] = 'Last name is required and must contain letters only (no numbers).';
    }
    if ($middle_name !== '' && (!preg_match($nameNoDigitsRegex, $middle_name) || !preg_match($containsLetterRegex, $middle_name))) {
        $errors[] = 'Middle name must not contain numbers.';
    }

    if (!empty($errors)) {
        setFlashMessage('error', implode('<br>', $errors));
    } else {
        $updateSql = "UPDATE patients SET first_name = ?, middle_name = ?, last_name = ?, date_of_birth = ?, gender = ?, civil_status = ?, blood_type = ?, contact_number = ?, email = ?, address = ?, weight = ?, height = ?, is_pregnant = ?, weeks_of_pregnancy = ?, allergies = ?, medical_history = ?, family_medical_history = ?, emergency_contact_name = ?, emergency_contact_number = ? WHERE id = ?";

        // Ensure patients table has weight, height, and family_medical_history columns
        $col = $conn->query("SHOW COLUMNS FROM patients LIKE 'weight'");
        if (!$col || $col->num_rows === 0) {
            $conn->query("ALTER TABLE patients ADD COLUMN weight DECIMAL(6,2) NULL");
        }
        $col = $conn->query("SHOW COLUMNS FROM patients LIKE 'height'");
        if (!$col || $col->num_rows === 0) {
            $conn->query("ALTER TABLE patients ADD COLUMN height DECIMAL(6,2) NULL");
        }
        $col = $conn->query("SHOW COLUMNS FROM patients LIKE 'family_medical_history'");
        if (!$col || $col->num_rows === 0) {
            $conn->query("ALTER TABLE patients ADD COLUMN family_medical_history TEXT NULL");
        }

        $stmt = $conn->prepare($updateSql);
                if ($stmt) {
                $stmt->bind_param('ssssssssssddiisssssi', $first_name, $middle_name, $last_name, $date_of_birth, $gender, $civil_status, $blood_type, $contact_number, $email, $address, $weight, $height, $is_pregnant, $weeks_of_pregnancy, $allergies, $medical_history, $family_medical_history, $emergency_contact_name, $emergency_contact_number, $patientId);
                $executed = $stmt->execute();
        if ($connError && stripos($connError, "Unknown column 'weeks_of_pregnancy'") !== false) {
            $conn->query("ALTER TABLE patients ADD COLUMN weeks_of_pregnancy INT DEFAULT NULL");
            $stmt = $conn->prepare($updateSql);
        }
    }

    if (!$stmt) {
        setFlashMessage('error', 'Failed to prepare update statement: ' . $conn->error);
        $conn->close();
        redirect('modules/reception/patient-view.php?id=' . $patientId);
    }

    // types: 10 strings, 2 doubles, 2 ints, 5 strings, 1 int (includes middle_name)
    $stmt->bind_param('ssssssssssddiisssssi', $first_name, $middle_name, $last_name, $date_of_birth, $gender, $civil_status, $blood_type, $contact_number, $email, $address, $weight, $height, $is_pregnant, $weeks_of_pregnancy, $allergies, $medical_history, $family_medical_history, $emergency_contact_name, $emergency_contact_number, $patientId);
    $executed = $stmt->execute();
    if (!$executed) {
        // If execute failed due to unknown column, try to add column and retry once
        $execError = $stmt->error ?: $conn->error;
        if ($execError && stripos($execError, "Unknown column 'weeks_of_pregnancy'") !== false) {
            $stmt->close();
            $conn->query("ALTER TABLE patients ADD COLUMN weeks_of_pregnancy INT DEFAULT NULL");
            $stmt = $conn->prepare($updateSql);
            if ($stmt) {
                $stmt->bind_param('ssssssssssddiisssssi', $first_name, $middle_name, $last_name, $date_of_birth, $gender, $civil_status, $blood_type, $contact_number, $email, $address, $weight, $height, $is_pregnant, $weeks_of_pregnancy, $allergies, $medical_history, $family_medical_history, $emergency_contact_name, $emergency_contact_number, $patientId);
                $executed = $stmt->execute();
            }
        }
    }

    $stmt->close();

    if ($executed) {
        logActivity('update', 'patients', $patientId, null, json_encode($_POST));
        setFlashMessage('success', 'Patient updated successfully.');
        $conn->close();
        redirect('modules/reception/patient-view.php?id=' . $patientId);
    } else {
        setFlashMessage('error', 'Failed to update patient.');
    }
    }
    }

// Load patient
$result = $conn->query("SELECT * FROM patients WHERE id = $patientId");
if (!$result || $result->num_rows === 0) {
    setFlashMessage('error', 'Patient not found.');
    $conn->close();
    redirect('modules/reception/patients.php');
}
    $patient = $result->fetch_assoc();
// Precompute allergies select/other fields from stored allergies
$storedAllergy = trim($patient['allergies'] ?? '');
$allergyOptions = ['Penicillin','Aspirin','Sulfa','Latex','Peanuts','Shellfish','Pollen','Dust'];
if ($storedAllergy === '' || strtolower($storedAllergy) === 'none' || strtolower($storedAllergy) === 'no known allergies') {
    $patient['allergies_select'] = 'None';
    $patient['allergies_other'] = '';
} elseif (in_array($storedAllergy, $allergyOptions)) {
    $patient['allergies_select'] = $storedAllergy;
    $patient['allergies_other'] = '';
} else {
    $patient['allergies_select'] = 'Other';
    $patient['allergies_other'] = $storedAllergy;
}
// Parse stored address into components (try to split 'Street, Barangay, Municipality')
$address_municipality = '';
$address_barangay = '';
$address_street = '';
if (!empty($patient['address'])) {
    $parts = array_map('trim', explode(',', $patient['address']));
    if (count($parts) >= 1) {
        $address_municipality = array_pop($parts) ?: '';
    }
    if (count($parts) >= 1) {
        $address_barangay = array_pop($parts) ?: '';
    }
    if (count($parts) >= 1) {
        $address_street = implode(', ', $parts);
    }
}
$patient['address_municipality'] = $address_municipality;
$patient['address_barangay'] = $address_barangay;
$patient['address_street'] = $address_street;
$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Edit Patient</h1>
        <p class="page-subtitle">Update patient information</p>
    </div>
    <div>
        <a href="patient-view.php?id=<?php echo $patientId; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="patient-edit.php?id=<?php echo $patientId; ?>">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>First Name</label>
                    <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($patient['first_name'] ?? ''); ?>" required pattern="^[^0-9]+$" title="No numbers allowed">
                </div>
                <div class="form-group col-md-4">
                    <label>Middle Name <small class="text-muted">(optional)</small></label>
                    <input type="text" name="middle_name" class="form-control" value="<?php echo htmlspecialchars($patient['middle_name'] ?? ''); ?>" pattern="^[^0-9]*$" title="No numbers allowed">
                </div>
                <div class="form-group col-md-4">
                    <label>Last Name</label>
                    <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($patient['last_name'] ?? ''); ?>" required pattern="^[^0-9]+$" title="No numbers allowed">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Date of Birth</label>
                    <input type="date" name="date_of_birth" class="form-control" value="<?php echo htmlspecialchars($patient['date_of_birth'] ?? ''); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Gender</label>
                    <select id="gender" name="gender" class="form-control">
                        <option value="">Select</option>
                        <option value="Male" <?php echo $patient['gender']=='Male' ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo $patient['gender']=='Female' ? 'selected' : ''; ?>>Female</option>
                        <option value="Other" <?php echo $patient['gender']=='Other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <label>Civil Status</label>
                    <select name="civil_status" class="form-control">
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
                    <label>Blood Type</label>
                    <select name="blood_type" class="form-control">
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
                <div class="form-group col-md-3">
                    <label>Contact Number</label>
                    <input type="text" name="contact_number" class="form-control" pattern="09[0-9]{9}" minlength="11" maxlength="11" value="<?php echo htmlspecialchars($patient['contact_number'] ?? ''); ?>" placeholder="09XXXXXXXXX">
                </div>
                <div class="form-group col-md-2">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($patient['email'] ?? ''); ?>">
                </div>
                <div class="form-group col-md-2">
                    <label>Weight (kg)</label>
                    <input type="number" step="0.1" name="weight" class="form-control" value="<?php echo htmlspecialchars($patient['weight'] ?? ''); ?>" placeholder="e.g., 70.0">
                </div>
                <div class="form-group col-md-2">
                    <label>Height (cm)</label>
                    <input type="number" step="0.1" name="height" class="form-control" value="<?php echo htmlspecialchars($patient['height'] ?? ''); ?>" placeholder="e.g., 170.0">
                </div>
            </div>

            <div class="form-group">
                <label>Address</label>
                <div class="address-selector" data-selected-municipality="<?php echo htmlspecialchars($patient['address_municipality'] ?? ''); ?>" data-selected-barangay="<?php echo htmlspecialchars($patient['address_barangay'] ?? ''); ?>" data-selected-street="<?php echo htmlspecialchars($patient['address_street'] ?? ''); ?>">
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <select name="address_municipality" class="form-control" style="min-width:200px"></select>
                        <select name="address_barangay" class="form-control" style="min-width:220px"></select>
                        <select name="address_street" class="form-control" style="min-width:220px"></select>
                    </div>
                </div>
                <small class="text-muted">Select municipality → barangay → street</small>
            </div>

            <div class="form-row">
                <div class="form-group col-md-2">
                    <label>Pregnant</label>
                    <select id="is_pregnant" name="is_pregnant" class="form-control">
                        <option value="0" <?php echo $patient['is_pregnant'] ? '' : 'selected'; ?>>No</option>
                        <option value="1" <?php echo $patient['is_pregnant'] ? 'selected' : ''; ?>>Yes</option>
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <label>Weeks</label>
                    <input type="number" id="weeks_of_pregnancy" name="weeks_of_pregnancy" class="form-control" value="<?php echo htmlspecialchars($patient['weeks_of_pregnancy'] ?? ''); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Emergency Contact</label>
                    <input type="text" name="emergency_contact_name" class="form-control" value="<?php echo htmlspecialchars($patient['emergency_contact_name'] ?? ''); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Emergency Contact Number</label>
                    <input type="text" name="emergency_contact_number" class="form-control" pattern="09[0-9]{9}" minlength="11" maxlength="11" value="<?php echo htmlspecialchars($patient['emergency_contact_number'] ?? ''); ?>" placeholder="09XXXXXXXXX">
                </div>
            </div>

            <div class="form-group">
                <label>Allergies</label>
                <?php
                $allergyOptions = ['None', 'Penicillin', 'Aspirin', 'Sulfa', 'Latex', 'Peanuts', 'Shellfish', 'Pollen', 'Dust', 'Other'];
                $sel = $patient['allergies_select'] ?? ($patient['allergies'] ? (in_array($patient['allergies'], $allergyOptions) ? $patient['allergies'] : 'Other') : '');
                $otherVal = $patient['allergies_other'] ?? ($sel === 'Other' ? $patient['allergies'] : '');
                ?>
                <div style="display:flex;gap:10px;align-items:center;">
                    <select name="allergies_select" id="allergies_select" class="form-control" style="max-width:320px">
                        <?php foreach ($allergyOptions as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($sel === $opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="allergies_other" id="allergies_other" class="form-control" placeholder="Type allergy" value="<?php echo htmlspecialchars($otherVal); ?>" style="display: <?php echo ($sel === 'Other') ? 'block' : 'none'; ?>; max-width:320px;">
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

            <div class="form-group">
                <label>Medical History</label>
                <textarea name="medical_history" class="form-control"><?php echo htmlspecialchars($patient['medical_history'] ?? ''); ?></textarea>
            </div>

            <div class="form-group">
                <label>Family Medical History</label>
                <textarea name="family_medical_history" class="form-control"><?php echo htmlspecialchars($patient['family_medical_history'] ?? ''); ?></textarea>
            </div>

            <div class="form-group text-right">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>window.BASE_URL = '<?php echo BASE_URL; ?>';</script>
<script src="<?php echo BASE_URL; ?>assets/js/address-selector.js?v=2"></script>

<script>
// When gender is Male, force pregnancy = No and hide weeks input
document.addEventListener('DOMContentLoaded', function() {
    var gender = document.getElementById('gender');
    var isPregnant = document.getElementById('is_pregnant');
    var weeks = document.getElementById('weeks_of_pregnancy');
    if (!gender || !isPregnant || !weeks) return;

    function handleGenderChange() {
        if (gender.value === 'Male') {
            isPregnant.value = '0';
            weeks.style.display = 'none';
            weeks.value = '';
        } else {
            weeks.style.display = '';
        }
    }

    gender.addEventListener('change', handleGenderChange);
    handleGenderChange();
});
</script>
