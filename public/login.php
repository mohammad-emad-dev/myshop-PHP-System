<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

// Handle Logout
if (isset($_GET['logout'])) {
    $token = $_GET['csrf_token'] ?? '';
    if (verify_csrf_token($token)) {
        // Destroy session safely
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
    redirect('login.php');
}

if (isset($_SESSION['staff_id'])) {
    redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Invalid request token.';
    } else {
        $username = sanitize_input($_POST['username']);
        $password = $_POST['password'];

        $stmt = $conn->prepare("SELECT id, password, full_name, role FROM Staff WHERE username = ?");
        if (!$stmt) {
            die("Database Error: " . $conn->error);
        }
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {
                // Secure session regeneration
                session_regenerate_id(true);
                $_SESSION['staff_id'] = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                
                // Refresh CSRF token for the active session
                unset($_SESSION['csrf_token']);
                generate_csrf_token();
                
                redirect('index.php');
            } else {
                $error = 'Invalid credentials';
            }
        } else {
            $error = 'Invalid credentials';
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
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
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
                                    placeholder="Password" required />
                                <label for="password">Password</label>
                            </div>
                            <div class="d-grid gap-2 mt-4 mb-3">
                                <button class="btn btn-primary btn-lg" type="submit">Log In</button>
                            </div>
                        </form>
                    </div>
                    <div class="card-footer text-center py-3 bg-light border-0 rounded-bottom">
                        <div class="small text-muted">Default: <strong>admin</strong> / <strong>admin123</strong></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/script.js"></script>
</body>
</html>