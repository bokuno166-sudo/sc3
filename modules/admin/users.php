<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin']);

$pageTitle = 'User Management';
$currentPage = 'users';

$conn = getDBConnection();

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $username = sanitize($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $fullName = sanitize($_POST['full_name']);
    $email = sanitize($_POST['email']);
    $phone = sanitize($_POST['phone']);
    $role = sanitize($_POST['role']);
    $department = sanitize($_POST['department']);
    
    $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, phone, role, department) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssss", $username, $password, $fullName, $email, $phone, $role, $department);
    
    if ($stmt->execute()) {
        logActivity('create', 'users', $stmt->insert_id);
        setFlashMessage('success', 'User created successfully!');
    } else {
        setFlashMessage('error', 'Error creating user: ' . $stmt->error);
    }
    $stmt->close();
    redirect('modules/admin/users.php');
}

// Handle user status toggle
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $userId = (int)$_GET['id'];
    $result = $conn->query("SELECT status FROM users WHERE id = $userId");
    $user = $result->fetch_assoc();
    $newStatus = $user['status'] === 'active' ? 'inactive' : 'active';
    
    $conn->query("UPDATE users SET status = '$newStatus' WHERE id = $userId");
    logActivity('update', 'users', $userId);
    setFlashMessage('success', 'User status updated successfully!');
    redirect('modules/admin/users.php');
}

// Get all users
$users = $conn->query("
    SELECT * FROM users 
    ORDER BY 
        CASE role 
            WHEN 'admin' THEN 1 
            WHEN 'doctor' THEN 2 
            WHEN 'nurse' THEN 3 
            ELSE 4 
        END, 
        full_name ASC
");

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">User Management</h1>
        <p class="page-subtitle">Manage system users and their roles</p>
    </div>
    <button type="button" class="btn btn-primary" onclick="openModal('addUserModal')">
        <i class="fas fa-user-plus"></i> Add User
    </button>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">All Users</h3>
        <span class="badge badge-secondary"><?php echo $users->num_rows; ?> Total</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Department</th>
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($user = $users->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <strong><?php echo $user['full_name']; ?></strong><br>
                            <small class="text-muted"><?php echo $user['email']; ?></small>
                        </td>
                        <td><?php echo $user['username']; ?></td>
                        <td><?php echo ucfirst($user['role']); ?></td>
                        <td><?php echo $user['department'] ?: 'N/A'; ?></td>
                        <td><?php echo $user['phone'] ?: 'N/A'; ?></td>
                        <td><?php echo getStatusBadge($user['status']); ?></td>
                        <td><?php echo $user['last_login'] ? formatDateTime($user['last_login']) : 'Never'; ?></td>
                        <td class="table-actions">
                            <a href="user-edit.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-info" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="?toggle=1&id=<?php echo $user['id']; ?>" class="btn btn-sm btn-<?php echo $user['status'] === 'active' ? 'warning' : 'success'; ?>" 
                               onclick="return confirm('Are you sure you want to <?php echo $user['status'] === 'active' ? 'deactivate' : 'activate'; ?> this user?')">
                                <i class="fas fa-<?php echo $user['status'] === 'active' ? 'ban' : 'check'; ?>"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal-overlay" id="addUserModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Add New User</h3>
            <button type="button" class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <div class="modal-body">
                <input type="hidden" name="action" value="create">
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Full Name <span style="color: red;">*</span></label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Username <span style="color: red;">*</span></label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Password <span style="color: red;">*</span></label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Role <span style="color: red;">*</span></label>
                        <select name="role" class="form-control" required>
                            <option value="">Select Role</option>
                            <option value="admin">Administrator</option>
                            <option value="doctor">Doctor</option>
                            <option value="nurse">Nurse</option>
                            <option value="cashier">Cashier</option>
                            <option value="laboratory">Laboratory Technician</option>
                            <option value="inventory">Inventory Manager</option>
                            <option value="staff">Staff</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <input type="text" name="department" class="form-control" placeholder="e.g., Emergency, Billing">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" name="phone" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addUserModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
