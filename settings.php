<?php
require_once 'includes/functions.php';
start_secure_session();
require_once 'includes/db.php';

verify_login();

$staff_id = $_SESSION['staff_id'];
$success = '';
$error = '';

// Fetch current user details from database
$stmt = $conn->prepare("SELECT id, username, full_name, password FROM Staff WHERE id = ?");
$stmt->bind_param("i", $staff_id);
$stmt->execute();
$staff = $stmt->get_result()->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Invalid token.';
    } else {
        $full_name = sanitize_input($_POST['full_name']);
        $username = sanitize_input($_POST['username']);
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($full_name) || empty($username)) {
            $error = 'Full Name and Username cannot be empty.';
        } else {
            // Check for duplicate username
            $dup_check = $conn->prepare("SELECT id FROM Staff WHERE username = ? AND id != ?");
            $dup_check->bind_param("si", $username, $staff_id);
            $dup_check->execute();
            
            if ($dup_check->get_result()->num_rows > 0) {
                $error = 'Username is already taken by another staff member.';
            } else {
                // Verify current password
                if (!password_verify($current_password, $staff['password'])) {
                    $error = 'Incorrect current password. Verification failed.';
                } else {
                    // Process update inside a safe database transaction
                    $conn->begin_transaction();
                    try {
                        if (!empty($new_password)) {
                            if ($new_password !== $confirm_password) {
                                throw new Exception('New password and confirmation do not match.');
                            }
                            $hashed_pass = password_hash($new_password, PASSWORD_BCRYPT);
                            $update_stmt = $conn->prepare("UPDATE Staff SET username = ?, full_name = ?, password = ? WHERE id = ?");
                            $update_stmt->bind_param("sssi", $username, $full_name, $hashed_pass, $staff_id);
                        } else {
                            $update_stmt = $conn->prepare("UPDATE Staff SET username = ?, full_name = ? WHERE id = ?");
                            $update_stmt->bind_param("ssi", $username, $full_name, $staff_id);
                        }

                        if ($update_stmt->execute()) {
                            $conn->commit();
                            $_SESSION['full_name'] = $full_name;
                            $success = 'Profile and settings updated successfully!';
                            
                            // Re-fetch updated details
                            $stmt = $conn->prepare("SELECT id, username, full_name, password FROM Staff WHERE id = ?");
                            $stmt->bind_param("i", $staff_id);
                            $stmt->execute();
                            $staff = $stmt->get_result()->fetch_assoc();
                        } else {
                            throw new Exception('Database error occurred while updating profile.');
                        }
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = $e->getMessage();
                    }
                }
            }
        }
    }
}

$page_title = 'Settings';
$active_page = 'settings';
$header_title = 'Staff Settings';

require_once 'includes/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once 'includes/sidebar.php'; ?>
    <?php require_once 'includes/navbar.php'; ?>

    <div class="container-fluid px-4 py-5">
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row my-2 justify-content-center">
            <div class="col-lg-6 col-md-8">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h4 class="mb-0 text-secondary fw-bold"><i class="fas fa-user-edit me-2 text-primary"></i>My Profile Settings</h4>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            
                            <div class="mb-3">
                                <label for="full_name" class="form-label fw-bold">Full Name</label>
                                <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($staff['full_name']); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="username" class="form-label fw-bold">Username</label>
                                <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($staff['username']); ?>" required>
                            </div>

                            <hr class="my-4">
                            
                            <h5 class="text-secondary mb-3 fw-bold"><i class="fas fa-key me-2 text-warning"></i>Change Password</h5>
                            
                            <div class="mb-3">
                                <label for="new_password" class="form-label fw-bold">New Password (leave empty to keep current)</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" placeholder="Enter new password">
                            </div>
                            
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label fw-bold">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm new password">
                            </div>

                            <hr class="my-4">
                            
                            <div class="mb-4 bg-light p-3 rounded-3 border-start border-warning border-3">
                                <label for="current_password" class="form-label fw-bold text-warning mb-1"><i class="fas fa-shield-alt me-1"></i>Security Verification</label>
                                <p class="small text-muted mb-2">You must provide your current password to save changes.</p>
                                <input type="password" class="form-control" id="current_password" name="current_password" placeholder="Enter current password to verify" required>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg py-3 fw-bold"><i class="fas fa-save me-2"></i>Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php
require_once 'includes/footer.php';
?>
