<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin']);

$pageTitle = 'Password Reset Requests';
$currentPage = 'reset-requests';

$conn = getDBConnection();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $requestId = (int)$_POST['request_id'];
    $adminId = $_SESSION['user_id'];
    
    if ($_POST['action'] === 'approve') {
        $userId = (int)$_POST['user_id'];
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $adminNotes = sanitize($_POST['admin_notes'] ?? '');
        
        if (empty($newPassword)) {
            setFlashMessage('error', 'New password cannot be empty.');
            redirect('modules/admin/reset-requests.php');
        }
        
        if ($newPassword !== $confirmPassword) {
            setFlashMessage('error', 'Passwords do not match.');
            redirect('modules/admin/reset-requests.php');
        }
        
        // Hash password
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Begin Transaction
        $conn->begin_transaction();
        try {
            // Update User Password
            $updateUser = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateUser->bind_param("si", $hash, $userId);
            $updateUser->execute();
            $updateUser->close();
            
            // Update Request Status
            $updateRequest = $conn->prepare("UPDATE password_reset_requests SET status = 'approved', admin_notes = ?, resolved_at = NOW(), resolved_by = ? WHERE id = ?");
            $updateRequest->bind_param("sii", $adminNotes, $adminId, $requestId);
            $updateRequest->execute();
            $updateRequest->close();
            
            // Log Activity
            logActivity('update', 'users', $userId, null, json_encode(['password_reset_approved_by_admin' => $adminId, 'request_id' => $requestId]));
            
            $conn->commit();
            setFlashMessage('success', 'Password reset request approved. Password updated successfully!');
        } catch (Exception $e) {
            $conn->rollback();
            setFlashMessage('error', 'Failed to approve request: ' . $e->getMessage());
        }
    } elseif ($_POST['action'] === 'reject') {
        $adminNotes = sanitize($_POST['admin_notes'] ?? '');
        
        if (empty($adminNotes)) {
            setFlashMessage('error', 'Please provide rejection notes/reason.');
            redirect('modules/admin/reset-requests.php');
        }
        
        $updateRequest = $conn->prepare("UPDATE password_reset_requests SET status = 'rejected', admin_notes = ?, resolved_at = NOW(), resolved_by = ? WHERE id = ?");
        $updateRequest->bind_param("sii", $adminNotes, $adminId, $requestId);
        
        if ($updateRequest->execute()) {
            setFlashMessage('success', 'Password reset request rejected.');
        } else {
            setFlashMessage('error', 'Failed to reject request: ' . $updateRequest->error);
        }
        $updateRequest->close();
    }
    
    redirect('modules/admin/reset-requests.php');
}

// Fetch all requests
$requests = $conn->query("
    SELECT r.*, u.username, u.full_name, u.email, u.phone, a.full_name AS admin_name
    FROM password_reset_requests r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN users a ON r.resolved_by = a.id
    ORDER BY 
        CASE r.status WHEN 'pending' THEN 1 ELSE 2 END,
        r.requested_at DESC
");

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Password Reset Requests</h1>
        <p class="page-subtitle">Manage user requests for credentials recovery</p>
    </div>
</div>

<!-- Requests Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">All Recovery Requests</h3>
        <span class="badge badge-secondary"><?php echo $requests->num_rows; ?> Total</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>User Details</th>
                        <th>Username</th>
                        <th>Contact</th>
                        <th>Requested At</th>
                        <th>Status</th>
                        <th>Resolved By & Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($requests->num_rows === 0): ?>
                    <tr>
                        <td colspan="7" class="text-center" style="padding: 30px;">
                            <i class="fas fa-key" style="font-size: 32px; color: var(--muted-color); margin-bottom: 10px; display: block;"></i>
                            <span class="text-muted">No password reset requests found.</span>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php while ($req = $requests->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($req['full_name']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($req['email']); ?></small>
                            </td>
                            <td><code><?php echo htmlspecialchars($req['username']); ?></code></td>
                            <td><?php echo htmlspecialchars($req['phone'] ?: 'N/A'); ?></td>
                            <td><?php echo formatDateTime($req['requested_at']); ?></td>
                            <td>
                                <?php if ($req['status'] === 'pending'): ?>
                                    <span class="badge badge-warning"><i class="fas fa-hourglass-half"></i> Pending</span>
                                <?php elseif ($req['status'] === 'approved'): ?>
                                    <span class="badge badge-success"><i class="fas fa-check-circle"></i> Approved</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fas fa-times-circle"></i> Rejected</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($req['status'] !== 'pending'): ?>
                                    <small>
                                        <strong>Admin:</strong> <?php echo htmlspecialchars($req['admin_name'] ?: 'System'); ?><br>
                                        <strong>Resolved:</strong> <?php echo formatDateTime($req['resolved_at']); ?><br>
                                        <?php if ($req['admin_notes']): ?>
                                            <strong>Notes:</strong> <?php echo htmlspecialchars($req['admin_notes']); ?>
                                        <?php endif; ?>
                                    </small>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="table-actions">
                                <?php if ($req['status'] === 'pending'): ?>
                                    <button type="button" class="btn btn-sm btn-success" title="Approve & Reset Password"
                                            onclick="openApproveModal(<?php echo $req['id']; ?>, <?php echo $req['user_id']; ?>, '<?php echo addslashes($req['username']); ?>', '<?php echo addslashes($req['full_name']); ?>')">
                                        <i class="fas fa-key"></i> Reset
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger" title="Reject Request"
                                            onclick="openRejectModal(<?php echo $req['id']; ?>, '<?php echo addslashes($req['full_name']); ?>')">
                                        <i class="fas fa-ban"></i> Reject
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted">No actions</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Approve / Reset Modal -->
<div class="modal-overlay" id="approveModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Approve & Reset Password</h3>
            <button type="button" class="modal-close" onclick="closeModal('approveModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <div class="modal-body">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="request_id" id="approve_request_id">
                <input type="hidden" name="user_id" id="approve_user_id">
                
                <div style="background: var(--bg-color); padding: 12px; border-radius: 8px; margin-bottom: 18px; border: 1px solid var(--border-color);">
                    <p style="margin: 0 0 5px 0; font-size: 13px;"><strong>User:</strong> <span id="approve_user_display"></span></p>
                    <p style="margin: 0; font-size: 13px;"><strong>Username:</strong> <code id="approve_username_display"></code></p>
                </div>

                <div class="form-group">
                    <label class="form-label">New Password <span style="color: red;">*</span></label>
                    <div style="display: flex; gap: 8px;">
                        <input type="text" name="new_password" id="new_password" class="form-control" required style="flex-grow: 1;">
                        <button type="button" class="btn btn-secondary" onclick="generateRandomPassword()" style="white-space: nowrap;">
                            <i class="fas fa-random"></i> Generate
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Confirm New Password <span style="color: red;">*</span></label>
                    <input type="text" name="confirm_password" id="confirm_password" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Admin Notes (optional)</label>
                    <input type="text" name="admin_notes" class="form-control" placeholder="e.g., Temporary password sent via SMS">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('approveModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Password</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Reject Password Request</h3>
            <button type="button" class="modal-close" onclick="closeModal('rejectModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <div class="modal-body">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="request_id" id="reject_request_id">
                
                <div style="background: var(--bg-color); padding: 12px; border-radius: 8px; margin-bottom: 18px; border: 1px solid var(--border-color);">
                    <p style="margin: 0; font-size: 13px;"><strong>User:</strong> <span id="reject_user_display"></span></p>
                </div>

                <div class="form-group">
                    <label class="form-label">Rejection Notes / Reason <span style="color: red;">*</span></label>
                    <textarea name="admin_notes" class="form-control" rows="3" required placeholder="e.g., Requested directly by a different phone number. Request denied after checking identity."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="btn btn-danger">Reject Request</button>
            </div>
        </form>
    </div>
</div>

<script>
function openApproveModal(requestId, userId, username, fullName) {
    document.getElementById('approve_request_id').value = requestId;
    document.getElementById('approve_user_id').value = userId;
    document.getElementById('approve_user_display').textContent = fullName;
    document.getElementById('approve_username_display').textContent = username;
    document.getElementById('new_password').value = '';
    document.getElementById('confirm_password').value = '';
    openModal('approveModal');
}

function openRejectModal(requestId, fullName) {
    document.getElementById('reject_request_id').value = requestId;
    document.getElementById('reject_user_display').textContent = fullName;
    openModal('rejectModal');
}

function generateRandomPassword() {
    const length = 10;
    const charset = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789"; // skip confusing characters (l, 1, o, 0)
    let retVal = "";
    for (let i = 0, n = charset.length; i < length; ++i) {
        retVal += charset.charAt(Math.floor(Math.random() * n));
    }
    document.getElementById('new_password').value = retVal;
    document.getElementById('confirm_password').value = retVal;
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
