<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$flash = getFlashMessage();
$pendingResetRequestCount = 0;
if (hasRole(['admin'])) {
    try {
        ensurePasswordResetRequestsTable();
        $resetConn = getDBConnection();
        $resetResult = $resetConn->query("SELECT COUNT(*) AS cnt FROM password_reset_requests WHERE status = 'pending'");
        if ($resetResult) {
            $pendingResetRequestCount = intval($resetResult->fetch_assoc()['cnt']);
            $resetResult->free();
        }
        $resetConn->close();
    } catch (Exception $e) {
        $pendingResetRequestCount = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' . APP_SHORT_NAME : APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Choices.js for searchable multi-selects -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css">
</head>
<body class="page-enter">
    <a href="#content" class="sr-only" style="position:static;left:0;top:0;padding:8px;background:#000;color:#fff;z-index:3000;">Skip to content</a>
    <div class="wrapper">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-hospital" style="font-size: 40px; margin-bottom: 10px;"></i>
                <h3>Saint Claire</h3>
                <p>Hospital Management</p>
            </div>
            
            <nav class="sidebar-menu">
                <div class="menu-category">Main</div>
                <a href="<?php echo BASE_URL; ?>dashboard.php" class="menu-item <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                
                <?php if (hasRole(['admin', 'staff', 'reception'])): ?>
                <div class="menu-category">Front Desk</div>
                <a href="<?php echo BASE_URL; ?>modules/reception/patients.php" class="menu-item <?php echo $currentPage === 'patients' ? 'active' : ''; ?>">
                    <i class="fas fa-user-injured"></i>
                    <span>Patients</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/reception/queue.php" class="menu-item <?php echo $currentPage === 'queue' ? 'active' : ''; ?>">
                    <i class="fas fa-list-ol"></i>
                    <span>Queue</span>
                </a>
                <?php if (hasRole(['reception'])): ?>
                <a href="<?php echo BASE_URL; ?>modules/reception/visits.php" class="menu-item <?php echo $currentPage === 'visits' ? 'active' : ''; ?>">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Visits</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                
                <?php if (hasRole(['nurse', 'doctor'])): ?>
                <div class="menu-category">Nursing</div>
                <?php if (hasRole(['nurse'])): ?>
                <a href="<?php echo BASE_URL; ?>modules/triage/triage.php" class="menu-item <?php echo $currentPage === 'triage' ? 'active' : ''; ?>">
                    <i class="fas fa-heartbeat"></i>
                    <span>Patient Assessment</span>
                </a>
                <?php endif; ?>
                <a href="<?php echo BASE_URL; ?>modules/admission/admissions.php" class="menu-item <?php echo $currentPage === 'admissions' ? 'active' : ''; ?>">
                    <i class="fas fa-procedures"></i>
                    <span>Admissions</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/admission/monitoring.php" class="menu-item <?php echo $currentPage === 'monitoring' ? 'active' : ''; ?>">
                    <i class="fas fa-notes-medical"></i>
                    <span>Monitoring</span>
                </a>
                <?php if (hasRole(['//not connected'])): ?>
                <a href="<?php echo BASE_URL; ?>modules/nursing/check-demo.php" class="menu-item <?php echo $currentPage === 'nursing' ? 'active' : ''; ?>">
                    <i class="fas fa-sticky-note"></i>
                    <span>Add Notes</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>

                 <?php if (hasRole(['admin'])): ?>
                <div class="menu-category">Admission</div>
                 <a href="<?php echo BASE_URL; ?>modules/admission/admissions.php" class="menu-item <?php echo $currentPage === 'admissions' ? 'active' : ''; ?>">
                    <i class="fas fa-procedures"></i>
                    <span>Admissions</span>
                </a>
                <?php endif; ?>
                
                <?php if (hasRole(['admin', 'doctor',])): ?>
                <div class="menu-category">Maternity & Lying-In</div>
                <a href="<?php echo BASE_URL; ?>modules/maternity/index.php" class="menu-item <?php echo $currentPage === 'maternity' ? 'active' : ''; ?>">
                    <i class="fas fa-baby"></i>
                    <span>Maternity Dashboard</span>
                </a>
                <?php endif; ?>
                
                <?php if (hasRole(['staff'])): ?>
                <a href="<?php echo BASE_URL; ?>modules/reception/visit-add.php" class="menu-item <?php echo $currentPage === 'visit-add' ? 'active' : ''; ?>">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Queue for Assessment</span>
                </a>
                <?php endif; ?>
                
                <?php if (hasRole(['admin', 'doctor']) || hasRole(['admin', 'doctor', 'cashier', 'pharmacist', 'nurse', 'staff'])): ?>
                <div class="menu-category">Medical</div>
                <?php if (hasRole(['admin', 'doctor'])): ?>
                <a href="<?php echo BASE_URL; ?>modules/consultation/consultations.php" class="menu-item <?php echo $currentPage === 'consultations' ? 'active' : ''; ?>">
                    <i class="fas fa-stethoscope"></i>
                    <span>Consultations</span>
                </a>
                <?php endif; ?>
                <?php if (hasRole(['admin', 'doctor', 'cashier', 'pharmacist', 'nurse', 'staff'])): ?>
                <a href="<?php echo BASE_URL; ?>modules/consultation/prescriptions.php" class="menu-item <?php echo $currentPage === 'prescriptions' ? 'active' : ''; ?>">
                    <i class="fas fa-prescription"></i>
                    <span>Prescriptions</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                
                <?php if (hasRole(['admin', 'laboratory',])): ?>
                <div class="menu-category">Laboratory</div>
                <a href="<?php echo BASE_URL; ?>modules/laboratory/requests.php" class="menu-item <?php echo $currentPage === 'lab-requests' ? 'active' : ''; ?>">
                    <i class="fas fa-vials"></i>
                    <span>Lab Requests</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/laboratory/results.php" class="menu-item <?php echo $currentPage === 'lab-results' ? 'active' : ''; ?>">
                    <i class="fas fa-poll-h"></i>
                    <span>Lab Results</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/laboratory/tests.php" class="menu-item <?php echo $currentPage === 'lab-tests' ? 'active' : ''; ?>">
                    <i class="fas fa-flask"></i>
                    <span>Test Catalog</span>
                </a>
                <?php if (hasRole(['admin'])): ?>
                <a href="<?php echo BASE_URL; ?>modules/laboratory/tests-add.php" class="menu-item <?php echo $currentPage === 'lab-tests-add' ? 'active' : ''; ?>">
                    <i class="fas fa-plus-circle"></i>
                    <span>Add New Test</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                
                <?php if (hasRole(['cashier'])): ?>
                <div class="menu-category">Billing</div>
                <a href="<?php echo BASE_URL; ?>modules/billing/invoices.php" class="menu-item <?php echo $currentPage === 'invoices' ? 'active' : ''; ?>">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Invoices</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/billing/payments.php" class="menu-item <?php echo $currentPage === 'payments' ? 'active' : ''; ?>">
                    <i class="fas fa-cash-register"></i>
                    <span>Payments</span>
                </a>
                <?php endif; ?>
                 <?php if (hasRole(['admin'])): ?>
                <div class="menu-category">Payment</div>
                <a href="<?php echo BASE_URL; ?>modules/billing/payments.php" class="menu-item <?php echo $currentPage === 'payments' ? 'active' : ''; ?>">
                    <i class="fas fa-cash-register"></i>
                    <span>Payments</span>
                </a>
                <?php endif; ?>
                
                <?php if (hasRole(['admin', 'inventory'])): ?>
                <div class="menu-category">Inventory</div>
                <a href="<?php echo BASE_URL; ?>modules/inventory/items.php" class="menu-item <?php echo $currentPage === 'inventory' ? 'active' : ''; ?>">
                    <i class="fas fa-boxes"></i>
                    <span>Items</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/inventory/stock.php" class="menu-item <?php echo $currentPage === 'stock' ? 'active' : ''; ?>">
                    <i class="fas fa-warehouse"></i>
                    <span>Stock</span>
                </a>
                <?php endif; ?>
                
                <?php if (hasRole(['admin'])): ?>
                <div class="menu-category">Administration</div>
                <a href="<?php echo BASE_URL; ?>modules/admin/users.php" class="menu-item <?php echo $currentPage === 'users' ? 'active' : ''; ?>">
                    <i class="fas fa-users-cog"></i>
                    <span>Users</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/admin/reset-requests.php" class="menu-item <?php echo $currentPage === 'reset-requests' ? 'active' : ''; ?>">
                    <i class="fas fa-key"></i>
                    <span>Reset Requests</span>
                    <?php if ($pendingResetRequestCount > 0): ?>
                        <span class="badge badge-warning" style="margin-left:auto; min-width:22px; text-align:center;"><?php echo $pendingResetRequestCount; ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/admin/rooms.php" class="menu-item <?php echo $currentPage === 'rooms' ? 'active' : ''; ?>">
                    <i class="fas fa-door-closed"></i>
                    <span>Rooms</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/admin/services.php" class="menu-item <?php echo $currentPage === 'services' ? 'active' : ''; ?>">
                    <i class="fas fa-cogs"></i>
                    <span>Services</span>
                </a>
                <a href="<?php echo BASE_URL; ?>modules/admin/reports.php" class="menu-item <?php echo $currentPage === 'reports' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span>Analytics</span>
                </a>
                <?php endif; ?>
                <?php // Make Analytics visible to cashier as well ?>
                <?php if (hasRole(['cashier']) && !hasRole(['admin'])): ?>
                <div class="menu-category">Billing</div>
                <a href="<?php echo BASE_URL; ?>modules/admin/reports.php" class="menu-item <?php echo $currentPage === 'reports' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span>Analytics</span>
                </a>
                <?php endif; ?>
            </nav>
        </aside>
        
        <script>
        (function(){
            var sm = document.querySelector('.sidebar-menu');
            var isRestoring = false;

            function restoreScroll() {
                if (!sm) return;
                var scrollPos = sessionStorage.getItem('sidebarScrollPosition');
                if (scrollPos !== null) {
                    isRestoring = true;
                    sm.scrollTop = parseInt(scrollPos, 10);
                    setTimeout(function() {
                        isRestoring = false;
                    }, 50);
                } else {
                    var activeItem = sm.querySelector('.menu-item.active');
                    if (activeItem) {
                        isRestoring = true;
                        activeItem.scrollIntoView({ block: 'nearest' });
                        setTimeout(function() {
                            isRestoring = false;
                        }, 50);
                    }
                }
            }

            function updateSidebarLayout(){
                var sh = document.querySelector('.sidebar-header');
                if(!sh || !sm) return;
                var h = sh.offsetHeight;
                document.documentElement.style.setProperty('--sidebar-header-height', h + 'px');
                document.documentElement.style.setProperty('--sidebar-header-height-collapsed', Math.max(56, Math.round(h*0.6)) + 'px');
                sm.style.marginTop = h + 'px';
                sm.style.maxHeight = 'calc(100vh - ' + h + 'px)';
                
                restoreScroll();
            }

            if (sm) {
                sm.addEventListener('scroll', function() {
                    if (!isRestoring) {
                        sessionStorage.setItem('sidebarScrollPosition', sm.scrollTop);
                    }
                });

                // Also save scroll position on click of any menu link
                sm.addEventListener('click', function(e) {
                    var link = e.target.closest('.menu-item');
                    if (link) {
                        sessionStorage.setItem('sidebarScrollPosition', sm.scrollTop);
                    }
                });
            }

            window.addEventListener('load', updateSidebarLayout);
            window.addEventListener('resize', updateSidebarLayout);
            // also run shortly after load in case fonts/images change size
            setTimeout(updateSidebarLayout, 200);
        })();
        </script>

        <!-- Main Content -->
        <main class="main-content" id="content">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <button class="toggle-sidebar" id="toggleSidebar" aria-controls="sidebar" aria-expanded="false" aria-label="Toggle navigation">
                        <i class="fas fa-bars" aria-hidden="true"></i>
                    </button>
                    <nav class="breadcrumb">
                        <li><a href="<?php echo BASE_URL; ?>dashboard.php">Home</a></li>
                        <?php if (isset($pageTitle)): ?>
                        <li><?php echo $pageTitle; ?></li>
                        <?php endif; ?>
                    </nav>
                </div>
                
                <div class="header-right">
                    <div class="theme-switcher">
                        <button type="button" id="themeToggle" class="theme-toggle" aria-label="Toggle light and dark mode">
                            <i class="fas fa-moon" aria-hidden="true"></i>
                            <span class="theme-label">Dark</span>
                        </button>
                    </div>
                    <div class="header-icon">
                            <?php
                            $notificationCount = 0;
                            $notifications = [];
                            if (isLoggedIn()) {
                                try {
                                    $nconn = getDBConnection();
                                    // ensure notifications table exists
                                    $nconn->query("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, recipient_user_id INT NOT NULL, title VARCHAR(150), message TEXT, is_read TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (recipient_user_id) REFERENCES users(id))");
                                    $uid = $_SESSION['user_id'];
                                    $resCount = $nconn->query("SELECT COUNT(*) as cnt FROM notifications WHERE recipient_user_id = " . intval($uid) . " AND is_read = 0 AND DATE(created_at) = CURDATE()");
                                    if ($resCount) {
                                        $notificationCount = $resCount->fetch_assoc()['cnt'];
                                        $resCount->free();
                                    }
                                    $res = $nconn->query("SELECT id, title, message, is_read, created_at FROM notifications WHERE recipient_user_id = " . intval($uid) . " AND DATE(created_at) = CURDATE() ORDER BY created_at DESC LIMIT 8");
                                    if ($res) {
                                        while ($r = $res->fetch_assoc()) { $notifications[] = $r; }
                                        $res->free();
                                    }
                                    $nconn->close();
                                } catch (Exception $e) {
                                    // ignore DB errors — fallback to 0
                                }
                            }
                            ?>
                            <i class="fas fa-bell"></i>
                            <span class="badge"><?php echo intval($notificationCount); ?></span>
                    </div>
                    
                    <div class="user-dropdown" id="userDropdownTrigger" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false">
                        <?php
                        $headerAvatarUrl = null;
                        if (isLoggedIn() && !empty($_SESSION['user_id'])) {
                            $hUid = intval($_SESSION['user_id']);
                            $avatarPattern = rtrim(UPLOAD_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . 'user_' . $hUid . '.*';
                            $hMatches = glob($avatarPattern);
                            if ($hMatches && count($hMatches) > 0) {
                                $hPath = $hMatches[0];
                                $hFile = basename($hPath);
                                $headerAvatarUrl = BASE_URL . 'uploads/avatars/' . $hFile . '?v=' . @filemtime($hPath);
                            }
                        }
                        ?>
                        <div class="user-avatar">
                            <?php if ($headerAvatarUrl): ?>
                                <img src="<?php echo htmlspecialchars($headerAvatarUrl); ?>" alt="Avatar" style="width:36px;height:36px;object-fit:cover;border-radius:50%;display:block;">
                            <?php else: ?>
                                <?php echo isset($_SESSION['user_name']) && $_SESSION['user_name'] !== '' ? strtoupper(substr($_SESSION['user_name'], 0, 1)) : ''; ?>
                            <?php endif; ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?php echo isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'Guest'; ?></div>
                            <div class="user-role"><?php echo getActiveRole() ? ucfirst(htmlspecialchars(getActiveRole())) : ''; ?></div>
                        </div>
                        <i class="fas fa-chevron-down"></i>
                    </div>

                    <div class="user-menu" id="userMenu">
                        <?php if (canSwitchRoles()): ?>
                        <form method="POST" action="<?php echo BASE_URL; ?>switch-role.php" class="role-switch-menu-item">
                            <label class="small muted" for="roleSwitchSelect">Switch role</label>
                            <select id="roleSwitchSelect" name="role" class="form-control role-switch-select" onchange="this.form.submit()" aria-label="Switch role">
                                <option value="doctor" <?php echo getActiveRole() === 'doctor' ? 'selected' : ''; ?>>Doctor View</option>
                                <option value="admin" <?php echo getActiveRole() === 'admin' ? 'selected' : ''; ?>>Admin View</option>
                            </select>
                        </form>
                        <?php endif; ?>
                        <a href="<?php echo BASE_URL; ?>profile.php"><i class="fas fa-user"></i> Profile</a>
                        <a href="<?php echo BASE_URL; ?>logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                    
                    <!-- Server-rendered notifications panel (will be toggled by JS) -->
                    <div class="notifications-panel" id="notificationsPanel">
                        <?php if (!empty($notifications)): ?>
                            <?php foreach ($notifications as $note): ?>
                                <div class="item" data-id="<?php echo $note['id']; ?>">
                                    <strong><?php echo htmlspecialchars($note['title']); ?></strong>
                                    <div class="small muted"><?php echo htmlspecialchars(substr($note['message'], 0, 80)); ?></div>
                                    <div class="small muted" style="font-size:11px; margin-top:6px;"><?php echo date('M d, Y h:i A', strtotime($note['created_at'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="item">No new notifications</div>
                        <?php endif; ?>
                    </div>
                </div>
            </header>
            
            <!-- Content -->
            <div class="content">
                <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type']; ?> fade-in">
                    <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'error' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
                    <?php echo $flash['message']; ?>
                </div>
                <?php endif; ?>
