<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';
$csp_nonce = send_security_headers();

$error = '';
$is_logout_request = $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'logout';

if ($is_logout_request) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        http_response_code(403);
        $error = 'Security check failed. Invalid request token.';
    } else {
        destroy_current_session();
        redirect('login.php');
    }
}

if (!$is_logout_request && isset($_SESSION['staff_id']) && verify_login(false)) {
    redirect('index.php');
}

// A logout POST is never allowed to fall through to the login handler. This
// also ensures invalid logout tokens do not initialize or increment attempts.
if (!$is_logout_request && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Brute Force Protection (Session-based) ---
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['last_attempt_time'] = time();
    }

    $lockout_time = 15 * 60; // 15 minutes
    $max_attempts = 5;

    if ($_SESSION['login_attempts'] >= $max_attempts) {
        if (time() - $_SESSION['last_attempt_time'] < $lockout_time) {
            $remaining = ceil(($lockout_time - (time() - $_SESSION['last_attempt_time'])) / 60);
            $error = "Too many failed attempts. Please try again in {$remaining} minutes.";
        } else {
            // Reset after lockout expires
            $_SESSION['login_attempts'] = 0;
            $_SESSION['last_attempt_time'] = time();
        }
    }

    if (empty($error)) {
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!verify_csrf_token($csrf_token)) {
            http_response_code(403);
            $error = 'Security check failed. Invalid request token.';
        } else {
            $username = sanitize_input($_POST['username']);
            $password = $_POST['password'];

            $stmt = $conn->prepare("SELECT id, password, full_name, role FROM Staff WHERE username = ? AND is_active = 1");
            if (!$stmt) {
                error_log('Login query prepare failed: ' . $conn->error);
                $error = 'Unable to process the login request right now.';
            } else {
                $stmt->bind_param("s", $username);
                if (!$stmt->execute()) {
                    error_log('Login query execution failed: ' . $stmt->error);
                    $error = 'Unable to process the login request right now.';
                } else {
                    $result = $stmt->get_result();

                    if ($result->num_rows === 1) {
                        $user = $result->fetch_assoc();

                        if (password_verify($password, $user['password'])) {
                            // Secure session regeneration to prevent fixation
                            session_regenerate_id(true);

                            // Clear failed attempts
                            unset($_SESSION['login_attempts']);
                            unset($_SESSION['last_attempt_time']);

                            $_SESSION['staff_id'] = $user['id'];
                            $_SESSION['full_name'] = $user['full_name'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['last_activity'] = time();

                            // Refresh CSRF token for the active session
                            unset($_SESSION['csrf_token']);
                            generate_csrf_token();

                            redirect('index.php');
                        } else {
                            $_SESSION['login_attempts']++;
                            $_SESSION['last_attempt_time'] = time();
                            $error = 'Invalid credentials';
                        }
                    } else {
                        $_SESSION['login_attempts']++;
                        $_SESSION['last_attempt_time'] = time();
                        $error = 'Invalid credentials';
                    }
                }
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - myshop Dashboard</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="<?php echo htmlspecialchars(get_asset_integrity('https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css'), ENT_QUOTES, 'UTF-8'); ?>" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet" integrity="<?php echo htmlspecialchars(get_asset_integrity('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css'), ENT_QUOTES, 'UTF-8'); ?>" crossorigin="anonymous">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-gradient-primary login-page d-flex align-items-center justify-content-center min-vh-100">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-lg border-0 rounded-lg mt-5">
                    <div class="card-header bg-white border-0 text-center pt-4 pb-0">
                        <h3 class="font-weight-light my-2 text-primary fw-bold">myshop</h3>
                        <p class="text-muted small">Inventory & Order Management</p>
                    </div>
                    <div class="card-body px-5 py-4">
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <div class="form-floating mb-3">
                                <input class="form-control" id="username" name="username" type="text"
                                    placeholder="Username" required autofocus />
                                <label for="username">Username</label>
                            </div>
                            <div class="form-floating mb-3">
                                <input class="form-control" id="password" name="password" type="password"
                                    placeholder="Password" autocomplete="current-password" required />
                                <label for="password">Password</label>
                            </div>
                            <div class="d-grid gap-2 mt-4 mb-3">
                                <button class="btn btn-primary btn-lg" type="submit">Log In</button>
                            </div>
                        </form>
                    </div>
                    <div class="card-footer text-center py-3 bg-light border-0 rounded-bottom">
                        <div class="small text-muted">Use the administrator account created during installation.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="<?php echo htmlspecialchars(get_asset_integrity('https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'), ENT_QUOTES, 'UTF-8'); ?>" crossorigin="anonymous"></script>
    <script src="assets/js/script.js"></script>
</body>
</html>
