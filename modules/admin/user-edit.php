<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin']);

$pageTitle = 'Edit User';
$currentPage = 'users';

$conn = getDBConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
if ($id <= 0) {
    setFlashMessage('error', 'Invalid user id.');
    redirect('modules/admin/users.php');
}

// Handle updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update') {
        $fullName = sanitize($_POST['full_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $role = sanitize($_POST['role'] ?? '');
        $department = sanitize($_POST['department'] ?? '');

        $stmt = $conn->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, role = ?, department = ? WHERE id = ?');
        $stmt->bind_param('sssssi', $fullName, $email, $phone, $role, $department, $id);
        if ($stmt->execute()) {
            logActivity('update', 'users', $id);
            setFlashMessage('success', 'User updated successfully.');
        } else {
            setFlashMessage('error', 'Failed to update user: ' . $stmt->error);
        }
        $stmt->close();
        redirect('modules/admin/user-edit.php?id=' . $id);
    }

    /* reset password feature removed */

    if (isset($_POST['action']) && $_POST['action'] === 'set_password') {
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (empty($new)) {
            setFlashMessage('error', 'Password cannot be empty.');
            redirect('modules/admin/user-edit.php?id=' . $id);
        }
        if ($new !== $confirm) {
            setFlashMessage('error', 'Passwords do not match.');
            redirect('modules/admin/user-edit.php?id=' . $id);
        }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $id);
        if ($stmt->execute()) {
            logActivity('update', 'users', $id, null, json_encode(['password_set_by'=>$_SESSION['user_id']]));
            setFlashMessage('success', 'Password updated successfully.');
        } else {
            setFlashMessage('error', 'Failed to update password: ' . $stmt->error);
        }
        $stmt->close();
        redirect('modules/admin/user-edit.php?id=' . $id);
    }
}

// Load user
$stmt = $conn->prepare('SELECT id, username, full_name, email, phone, role, department, status FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'User not found.');
    $stmt->close();
    $conn->close();
    redirect('modules/admin/users.php');
}
$user = $res->fetch_assoc();
$stmt->close();
$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Edit User</h1>
        <p class="page-subtitle">Update user details or reset password</p>
    </div>
    <div>
        <a href="users.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="user-edit.php?id=<?php echo $user['id']; ?>">
            <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
            <input type="hidden" name="action" value="update">

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Full Name</label>
                    <input type="text" name="full_name" class="form-control" required value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>">
                </div>
                <div class="form-group col-md-6">
                    <label>Username</label>
                    <input type="text" class="form-control" disabled value="<?php echo htmlspecialchars($user['username']); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                </div>
                <div class="form-group col-md-6">
                    <label>Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Role</label>
                    <select name="role" class="form-control" required>
                        <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                        <option value="doctor" <?php echo $user['role'] === 'doctor' ? 'selected' : ''; ?>>Doctor</option>
                        <option value="nurse" <?php echo $user['role'] === 'nurse' ? 'selected' : ''; ?>>Nurse</option>
                        <option value="cashier" <?php echo $user['role'] === 'cashier' ? 'selected' : ''; ?>>Cashier</option>
                        <option value="laboratory" <?php echo $user['role'] === 'laboratory' ? 'selected' : ''; ?>>Laboratory</option>
                        <option value="inventory" <?php echo $user['role'] === 'inventory' ? 'selected' : ''; ?>>Inventory</option>
                        <option value="staff" <?php echo $user['role'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
                    </select>
                </div>
                <div class="form-group col-md-6">
                    <label>Department</label>
                    <input type="text" name="department" class="form-control" value="<?php echo htmlspecialchars($user['department'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-group text-right">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>

        <form method="post" action="user-edit.php?id=<?php echo $user['id']; ?>" onsubmit="return confirm('Set this password for the user?');">
            <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
            <input type="hidden" name="action" value="set_password">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="form-control" required>
                </div>
                <div class="form-group col-md-6">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
            </div>
            <div class="form-group text-right">
                <button type="submit" class="btn btn-warning">Set Password</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php';
