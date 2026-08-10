<?php
require_once 'config/config.php';
requireLogin();

$pageTitle = 'Edit Profile';
$currentPage = 'profile';

$conn = getDBConnection();
$userId = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$user = null;
if ($userId) {
    $stmt = $conn->prepare("SELECT id, username, full_name, email, phone, password FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $user = $res->fetch_assoc();
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $username = sanitize($_POST['username'] ?? '');
    $fullName = sanitize($_POST['full_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validate username uniqueness
    $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
    $check->bind_param('si', $username, $userId);
    $check->execute();
    $resC = $check->get_result();
    if ($resC && $resC->num_rows > 0) {
        setFlashMessage('error', 'Username is already taken.');
        $check->close();
        redirect('profile-edit.php');
    }
    $check->close();

    // Handle avatar upload (optional)
    $avatarUploaded = false;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['avatar'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $maxSize = 5 * 1024 * 1024; // 5 MB
            if ($file['size'] > $maxSize) {
                setFlashMessage('error', 'Avatar exceeds maximum size of 5MB.');
                redirect('profile-edit.php');
            }

            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            // Verify MIME is allowed
            if (!isset($allowed[$mime])) {
                setFlashMessage('error', 'Invalid avatar file type. Allowed: jpg, png.');
                redirect('profile-edit.php');
            }

            // Verify file is a real image
            $imgInfo = @getimagesize($file['tmp_name']);
            if ($imgInfo === false) {
                setFlashMessage('error', 'Uploaded avatar is not a valid image.');
                redirect('profile-edit.php');
            }

            $ext = $allowed[$mime];
            $uploadDir = UPLOAD_PATH . 'avatars/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $targetName = 'user_' . $userId . '.' . $ext;
            $targetPath = $uploadDir . $targetName;

            // remove existing avatars for this user (different ext)
            foreach (glob($uploadDir . 'user_' . $userId . '.*') as $existing) {
                @unlink($existing);
            }

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                setFlashMessage('error', 'Failed to upload avatar.');
                redirect('profile-edit.php');
            }
            $avatarUploaded = true;
        } else {
            setFlashMessage('error', 'Avatar upload error.');
            redirect('profile-edit.php');
        }
    }

    // Password change handling (optional)
    $passwordChanged = false;
    $newHash = null;
    if (!empty($newPassword) || !empty($confirmPassword)) {
        // Require current password
        if (empty($currentPassword)) {
            setFlashMessage('error', 'To change your password, provide your current password.');
            redirect('profile-edit.php');
        }

        // Verify current password. Support bcrypt + plaintext/md5 fallback.
        $stored = $user['password'] ?? '';
        $verified = false;
        if (!empty($stored) && password_verify($currentPassword, $stored)) {
            $verified = true;
        } elseif ($currentPassword === $stored) {
            $verified = true;
        } elseif (md5($currentPassword) === $stored) {
            $verified = true;
        }

        if (!$verified) {
            setFlashMessage('error', 'Current password is incorrect.');
            redirect('profile-edit.php');
        }

        if ($newPassword !== $confirmPassword) {
            setFlashMessage('error', 'New password and confirmation do not match.');
            redirect('profile-edit.php');
        }

        if (strlen($newPassword) < 6) {
            setFlashMessage('error', 'New password must be at least 6 characters.');
            redirect('profile-edit.php');
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $passwordChanged = true;
    }

    // Update user
    if ($passwordChanged) {
        $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, phone = ?, password = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('sssssi', $username, $fullName, $email, $phone, $newHash, $userId);
    } else {
        $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('ssssi', $username, $fullName, $email, $phone, $userId);
    }

    if ($stmt->execute()) {
        // Update session values
        $_SESSION['username'] = $username;
        $_SESSION['user_name'] = $fullName;
        if ($passwordChanged) {
            session_regenerate_id(true);
            setFlashMessage('success', 'Profile and password updated successfully.');
        } else {
            setFlashMessage('success', 'Profile updated successfully.');
        }
    } else {
        setFlashMessage('error', 'Failed to update profile.');
    }
    $stmt->close();
    $conn->close();

    redirect('profile.php');
}

$conn->close();

include 'includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Edit Profile</h1>
        <p class="page-subtitle">Update your account information</p>
    </div>
    <div>
        <a href="profile.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<?php $flash = getFlashMessage(); if ($flash): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>"><?php echo $flash['message']; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" action="" enctype="multipart/form-data">
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" required value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Full Name</label>
                <input type="text" name="full_name" class="form-control" required value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Current Password</label>
                <input type="password" name="current_password" class="form-control" placeholder="Enter current password to change password">
            </div>

            <div class="form-group">
                <label class="form-label">New Password</label>
                <input type="password" name="new_password" class="form-control" placeholder="New password">
            </div>

            <div class="form-group">
                <label class="form-label">Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" placeholder="Confirm new password">
            </div>

            <div class="form-group">
                <label class="form-label">Avatar (jpg/png)</label>
                <input type="file" name="avatar" accept="image/jpeg,image/png" class="form-control">
                <div class="form-note">Leave empty to keep existing avatar. Max size 5MB.</div>
            </div>

            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save Changes</button>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
