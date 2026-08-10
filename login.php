<?php
require_once 'config/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$error = '';
$success_msg = '';
$transitionView = false;
$transitionName = '';

// Load any flash messages set by other pages
$flash = getFlashMessage();
if ($flash) {
    if (isset($flash['type']) && $flash['type'] === 'success') {
        $success_msg = $flash['message'];
    } elseif (isset($flash['type']) && $flash['type'] === 'error' && empty($error)) {
        $error = $flash['message'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $conn = getDBConnection();
        $selectStmt = $conn->prepare("SELECT id, username, password, full_name, role, status FROM users WHERE username = ?");
        $selectStmt->bind_param("s", $username);
        $selectStmt->execute();
        $result = $selectStmt->get_result();
        
        $genericAuthError = 'Invalid user or password.';

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            // Check password using current and legacy methods
            $passwordOk = (
                password_verify($password, $user['password'])
                || $password === $user['password']
                || md5($password) === $user['password']
            );

            // Only allow login when password matches and account is active
            if ($passwordOk && $user['status'] === 'active') {
                // Login successful
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['base_role'] = $user['role'];
                $_SESSION['user_role'] = $user['role'];
                
                // Update last login
                $updateStmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $updateStmt->bind_param("i", $user['id']);
                $updateStmt->execute();
                $updateStmt->close();
                
                // Log activity
                logActivity('login');
                
                $transitionView = true;
                $transitionName = $user['full_name'];
                setFlashMessage('success', 'Welcome back, ' . $user['full_name'] . '!');
            } else {
                // Generic error to avoid revealing which part failed
                $error = $genericAuthError;
            }
        } else {
            // Username not found -> same generic error
            $error = $genericAuthError;
        }

        if ($result) {
            $result->free();
        }
        $selectStmt->close();
        $conn->close();
    }
}
?>
<?php
if ($transitionView) {
    $transitionName = htmlspecialchars($transitionName ?: 'there');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Opening Dashboard - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-page transition-page">
    <div class="transition-shell">
        <div class="transition-card">
            <div class="transition-logo">
                <i class="fas fa-hospital"></i>
                <h1>Opening Dashboard</h1>
                <p>Welcome back, <?php echo $transitionName; ?></p>
            </div>
            <div class="transition-chart" aria-hidden="true">
                <img src="<?php echo BASE_URL; ?>assets/loaders.gif" alt="Loading..." class="heartbeat-loader">
            </div>
            <div class="transition-text">
                <span>Preparing your workspace</span>
                <div class="transition-dots">
                    <span></span><span></span><span></span>
                </div>
            </div>
        </div>
    </div>
    <script>
        window.addEventListener('load', function () {
            setTimeout(function () {
                window.location.href = 'dashboard.php';
            }, 1250);
        });
    </script>
</body>
</html>
<?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
     <style>
        :root {
            --blue: #1168ad;
            --blue-dark: #0b4f89;
            --blue-soft: #eaf5ff;
            --ink: #17324d;
            --muted: #6f8090;
            --white: #fff;
            --line: #dbe7ef;
            --success: #1c9b6b;
            --shadow: 0 24px 70px rgba(19, 54, 83, 0.18);
        }

        * { box-sizing: border-box; }

        body {
            position: relative;
            margin: 0;
            min-height: 100vh;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--ink);
            background: #eef6fb;
            display: grid;
            place-items: center;
            padding: 24px;
            overflow: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: -12px;
            z-index: -1;
            background:
                linear-gradient(rgba(230, 242, 250, 0.137), rgba(238, 246, 251, 0.126)),
                url("<?php echo BASE_URL; ?>assets/css/sclmc.png") center/cover no-repeat;
            filter: blur(5px) saturate(90%);
            transform: scale(1.08);
        }

        .app {
            position: relative;
            width: min(1280px, 100%);
            min-height: min(760px, calc(100vh - 48px));
            background: var(--white);
            border-radius: 28px;
            overflow: hidden;
            box-shadow: var(--shadow);
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(460px, .82fr);
            perspective: 1800px;
            transform-style: preserve-3d;
        }

        .scene {
            border-radius: 28px 0 0 28px;
        }

        .scene {
            position: relative;
            min-height: 680px;
            overflow: hidden;
            background: #b9e5fa;
            isolation: isolate;
            transform-origin: left center;
            backface-visibility: hidden;
        }

        .scene::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(180deg, rgba(255,255,255,.05), rgba(5,49,79,.14)),
                url("<?php echo BASE_URL; ?>assets/css/hospital.png") center/cover no-repeat;
            z-index: -2;
        }

        .scene::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, rgba(5,45,74,.08), transparent 55%, rgba(5,45,74,.18));
            z-index: -1;
            pointer-events: none;
        }

        .scene-header {
            position: absolute;
            top: 28px;
            left: 30px;
            right: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 10;
        }

        .brand-mini {
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
            text-shadow: 0 2px 10px rgba(0,0,0,.25);
            font-weight: 800;
            letter-spacing: .2px;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            overflow: hidden;
            display: grid;
            place-items: center;
            background: rgba(255,255,255,.94);
            box-shadow: 0 8px 22px rgba(0,0,0,.12);
            border: 2px solid rgba(255,255,255,.9);
        }

        .brand-mark img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .help-pill {
            border: 1px solid rgba(255,255,255,.65);
            background: rgba(255,255,255,.2);
            backdrop-filter: blur(10px);
            color: white;
            padding: 9px 13px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .mission {
            position: absolute;
            left: 18px;
            bottom: 18px;
            z-index: 8;
            width: min(320px, calc(100% - 36px));
            padding: 12px 14px 10px;
            border-radius: 16px;
            background: rgba(255,255,255,.9);
            backdrop-filter: blur(12px);
            box-shadow: 0 10px 26px rgba(8,46,74,.14);
        }

        .mission-kicker {
            color: var(--blue);
            font-size: 9px;
            font-weight: 900;
            letter-spacing: .12em;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .mission h2 { margin: 0 0 3px; font-size: 17px; line-height: 1.18; }
        .mission p { margin: 0; color: #617383; font-size: 11.5px; line-height: 1.4; }

        .progress {
            margin-top: 8px;
            height: 6px;
            border-radius: 99px;
            background: #dcebf5;
            overflow: hidden;
        }

        .progress > span {
            display: block;
            height: 100%;
            width: 8%;
            background: linear-gradient(90deg, #1591dd, #1168ad);
            border-radius: inherit;
            transition: width .15s ease;
        }

        .goal {
            position: absolute;
            left: 68%;
            top: 38%;
            width: 72px;
            height: 72px;
            transform: translate(-50%, -50%);
            border: 3px solid rgba(255,255,255,.95);
            border-radius: 50%;
            background: rgba(17,104,173,.22);
            box-shadow: 0 0 0 8px rgba(255,255,255,.1), 0 8px 20px rgba(8,50,75,.12);
            z-index: 3;
            display: grid;
            place-items: center;
            color: white;
            font-weight: 900;
            font-size: 9px;
            text-align: center;
            text-shadow: 0 1px 4px rgba(0,0,0,.35);
            transition: .3s ease;
        }

        .goal::after {
            content: "";
            position: absolute;
            inset: -18px;
            border: 2px dashed rgba(255,255,255,.65);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        .goal.done {
            background: rgba(28,155,107,.88);
            transform: translate(-50%, -50%) scale(1.08);
        }

        @keyframes pulse { 50% { transform: scale(1.08); opacity: .45; } }

        .character {
            position: absolute;
            z-index: 6;
            left: 6%;
            bottom: 12%;
            width: clamp(120px, 14vw, 200px);
            min-width: 120px;
            user-select: none;
            touch-action: none;
            cursor: grab;
            filter: drop-shadow(0 18px 12px rgba(0,0,0,.14));
            transform: translate3d(0,0,0);
            transition: filter .2s ease;
        }

        .character.dragging {
            cursor: grabbing;
            filter: drop-shadow(0 24px 18px rgba(0,0,0,.2));
        }

        .character img {
            display: block;
            width: 100%;
            height: auto;
            pointer-events: none;
        }

        .drag-hint {
            position: absolute;
            z-index: 7;
            left: 18%;
            top: 48%;
            transform: translateY(-100%);
            background: rgba(255,255,255,.94);
            color: var(--ink);
            border-radius: 999px;
            padding: 9px 13px;
            font-size: 12px;
            font-weight: 800;
            box-shadow: 0 10px 28px rgba(0,0,0,.14);
            pointer-events: none;
            transition: .25s ease;
        }

        .drag-hint.hidden { opacity: 0; transform: translateY(8px); }

        .success {
            position: absolute;
            left: 50%;
            top: 18%;
            transform: translate(-50%, -18px);
            opacity: 0;
            z-index: 20;
            background: #fff;
            border: 1px solid #d8eee5;
            color: #147a55;
            padding: 12px 16px;
            border-radius: 14px;
            font-weight: 800;
            box-shadow: 0 14px 40px rgba(0,0,0,.14);
            transition: .35s ease;
            pointer-events: none;
        }

        .success.show { opacity: 1; transform: translate(-50%, 0); }

        body.login-success .app {
            transform: perspective(2600px) scale(1.01);
            filter: saturate(1.08);
            transition: transform 1.85s cubic-bezier(0.18, 0.85, 0.2, 1), filter 1.18s ease;
        }

        body.login-success .scene {
            transform: translateX(-320px) rotateY(-46deg) scale(0.962);
            opacity: 0.28;
            filter: blur(0.55px) saturate(1.18);
            box-shadow: 54px 0 80px rgba(8, 30, 48, 0.18);
            transition: transform 1.85s cubic-bezier(0.18, 0.85, 0.2, 1), opacity 1.15s ease, filter 1.15s ease, box-shadow 1.15s ease;
        }

        body.login-success .login-panel {
            transform: translateX(320px) rotateY(46deg) scale(0.962);
            opacity: 0.28;
            filter: blur(0.55px) saturate(1.1);
            box-shadow: -54px 0 80px rgba(8, 30, 48, 0.18);
            transition: transform 1.85s cubic-bezier(0.18, 0.85, 0.2, 1), opacity 1.15s ease, filter 1.15s ease, box-shadow 1.15s ease;
        }

        body.login-success.zooming .app {
            transform: perspective(2000px) scale(1.2);
            filter: saturate(1.2);
            transition: transform 0.48s cubic-bezier(0.2, 0.9, 0.25, 1), filter 0.38s ease;
        }

        body.login-success.zooming .scene {
            transform: translateX(-360px) rotateY(-12deg) scale(1.04);
            opacity: 0;
            visibility: hidden;
            filter: blur(1.6px) saturate(0.8);
            box-shadow: none;
            transition: transform 0.48s cubic-bezier(0.2, 0.9, 0.25, 1), opacity 0.28s ease, filter 0.28s ease, box-shadow 0.28s ease, visibility 0.28s ease;
        }

        body.login-success.zooming .login-panel {
            transform: translateX(360px) rotateY(12deg) scale(1.04);
            opacity: 0;
            visibility: hidden;
            filter: blur(1.6px) saturate(0.8);
            box-shadow: none;
            transition: transform 0.48s cubic-bezier(0.2, 0.9, 0.25, 1), opacity 0.28s ease, filter 0.28s ease, box-shadow 0.28s ease, visibility 0.28s ease;
        }

        body.login-success .scene,
        body.login-success .login-panel,
        body.login-success.zooming .scene,
        body.login-success.zooming .login-panel {
            will-change: transform, opacity, filter, box-shadow;
        }

        .scene,
        .login-panel {
            will-change: transform, opacity, filter, box-shadow;
        }

        .scene {
            position: relative;
            z-index: 1;
            border-right: 1px solid rgba(255,255,255,0.65);
            box-shadow: 0 0 0 rgba(0,0,0,0);
            transform-origin: left center;
            transform-style: preserve-3d;
        }

        .login-panel {
            position: relative;
            z-index: 2;
            border-left: 1px solid rgba(17,104,173,0.08);
            border-radius: 0 28px 28px 0;
            padding: 54px clamp(34px, 6vw, 78px);
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #fff;
            width: min(100%, 520px);
            margin: 0 auto;
            box-shadow: 0 0 0 rgba(0,0,0,0);
            transform-origin: right center;
            transform-style: preserve-3d;
            transition: transform 1.9s cubic-bezier(0.16, 1, 0.3, 1), opacity 1.3s ease, filter 1.3s ease, box-shadow 1.3s ease;
        }

        .login-panel::before {
            content: "";
            position: absolute;
            left: -1px;
            top: 4%;
            width: 3px;
            height: 92%;
            background: linear-gradient(180deg, rgba(17, 104, 173, 0.05), rgba(17, 104, 173, 0.5), rgba(17, 104, 173, 0.05));
            border-radius: 999px;
            box-shadow: 0 0 0 1px rgba(17, 104, 173, 0.06), 0 0 18px rgba(17, 104, 173, 0.12);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
        }

        .logo-icon {
            width: 50px;
            height: 50px;
            border-radius: 15px;
            display: grid;
            place-items: center;
            color: white;
            background: linear-gradient(145deg, #1783d0, #0e5b9d);
            box-shadow: 0 10px 24px rgba(17,104,173,.22);
            font-size: 25px;
        }

        .logo strong { display: block; font-size: 18px; line-height: 1.1; }
        .logo small { display: block; color: #8a99a5; font-size: 10px; margin-top: 4px; }

        .eyebrow {
            color: var(--blue);
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .12em;
            margin-bottom: 7px;
        }

        h1 { margin: 0; font-size: 30px; letter-spacing: -0.03em; }

        .intro {
            margin: 9px 0 28px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .field { margin-bottom: 17px; }

        label {
            display: block;
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 7px;
        }

        .input-wrap { position: relative; }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #8ea0ae;
            font-size: 14px;
        }

        input {
            width: 100%;
            height: 48px;
            border: 1px solid var(--line);
            border-radius: 12px;
            outline: none;
            padding: 0 42px;
            font: inherit;
            font-size: 13px;
            color: var(--ink);
            background: #fbfdff;
            transition: .2s ease;
        }

        input:focus {
            border-color: #58a9df;
            box-shadow: 0 0 0 4px rgba(17,104,173,.10);
            background: #fff;
        }

        .toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #7f919f;
            cursor: pointer;
            padding: 7px;
            font-size: 13px;
        }

        .options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 1px 0 22px;
            font-size: 11px;
            color: #758695;
        }

        .remember {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .remember input { width: 14px; height: 14px; padding: 0; accent-color: var(--blue); }

        .link {
            color: var(--blue);
            font-weight: 800;
            text-decoration: none;
        }

        .login-btn {
            width: 100%;
            height: 49px;
            border: 0;
            border-radius: 12px;
            color: white;
            background: linear-gradient(135deg, #1377c0, #0c5d9f);
            font: inherit;
            font-weight: 900;
            font-size: 13px;
            cursor: pointer;
            box-shadow: 0 12px 25px rgba(17,104,173,.22);
            transition: .2s ease;
        }

        .login-btn:hover { transform: translateY(-1px); box-shadow: 0 15px 30px rgba(17,104,173,.28); }
        .login-btn:active { transform: translateY(0); }
        .login-btn:disabled { opacity: .55; cursor: not-allowed; transform: none; }

        .status {
            min-height: 18px;
            margin-top: 12px;
            text-align: center;
            color: #758695;
            font-size: 11px;
        }

        .status.success-text { color: var(--success); font-weight: 800; }

        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            line-height: 1.5;
            font-weight: 500;
        }
        .alert-error {
            background: rgba(220, 53, 69, 0.08);
            border: 1px solid rgba(220, 53, 69, 0.15);
            color: #b02a37;
        }
        .alert-success {
            background: rgba(28, 155, 107, 0.08);
            border: 1px solid rgba(28, 155, 107, 0.15);
            color: #147a55;
        }

        .footer {
            margin-top: 30px;
            padding-top: 18px;
            border-top: 1px solid #edf2f5;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 10px;
            color: #91a0ab;
        }

        @media (max-width: 900px) {
            body { padding: 0; }
            .app { min-height: 100vh; border-radius: 0; grid-template-columns: 1fr; }
            .scene { min-height: 500px; order: 2; }
            .login-panel { padding: 42px 28px; order: 1; }
            .mission { left: 16px; bottom: 16px; width: min(290px, calc(100% - 32px)); }
            .character { width: 135px; bottom: 12%; left: 2%; }
            .goal { left: 74%; top: 36%; }
        }

        @media (max-width: 560px) {
            .scene { min-height: 440px; }
            .scene-header { top: 18px; left: 18px; right: 18px; }
            .mission { left: 12px; bottom: 12px; width: calc(100% - 24px); padding: 10px 12px; }
            .mission h2 { font-size: 15px; }
            .character { min-width: 100px; width: 28%; bottom: 15%; left: 1%; }
            .drag-hint { left: 18%; top: 50%; font-size: 11px; }
            .goal { width: 58px; height: 58px; left: 76%; top: 36%; }
        }
    </style>
</head>
<body>
    <main class="app">
        <section class="scene" id="scene" aria-label="Interactive hospital arrival scene">
            <div class="scene-header">
                <div class="brand-mini">
                    <div class="brand-mark">
                        <img src="<?php echo BASE_URL; ?>assets/logo.png" alt="Saint Claire Medical Center logo" />
                    </div>
                    <span>Saint Claire Hospital</span>
                </div>
                <div class="help-pill">Welcome</div>
            </div>

            <div class="goal" id="goal">HOSPITAL<br>ENTRANCE</div>
            <div class="success" id="success">✓ Great job! You helped them reach the hospital.</div>
            <div class="drag-hint" id="dragHint">↔ Drag the healthcare worker to push forward</div>

            <div class="character" id="character" role="button" tabindex="0" aria-label="Drag the healthcare worker and wheelchair toward the hospital">
                <img src="<?php echo BASE_URL; ?>assets/css/worker-wheelchair.png" alt="Healthcare worker assisting an older adult in a wheelchair" />
            </div>

            <div class="mission">
                <div class="mission-kicker">Care starts here</div>
                <h2>Help them get safely to the hospital</h2>
                <p>Drag the healthcare worker toward the hospital entrance.</p>
                <div class="progress"><span id="progressBar"></span></div>
            </div>
        </section>

        <section class="login-panel">
            <div class="logo">
                <div class="logo-icon">✚</div>
                <div>
                    <strong>Saint Claire Hospital</strong>
                    <small>Hospital Management System</small>
                </div>
            </div>

            <div class="eyebrow">Secure access</div>
            <h1>Welcome back</h1>
            <p class="intro">Sign in to manage patients, medical records, appointments, billing, and hospital operations.</p>

            <?php if ($error): ?>
                <div class="alert alert-error" style="margin-bottom: 20px;">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            <?php if ($success_msg): ?>
                <div class="alert alert-success" style="margin-bottom: 20px;">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form id="loginForm" method="POST" action="">
                <div class="field">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <span class="input-icon">♙</span>
                        <input id="username" name="username" autocomplete="username" placeholder="Enter your username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8') : ''; ?>" required />
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <span class="input-icon">▣</span>
                        <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required />
                        <button class="toggle" type="button" id="togglePassword" aria-label="Show password">Show</button>
                    </div>
                </div>

                <div class="options">
                    <a class="link" href="#" id="forgotPasswordLink">Forgot password?</a>
                </div>

                <button class="login-btn" id="loginBtn" type="submit">Sign in securely</button>
                <div class="status" id="status" aria-live="polite"></div>
            </form>

            <!-- Forgot Password Form -->
            <form id="forgotForm" method="POST" action="" style="display: none;">
                <div class="field">
                    <label for="forgot_username">Username</label>
                    <div class="input-wrap">
                        <span class="input-icon">♙</span>
                        <input id="forgot_username" name="forgot_username" autocomplete="username" placeholder="Enter your username" required />
                    </div>
                </div>

                <div class="field">
                    <label for="forgot_email">Registered Email</label>
                    <div class="input-wrap">
                        <span class="input-icon">✉</span>
                        <input id="forgot_email" name="forgot_email" type="email" autocomplete="email" placeholder="Enter your registered email" required />
                    </div>
                </div>

                <div class="options">
                    <a class="link" href="#" id="backToLoginLink">Back to Sign in</a>
                </div>

                <button class="login-btn" id="forgotBtn" type="submit">Submit Request</button>
                <div class="status" id="forgotStatus" aria-live="polite"></div>
            </form>

            <div class="footer">
                <span>© 2026 Saint Claire Hospital</span>
                <span>Protected system</span>
            </div>
        </section>
    </main>

    <script>
        const scene = document.getElementById("scene");
        const character = document.getElementById("character");
        const goal = document.getElementById("goal");
        const progressBar = document.getElementById("progressBar");
        const dragHint = document.getElementById("dragHint");
        const success = document.getElementById("success");
        const loginBtn = document.getElementById("loginBtn");
        const status = document.getElementById("status");

        let dragging = false;
        let startPointerX = 0;
        let startLeft = 0;
        let completed = false;

        function bounds() {
            const sceneRect = scene.getBoundingClientRect();
            const charRect = character.getBoundingClientRect();
            const min = sceneRect.width * 0.015;
            const max = sceneRect.width * 0.61 - charRect.width;
            return { sceneRect, min, max: Math.max(min, max) };
        }

        function currentLeft() {
            return character.offsetLeft;
        }

        function setPosition(x) {
            const { sceneRect, min, max } = bounds();
            const clamped = Math.max(min, Math.min(max, x));
            character.style.left = `${clamped}px`;

            const progress = Math.max(0, Math.min(100, ((clamped - min) / Math.max(1, max - min)) * 100));
            progressBar.style.width = `${Math.max(8, progress)}%`;

            if (progress > 18) dragHint.classList.add("hidden");
            if (progress >= 88 && !completed) completeJourney();
        }

        function completeJourney() {
            completed = true;
            dragging = false;
            character.style.pointerEvents = "none";
            character.classList.remove("dragging");
            goal.classList.add("done");
            goal.textContent = "✓ ARRIVED";
            success.classList.add("show");
            status.textContent = "Journey complete — you can now sign in.";
            status.classList.add("success-text");
            setTimeout(() => success.classList.remove("show"), 3600);
        }

        character.addEventListener("pointerdown", (e) => {
            if (completed) {
                e.preventDefault();
                return;
            }
            dragging = true;
            character.classList.add("dragging");
            character.setPointerCapture(e.pointerId);
            startPointerX = e.clientX;
            startLeft = currentLeft();
            e.preventDefault();
        });

        character.addEventListener("pointermove", (e) => {
            if (!dragging || completed) return;
            const delta = e.clientX - startPointerX;
            setPosition(startLeft + delta);
        });

        function stopDrag() {
            dragging = false;
            character.classList.remove("dragging");
        }

        character.addEventListener("pointerup", stopDrag);
        character.addEventListener("pointercancel", stopDrag);

        character.addEventListener("keydown", (e) => {
            if (completed) return;
            if (!["ArrowRight", "ArrowLeft"].includes(e.key)) return;
            e.preventDefault();
            setPosition(currentLeft() + (e.key === "ArrowRight" ? 18 : -18));
        });

        document.getElementById("togglePassword").addEventListener("click", (e) => {
            const password = document.getElementById("password");
            const visible = password.type === "text";
            password.type = visible ? "password" : "text";
            e.currentTarget.textContent = visible ? "Show" : "Hide";
            e.currentTarget.setAttribute("aria-label", visible ? "Show password" : "Hide password");
        });

        // Form toggling logic
        const loginForm = document.getElementById("loginForm");
        const forgotForm = document.getElementById("forgotForm");
        const forgotPasswordLink = document.getElementById("forgotPasswordLink");
        const backToLoginLink = document.getElementById("backToLoginLink");
        const forgotBtn = document.getElementById("forgotBtn");
        const forgotStatus = document.getElementById("forgotStatus");

        forgotPasswordLink.addEventListener("click", (e) => {
            e.preventDefault();
            loginForm.style.display = "none";
            forgotForm.style.display = "block";
            // Clear status
            status.textContent = "";
            forgotStatus.textContent = "";
        });

        backToLoginLink.addEventListener("click", (e) => {
            e.preventDefault();
            forgotForm.style.display = "none";
            loginForm.style.display = "block";
            // Clear status
            status.textContent = "";
            forgotStatus.textContent = "";
        });

        // Keep the forgot form visible if it was submitted
        <?php if (isset($_POST['forgot_username'])): ?>
        loginForm.style.display = "none";
        forgotForm.style.display = "block";
        <?php endif; ?>

        <?php if ($transitionView): ?>
        document.addEventListener('DOMContentLoaded', () => {
            requestAnimationFrame(() => {
                document.body.classList.add('login-success');
            });
            setTimeout(() => {
                document.body.classList.add('zooming');
            }, 1050);
            setTimeout(() => {
                window.location.href = 'dashboard.php';
            }, 2200);
        });
        <?php endif; ?>

        document.getElementById("loginForm").addEventListener("submit", (e) => {
            const username = document.getElementById("username").value.trim();
            const password = document.getElementById("password").value;

            if (!username || !password) {
                status.textContent = "Please enter your username and password.";
                status.classList.remove("success-text");
                e.preventDefault();
                return;
            }

            loginBtn.disabled = true;
            loginBtn.textContent = "Signing in…";
            status.textContent = "Authenticating…";
            status.classList.remove("success-text");
        });

        forgotForm.addEventListener("submit", (e) => {
            const username = document.getElementById("forgot_username").value.trim();
            const email = document.getElementById("forgot_email").value.trim();

            if (!username || !email) {
                forgotStatus.textContent = "Please enter your username and email.";
                e.preventDefault();
                return;
            }

            forgotBtn.disabled = true;
            forgotBtn.textContent = "Submitting…";
            forgotStatus.textContent = "Submitting request…";
        });

        window.addEventListener("resize", () => {
            setPosition(currentLeft());
        });
    </script>
</body>
</html>
