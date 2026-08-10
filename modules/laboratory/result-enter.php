<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'laboratory']);

$pageTitle = 'Enter Lab Result';
$currentPage = 'lab-requests';

$conn = getDBConnection();

// Get request details
$requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
$requestResult = $conn->query("
    SELECT lr.*, p.*, lt.test_name, lt.category, lt.normal_range, u.full_name as doctor_name
    FROM laboratory_requests lr
    JOIN patients p ON lr.patient_id = p.id
    JOIN laboratory_tests lt ON lr.test_id = lt.id
    JOIN users u ON lr.doctor_id = u.id
    WHERE lr.id = $requestId
");

if ($requestId === 0) {
    setFlashMessage('info', 'Please select a pending lab request to process and enter results.');
    redirect('modules/laboratory/requests.php');
}

if (!$requestResult || $requestResult->num_rows === 0) {
    setFlashMessage('error', 'Laboratory request not found.');
    redirect('modules/laboratory/requests.php');
}

$request = $requestResult->fetch_assoc();

// Check if result already exists
$existingResult = $conn->query("SELECT * FROM laboratory_results WHERE request_id = $requestId");
$resultData = $existingResult->num_rows > 0 ? $existingResult->fetch_assoc() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // result value may come from a select or custom input when 'Other' is chosen
    $resultValue = '';
    if (isset($_POST['result_value_select']) && $_POST['result_value_select'] !== '') {
        if ($_POST['result_value_select'] === 'Other') {
            $resultValue = sanitize($_POST['result_value_other'] ?? '');
        } else {
            $resultValue = sanitize($_POST['result_value_select']);
        }
    } else {
        // fallback to legacy field name
        $resultValue = sanitize($_POST['result_value'] ?? '');
    }
    // reference range may come from select or custom input
    $referenceRange = '';
    if (isset($_POST['reference_range_select']) && $_POST['reference_range_select'] !== '') {
        if ($_POST['reference_range_select'] === 'Other') {
            $referenceRange = sanitize($_POST['reference_range_other'] ?? '');
        } elseif ($_POST['reference_range_select'] === 'UseDefault') {
            // use the test's normal range from the request
            $referenceRange = $request['normal_range'] ?? '';
        } else {
            $referenceRange = sanitize($_POST['reference_range_select']);
        }
    } else {
        // fallback to legacy field name
        $referenceRange = sanitize($_POST['reference_range'] ?? ($request['normal_range'] ?? ''));
    }
    $interpretation = sanitize($_POST['interpretation']);
        $remarks = sanitize($_POST['remarks']);
        // Handle file upload for attachment
        $attachmentPath = $resultData ? ($resultData['attachment_path'] ?? null) : null;
        if (isset($_FILES['attachment']) && is_uploaded_file($_FILES['attachment']['tmp_name'])) {
            $file = $_FILES['attachment'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $maxSize = 5 * 1024 * 1024; // 5 MB
                if ($file['size'] <= $maxSize) {
                    // Allowed MIME types: jpeg, png, pdf
                    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);

                    if (!isset($allowed[$mime])) {
                        setFlashMessage('error', 'Invalid attachment type. Allowed: JPG, PNG, PDF.');
                    } else {
                        // For images, ensure file is a valid image (prevents fake-image uploads)
                        $isValid = true;
                        if (strpos($mime, 'image/') === 0) {
                            $imgInfo = @getimagesize($file['tmp_name']);
                            if ($imgInfo === false) {
                                $isValid = false;
                            }
                        } elseif ($mime === 'application/pdf') {
                            // quick check for PDF header
                            $fh = fopen($file['tmp_name'], 'rb');
                            $header = fread($fh, 4);
                            fclose($fh);
                            if ($header !== '%PDF') {
                                $isValid = false;
                            }
                        }

                        if (!$isValid) {
                            setFlashMessage('error', 'Uploaded file failed validation (not a valid image or PDF).');
                        } else {
                            $uploadsDir = __DIR__ . '/../../uploads/lab_results';
                            if (!is_dir($uploadsDir)) {
                                mkdir($uploadsDir, 0755, true);
                            }
                            $origName = basename($file['name']);
                            $ext = $allowed[$mime];
                            $safeBase = preg_replace('/[^a-zA-Z0-9-_\.]/', '_', pathinfo($origName, PATHINFO_FILENAME));
                            $newName = time() . '_' . bin2hex(random_bytes(6)) . '_' . $safeBase . ($ext ? '.' . $ext : '');
                            $target = $uploadsDir . '/' . $newName;
                            if (move_uploaded_file($file['tmp_name'], $target)) {
                                // remove previous attachment if present and different
                                if (!empty($attachmentPath)) {
                                    $old = __DIR__ . '/../../' . ltrim($attachmentPath, '/');
                                    if (is_file($old) && realpath($old) !== realpath($target)) {
                                        @unlink($old);
                                    }
                                }
                                $attachmentPath = 'uploads/lab_results/' . $newName;
                            } else {
                                setFlashMessage('error', 'Failed to move uploaded file.');
                            }
                        }
                    }
                } else {
                    setFlashMessage('error', 'Attachment exceeds maximum size of 5MB.');
                }
            } else {
                if ($file['error'] !== UPLOAD_ERR_NO_FILE) {
                    setFlashMessage('error', 'File upload error code: ' . $file['error']);
                }
            }
        }
    
    if ($resultData) {
        // Update existing result
            // Update existing result (include attachment_path)
            $stmt = $conn->prepare("\n            UPDATE laboratory_results 
                SET result_value = ?, reference_range = ?, interpretation = ?, remarks = ?, attachment_path = ?
                WHERE id = ?
            ");
            $stmt->bind_param("sssssi", $resultValue, $referenceRange, $interpretation, $remarks, $attachmentPath, $resultData['id']);
    } else {
        // Insert new result
            // Insert new result (include attachment_path)
            $stmt = $conn->prepare("\n            INSERT INTO laboratory_results 
                (request_id, patient_id, technician_id, result_value, reference_range, interpretation, remarks, attachment_path, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending-review')
            ");
            $stmt->bind_param("iiisssss", $requestId, $request['patient_id'], $_SESSION['user_id'], 
                $resultValue, $referenceRange, $interpretation, $remarks, $attachmentPath);
    }
    
    if ($stmt->execute()) {
        // Update request status
        $conn->query("UPDATE laboratory_requests SET status = 'completed', completed_at = NOW() WHERE id = $requestId");
        
        // Update visit status back to consultation
            // Update visit status back to consultation. Some installations may lack `updated_by` column.
            $uid = (int)($_SESSION['user_id'] ?? 0);
            $colRes = $conn->query("SHOW COLUMNS FROM patient_visits LIKE 'updated_by'");
            if ($colRes && $colRes->num_rows > 0) {
                $conn->query("UPDATE patient_visits v
                    JOIN laboratory_requests lr ON v.id = lr.visit_id
                    SET v.status = 'in-consultation', v.updated_by = $uid
                    WHERE lr.id = $requestId");
            } else {
                $conn->query("UPDATE patient_visits v
                    JOIN laboratory_requests lr ON v.id = lr.visit_id
                    SET v.status = 'in-consultation'
                    WHERE lr.id = $requestId");
            }
        
        logActivity($resultData ? 'update' : 'create', 'laboratory_results', $stmt->insert_id ?: $resultData['id']);
        setFlashMessage('success', 'Lab result saved successfully!');
        redirect('modules/laboratory/requests.php');
    } else {
        setFlashMessage('error', 'Error saving result: ' . $stmt->error);
    }
    
    $stmt->close();
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Enter Laboratory Result</h1>
        <p class="page-subtitle"><?php echo $request['test_name']; ?> for <?php echo $request['first_name'] . ' ' . $request['last_name']; ?></p>
    </div>
    <a href="requests.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<!-- Patient & Test Info -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-info-circle"></i> Test Information</h3>
    </div>
    <div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Patient</label>
                <p><strong><?php echo $request['first_name'] . ' ' . $request['last_name']; ?></strong></p>
                <p class="text-muted"><?php echo $request['patient_code']; ?></p>
            </div>
            <div class="form-group">
                <label class="form-label">Test</label>
                <p><strong><?php echo $request['test_name']; ?></strong></p>
                <p class="text-muted"><?php echo ucfirst($request['category']); ?></p>
            </div>
            <div class="form-group">
                <label class="form-label">Requested By</label>
                <p><?php echo $request['doctor_name']; ?></p>
            </div>
            <div class="form-group">
                <label class="form-label">Priority</label>
                <p><?php echo getStatusBadge($request['priority']); ?></p>
            </div>
        </div>
        <?php if ($request['notes']): ?>
        <div class="form-row">
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Doctor's Notes</label>
                <p class="alert alert-info"><?php echo $request['notes']; ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Result Entry -->
<form method="POST" action="" enctype="multipart/form-data">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-vial"></i> Test Result</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Result Value <span style="color: red;">*</span></label>
                    <?php
                    // common selectable result options
                    $resultOptions = ['Positive','Negative','Detected','Not detected','Reactive','Non-reactive','Trace','0','1','2','3'];
                    $currentVal = $resultData ? $resultData['result_value'] : '';
                    $matched = false;
                    foreach ($resultOptions as $opt) { if (strcasecmp(trim($opt), trim($currentVal)) === 0) { $matched = true; break; } }
                    ?>
                    <select name="result_value_select" id="result_value_select" class="form-control" required>
                        <option value="">-- Select or type --</option>
                        <?php foreach ($resultOptions as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($matched && strcasecmp($opt, $currentVal)===0) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                        <option value="Other" <?php echo (!$matched && $currentVal !== '' ) ? 'selected' : ''; ?>>Other</option>
                    </select>
                    <textarea name="result_value_other" id="result_value_other" class="form-control" rows="3" placeholder="Enter custom result..." style="margin-top:8px; display:<?php echo (!$matched && $currentVal !== '') ? 'block' : 'none'; ?>;" <?php echo (!$matched && $currentVal !== '') ? 'required' : ''; ?>><?php echo (!$matched && $currentVal !== '') ? htmlspecialchars($currentVal) : ''; ?></textarea>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Reference Range</label>
                    <?php
                    $currentRef = $resultData ? $resultData['reference_range'] : ($request['normal_range'] ?? '');
                    $refOptions = ['Normal','Abnormal','Not applicable'];
                    $refMatched = false;
                    foreach ($refOptions as $ro) { if (strcasecmp(trim($ro), trim($currentRef)) === 0) { $refMatched = true; break; } }
                    $useDefaultSelected = ($currentRef !== '' && isset($request['normal_range']) && trim($currentRef) === trim($request['normal_range']));
                    ?>
                    <select name="reference_range_select" id="reference_range_select" class="form-control">
                        <option value="">-- Select or use default --</option>
                        <option value="UseDefault" <?php echo $useDefaultSelected ? 'selected' : ''; ?>>Use test normal range</option>
                        <?php foreach ($refOptions as $ro): ?>
                            <option value="<?php echo htmlspecialchars($ro); ?>" <?php echo ($refMatched && strcasecmp($ro, $currentRef)===0) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ro); ?></option>
                        <?php endforeach; ?>
                        <option value="Other" <?php echo (!$refMatched && !$useDefaultSelected && $currentRef !== '') ? 'selected' : ''; ?>>Other</option>
                    </select>
                    <textarea name="reference_range_other" id="reference_range_other" class="form-control" rows="2" placeholder="Enter custom reference range..." style="margin-top:8px; display:<?php echo (!$refMatched && !$useDefaultSelected && $currentRef !== '') ? 'block' : 'none'; ?>;" <?php echo (!$refMatched && !$useDefaultSelected && $currentRef !== '') ? 'required' : ''; ?>><?php echo (!$refMatched && !$useDefaultSelected && $currentRef !== '') ? htmlspecialchars($currentRef) : ''; ?></textarea>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Interpretation</label>
                    <select name="interpretation" class="form-control">
                        <option value="">-- Select Interpretation --</option>
                        <option value="Normal" <?php echo ($resultData && $resultData['interpretation'] == 'Normal') ? 'selected' : ''; ?>>Normal</option>
                        <option value="Abnormal" <?php echo ($resultData && $resultData['interpretation'] == 'Abnormal') ? 'selected' : ''; ?>>Abnormal</option>
                        <option value="Critical" <?php echo ($resultData && $resultData['interpretation'] == 'Critical') ? 'selected' : ''; ?>>Critical</option>
                        <option value="Borderline" <?php echo ($resultData && $resultData['interpretation'] == 'Borderline') ? 'selected' : ''; ?>>Borderline</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Remarks / Comments</label>
                    <textarea name="remarks" class="form-control" rows="3" placeholder="Any additional remarks..."><?php echo $resultData ? $resultData['remarks'] : ''; ?></textarea>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Attachment (optional)</label>
                    <?php if ($resultData && !empty($resultData['attachment_path'])): ?>
                        <div style="margin-bottom:8px;"><a href="<?php echo BASE_URL . ltrim($resultData['attachment_path'], '/'); ?>" target="_blank">Current attachment</a></div>
                    <?php endif; ?>
                    <input type="file" name="attachment" class="form-control" accept="image/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                    <small class="form-text text-muted">Max 5MB. Allowed: images, PDF, Word.</small>
                </div>
            </div>
        </div>
    </div>
    
    <div class="form-group" style="text-align: right;">
        <a href="requests.php" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Save Result
        </button>
    </div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function(){
    var sel = document.getElementById('result_value_select');
    var other = document.getElementById('result_value_other');
    if (!sel || !other) return;
    sel.addEventListener('change', function(){
        if (this.value === 'Other') {
            other.style.display = 'block';
            other.required = true;
        } else {
            other.style.display = 'none';
            other.required = false;
            other.value = '';
        }
    });
    // Reference range toggle
    var rSel = document.getElementById('reference_range_select');
    var rOther = document.getElementById('reference_range_other');
    if (rSel && rOther) {
        rSel.addEventListener('change', function(){
            if (this.value === 'Other') {
                rOther.style.display = 'block';
                rOther.required = true;
            } else {
                rOther.style.display = 'none';
                rOther.required = false;
                rOther.value = '';
            }
        });
    }
});
</script>
