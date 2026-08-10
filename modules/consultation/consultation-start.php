<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['doctor']);

// ICD-10 codes helper
require_once __DIR__ . '/../../config/icd10_codes.php';

$pageTitle = 'Start Consultation';
$currentPage = 'consultations';

$conn = getDBConnection();

// Get visit details
$visitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;
$visitResult = $conn->query("
    SELECT v.*, p.*, t.*, u.full_name AS nurse_name, t.created_at AS triage_created_at
    FROM patient_visits v
    JOIN patients p ON v.patient_id = p.id
    LEFT JOIN triage_records t ON v.id = t.visit_id
    LEFT JOIN users u ON t.nurse_id = u.id
    WHERE v.id = $visitId
");

if ($visitResult->num_rows === 0) {
    setFlashMessage('error', 'Visit not found.');
    redirect('modules/consultation/consultations.php');
}

$visit = $visitResult->fetch_assoc();

// If this visit was assigned a maternity checkup, prevent starting a general consultation
if (!empty($visit['checkup_type']) && $visit['checkup_type'] === 'maternity') {
    setFlashMessage('info', 'This visit is assigned as a Maternity check-up. Please use the Maternity workflow.');
    $redirect = 'modules/maternity/checkup-add.php?visit_id=' . intval($visitId) . '&patient_id=' . intval($visit['patient_id']);
    redirect($redirect);
}

// Ensure we have explicit patient and triage ids (avoid overwritten keys from SELECT v.*, p.*, t.*)
$patientId = isset($visit['patient_id']) && $visit['patient_id'] ? $visit['patient_id'] : null;
if (!$patientId) {
    $pv = $conn->query("SELECT patient_id FROM patient_visits WHERE id = $visitId");
    if ($pv && $pv->num_rows) {
        $patientId = $pv->fetch_assoc()['patient_id'];
    }
}

$triageId = isset($visit['triage_id']) && $visit['triage_id'] ? $visit['triage_id'] : null;
if (!$triageId) {
    $tres = $conn->query("SELECT id FROM triage_records WHERE visit_id = $visitId ORDER BY id DESC LIMIT 1");
    if ($tres && $tres->num_rows) {
        $triageRow = $tres->fetch_assoc();
        $triageId = $triageRow['id'];
    }
}

// Get available laboratory tests
$labTests = $conn->query("SELECT * FROM laboratory_tests WHERE status = 'active' ORDER BY test_name");

// Fetch existing consultation record if any
$existingConsult = null;
if ($visitId) {
    $cRes = $conn->query("SELECT * FROM consultations WHERE visit_id = $visitId LIMIT 1");
    if ($cRes && $cRes->num_rows > 0) {
        $existingConsult = $cRes->fetch_assoc();
    }
}

// Initialize form variables from existing draft or default to empty
$physicalExamination = $existingConsult ? ($existingConsult['physical_examination'] ?? '') : '';
$diagnosis = $existingConsult ? ($existingConsult['diagnosis'] ?? '') : '';
$diagnosisCodes = $existingConsult ? ($existingConsult['diagnosis_codes'] ?? '') : '';
$treatmentPlan = $existingConsult ? ($existingConsult['treatment_plan'] ?? '') : '';
$followUpDate = $existingConsult ? ($existingConsult['follow_up_date'] ?? '') : '';
$followUpInstructions = $existingConsult ? ($existingConsult['follow_up_instructions'] ?? '') : '';


// Fetch laboratory requests and results for this visit
$labResults = [];
if ($visitId) {
    $labResultsRes = $conn->query("
        SELECT lr.id AS request_id, lr.priority, lt.test_name, lres.result_value, lres.interpretation, lres.remarks, lres.created_at, u.full_name as tech_name
        FROM laboratory_requests lr
        JOIN laboratory_results lres ON lr.id = lres.request_id
        JOIN laboratory_tests lt ON lr.test_id = lt.id
        LEFT JOIN users u ON lres.technician_id = u.id
        WHERE lr.visit_id = $visitId
    ");
    if ($labResultsRes && $labResultsRes->num_rows > 0) {
        $docId = (int)($_SESSION['user_id'] ?? 0);
        // Ensure doctor_lab_views table exists
        $conn->query("CREATE TABLE IF NOT EXISTS doctor_lab_views (
            id INT AUTO_INCREMENT PRIMARY KEY,
            visit_id INT NOT NULL,
            request_id INT NOT NULL,
            doctor_id INT NOT NULL,
            viewed_at DATETIME NOT NULL,
            UNIQUE KEY uq_req_doc (request_id, doctor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        while ($row = $labResultsRes->fetch_assoc()) {
            $labResults[] = $row;
            if ($docId) {
                $viewStmt = $conn->prepare("INSERT INTO doctor_lab_views (visit_id, request_id, doctor_id, viewed_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE viewed_at = NOW()");
                if ($viewStmt) {
                    $viewStmt->bind_param('iii', $visitId, $row['request_id'], $docId);
                    $viewStmt->execute();
                    $viewStmt->close();
                }
            }
        }
    }
}

// Check whether there are completed lab results for this visit that the current doctor
// has not yet viewed. If so, require viewing before allowing a final outcome.
$mustViewLabResults = false;
$docId = (int)($_SESSION['user_id'] ?? 0);
if ($docId && $visitId) {
    $checkStmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM laboratory_requests lr
         JOIN laboratory_results lres ON lres.request_id = lr.id
         LEFT JOIN doctor_lab_views v ON v.request_id = lr.id AND v.doctor_id = ?
         WHERE lr.visit_id = ? AND lres.id IS NOT NULL AND v.id IS NULL"
    );
    if ($checkStmt) {
        $checkStmt->bind_param('ii', $docId, $visitId);
        $checkStmt->execute();
        $r = $checkStmt->get_result();
        $cnt = $r ? (int)$r->fetch_assoc()['cnt'] : 0;
        $mustViewLabResults = $cnt > 0;
        $checkStmt->close();
    }
}

// Get latest visit chief complaint
$latestVisitRes = $conn->query("SELECT chief_complaint, visit_date FROM patient_visits WHERE patient_id = $patientId ORDER BY visit_date DESC LIMIT 1");
$latestVisit = ($latestVisitRes && $latestVisitRes->num_rows) ? $latestVisitRes->fetch_assoc() : null;

// Get latest consultation summary (diagnosis/notes)
$latestConsultRes = $conn->query("SELECT c.diagnosis, c.notes, c.created_at, u.full_name as doctor_name FROM consultations c LEFT JOIN users u ON c.doctor_id = u.id WHERE c.patient_id = $patientId ORDER BY c.created_at DESC LIMIT 1");
$latestConsult = ($latestConsultRes && $latestConsultRes->num_rows) ? $latestConsultRes->fetch_assoc() : null;

$generalMedicalHistory = trim($visit['medical_history_notes'] ?: ($visit['medical_history'] ?: ''));

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitAction = isset($_POST['submit_action']) ? sanitize($_POST['submit_action']) : 'complete';
    
    $physicalExamination = sanitize($_POST['physical_examination'] ?? '');
    $diagnosis = sanitize($_POST['diagnosis'] ?? '');
    if (isset($_POST['diagnosis_codes'])) {
        $diagnosisCodes = is_array($_POST['diagnosis_codes']) ? sanitize($_POST['diagnosis_codes'][0]) : sanitize($_POST['diagnosis_codes']);
    } else {
        $diagnosisCodes = '';
    }
    $treatmentPlan = sanitize($_POST['treatment_plan'] ?? '');
    $followUpDate = sanitize($_POST['follow_up_date'] ?? '');
    $followUpInstructions = sanitize($_POST['follow_up_instructions'] ?? '');
    $transferDestination = null;
    $notes = '';
    
    $treatmentChoice = sanitize($_POST['treatment_choice'] ?? '');
    $outcome = ($submitAction === 'request_lab') ? 'laboratory-request' : 'prescription-only';
    if ($submitAction === 'complete' && ($treatmentChoice === 'Admission' || $treatmentPlan === 'Admission')) {
        $outcome = 'admission';
    }
    if ($submitAction === 'complete' && ($treatmentChoice === 'Referral' || $treatmentPlan === 'Referral')) {
        $outcome = 'referral';
    }
    
    // Check if the `transfer_destination` column exists in the DB; adapt INSERT accordingly
    $hasTransferDestination = false;
    $colRes = $conn->query("SHOW COLUMNS FROM consultations LIKE 'transfer_destination'");
    if ($colRes && $colRes->num_rows > 0) {
        $hasTransferDestination = true;
    }
    
    // Ensure we have a patient id before attempting to insert
    if (empty($patientId)) {
        setFlashMessage('error', 'Patient not found for this visit.');
        redirect('modules/consultation/consultations.php');
        exit;
    }
    
    // Check if consultation already exists
    $checkConsult = $conn->query("SELECT id FROM consultations WHERE visit_id = $visitId LIMIT 1");
    $existingConsultId = ($checkConsult && $checkConsult->num_rows > 0) ? (int)$checkConsult->fetch_assoc()['id'] : null;
    
    $dbSuccess = false;
    $consultationId = null;
    $stmtError = '';
    
    if ($existingConsultId) {
        // Update existing consultation
        if ($submitAction === 'request_lab') {
            $sql = "UPDATE consultations SET physical_examination = ?, outcome = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("ssi", $physicalExamination, $outcome, $existingConsultId);
                $dbSuccess = $stmt->execute();
                $stmt->close();
            } else {
                $stmtError = $conn->error;
            }
        } else {
            // complete
            $sql = "UPDATE consultations SET physical_examination = ?, diagnosis = ?, diagnosis_codes = ?, treatment_plan = ?, outcome = ?, follow_up_date = ?, follow_up_instructions = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("sssssssi", $physicalExamination, $diagnosis, $diagnosisCodes, $treatmentPlan, $outcome, $followUpDate, $followUpInstructions, $existingConsultId);
                $dbSuccess = $stmt->execute();
                $stmt->close();
            } else {
                $stmtError = $conn->error;
            }
        }
        $consultationId = $existingConsultId;
    } else {
        // Insert new consultation
        if ($submitAction === 'request_lab') {
            $sql = "INSERT INTO consultations (visit_id, patient_id, doctor_id, triage_id, physical_examination, outcome) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("iiiiss", $visitId, $patientId, $_SESSION['user_id'], $triageId, $physicalExamination, $outcome);
                $dbSuccess = $stmt->execute();
                $consultationId = $stmt->insert_id;
                $stmt->close();
            } else {
                $stmtError = $conn->error;
            }
        } else {
            // complete
            if ($hasTransferDestination) {
                $sql = "INSERT INTO consultations (visit_id, patient_id, doctor_id, triage_id, physical_examination, diagnosis, diagnosis_codes, treatment_plan, outcome, follow_up_date, follow_up_instructions) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("iiiisssssss", $visitId, $patientId, $_SESSION['user_id'], $triageId, $physicalExamination, $diagnosis, $diagnosisCodes, $treatmentPlan, $outcome, $followUpDate, $followUpInstructions);
                    $dbSuccess = $stmt->execute();
                    $consultationId = $stmt->insert_id;
                    $stmt->close();
                } else {
                    $stmtError = $conn->error;
                }
            } else {
                $sql = "INSERT INTO consultations (visit_id, patient_id, doctor_id, triage_id, physical_examination, diagnosis, diagnosis_codes, treatment_plan, outcome, follow_up_date, follow_up_instructions) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("iiiisssssss", $visitId, $patientId, $_SESSION['user_id'], $triageId, $physicalExamination, $diagnosis, $diagnosisCodes, $treatmentPlan, $outcome, $followUpDate, $followUpInstructions);
                    $dbSuccess = $stmt->execute();
                    $consultationId = $stmt->insert_id;
                    $stmt->close();
                } else {
                    $stmtError = $conn->error;
                }
            }
        }
    }
    
    if ($dbSuccess && $consultationId) {
        logActivity($existingConsultId ? 'update' : 'create', 'consultations', $consultationId);
        
        // Handle laboratory requests
        if ($submitAction === 'request_lab' && isset($_POST['lab_tests']) && is_array($_POST['lab_tests'])) {
            foreach ($_POST['lab_tests'] as $testId) {
                $testId = (int)$testId;
                $priority = sanitize($_POST['lab_priority'][$testId] ?? 'routine');
                $labNotes = sanitize($_POST['lab_notes'][$testId] ?? '');
                
                // Generate request code
                $reqResult = $conn->query("SELECT COUNT(*) as count FROM laboratory_requests WHERE DATE(requested_at) = CURDATE()");
                $reqCount = $reqResult->fetch_assoc()['count'];
                $requestCode = 'LAB' . date('Ymd') . '-' . str_pad($reqCount + 1, 3, '0', STR_PAD_LEFT);
                
                $labStmt = $conn->prepare("
                    INSERT INTO laboratory_requests 
                    (request_code, visit_id, patient_id, doctor_id, test_id, priority, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $labStmt->bind_param("siiiiss", $requestCode, $visitId, $patientId, 
                    $_SESSION['user_id'], $testId, $priority, $labNotes);
                $labStmt->execute();
                $labId = $labStmt->insert_id;
                $labStmt->close();
 
                // Add a lab invoice item to the visit/admission invoice so cashier can see it's pending
                try {
                    // get test price
                    $tRes = $conn->query("SELECT price, test_name FROM laboratory_tests WHERE id = " . intval($testId) . " LIMIT 1");
                    $testPrice = 0.0;
                    $testName = 'Lab Test';
                    if ($tRes && $tRes->num_rows > 0) {
                        $tRow = $tRes->fetch_assoc();
                        $testPrice = (float)$tRow['price'];
                        $testName = $tRow['test_name'];
                    }
 
                    // find or create pending invoice for this visit/admission
                    $invoiceId = null;
                    $invRes = $conn->query("SELECT id FROM invoices WHERE visit_id = " . intval($visitId) . " AND status IN ('pending','partial') LIMIT 1");
                    if ($invRes && $invRes->num_rows > 0) {
                        $invoiceId = (int)$invRes->fetch_assoc()['id'];
                    } else {
                        $pId = (int)$patientId;
                        $createdBy = (int)($_SESSION['user_id'] ?? 0);
                        $insSql = "INSERT INTO invoices (invoice_number, patient_id, visit_id, admission_id, total_amount, discount_amount, tax_amount, net_amount, paid_amount, balance_amount, status, created_by) VALUES ('TBD', $pId, " . intval($visitId) . ", NULL, 0, 0, 0, 0, 0, 0, 'pending', $createdBy)";
                        if ($conn->query($insSql)) {
                            $invoiceId = (int)$conn->insert_id;
                            $invoiceNumber = generateCode('INV', $invoiceId);
                            $conn->query("UPDATE invoices SET invoice_number = '" . $conn->real_escape_string($invoiceNumber) . "' WHERE id = $invoiceId");
                        }
                    }
 
                    if ($invoiceId) {
                        $desc = $conn->real_escape_string('Laboratory: ' . $testName);
                        $qty = 1;
                        $unit = $testPrice;
                        $total = round($unit * $qty, 2);
                        // insert as invoice item referencing the laboratory request
                        $conn->query("INSERT INTO invoice_items (invoice_id, service_id, item_description, quantity, unit_price, total_price, reference_type, reference_id) VALUES ($invoiceId, NULL, '$desc', $qty, $unit, $total, 'laboratory', $labId)");
                    }
                } catch (Throwable $e) {
                    // ignore billing errors here
                }
            }
        }
        
        // Handle prescriptions if action is complete
        if ($submitAction === 'complete') {
            // Handle prescriptions submitted as form arrays
            if (isset($_POST['medications']) && is_array($_POST['medications'])) {
                foreach ($_POST['medications'] as $index => $medication) {
                    if (!empty($medication)) {
                        $medName = sanitize($medication);
                        $dosage = sanitize($_POST['dosage'][$index]);
                        $frequency = sanitize($_POST['frequency'][$index]);
                        $duration = sanitize($_POST['duration'][$index]);
                        $instructions = sanitize($_POST['med_instructions'][$index]);
                        $quantity = (int)$_POST['quantity'][$index];
                        
                        $prescStmt = $conn->prepare(
                            "INSERT INTO prescriptions 
                            (consultation_id, patient_id, doctor_id, medication_name, dosage, frequency, duration, instructions, quantity)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $prescStmt->bind_param("iiisssssi", $consultationId, $patientId, 
                            $_SESSION['user_id'], $medName, $dosage, $frequency, $duration, $instructions, $quantity);
                        $prescStmt->execute();
                        $prescId = $prescStmt->insert_id;
 
                        $prescStmt->close();
                    }
                }
            }
            // Handle prescriptions saved in localStorage and exported as JSON
            elseif (!empty($_POST['prescriptions_json'])) {
                $json = $_POST['prescriptions_json'];
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $m) {
                        $medName = isset($m['medication']) ? sanitize($m['medication']) : '';
                        if (empty($medName)) continue;
                        $dosage = isset($m['dose']) ? sanitize($m['dose']) : '';
                        $frequency = isset($m['frequency']) ? sanitize($m['frequency']) : '';
                        $duration = isset($m['duration']) ? sanitize($m['duration']) : '';
                        $instructions = isset($m['instructions']) ? sanitize($m['instructions']) : '';
                        $quantity = isset($m['quantity']) ? (int)$m['quantity'] : 0;
 
                        $prescStmt = $conn->prepare(
                            "INSERT INTO prescriptions (consultation_id, patient_id, doctor_id, medication_name, dosage, frequency, duration, instructions, quantity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $prescStmt->bind_param("iiisssssi", $consultationId, $patientId, $_SESSION['user_id'], $medName, $dosage, $frequency, $duration, $instructions, $quantity);
                        $prescStmt->execute();
                        $prescId = $prescStmt->insert_id;
 
                        // Inventory deduction for JSON-submitted prescriptions
                        if ($quantity > 0) {
                            $itemId = null;
                            if (isset($m['item_id']) && (int)$m['item_id'] > 0) {
                                $itemId = (int)$m['item_id'];
                            } else {
                                $escMed = $conn->real_escape_string($medName);
                                $itRes = $conn->query("SELECT id FROM inventory_items WHERE item_name LIKE '%" . $escMed . "%' OR item_code LIKE '%" . $escMed . "%' LIMIT 1");
                                if ($itRes && $itRes->num_rows > 0) {
                                    $itRow = $itRes->fetch_assoc();
                                    $itemId = (int)$itRow['id'];
                                }
                            }
                            if ($itemId) {
                                $r = $conn->query("SELECT SUM(quantity_in_stock - quantity_reserved) as available FROM inventory_stock WHERE item_id = $itemId");
                                $avail = $r ? (int)$r->fetch_assoc()['available'] : 0;
                                if ($avail >= $quantity) {
                                    $need = $quantity;
                                    $stockRes = $conn->query("SELECT id, quantity_in_stock, quantity_reserved FROM inventory_stock WHERE item_id = $itemId AND (quantity_in_stock - quantity_reserved) > 0 ORDER BY expiry_date ASC, id ASC");
                                    while ($stockRes && $stockRes->num_rows > 0 && $need > 0) {
                                        while ($row = $stockRes->fetch_assoc()) {
                                            $stockId = (int)$row['id'];
                                            $stockQty = (int)$row['quantity_in_stock'];
                                            $reserved = (int)$row['quantity_reserved'];
                                            $availableBatch = $stockQty - $reserved;
                                            if ($availableBatch <= 0) continue;
                                            $take = min($availableBatch, $need);
                                            $newQty = $stockQty - $take;
                                            $uStmt = $conn->prepare('UPDATE inventory_stock SET quantity_in_stock = ?, last_movement_date = NOW() WHERE id = ?');
                                            $uStmt->bind_param('ii', $newQty, $stockId);
                                            $uStmt->execute();
                                            $uStmt->close();
                                            $performedBy = (int)($_SESSION['user_id'] ?? 0);
                                            $conn->query("INSERT INTO inventory_transactions (item_id, transaction_type, quantity, unit_cost, reference_type, reference_id, notes, performed_by) VALUES ($itemId, 'issue', $take, NULL, 'patient', $prescId, 'Issued for prescription ID $prescId', $performedBy)");
                                            $need -= $take;
                                            if ($need <= 0) break;
                                        }
                                        break;
                                    }
                                } else {
                                    $conn->query("UPDATE prescriptions SET instructions = CONCAT(IFNULL(instructions,''), ' [Note: insufficient stock available]') WHERE id = $prescId");
                                }
                            }
                        }
 
                        $prescStmt->close();
                    }
                }
            }
        }
        
        // Update visit status based on outcome
        $newStatus = 'discharged';
        if ($submitAction === 'request_lab') {
            $newStatus = 'in-laboratory';
        } else {
            switch ($outcome) {
                case 'laboratory-request':
                    $newStatus = 'in-laboratory';
                    break;
                case 'admission':
                    $newStatus = 'in-treatment';
                    break;
                case 'surgery':
                case 'emergency-operation':
                    $newStatus = 'in-treatment';
                    break;
                case 'transfer':
                    $newStatus = 'transferred';
                    break;
                case 'outpatient':
                case 'prescription-only':
                case 'discharge':
                default:
                    $newStatus = 'discharged';
            }
            
            // Create a consultation invoice (placed as pending so cashier can process payment)
            try {
                $consultFee = 0.00;
                $serviceId = null;
                $svcRes = $conn->query("SELECT id, price FROM services WHERE service_code IN ('CONSULT','CONSULTATION') OR service_name LIKE '%consult%' LIMIT 1");
                if ($svcRes && $svcRes->num_rows > 0) {
                    $svcRow = $svcRes->fetch_assoc();
                    $serviceId = (int)$svcRow['id'];
                    $consultFee = (float)$svcRow['price'];
                }
 
                // If we found a consultation service (or fee > 0), create an invoice so it appears in pending billing
                if ($consultFee > 0) {
                    $pId = (int)$patientId;
                    $vId = (int)$visitId;
                    $createdBy = (int)($_SESSION['user_id'] ?? 0);
 
                    // Check if this visit already has an admission (inpatient) to associate billing
                    $admissionId = 'NULL';
                    $aRes = $conn->query("SELECT id FROM admissions WHERE visit_id = $vId AND status IN ('admitted','in-treatment') LIMIT 1");
                    if ($aRes && $aRes->num_rows > 0) {
                        $aRow = $aRes->fetch_assoc();
                        $admissionId = (int)$aRow['id'];
                    }
 
                    // find or create pending invoice
                    $invoiceId = null;
                    $invRes = $conn->query("SELECT id FROM invoices WHERE visit_id = $vId AND status IN ('pending','partial') LIMIT 1");
                    if ($invRes && $invRes->num_rows > 0) {
                        $invoiceId = (int)$invRes->fetch_assoc()['id'];
                    } else {
                        $insSql = "INSERT INTO invoices (invoice_number, patient_id, visit_id, admission_id, total_amount, discount_amount, tax_amount, net_amount, paid_amount, balance_amount, status, created_by) VALUES ('TBD', $pId, $vId, " . ($admissionId === 'NULL' ? 'NULL' : $admissionId) . ", $consultFee, 0, 0, $consultFee, 0, $consultFee, 'pending', $createdBy)";
                        if ($conn->query($insSql)) {
                            $invoiceId = (int)$conn->insert_id;
                            $invoiceNumber = generateCode('INV', $invoiceId);
                            $conn->query("UPDATE invoices SET invoice_number = '" . $conn->real_escape_string($invoiceNumber) . "' WHERE id = $invoiceId");
                        }
                    }
                    
                    if ($invoiceId) {
                        // Check if consultation item already exists
                        $ciCheck = $conn->query("SELECT COUNT(*) as cnt FROM invoice_items WHERE invoice_id = $invoiceId AND reference_type = 'consultation' AND reference_id = $consultationId");
                        $hasItem = ($ciCheck && $ciCheck->num_rows > 0) ? (int)$ciCheck->fetch_assoc()['cnt'] > 0 : false;
                        if (!$hasItem) {
                            $desc = 'Consultation Fee';
                            $itemSql = "INSERT INTO invoice_items (invoice_id, service_id, item_description, quantity, unit_price, total_price, reference_type, reference_id) VALUES ($invoiceId, " . ($serviceId ? $serviceId : 'NULL') . ", '" . $conn->real_escape_string($desc) . "', 1, $consultFee, $consultFee, 'consultation', $consultationId)";
                            $conn->query($itemSql);
                        }
                        
                        // Update invoice total
                        $sum = $conn->query("SELECT COALESCE(SUM(total_price),0) AS total FROM invoice_items WHERE invoice_id = $invoiceId");
                        $totalAmt = ($sum && $sum->num_rows) ? (float)$sum->fetch_assoc()['total'] : 0.00;
                        $conn->query("UPDATE invoices SET total_amount = $totalAmt, net_amount = $totalAmt, balance_amount = $totalAmt WHERE id = $invoiceId");
 
                        logActivity('create', 'invoices', $invoiceId);
                    }
                }
            } catch (Throwable $e) {
                error_log('Failed to create consultation invoice: ' . $e->getMessage());
            }
        }
 
        // After attempting to create invoice(s), set the visit status.
        $finalVisitStatus = $newStatus;
        if ($newStatus === 'discharged') {
            if (isset($consultFee) && $consultFee > 0) {
                $finalVisitStatus = 'ready-for-discharge';
            } else {
                $finalVisitStatus = 'discharged';
            }
        }
 
        // Before updating status, ensure doctor has viewed lab results if required
        if ($submitAction === 'complete' && $mustViewLabResults && $outcome !== 'laboratory-request') {
            setFlashMessage('error', 'May laboratory result na hindi pa nabubuksan. Buksan muna ang result bago magbigay ng final outcome.');
            redirect('modules/consultation/consultation-view.php?id=' . $consultationId);
            exit;
        }
 
        $hasUpdatedBy = false;
        $colRes2 = $conn->query("SHOW COLUMNS FROM patient_visits LIKE 'updated_by'");
        if ($colRes2 && $colRes2->num_rows > 0) {
            $hasUpdatedBy = true;
        }
        $uid = (int)($_SESSION['user_id'] ?? 0);
        
        if ($hasUpdatedBy) {
            $conn->query("UPDATE patient_visits SET status = '" . $conn->real_escape_string($finalVisitStatus) . "', updated_by = $uid WHERE id = $visitId");
        } else {
            $conn->query("UPDATE patient_visits SET status = '" . $conn->real_escape_string($finalVisitStatus) . "' WHERE id = $visitId");
        }
 
        if ($submitAction === 'request_lab') {
            setFlashMessage('success', 'Laboratory requests submitted successfully. Patient is now waiting for laboratory results.');
            redirect('modules/consultation/consultations.php');
        } else {
            if ($outcome === 'admission') {
                setFlashMessage('success', 'Consultation completed successfully! Proceeding to patient admission.');
                $admissionNotes = "Admission requested from consultation.\nDiagnosis: " . $diagnosis . "\nTreatment Plan: " . $treatmentPlan;
                redirect('modules/admission/admission-add.php?patient_id=' . $patientId . '&visit_id=' . $visitId . '&doctor_id=' . $_SESSION['user_id'] . '&notes=' . urlencode($admissionNotes) . '&reason=' . urlencode($diagnosis));
            } elseif ($outcome === 'referral') {
                setFlashMessage('success', 'Consultation completed successfully! Proceeding to create referral letter.');
                redirect('modules/consultation/referral-add.php?consultation_id=' . $consultationId);
            } else {
                setFlashMessage('success', 'Consultation completed successfully!');
                redirect('modules/consultation/consultations.php');
            }
        }
    } else {
        setFlashMessage('error', 'Error saving consultation: ' . $stmtError);
    }
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
// load ICD-10 codes for the diagnosis codes dropdown
$icd10Codes = function_exists('getICDCodes') ? getICDCodes() : [];
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Patient Consultation</h1>
        <p class="page-subtitle">Examining: <?php echo $visit['first_name'] . ' ' . $visit['last_name']; ?></p>
    </div>
    <a href="consultations.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<?php
// Pre-calculate values for the redesigned professional UI/UX dashboard
$fullName = htmlspecialchars($visit['first_name'] . ' ' . $visit['last_name']);
$patientCode = htmlspecialchars($visit['patient_code']);
$age = calculateAge($visit['date_of_birth']);
$gender = htmlspecialchars($visit['gender']);
$queueNum = htmlspecialchars($visit['queue_number']);
$isPregnant = !empty($visit['is_pregnant']) && $visit['is_pregnant'];

// Dynamic BMI Calculation & Color Badges
$bmiVal = !empty($visit['bmi']) ? (float)$visit['bmi'] : null;
if ($bmiVal === null && !empty($visit['weight']) && !empty($visit['height'])) {
    $heightM = (float)$visit['height'] / 100.0;
    if ($heightM > 0) {
        $bmiVal = round((float)$visit['weight'] / ($heightM * $heightM), 1);
    }
}
$bmiClass = '';
$bmiColor = '';
if ($bmiVal !== null) {
    if ($bmiVal < 18.5) {
        $bmiClass = 'Underweight';
        $bmiColor = '#54a0ff';
    } else if ($bmiVal >= 18.5 && $bmiVal < 25) {
        $bmiClass = 'Normal';
        $bmiColor = '#2ed573';
    } else if ($bmiVal >= 25 && $bmiVal < 30) {
        $bmiClass = 'Overweight';
        $bmiColor = '#ffa502';
    } else {
        $bmiClass = 'Obese';
        $bmiColor = '#ff4757';
    }
}

// Pain Scale Details & Color Indicators
$pain = isset($visit['pain_scale']) ? (int)$visit['pain_scale'] : 0;
$painColor = '#2ed573';
$painText = 'No Pain';
if ($pain >= 1 && $pain <= 3) {
    $painColor = '#10ac84';
    $painText = 'Mild Pain';
} else if ($pain >= 4 && $pain <= 6) {
    $painColor = '#ffa502';
    $painText = 'Mod. Pain';
} else if ($pain >= 7 && $pain <= 10) {
    $painColor = '#ff4757';
    $painText = 'Severe Pain';
}
?>

<style>
/* CSS styles for the professional UI/UX Patient Dashboard Card */
.pat-dashboard-card {
    background: linear-gradient(135deg, #0f5ea8 0%, #083c6b 100%);
    border: 1px solid rgba(255, 255, 255, 0.15);
    box-shadow: var(--box-shadow-lg);
    border-radius: 16px;
    padding: 28px;
    margin-bottom: 30px;
    color: #ffffff;
    transition: all 0.3s ease;
}
.pat-dashboard-header h2 {
    font-size: 28px;
    font-weight: 800;
    letter-spacing: -0.5px;
    text-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.pat-metric-card {
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
    border-radius: 12px;
    padding: 16px;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.pat-metric-card:hover {
    transform: translateY(-2px);
    background: rgba(255, 255, 255, 0.11);
    border-color: rgba(255, 255, 255, 0.22);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
}
.pat-segmented-control {
    display: inline-flex;
    background: rgba(0, 0, 0, 0.22);
    padding: 4px;
    border-radius: 30px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    margin-bottom: 22px;
    gap: 2px;
}
.pat-tab-btn {
    border: none;
    background: transparent;
    padding: 8px 18px;
    border-radius: 20px;
    color: rgba(255, 255, 255, 0.75);
    cursor: pointer;
    font-size: 13.5px;
    font-weight: 600;
    transition: all 0.2s ease;
    outline: none;
    display: flex;
    align-items: center;
    gap: 6px;
}
.pat-tab-btn:hover {
    color: #ffffff;
    background: rgba(255, 255, 255, 0.08);
}
.pat-tab-btn.active {
    background: #ffffff;
    color: #0f5ea8;
    box-shadow: 0 4px 10px rgba(0,0,0,0.18);
}
.pat-tab-content-panel {
    background: rgba(0, 0, 0, 0.13);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    padding: 22px;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
}
.pat-detail-widget {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 8px;
    padding: 12px 16px;
    transition: all 0.2s ease;
}
.pat-detail-widget:hover {
    background: rgba(255, 255, 255, 0.08);
}
.pat-detail-widget label {
    font-size: 10px;
    font-weight: 700;
    opacity: 0.65;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 4px;
    display: block;
}
.pat-detail-widget span {
    font-size: 15px;
    font-weight: 600;
}
</style>

<!-- Redesigned Patient Info & Vitals Dashboard -->
<div class="pat-dashboard-card">
    <!-- Header Summary Section -->
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255, 255, 255, 0.15); padding-bottom: 18px; margin-bottom: 22px; flex-wrap: wrap; gap: 15px;">
        <div class="pat-dashboard-header" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <div style="background: rgba(255, 255, 255, 0.15); width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; border: 1px solid rgba(255, 255, 255, 0.25);">
                <i class="fas fa-user-injured"></i>
            </div>
            <div>
                <h2 style="margin: 0; display: inline-block; vertical-align: middle;"><?php echo $fullName; ?></h2>
                <span class="patient-code" style="margin-left: 12px; display: inline-block; vertical-align: middle; background: rgba(255, 255, 255, 0.18); padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 700; border: 1px solid rgba(255,255,255,0.1);"><?php echo $patientCode; ?></span>
                <?php if ($isPregnant): ?>
                    <span class="patient-code" style="margin-left: 8px; display: inline-block; vertical-align: middle; background: rgba(255, 193, 7, 0.25); border: 1px solid rgba(255, 193, 7, 0.4); padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 700; color: #ffeaa7;"><i class="fas fa-baby"></i> Pregnant</span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <span style="font-size: 13px; background: rgba(255, 255, 255, 0.12); padding: 6px 14px; border-radius: 8px; font-weight: 600; border: 1px solid rgba(255,255,255,0.06);">
                <i class="fas fa-ticket-alt" style="margin-right: 6px; opacity: 0.8;"></i> Queue: <strong style="color: #ffffff;"><?php echo $queueNum; ?></strong>
            </span>
            <span style="font-size: 13px; background: rgba(255, 255, 255, 0.12); padding: 6px 14px; border-radius: 8px; font-weight: 600; border: 1px solid rgba(255,255,255,0.06);">
                <i class="fas fa-venus-mars" style="margin-right: 6px; opacity: 0.8;"></i> <?php echo $age; ?> yrs / <?php echo $gender; ?>
            </span>
        </div>
    </div>

    <!-- Quick Reference Metric Cards Grid -->
    <div style="margin-bottom: 25px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
        
        <!-- Metric 1: Blood & Allergies -->
        <div class="pat-metric-card">
            <label style="font-size: 10px; opacity: 0.75; display: flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700; color: #fff;">
                <i class="fas fa-tint" style="color: #ff7675; font-size: 12px;"></i> Blood & Allergies
            </label>
            <div style="margin-top: 8px;">
                <div style="font-size: 17px; font-weight: 800;"><?php echo htmlspecialchars($visit['blood_type'] ?: 'N/A'); ?> Type</div>
                <div style="font-size: 12px; font-weight: 600; margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: <?php echo !empty($visit['allergies']) ? '#ffccd5' : '#e2f0fd'; ?>;" title="<?php echo htmlspecialchars($visit['allergies'] ?: 'No allergies recorded'); ?>">
                    <i class="fas fa-ban" style="font-size: 10px; margin-right: 4px; opacity: 0.9;"></i> <?php echo htmlspecialchars($visit['allergies'] ?: 'No allergies'); ?>
                </div>
            </div>
        </div>

        <!-- Metric 2: Blood Pressure & Heart Rate -->
        <div class="pat-metric-card">
            <label style="font-size: 10px; opacity: 0.75; display: flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700; color: #fff;">
                <i class="fas fa-heartbeat" style="color: #fd79a8; font-size: 12px;"></i> BP & Heart Rate
            </label>
            <div style="margin-top: 8px; display: flex; justify-content: space-between; align-items: baseline;">
                <div>
                    <span style="font-size: 19px; font-weight: 800;"><?php echo !empty($visit['blood_pressure']) ? htmlspecialchars($visit['blood_pressure']) : 'N/A'; ?></span>
                    <span style="font-size: 10px; opacity: 0.8; font-weight: 500;"> mmHg</span>
                </div>
                <div>
                    <span style="font-size: 16px; font-weight: 700; color: #fd79a8;"><?php echo !empty($visit['heart_rate']) ? htmlspecialchars($visit['heart_rate']) : 'N/A'; ?></span>
                    <span style="font-size: 10px; opacity: 0.8; font-weight: 500;"> bpm</span>
                </div>
            </div>
        </div>

        <!-- Metric 3: Temp & Oxygen Sat -->
        <div class="pat-metric-card">
            <label style="font-size: 10px; opacity: 0.75; display: flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700; color: #fff;">
                <i class="fas fa-thermometer-half" style="color: #ffeaa7; font-size: 12px;"></i> Temp & O2 Sat
            </label>
            <div style="margin-top: 8px; display: flex; justify-content: space-between; align-items: baseline;">
                <div>
                    <span style="font-size: 19px; font-weight: 800;"><?php echo !empty($visit['temperature']) ? htmlspecialchars($visit['temperature']) : 'N/A'; ?></span>
                    <span style="font-size: 10px; opacity: 0.8; font-weight: 500;"> °C</span>
                </div>
                <div>
                    <span style="font-size: 16px; font-weight: 700; color: #74b9ff;"><?php echo !empty($visit['oxygen_saturation']) ? htmlspecialchars($visit['oxygen_saturation']) : 'N/A'; ?></span>
                    <span style="font-size: 10px; opacity: 0.8; font-weight: 500;"> % O₂</span>
                </div>
            </div>
        </div>

        <!-- Metric 4: Weight & BMI -->
        <div class="pat-metric-card">
            <label style="font-size: 10px; opacity: 0.75; display: flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700; color: #fff;">
                <i class="fas fa-weight" style="color: #a29bfe; font-size: 12px;"></i> Weight & BMI
            </label>
            <div style="margin-top: 8px; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <span style="font-size: 16px; font-weight: 800;"><?php echo !empty($visit['weight']) ? htmlspecialchars($visit['weight']) . ' kg' : 'N/A'; ?></span>
                    <span style="font-size: 11px; opacity: 0.65; display: block; font-weight: 500; margin-top: 2px;"><?php echo !empty($visit['height']) ? htmlspecialchars($visit['height']) . ' cm' : 'N/A'; ?></span>
                </div>
                <?php if ($bmiVal !== null): ?>
                <div style="text-align: right;">
                    <span style="background: <?php echo $bmiColor; ?>; color: #12233b; padding: 2px 7px; border-radius: 6px; font-size: 11px; font-weight: 800; display: block; text-transform: uppercase; letter-spacing: 0.5px; text-align: center;">
                        <?php echo $bmiVal; ?>
                    </span>
                    <span style="font-size: 9px; opacity: 0.8; text-transform: uppercase; font-weight: 700; display: block; margin-top: 2px;"><?php echo $bmiClass; ?></span>
                </div>
                <?php else: ?>
                <span style="font-size: 13px; opacity: 0.6; font-weight: 600;">BMI: N/A</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Metric 5: Pain Scale -->
        <div class="pat-metric-card">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <label style="font-size: 10px; opacity: 0.75; display: flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700; color: #fff;">
                    <i class="fas fa-exclamation-triangle" style="color: <?php echo $painColor; ?>; font-size: 12px;"></i> Pain Scale
                </label>
                <span style="background: <?php echo $painColor; ?>; color: #12233b; padding: 2px 6px; border-radius: 6px; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">
                    <?php echo $painText; ?>
                </span>
            </div>
            <div style="margin-top: 8px;">
                <div style="font-size: 17px; font-weight: 800;"><?php echo $pain; ?> <span style="font-size: 11px; opacity: 0.7; font-weight: 500;">/ 10</span></div>
                <!-- Visual slider visualizer -->
                <div style="width: 100%; height: 6px; background: rgba(255,255,255,0.2); border-radius: 3px; overflow: hidden; margin-top: 6px; position: relative;">
                    <div style="width: <?php echo ($pain * 10); ?>%; height: 100%; background: <?php echo $painColor; ?>; border-radius: 3px; transition: width 0.3s ease;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Segmented Tab Bar -->
    <div class="pat-segmented-control">
        <button type="button" class="pat-tab-btn active" onclick="switchPatTab(event, 'pat-vitals-tab')">
            <i class="fas fa-stethoscope"></i> Vitals & Measurements
        </button>
        <button type="button" class="pat-tab-btn" onclick="switchPatTab(event, 'pat-triage-tab')">
            <i class="fas fa-clipboard-list"></i> Nursing Assessment
        </button>
        <button type="button" class="pat-tab-btn" onclick="switchPatTab(event, 'pat-history-tab')">
            <i class="fas fa-history"></i> Patient History
        </button>
        <button type="button" class="pat-tab-btn" onclick="switchPatTab(event, 'pat-contact-tab')">
            <i class="fas fa-address-book"></i> Demographics & Contact
        </button>
    </div>

    <!-- Tab Panels Container -->
    <div class="pat-tab-content-panel">
        
        <!-- Tab 1: Vitals & Measurements -->
        <div id="pat-vitals-tab" class="pat-tab-content" style="display: block;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px;">
                <div class="pat-detail-widget" style="border-left: 3px solid #ff7675;">
                    <label>Blood Pressure</label>
                    <span><?php echo !empty($visit['blood_pressure']) ? htmlspecialchars($visit['blood_pressure']) : 'N/A'; ?></span>
                    <span style="font-size: 11px; opacity: 0.6; display: inline; margin-left: 4px; font-weight: 500;">mmHg</span>
                </div>
                <div class="pat-detail-widget" style="border-left: 3px solid #fd79a8;">
                    <label>Heart Rate</label>
                    <span><?php echo !empty($visit['heart_rate']) ? htmlspecialchars($visit['heart_rate']) : 'N/A'; ?></span>
                    <span style="font-size: 11px; opacity: 0.6; display: inline; margin-left: 4px; font-weight: 500;">bpm</span>
                </div>
                <div class="pat-detail-widget" style="border-left: 3px solid #ffeaa7;">
                    <label>Temperature</label>
                    <span><?php echo !empty($visit['temperature']) ? htmlspecialchars($visit['temperature']) : 'N/A'; ?></span>
                    <span style="font-size: 11px; opacity: 0.6; display: inline; margin-left: 4px; font-weight: 500;">°C</span>
                </div>
                <div class="pat-detail-widget" style="border-left: 3px solid #81ecec;">
                    <label>Respiratory Rate</label>
                    <span><?php echo !empty($visit['respiratory_rate']) ? htmlspecialchars($visit['respiratory_rate']) : 'N/A'; ?></span>
                    <span style="font-size: 11px; opacity: 0.6; display: inline; margin-left: 4px; font-weight: 500;">breaths/min</span>
                </div>
                <div class="pat-detail-widget" style="border-left: 3px solid #74b9ff;">
                    <label>Oxygen Saturation</label>
                    <span><?php echo !empty($visit['oxygen_saturation']) ? htmlspecialchars($visit['oxygen_saturation']) : 'N/A'; ?></span>
                    <span style="font-size: 11px; opacity: 0.6; display: inline; margin-left: 4px; font-weight: 500;">% SpO₂</span>
                </div>
                <div class="pat-detail-widget" style="border-left: 3px solid #a29bfe;">
                    <label>Weight / Height</label>
                    <span><?php echo !empty($visit['weight']) ? htmlspecialchars($visit['weight']) . ' kg' : 'N/A'; ?> / <?php echo !empty($visit['height']) ? htmlspecialchars($visit['height']) . ' cm' : 'N/A'; ?></span>
                </div>
                <div class="pat-detail-widget" style="border-left: 3px solid <?php echo $bmiColor ?: 'rgba(255,255,255,0.3)'; ?>;">
                    <label>Body Mass Index (BMI)</label>
                    <span><?php echo !empty($bmiVal) ? $bmiVal : 'N/A'; ?></span>
                    <?php if ($bmiClass): ?>
                        <span style="font-size: 11px; color: <?php echo $bmiColor; ?>; display: inline; margin-left: 8px; font-weight: 700; text-transform: uppercase;"><?php echo $bmiClass; ?></span>
                    <?php endif; ?>
                </div>
                <div class="pat-detail-widget" style="border-left: 3px solid <?php echo $painColor; ?>;">
                    <label>Pain Scale Rating</label>
                    <span><?php echo $pain; ?> / 10</span>
                    <span style="font-size: 11px; color: <?php echo $painColor; ?>; display: inline; margin-left: 8px; font-weight: 700; text-transform: uppercase;"><?php echo $painText; ?></span>
                </div>
            </div>
        </div>

        <!-- Tab 2: Nursing Assessment -->
        <div id="pat-triage-tab" class="pat-tab-content" style="display: none;">
            <div style="display: grid; grid-template-columns: 1fr; gap: 15px;">
                <div style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255,255,255,0.06); padding: 16px; border-radius: 8px;">
                    <label style="font-size: 10px; opacity: 0.65; display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700;"><i class="fas fa-comment-medical" style="margin-right: 5px;"></i> Symptoms / Chief Complaint Details</label>
                    <span style="font-size: 14px; font-weight: 500; display: block; line-height: 1.5; white-space: pre-wrap; color: #f1f6fb;"><?php echo htmlspecialchars($visit['symptoms'] ?: ($visit['chief_complaint'] ?: 'None recorded')); ?></span>
                </div>
                <div style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255,255,255,0.06); padding: 16px; border-radius: 8px;">
                    <label style="font-size: 10px; opacity: 0.65; display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700;"><i class="fas fa-clipboard-check" style="margin-right: 5px;"></i> Nurse Notes & General Observations</label>
                    <span style="font-size: 14px; font-weight: 500; display: block; line-height: 1.5; white-space: pre-wrap; color: #f1f6fb;"><?php echo htmlspecialchars($visit['notes'] ?: 'None recorded'); ?></span>
                </div>
                <?php if ($isPregnant): ?>
                <div style="background: rgba(255, 193, 7, 0.03); border: 1px dashed rgba(255, 193, 7, 0.35); padding: 18px; border-radius: 8px;">
                    <label style="font-size: 12px; font-weight: 700; opacity: 0.95; display: block; margin-bottom: 12px; color: #ffeaa7;"><i class="fas fa-baby" style="margin-right: 6px;"></i> Obstetric/Pregnancy Assessment Details</label>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px;">
                        <div class="pat-detail-widget" style="background: rgba(255,255,255,0.04);">
                            <label>Weeks of Pregnancy</label>
                            <span><?php echo !empty($visit['weeks_of_pregnancy']) ? htmlspecialchars($visit['weeks_of_pregnancy']) . ' weeks' : 'N/A'; ?></span>
                        </div>
                        <div class="pat-detail-widget" style="background: rgba(255,255,255,0.04);">
                            <label>Fetal Heartbeat</label>
                            <span><?php echo !empty($visit['fetal_heartbeat']) ? htmlspecialchars($visit['fetal_heartbeat']) . ' bpm' : 'N/A'; ?></span>
                        </div>
                        <div class="pat-detail-widget" style="background: rgba(255,255,255,0.04);">
                            <label>Contractions</label>
                            <span><?php echo !empty($visit['contractions']) ? htmlspecialchars($visit['contractions']) : 'N/A'; ?></span>
                        </div>
                        <div class="pat-detail-widget" style="background: rgba(255,255,255,0.04);">
                            <label>Cervix Dilation</label>
                            <span><?php echo !empty($visit['cervix_dilation']) ? htmlspecialchars($visit['cervix_dilation']) . ' cm' : 'N/A'; ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div style="font-size: 11px; opacity: 0.7; text-align: right; margin-top: 5px;">
                    Assessed by: <strong style="color: #ffffff;"><?php echo htmlspecialchars($visit['nurse_name'] ?? 'Nurse/Staff'); ?></strong> on <?php echo !empty($visit['triage_created_at']) ? formatDateTime($visit['triage_created_at']) : 'N/A'; ?>
                </div>
            </div>
        </div>

        <!-- Tab 3: Patient History -->
        <div id="pat-history-tab" class="pat-tab-content" style="display: none;">
            <div style="display: grid; grid-template-columns: 1fr; gap: 14px;">
                <div style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255,255,255,0.06); padding: 16px; border-radius: 8px;">
                    <label style="font-size: 10px; opacity: 0.65; display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700;"><i class="fas fa-history" style="margin-right: 5px;"></i> General Medical History (Assessment Notes)</label>
                    <span style="font-size: 14px; font-weight: 500; display: block; line-height: 1.5; white-space: pre-wrap; color: #f1f6fb;"><?php echo htmlspecialchars($generalMedicalHistory ?: 'No general medical history recorded.'); ?></span>
                </div>
                <div style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255,255,255,0.06); padding: 16px; border-radius: 8px;">
                    <label style="font-size: 10px; opacity: 0.65; display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700;"><i class="fas fa-users" style="margin-right: 5px;"></i> Family Medical History</label>
                    <span style="font-size: 14px; font-weight: 500; display: block; line-height: 1.5; white-space: pre-wrap; color: #f1f6fb;"><?php echo htmlspecialchars($visit['family_medical_history'] ?: 'No family medical history recorded.'); ?></span>
                </div>
            </div>
        </div>

        <!-- Tab 4: Demographics & Contact -->
        <div id="pat-contact-tab" class="pat-tab-content" style="display: none;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px;">
                <div class="pat-detail-widget">
                    <label>Civil Status</label>
                    <span><?php echo htmlspecialchars($visit['civil_status'] ?: 'N/A'); ?></span>
                </div>
                <div class="pat-detail-widget">
                    <label>Contact Number</label>
                    <span><i class="fas fa-phone-alt" style="font-size: 11px; margin-right: 4px; opacity: 0.8;"></i> <?php echo htmlspecialchars($visit['contact_number'] ?: 'N/A'); ?></span>
                </div>
                <div class="pat-detail-widget">
                    <label>Email Address</label>
                    <span><i class="fas fa-envelope" style="font-size: 11px; margin-right: 4px; opacity: 0.8;"></i> <?php echo htmlspecialchars($visit['email'] ?: 'N/A'); ?></span>
                </div>
                <div class="pat-detail-widget" style="grid-column: span 2;">
                    <label>Residential Address</label>
                    <span><i class="fas fa-map-marker-alt" style="font-size: 11px; margin-right: 4px; opacity: 0.8;"></i> <?php echo htmlspecialchars($visit['address'] ?: 'N/A'); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function switchPatTab(e, tabId) {
    e.preventDefault();
    // Hide all tab contents
    document.querySelectorAll('.pat-tab-content').forEach(el => {
        el.style.display = 'none';
    });
    // Remove active class and reset styles of all tab buttons
    document.querySelectorAll('.pat-tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    // Show selected tab content
    document.getElementById(tabId).style.display = 'block';
    // Add active class to button
    e.currentTarget.classList.add('active');
}
</script>

<form method="POST" action="" id="consultationForm">
    <input type="hidden" name="submit_action" id="submit_action" value="complete">

    <!-- Progress steps indicator bar -->
    <ul class="wizard-steps">
        <li class="wizard-step active" data-step="1">
            <span class="step-num">1</span>
            <span>Physical Exam</span>
        </li>
        <li class="wizard-step" data-step="2">
            <span class="step-num">2</span>
            <span>Lab Request</span>
        </li>
        <li class="wizard-step" data-step="3">
            <span class="step-num">3</span>
            <span>Diagnosis</span>
        </li>
        <li class="wizard-step" data-step="4">
            <span class="step-num">4</span>
            <span>Treatment Plan</span>
        </li>
        <li class="wizard-step" data-step="5">
            <span class="step-num">5</span>
            <span>Prescriptions</span>
        </li>
    </ul>

    <!-- Step 1: Physical Examination -->
    <div class="wizard-panel active" id="panel_1">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-stethoscope"></i> Physical Examination</h3>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Physical Examination Findings</label>
                        <select id="phys_choice" class="form-control">
                            <option value="">-- Select finding --</option>
                            <option value="Normal">Normal</option>
                            <option value="Respiratory: rales">Respiratory: rales</option>
                            <option value="Respiratory: wheeze">Respiratory: wheeze</option>
                            <option value="Cardiac: murmur">Cardiac: murmur</option>
                            <option value="Abdomen: tenderness">Abdomen: tenderness</option>
                            <option value="Neurologic deficit">Neurologic deficit</option>
                            <option value="Jaundice">Jaundice</option>
                            <option value="Pallor">Pallor</option>
                            <option value="Other">Other (specify)</option>
                        </select>
                        <textarea name="physical_examination" id="physical_examination" class="form-control" rows="4" placeholder="Document your physical examination findings here..."><?php echo htmlspecialchars($physicalExamination ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-group" style="text-align: right; margin-top: 20px;">
            <a href="consultations.php" class="btn btn-secondary">Cancel</a>
            <button type="button" class="btn btn-primary btn-next-step" onclick="changeStep(2)">
                Next Step <i class="fas fa-arrow-right"></i>
            </button>
        </div>
    </div>
    
    <!-- Step 2: Laboratory Requests -->
    <div class="wizard-panel" id="panel_2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-vials"></i> Laboratory Requests</h3>
            </div>
            <div class="card-body">
                <p class="text-muted">Select any laboratory tests needed for this patient. If no tests are required, you can click <strong>Skip Laboratory Request</strong> to continue to the Diagnosis step.</p>
                <div class="form-row" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px;">
                    <?php if ($labTests && $labTests->num_rows > 0): ?>
                        <?php while ($test = $labTests->fetch_assoc()): ?>
                        <div style="border: 1px solid var(--border-color); padding: 12px; border-radius: 8px; background: var(--surface-muted);">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                <input type="checkbox" name="lab_tests[]" value="<?php echo $test['id']; ?>" id="lab_<?php echo $test['id']; ?>" class="lab-checkbox">
                                <label for="lab_<?php echo $test['id']; ?>" style="margin: 0; font-weight: 600; cursor: pointer;"><?php echo htmlspecialchars($test['test_name']); ?></label>
                            </div>
                            <div class="lab-options-fields" style="display: none; padding-left: 24px; border-left: 2px solid var(--primary-color);">
                                <div style="margin-bottom: 8px;">
                                    <label style="font-size: 12px; display: block; margin-bottom: 4px;">Priority</label>
                                    <select name="lab_priority[<?php echo $test['id']; ?>]" class="form-control" style="font-size: 12px; padding: 4px 8px; height: auto; width: 100%;">
                                        <option value="routine">Routine</option>
                                        <option value="urgent">Urgent</option>
                                        <option value="stat">STAT</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size: 12px; display: block; margin-bottom: 4px;">Specific Notes / Instructions</label>
                                    <textarea name="lab_notes[<?php echo $test['id']; ?>]" class="form-control" rows="2" style="font-size: 12px; width: 100%;" placeholder="Instructions for the lab technician..."></textarea>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="text-muted">No active laboratory tests found in configuration.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
            <button type="button" class="btn btn-secondary" onclick="changeStep(1)">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-info" onclick="changeStep(3)">
                    Skip Laboratory Request <i class="fas fa-arrow-right"></i>
                </button>
                <button type="button" class="btn btn-warning" onclick="submitLabRequest()">
                    <i class="fas fa-save"></i> Submit Lab Request & Place on Hold
                </button>
            </div>
        </div>
    </div>

    <!-- Step 3: Diagnosis -->
    <div class="wizard-panel" id="panel_3">
        <!-- Laboratory Results display if present -->
        <?php if (!empty($labResults)): ?>
            <div class="lab-results-box">
                <h4 style="color: var(--success-color); font-weight: bold;"><i class="fas fa-vial"></i> Laboratory Results Received</h4>
                <div style="display: flex; flex-direction: column; gap: 15px; margin-top: 10px;">
                    <?php foreach ($labResults as $lr): ?>
                        <div class="result-item" style="background: var(--surface-color); padding: 12px; border-radius: 8px; border: 1px solid var(--border-color);">
                            <div style="display:flex; justify-content:space-between; margin-bottom: 6px; align-items: center;">
                                <strong style="font-size: 15px; color: var(--text-color);"><?php echo htmlspecialchars($lr['test_name']); ?></strong>
                                <span class="badge badge-success" style="text-transform: uppercase;"><?php echo htmlspecialchars($lr['priority']); ?></span>
                            </div>
                            <div style="margin-bottom: 6px;">
                                <span style="font-size: 13px; color: var(--text-muted);">Result Value:</span>
                                <div style="font-size: 14px; font-weight: bold; background: var(--surface-muted); padding: 8px; border-radius: 4px; margin-top: 2px;">
                                    <?php echo nl2br(htmlspecialchars($lr['result_value'])); ?>
                                </div>
                            </div>
                            <?php if (!empty($lr['interpretation'])): ?>
                                <div style="margin-bottom: 6px;">
                                    <span style="font-size: 13px; color: var(--text-muted);">Interpretation:</span>
                                    <div style="font-size: 13px; background: var(--surface-muted); padding: 6px; border-radius: 4px; margin-top: 2px; color: var(--text-color);">
                                        <?php echo htmlspecialchars($lr['interpretation']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($lr['remarks'])): ?>
                                <div style="margin-bottom: 4px;">
                                    <span style="font-size: 13px; color: var(--text-muted);">Remarks:</span>
                                    <div style="font-size: 13px; background: var(--surface-muted); padding: 6px; border-radius: 4px; margin-top: 2px; color: var(--text-color);">
                                        <?php echo htmlspecialchars($lr['remarks']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 6px; text-align: right;">
                                Completed: <?php echo htmlspecialchars($lr['created_at']); ?> by <?php echo htmlspecialchars($lr['tech_name'] ?? 'Lab Tech'); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-diagnoses"></i> Diagnosis</h3>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Diagnosis <span style="color: red;">*</span></label>
                        <select id="diagnosis_choice" class="form-control" required>
                            <option value="">-- Select diagnosis --</option>
                            <option value="Upper respiratory infection">Upper respiratory infection</option>
                            <option value="Gastroenteritis">Gastroenteritis</option>
                            <option value="Urinary tract infection">Urinary tract infection</option>
                            <option value="Hypertensive crisis">Hypertensive crisis</option>
                            <option value="Diabetic ketoacidosis">Diabetic ketoacidosis</option>
                            <option value="Fracture">Fracture</option>
                            <option value="Other">Other (specify)</option>
                        </select>
                        <textarea name="diagnosis" id="diagnosis" class="form-control" rows="3" placeholder="Enter your diagnosis..."><?php echo htmlspecialchars($diagnosis ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Diagnosis Codes (ICD-10)</label>
                        <select name="diagnosis_codes" class="form-control">
                            <option value="">-- Select ICD-10 code --</option>
                            <?php foreach ($icd10Codes as $code => $desc): ?>
                                <option value="<?php echo htmlspecialchars($code); ?>" <?php echo ($diagnosisCodes === $code) ? 'selected' : ''; ?>><?php echo htmlspecialchars($code . ' - ' . $desc); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
            <button type="button" class="btn btn-secondary btn-back-to-2" onclick="changeStep(2)">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <button type="button" class="btn btn-primary" onclick="validateDiagnosisStep()">
                Next Step <i class="fas fa-arrow-right"></i>
            </button>
        </div>
    </div>

    <!-- Step 4: Treatment Plan -->
    <div class="wizard-panel" id="panel_4">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-clipboard-list"></i> Treatment Plan</h3>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Treatment Plan</label>
                        <select name="treatment_choice" id="treatment_choice" class="form-control">
                            <option value="">-- Select treatment plan --</option>
                            <option value="Medication and Observation">Medication and Observation</option>
                            <option value="Admission">Admission</option>
                            <option value="Referral">Referral</option>
                            <option value="Other">Other (specify)</option>
                        </select>
                        <textarea name="treatment_plan" id="treatment_plan" class="form-control" rows="3" placeholder="Describe the treatment plan..."><?php echo htmlspecialchars($treatmentPlan ?? ''); ?></textarea>
                    </div>
                </div>
                
                <h4 style="margin-top: 25px; margin-bottom: 12px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">Follow-up Schedule</h4>
                <div class="form-row" style="display:flex; gap:12px;">
                    <div class="form-group" style="flex:1; min-width:200px;">
                        <label class="form-label">Follow-up Date</label>
                        <input type="date" name="follow_up_date" class="form-control" value="<?php echo htmlspecialchars($followUpDate ?? ''); ?>">
                    </div>
                    <div class="form-group" style="flex:2; min-width:300px;">
                        <label class="form-label">Follow-up Instructions</label>
                        <textarea name="follow_up_instructions" class="form-control" rows="3" placeholder="Diet, activity constraints, follow-up parameters..."><?php echo htmlspecialchars($followUpInstructions ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
            <button type="button" class="btn btn-secondary" onclick="changeStep(3)">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <button type="button" class="btn btn-primary" onclick="changeStep(5)">
                Next Step <i class="fas fa-arrow-right"></i>
            </button>
        </div>
    </div>

    <!-- Step 5: Prescriptions -->
    <div class="wizard-panel" id="panel_5">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-prescription"></i> Prescriptions</h3>
            </div>
            <div class="card-body">
                <p class="text-muted">Add prescriptions here. Fields are optional — you can leave prescriptions blank and still complete the consultation.</p>
                <div id="medicationsContainer"></div>
 
                <div style="margin-top:10px;">
                    <button id="addMedicationBtn" type="button" class="btn btn-sm btn-primary" onclick="addMedication()"><i class="fas fa-plus"></i> Add Medication</button>
                    <div id="prescNotifContainer" style="display:inline-block; margin-left:12px; vertical-align:middle;"></div>
                </div>
            </div>
        </div>
        <div class="form-group" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
            <button type="button" class="btn btn-secondary" onclick="changeStep(4)">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <div style="display: flex; gap: 10px;">
                <a href="consultations.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check-circle"></i> Complete Consultation
                </button>
            </div>
        </div>
    </div>
</form>

<script>
let medicationCount = 0;

function addMedication(prefill = {}) {
    // Prevent adding a new row if the last row exists and medication name is empty
    const container = document.getElementById('medicationsContainer');
    const lastRow = container.lastElementChild;
    if (lastRow) {
        const lastMed = lastRow.querySelector('[name="medications[]"]');
        if (lastMed && lastMed.value.trim() === '') {
            showPrescNotification('May nakabukas na medication row. Punan muna bago mag-add.');
            return;
        }
    }

    medicationCount++;
    const div = document.createElement('div');
    div.className = 'form-row medication-row';
    div.style.marginBottom = '15px';
    div.style.paddingBottom = '15px';
    div.style.borderBottom = '1px solid #eee';
    div.innerHTML = `
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
            <div style="flex:1; min-width:160px;">
                <label class="form-label">Prescription Code</label>
                <input type="text" name="prescription_codes[]" class="form-control" readonly value="${prefill.code || generatePrescriptionCode()}">
            </div>
            <div style="flex:2; min-width:220px;">
                <label class="form-label">Medication Name</label>
                <input type="text" name="medications[]" class="form-control med-name-input" placeholder="e.g., Paracetamol" value="${prefill.medication || ''}">
            </div>
            <div style="flex:1; min-width:140px;">
                <label class="form-label">Strength / Form</label>
                <input type="text" name="strength_forms[]" class="form-control" placeholder="500 mg / tablet" value="${prefill.strength || ''}">
            </div>
            <div style="flex:1; min-width:120px;">
                <label class="form-label">Route</label>
                <select name="route[]" class="form-control">
                    <option value="oral" ${prefill.route==='oral' ? 'selected' : ''}>Oral</option>
                    <option value="iv" ${prefill.route==='iv' ? 'selected' : ''}>IV</option>
                    <option value="im" ${prefill.route==='im' ? 'selected' : ''}>IM</option>
                    <option value="topical" ${prefill.route==='topical' ? 'selected' : ''}>Topical</option>
                </select>
            </div>
            <div style="flex:1; min-width:120px;">
                <label class="form-label">Dose</label>
                <input type="text" name="dosage[]" class="form-control" placeholder="e.g., 1 tablet" value="${prefill.dose || ''}">
            </div>
            <div style="flex:1; min-width:140px;">
                <label class="form-label">Frequency</label>
                <input type="text" name="frequency[]" class="form-control" placeholder="e.g., 3x daily" value="${prefill.frequency || ''}">
            </div>
            <div style="flex:1; min-width:120px;">
                <label class="form-label">Duration</label>
                <input type="text" name="duration[]" class="form-control" placeholder="e.g., 5 days" value="${prefill.duration || ''}">
            </div>
            <div style="flex:1; min-width:100px;">
                <label class="form-label">Qty</label>
                <input type="number" name="quantity[]" class="form-control" placeholder="Qty" value="${prefill.quantity || ''}">
            </div>
            <div style="flex:1; min-width:100px;">
                <label class="form-label">Refills</label>
                <input type="number" name="refills[]" class="form-control" value="${prefill.refills || 0}" min="0">
            </div>
            <div style="flex:1 1 100%;">
                <label class="form-label">Instructions</label>
                <input type="text" name="med_instructions[]" class="form-control" placeholder="Patient directions" value="${prefill.instructions || ''}">
            </div>
            <div style="display:flex; align-items:flex-end;">
                <button type="button" class="btn btn-danger" onclick="this.closest('.medication-row').remove()">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `;
    container.appendChild(div);
    // focus the medication input of the newly added row
    setTimeout(() => {
        const input = div.querySelector('.med-name-input');
        if (input) input.focus();
    }, 50);

    // No medication autocomplete suggestions are used; the doctor can type the medication freely.
    const medInput = div.querySelector('.med-name-input');
    if (medInput) {
        medInput.addEventListener('input', function(e){
            // free-text medication entry
        });
    }
}

function showPrescNotification(msg, timeout = 2500) {
    const c = document.getElementById('prescNotifContainer');
    if (!c) { alert(msg); return; }
    c.innerHTML = `<div id="prescNotif" class="alert alert-info" style="display:inline-block; padding:6px 10px; margin:0;">${msg}</div>`;
    if (window._prescNotifTimer) clearTimeout(window._prescNotifTimer);
    window._prescNotifTimer = setTimeout(() => { if (c) c.innerHTML = ''; }, timeout);
}

function generatePrescriptionCode() {
    const now = new Date();
    const ts = now.getFullYear().toString().slice(-2) + ('0'+(now.getMonth()+1)).slice(-2) + ('0'+now.getDate()).slice(-2) + now.getTime().toString().slice(-5);
    const rand = Math.floor(Math.random() * 900 + 100);
    return 'RX' + ts + '-' + rand;
}

function getLocalKey() {
    return 'prescriptions_visit_' + <?php echo (int)$visitId; ?>;
}

function savePrescriptionsLocal() {
    // Local save removed per UX request. This function is intentionally empty.
}

// saved-prescription helpers removed per UX preference

// export to hidden input so server can receive JSON as well
function exportPrescriptionsToHidden() {
    const rows = document.querySelectorAll('.medication-row');
    const meds = [];
    rows.forEach(row => {
        const getVal = (sel) => { const el = row.querySelector(sel); return el ? el.value.trim() : ''; };
        const m = {
            code: getVal('[name="prescription_codes[]"]'),
            medication: getVal('[name="medications[]"]'),
            strength: getVal('[name="strength_forms[]"]'),
            route: getVal('[name="route[]"]'),
            dose: getVal('[name="dosage[]"]'),
            frequency: getVal('[name="frequency[]"]'),
            duration: getVal('[name="duration[]"]'),
            quantity: getVal('[name="quantity[]"]') || 0,
            refills: getVal('[name="refills[]"]') || 0,
            instructions: getVal('[name="med_instructions[]"]')
        };
        // Only include entries with a medication name or quantity > 0
        if (m.medication !== '' || parseInt(m.quantity) > 0) meds.push(m);
    });

    let hidden = document.getElementById('prescriptions_json_input');
    if (!hidden) {
        hidden = document.createElement('input');
        hidden.type='hidden';
        hidden.name='prescriptions_json';
        hidden.id='prescriptions_json_input';
        document.getElementById('consultationForm').appendChild(hidden);
    }
    hidden.value = JSON.stringify(meds);
}

let currentStep = 1;
const isResume = <?php echo ($existingConsult !== null) ? 'true' : 'false'; ?>;

function changeStep(step) {
    if (step < 1 || step > 5) return;
    
    // Hide all panels
    document.querySelectorAll('.wizard-panel').forEach(p => p.classList.remove('active'));
    // Show current panel
    const panel = document.getElementById('panel_' + step);
    if (panel) panel.classList.add('active');
    
    // Update step indicator classes
    document.querySelectorAll('.wizard-step').forEach(s => {
        const sNum = parseInt(s.getAttribute('data-step'));
        s.classList.remove('active', 'completed');
        if (sNum === step) {
            s.classList.add('active');
        } else if (sNum < step) {
            s.classList.add('completed');
        }
    });
    
    currentStep = step;
    
    // Scroll to top of form
    const form = document.getElementById('consultationForm');
    if (form) {
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function validateDiagnosisStep() {
    const diagChoice = document.getElementById('diagnosis_choice');
    const diagField = document.getElementById('diagnosis');
    
    if (diagChoice.value === '') {
        alert('Please select or specify a diagnosis.');
        diagChoice.focus();
        return;
    }
    
    if (diagChoice.value === 'Other' && diagField.value.trim() === '') {
        alert('Please specify the diagnosis details.');
        diagField.focus();
        return;
    }
    
    changeStep(4);
}

function submitLabRequest() {
    const checked = document.querySelectorAll('.lab-checkbox:checked');
    if (checked.length === 0) {
        alert('Please select at least one laboratory test to request, or click "Skip Laboratory Request".');
        return;
    }
    
    document.getElementById('submit_action').value = 'request_lab';
    document.getElementById('consultationForm').submit();
}

document.addEventListener('DOMContentLoaded', function() {
    // Wire up lab checkboxes toggle
    document.querySelectorAll('.lab-checkbox').forEach(cb => {
        const toggleFields = () => {
            const fields = cb.closest('div').nextElementSibling;
            if(fields) {
                fields.style.display = cb.checked ? 'block' : 'none';
            }
        };
        cb.addEventListener('change', toggleFields);
        toggleFields();
    });

    // Check if we should initialize at Step 3 (Resume)
    if (isResume) {
        changeStep(3);
    } else {
        changeStep(1);
    }
});

document.getElementById('consultationForm').addEventListener('submit', function(e){
    const action = document.getElementById('submit_action').value;
    if (action === 'complete') {
        const diagChoice = document.getElementById('diagnosis_choice');
        const diagField = document.getElementById('diagnosis');
        
        if (diagChoice.value === '' || (diagChoice.value === 'Other' && diagField.value.trim() === '')) {
            e.preventDefault();
            alert('Please complete the Diagnosis step before finalizing the consultation.');
            changeStep(3);
            return;
        }
    }
    exportPrescriptionsToHidden();
});
function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[c]; });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
// Physical Examination dropdown + custom input
(function(){
    var physChoice = document.getElementById('phys_choice');
    var physField = document.getElementById('physical_examination');
    var existing = <?php echo json_encode(isset($physicalExamination) ? $physicalExamination : ''); ?>;

    function syncPhys(){
        var v = physChoice.value;
        if (v === 'Other'){
            physField.style.display = '';
            physField.readOnly = false;
            if (!physField.value && existing) physField.value = existing;
        } else if (v === ''){
            physField.style.display = 'none';
            if (!existing) physField.value = '';
        } else {
            physField.style.display = 'none';
            physField.value = v;
            physField.readOnly = true;
        }
    }

    // initialize select based on existing value
    if (existing && ["Normal","Respiratory: rales","Respiratory: wheeze","Cardiac: murmur","Abdomen: tenderness","Neurologic deficit","Jaundice","Pallor"].indexOf(existing) !== -1){
        physChoice.value = existing;
    } else if (existing && existing !== ''){
        physChoice.value = 'Other';
    } else {
        physChoice.value = '';
    }

    physChoice.addEventListener('change', syncPhys);
    syncPhys();
})();

// Diagnosis dropdown + custom input
(function(){
    var diagChoice = document.getElementById('diagnosis_choice');
    var diagField = document.getElementById('diagnosis');
    var existingDiag = <?php echo json_encode(isset($diagnosis) ? $diagnosis : ($latestConsult['diagnosis'] ?? '')); ?>;

    function syncDiag(){
        var v = diagChoice.value;
        if (v === 'Other'){
            diagField.style.display = '';
            diagField.readOnly = false;
            if (!diagField.value && existingDiag) diagField.value = existingDiag;
        } else if (v === ''){
            diagField.style.display = 'none';
            if (!existingDiag) diagField.value = '';
        } else {
            diagField.style.display = 'none';
            diagField.value = v;
            diagField.readOnly = true;
        }
    }

    // initialize select based on existing value
    if (existingDiag && ["Upper respiratory infection","Gastroenteritis","Urinary tract infection","Hypertensive crisis","Diabetic ketoacidosis","Fracture"].indexOf(existingDiag) !== -1){
        diagChoice.value = existingDiag;
    } else if (existingDiag && existingDiag !== ''){
        diagChoice.value = 'Other';
    } else {
        diagChoice.value = '';
    }

    diagChoice.addEventListener('change', syncDiag);
    diagField.addEventListener('input', function(){ if (diagChoice.value === 'Other') { /* keep display updated */ } });
    syncDiag();
})();

// Treatment Plan dropdown + custom input
(function(){
    var treatChoice = document.getElementById('treatment_choice');
    var treatField = document.getElementById('treatment_plan');
    var existingTreat = <?php echo json_encode(isset($treatmentPlan) ? $treatmentPlan : ''); ?>;

    function syncTreat(){
        var v = treatChoice.value;
        if (v === 'Other'){
            treatField.style.display = '';
            treatField.readOnly = false;
            if (!treatField.value && existingTreat) treatField.value = existingTreat;
        } else if (v === ''){
            treatField.style.display = 'none';
            if (!existingTreat) treatField.value = '';
        } else {
            treatField.style.display = 'none';
            treatField.value = v;
            treatField.readOnly = true;
        }
    }

    if (existingTreat && ["Medication and Observation","Admission","Referral"].indexOf(existingTreat) !== -1){
        treatChoice.value = existingTreat;
    } else if (existingTreat && existingTreat !== ''){
        treatChoice.value = 'Other';
    } else {
        treatChoice.value = '';
    }

    treatChoice.addEventListener('change', syncTreat);
    treatField.addEventListener('input', function(){ /* keep updated for POST */ });
    syncTreat();
})();
</script>
