<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'doctor']);

$pageTitle = 'Update Referral Status';
$currentPage = 'referrals';

$conn = getDBConnection();

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $referralId = (int)($_POST['referral_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    if (!$referralId || empty($newStatus)) {
        setFlashMessage('error', 'Invalid referral or status.');
        redirect('modules/consultation/referrals.php');
    }
    
    // Update referral status
    $updateStmt = $conn->prepare("UPDATE referrals SET status = ? WHERE id = ?");
    if ($updateStmt) {
        $updateStmt->bind_param('si', $newStatus, $referralId);
        $updateStmt->execute();
        $updateStmt->close();
        
        // Log the status change
        logActivity('update', 'referrals', $referralId, 'Status changed to: ' . $newStatus);
        
        setFlashMessage('success', 'Referral status updated successfully.');
    } else {
        setFlashMessage('error', 'Error updating referral status.');
    }
    
    $conn->close();
    redirect('modules/consultation/referrals.php');
}
?>
