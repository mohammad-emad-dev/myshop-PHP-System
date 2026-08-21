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
        audit_log_current_actor($conn, 'logout', 'Session', null, false, ['reason' => 'csrf_validation_failed']);
        $error = 'Security check failed. Invalid request token.';
    } else {
        audit_log_current_actor($conn, 'logout', 'Session', null, true);
        destroy_current_session();
        redirect('login.php');
    }
}

if (!$is_logout_request && isset($_SESSION['staff_id']) && auth_verify_login($conn, false)) {
    redirect('index.php');
}

// A logout POST is never allowed to fall through to the login handler. This
// also ensures invalid logout tokens do not initialize or increment attempts.
if (!$is_logout_request && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        http_response_code(403);
        audit_log_current_actor($conn, 'login_failure', 'Staff', null, false, ['reason' => 'csrf_validation_failed']);
        $error = 'Security check failed. Invalid request token.';
    } else {
        $submitted_username = $_POST['username'] ?? '';
        $username = is_string($submitted_username) ? trim($submitted_username) : '';
        $password = $_POST['password'] ?? '';
        $source_ip = get_login_source_ip();
        $rate_limit_key = build_login_rate_limit_key($username, $source_ip);

        if ($rate_limit_key === false) {
            http_response_code(503);
            audit_log_current_actor($conn, 'login_failure', 'Staff', null, false, ['reason' => 'source_ip_unavailable']);
            $error = 'Unable to process the login request right now.';
        } else {
            $rate_limit_state = login_rate_limit_check($conn, $rate_limit_key);

            if ($rate_limit_state['status'] === 'error') {
                http_response_code(503);
                audit_log_current_actor($conn, 'login_failure', 'Staff', null, false, ['reason' => 'rate_limit_unavailable']);
                $error = 'Unable to process the login request right now.';
            } elseif ($rate_limit_state['status'] === 'blocked') {
                http_response_code(429);
                header('Retry-After: ' . (int)$rate_limit_state['retry_after']);
                audit_log_current_actor($conn, 'login_failure', 'Staff', null, false, ['reason' => 'rate_limited']);
                $error = 'Too many login attempts. Please try again later.';
            } else {
                try {
                    $stmt = $conn->prepare(
                        "SELECT id, password, full_name, role
                         FROM Staff
                         WHERE username = ? AND is_active = 1
                         LIMIT 1"
                    );

                    if (!$stmt) {
                        error_log('Login query prepare failed: ' . $conn->error);
                        http_response_code(503);
                        $error = 'Unable to process the login request right now.';
                    } elseif (!$stmt->bind_param('s', $username)) {
                        error_log('Login query bind failed: ' . $stmt->error);
                        $stmt->close();
                        http_response_code(503);
                        $error = 'Unable to process the login request right now.';
                    } elseif (!$stmt->execute()) {
                        error_log('Login query execution failed: ' . $stmt->error);
                        $stmt->close();
                        http_response_code(503);
                        $error = 'Unable to process the login request right now.';
                    } else {
                        $result = $stmt->get_result();
                        if (!$result) {
                            error_log('Login query result failed: ' . $stmt->error);
                            $stmt->close();
                            http_response_code(503);
                            $error = 'Unable to process the login request right now.';
                        } else {
                            $user = $result->num_rows === 1 ? $result->fetch_assoc() : null;
                            $result->free();
                            $stmt->close();

                            $credentials_valid = is_array($user)
                                && is_string($password)
                                && is_string($user['password'] ?? null)
                                && password_verify($password, $user['password']);

                            if ($credentials_valid) {
                                if (!login_rate_limit_reset($conn, $rate_limit_key)) {
                                    http_response_code(503);
                                    $error = 'Unable to process the login request right now.';
                                } elseif (!session_regenerate_id(true)) {
                                    error_log('Login session regeneration failed.');
                                    http_response_code(503);
                                    $error = 'Unable to process the login request right now.';
                                } else {
                                    // Remove legacy session counters if an older session contains them.
                                    unset($_SESSION['login_attempts'], $_SESSION['last_attempt_time']);

                                    $_SESSION['staff_id'] = $user['id'];
                                    $_SESSION['full_name'] = $user['full_name'];
                                    $_SESSION['role'] = $user['role'];
                                    $_SESSION['last_activity'] = time();

                                    // Refresh CSRF token for the active session.
                                    unset($_SESSION['csrf_token']);
                                    generate_csrf_token();

                                    audit_log($conn, (int)$user['id'], 'login_success', 'Staff', (int)$user['id'], true, [
                                        'role' => $user['role'],
                                    ]);
                                    redirect('index.php');
                                }
                            } else {
                                $failure_state = login_rate_limit_record_failure($conn, $rate_limit_key);
                                if ($failure_state['status'] === 'error') {
                                    http_response_code(503);
                                    audit_log_current_actor($conn, 'login_failure', 'Staff', null, false, ['reason' => 'rate_limit_unavailable']);
                                    $error = 'Unable to process the login request right now.';
                                } elseif ($failure_state['status'] === 'blocked') {
                                    http_response_code(429);
                                    header('Retry-After: ' . (int)$failure_state['retry_after']);
                                    audit_log_current_actor($conn, 'login_failure', 'Staff', null, false, ['reason' => 'rate_limited']);
                                    $error = 'Too many login attempts. Please try again later.';
                                } else {
                                    audit_log_current_actor($conn, 'login_failure', 'Staff', null, false, ['reason' => 'invalid_credentials']);
                                    $error = 'Invalid credentials';
                                }
                            }
                        }
                    }
                } catch (Throwable $exception) {
                    error_log('Login database operation failed: ' . $exception->getMessage());
                    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
                        $stmt->close();
                    }
                    http_response_code(503);
                    audit_log_current_actor($conn, 'login_failure', 'Staff', null, false, ['reason' => 'database_operation_failed']);
                    $error = 'Unable to process the login request right now.';
                }
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
<body class="login-page login-shell d-flex align-items-center justify-content-center min-vh-100">

    <main class="login-shell__main container" id="main-content">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-10 col-md-7 col-lg-5 col-xl-4">
                <div class="card login-card">
                    <div class="card-header login-card__header text-center">
                        <div class="login-brand-mark" aria-hidden="true"><i class="fas fa-store"></i></div>
                        <h1 class="login-brand-title">myshop</h1>
                        <p class="text-muted small">Inventory & Order Management</p>
                    </div>
                    <div class="card-body login-card__body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" class="login-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <div class="form-floating mb-3">
                                <input class="form-control" id="username" name="username" type="text"
                                    placeholder="Username" autocomplete="username" required autofocus />
                                <label for="username">Username</label>
                            </div>
                            <div class="form-floating mb-3">
                                <input class="form-control" id="password" name="password" type="password"
                                    placeholder="Password" autocomplete="current-password" required />
                                <label for="password">Password</label>
                            </div>
                            <div class="d-grid gap-2 mt-4 mb-3">
                                <button class="btn btn-primary btn-lg login-submit" type="submit">Log In</button>
                            </div>
                        </form>
                    </div>
                    <div class="card-footer login-card__footer text-center">
                        <div class="small text-muted">Use the administrator account created during installation.</div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="<?php echo htmlspecialchars(get_asset_integrity('https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'), ENT_QUOTES, 'UTF-8'); ?>" crossorigin="anonymous"></script>
    <script src="assets/js/script.js"></script>
</body>
</html>
