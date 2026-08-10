<?php
require_once 'config/config.php';
requireLogin();

$pageTitle = 'My Profile';
$currentPage = 'profile';

$conn = getDBConnection();
$userId = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$user = null;
if ($userId) {
    $stmt = $conn->prepare("SELECT id, username, full_name, email, phone, role, department, status, created_at, updated_at FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $user = $res->fetch_assoc();
    }
    $stmt->close();
}
$conn->close();

include 'includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">My Profile</h1>
        <p class="page-subtitle">Manage your account information</p>
    </div>
    <div>
        <a href="profile-edit.php" class="btn btn-secondary" style="margin-right:8px;"><i class="fas fa-edit"></i> Edit Profile</a>
        <a href="logout.php" class="btn btn-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<?php if (!$user): ?>
    <div class="card">
        <div class="card-body">
            <p class="text-muted">User profile not found. Please contact the administrator.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header card-header-accent">
            <div class="card-title"><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></div>
            <div class="small muted">Joined <?php echo formatDate($user['created_at'] ?? null); ?></div>
        </div>
        <div class="card-body">
            <div style="display:flex; gap:30px; align-items:flex-start;">
                <div style="min-width:140px;">
                    <?php
                    // Check for uploaded avatar file in uploads/avatars/user_<id>.*
                    $avatarUrl = null;
                    if (!empty($user['id'])) {
                        $avatarPattern = rtrim(UPLOAD_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . 'user_' . $user['id'] . '.*';
                        $matches = glob($avatarPattern);
                        if ($matches && count($matches) > 0) {
                            $avatarPath = $matches[0];
                            $avatarFile = basename($avatarPath);
                            $avatarUrl = BASE_URL . 'uploads/avatars/' . $avatarFile;
                            // append cache-busting query param
                            $avatarUrl .= '?v=' . @filemtime($avatarPath);
                        }
                    }
                    if ($avatarUrl): ?>
                        <div style="width:100px;height:100px;border-radius:50%;overflow:hidden;">
                            <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;display:block;">
                        </div>
                    <?php else: ?>
                        <div class="user-avatar" style="width:100px;height:100px;font-size:36px; display:flex; align-items:center; justify-content:center; border-radius:50%; background:var(--primary-color); color:#fff;">
                            <?php echo strtoupper(substr(($user['full_name'] ?? ' '), 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="flex:1">
                    <p><strong>Username:</strong> <?php echo htmlspecialchars($user['username'] ?? ''); ?></p>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email'] ?? ''); ?></p>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($user['phone'] ?? ''); ?></p>
                    <p><strong>Role:</strong> <?php echo htmlspecialchars(isset($user['role']) ? ucfirst($user['role']) : ''); ?></p>
                    <p><strong>Status:</strong> <?php echo htmlspecialchars($user['status'] ?? ''); ?></p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
