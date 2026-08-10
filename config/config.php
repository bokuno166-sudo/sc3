<?php
/**
 * Saint Claire Hospital Management System
 * Configuration File
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'prototype1');

// Application Configuration
define('APP_NAME', 'Saint Claire Hospital Management System');
define('APP_SHORT_NAME', 'St. Claire HMS');
define('APP_VERSION', '1.0.0');
// Detect base URL robustly (use DOCUMENT_ROOT and config directory)
if (!defined('BASE_URL')) {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

    // Try to compute base path from filesystem paths
    $basePath = '/';
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docRoot = realpath(rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/'));
        $configDir = realpath(__DIR__);
        if ($docRoot && $configDir && strpos(str_replace('\\', '/', $configDir), str_replace('\\', '/', $docRoot)) === 0) {
            $relative = str_replace('\\', '/', substr($configDir, strlen($docRoot)));
            // remove '/config' part
            $relative = preg_replace('#/config$#', '', $relative);
            $basePath = $relative === '' ? '/' : rtrim($relative, '/') . '/';
        } else {
            // fallback to script dirname
            $scriptDir = isset($_SERVER['SCRIPT_NAME']) ? dirname($_SERVER['SCRIPT_NAME']) : '/';
            $basePath = rtrim($scriptDir, '/') . '/';
        }
    } else {
        $scriptDir = isset($_SERVER['SCRIPT_NAME']) ? dirname($_SERVER['SCRIPT_NAME']) : '/';
        $basePath = rtrim($scriptDir, '/') . '/';
    }

    define('BASE_URL', $protocol . '://' . $host . $basePath);
}
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('MAX_UPLOAD_SIZE', 5242880); // 5MB

// Session Configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Timezone
date_default_timezone_set('Asia/Manila');

// Error Reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database Connection
function getDBConnection() {
    // Try connecting with a small retry/backoff and avoid throwing uncaught exceptions
    $attempt = 0;
    $maxAttempts = 3;
    $waitBase = 100000; // microseconds (100ms)

    // Temporarily disable mysqli exceptions/reports so we can handle failures gracefully
    $prevReport = mysqli_report(MYSQLI_REPORT_OFF);

    while ($attempt < $maxAttempts) {
        $attempt++;
        $conn = @new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);
        if ($conn && !$conn->connect_errno) {
            // restore reporting to previous state
            mysqli_report($prevReport);
            $conn->set_charset("utf8mb4");
            return $conn;
        }

        // Log the failure (don't expose credentials in logs)
        $err = $conn ? $conn->connect_error : 'unknown';
        error_log("getDBConnection attempt {$attempt} failed: {$err}");

        // Don't attempt to close partially opened connections here — avoid double-close Errors
        // Let PHP clean up the object; just log the attempt.

        // short exponential backoff
        usleep($waitBase * $attempt);
    }

    // If we reach here, connection attempts failed. Provide a friendly message and stop.
    // Prefer logging details and show a generic message to the user.
    error_log('getDBConnection: unable to connect to MySQL after ' . $maxAttempts . ' attempts.');

    // Restore reporting
    mysqli_report($prevReport);

    http_response_code(500);
    echo "<h1>Database connection error</h1>";
    echo "<p>Unable to connect to the database server. Please ensure MySQL is running (start it via XAMPP Control Panel) and check your database configuration.</p>";
    echo "<p>If the problem persists, check the server error log for details.</p>";
    exit;
}

// Helper Functions
function sanitize($data) {
    $conn = getDBConnection();
    // Coerce to string to avoid passing null to trim() (PHP 8.1+ deprecation)
    $data = trim((string)$data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    $data = $conn->real_escape_string($data);
    $conn->close();
    return $data;
}

function generateCode($prefix, $id) {
    return $prefix . date('Y') . str_pad($id, 5, '0', STR_PAD_LEFT);
}

function formatDate($date, $format = 'M d, Y') {
    if (!$date) return 'N/A';
    return date($format, strtotime($date));
}

function formatDateTime($datetime, $format = 'M d, Y h:i A') {
    if (!$datetime) return 'N/A';
    return date($format, strtotime($datetime));
}

function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}

function calculateAge($birthdate) {
    $birthDate = new DateTime($birthdate);
    $today = new DateTime('today');
    return $birthDate->diff($today)->y;
}

function getStatusBadge($status) {
    $badges = [
        'active' => 'success',
        'inactive' => 'secondary',
        'pending' => 'warning',
        'completed' => 'success',
        'cancelled' => 'danger',
        'waiting' => 'info',
        'in-progress' => 'primary',
        'admitted' => 'primary',
        'discharged' => 'success',
        'paid' => 'success',
        'partial' => 'warning',
        'available' => 'success',
        'occupied' => 'danger',
        'maintenance' => 'warning',
        'ready-for-discharge' => 'warning'
    ];
    
    $badgeClass = isset($badges[$status]) ? $badges[$status] : 'secondary';
    $label = ucfirst(str_replace('-', ' ', $status));
    return '<span class="badge badge-' . $badgeClass . '">' . $label . '</span>';
}

function redirect($url) {
    header("Location: " . BASE_URL . $url);
    exit();
}

function setFlashMessage($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function hasRole($roles) {
    if (!isLoggedIn()) return false;
    if (!is_array($roles)) $roles = [$roles];
    $userRole = getActiveRole();
    $roles = array_map(function($r){ return strtolower(trim($r)); }, $roles);
    return in_array($userRole, $roles, true);
}

function getUserBaseRole() {
    if (!isLoggedIn()) return null;
    return isset($_SESSION['base_role']) && !empty($_SESSION['base_role'])
        ? strtolower(trim($_SESSION['base_role']))
        : (isset($_SESSION['user_role']) ? strtolower(trim($_SESSION['user_role'])) : null);
}

function getActiveRole() {
    if (!isLoggedIn()) return null;
    return isset($_SESSION['user_role']) && !empty($_SESSION['user_role'])
        ? strtolower(trim($_SESSION['user_role']))
        : getUserBaseRole();
}

function canSwitchRoles() {
    $baseRole = getUserBaseRole();
    return in_array($baseRole, ['doctor', 'admin'], true);
}

function switchUserRole($role) {
    if (!isLoggedIn()) return false;

    $baseRole = getUserBaseRole();
    if (!in_array($baseRole, ['doctor', 'admin'], true)) {
        return false;
    }

    $targetRole = strtolower(trim((string)$role));
    if (!in_array($targetRole, ['doctor', 'admin'], true)) {
        return false;
    }

    $_SESSION['user_role'] = $targetRole;
    return true;
}

function requireLogin() {
    if (!isLoggedIn()) {
        setFlashMessage('error', 'Please login to continue.');
        redirect('login.php');
    }
}

function requireRole($roles) {
    requireLogin();
    if (!hasRole($roles)) {
        setFlashMessage('error', 'You do not have permission to access this page.');
        redirect('dashboard.php');
    }
}

function logActivity($action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null) {
    $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    $ipAddress = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

    $sql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $attempt = 0;
    $maxAttempts = 2;
    while ($attempt < $maxAttempts) {
        $attempt++;
        $conn = null;
        try {
            $conn = getDBConnection();
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $stmt->bind_param("ississss", $userId, $action, $tableName, $recordId, $oldValues, $newValues, $ipAddress, $userAgent);
            $ok = $stmt->execute();
            try { $stmt->close(); } catch (Throwable $t) { error_log('logActivity stmt->close error: '.$t->getMessage()); }
            try { $conn->close(); } catch (Throwable $t) { error_log('logActivity conn->close error: '.$t->getMessage()); }
            if ($ok) return true;
            // if execute failed but no exception, try again if server gone away
            $err = $conn->error;
            throw new Exception('Execute failed: ' . $err);
        } catch (mysqli_sql_exception $mse) {
            // handle server gone away by retrying once
            if ($conn) { try { $conn->close(); } catch (Throwable $t) { /* ignore */ } }
            error_log('logActivity mysqli_sql_exception (attempt ' . $attempt . '): ' . $mse->getMessage());
            if ($attempt >= $maxAttempts) return false;
            usleep(100000); // 100ms backoff
            continue;
        } catch (Exception $e) {
            if ($conn) { try { $conn->close(); } catch (Throwable $t) { /* ignore */ } }
            error_log('logActivity exception: ' . $e->getMessage());
            return false;
        }
    }
    return false;
}

// Get counts for dashboard
function ensurePasswordResetRequestsTable() {
    $conn = getDBConnection();
    $conn->query("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        admin_notes VARCHAR(255) NULL,
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP NULL,
        resolved_by INT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_user (user_id),
        INDEX idx_status (status)
    )");
    $conn->close();
    return true;
}

function getDashboardCounts() {
    $conn = getDBConnection();
    $counts = [];
    
    // Total Patients
    $result = $conn->query("SELECT COUNT(*) as count FROM patients WHERE status = 'active'");
    $counts['patients'] = $result->fetch_assoc()['count'];
    
    // Today's Visits
    $result = $conn->query("SELECT COUNT(*) as count FROM patient_visits WHERE visit_date = CURDATE()");
    $counts['today_visits'] = $result->fetch_assoc()['count'];
    
    // Waiting Patients
    $result = $conn->query("SELECT COUNT(*) as count FROM patient_visits WHERE status = 'waiting' AND visit_date = CURDATE()");
    $counts['waiting'] = $result->fetch_assoc()['count'];
    
    // In Triage
    $result = $conn->query("SELECT COUNT(*) as count FROM patient_visits WHERE status = 'in-triage' AND visit_date = CURDATE()");
    $counts['in_triage'] = $result->fetch_assoc()['count'];
    
    // In Consultation
    $result = $conn->query("SELECT COUNT(*) as count FROM patient_visits WHERE status = 'in-consultation' AND visit_date = CURDATE()");
    $counts['in_consultation'] = $result->fetch_assoc()['count'];
    
    // Admitted Patients
    $result = $conn->query("SELECT COUNT(*) as count FROM admissions WHERE status = 'admitted'");
    $counts['admitted'] = $result->fetch_assoc()['count'];
    
    // Pending Laboratory
    $result = $conn->query("SELECT COUNT(*) as count FROM laboratory_requests WHERE status = 'pending'");
    $counts['pending_lab'] = $result->fetch_assoc()['count'];
    
    // Pending Billing
    $result = $conn->query("SELECT COUNT(*) as count FROM invoices WHERE status IN ('pending', 'partial')");
    $counts['pending_billing'] = $result->fetch_assoc()['count'];
    
    // Low Stock Items
    $result = $conn->query("SELECT COUNT(*) as count FROM inventory_items i JOIN inventory_stock s ON i.id = s.item_id WHERE s.quantity_in_stock <= i.reorder_level");
    $counts['low_stock'] = $result->fetch_assoc()['count'];
    
    // Near expiry: batches with expiry within 30 days and available stock > 0
    $result = $conn->query("SELECT COUNT(DISTINCT s.item_id) as count FROM inventory_stock s JOIN inventory_items i ON s.item_id = i.id WHERE s.expiry_date IS NOT NULL AND s.expiry_date <> '0000-00-00' AND s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND (s.quantity_in_stock - s.quantity_reserved) > 0");
    $counts['near_expiry'] = $result ? $result->fetch_assoc()['count'] : 0;

    // Expired stock: batches with expiry before today and still have stock
    $result = $conn->query("SELECT COUNT(DISTINCT s.item_id) as count FROM inventory_stock s JOIN inventory_items i ON s.item_id = i.id WHERE s.expiry_date IS NOT NULL AND s.expiry_date <> '0000-00-00' AND s.expiry_date < CURDATE() AND (s.quantity_in_stock - s.quantity_reserved) > 0");
    $counts['expired_stock'] = $result ? $result->fetch_assoc()['count'] : 0;
    
    $conn->close();
    return $counts;
}
?>
