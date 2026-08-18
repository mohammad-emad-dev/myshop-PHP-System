<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

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
        http_response_code(403);
        $error = 'Security check failed. Invalid request token.';
    } else {
        $action = $_POST['action'] ?? 'update_profile';
        // All Staff table mutations are administrator-only. Cashiers may log in
        // and use the POS, but cannot change staff records, including profiles.
        require_admin();

        if ($action === 'update_profile') {
            $full_name = sanitize_input($_POST['full_name']);
            $username = sanitize_input($_POST['username']);
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];

            if (empty($full_name) || empty($username)) {
                $error = 'Full Name and Username cannot be empty.';
            } elseif ($new_password !== '' && !password_meets_policy($new_password)) {
                http_response_code(400);
                $error = 'Password does not meet the minimum security requirements.';
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
                            if ($e->getMessage() === 'New password and confirmation do not match.') {
                                $error = 'New password and confirmation do not match.';
                            } else {
                                error_log('Profile update failed: ' . $e->getMessage());
                                $error = 'Unable to update profile settings right now.';
                            }
                        }
                    }
                }
            }
        } elseif ($action === 'create_staff') {
            $new_username = sanitize_input($_POST['staff_username'] ?? '');
            $new_full_name = sanitize_input($_POST['staff_full_name'] ?? '');
            $new_role = sanitize_input($_POST['staff_role'] ?? 'cashier');
            $new_pass = $_POST['staff_password'] ?? '';

            if (!password_meets_policy($new_pass)) {
                http_response_code(400);
                $error = 'Password does not meet the minimum security requirements.';
            } elseif (create_staff_member($conn, $new_username, $new_pass, $new_full_name, $new_role)) {
                $success = 'Staff account registered successfully!';
            } else {
                $error = 'Failed to create staff account. The username may already be in use.';
            }
        } elseif ($action === 'update_staff') {
            $target_id = (int)($_POST['staff_id'] ?? 0);
            $target_username = sanitize_input($_POST['staff_username'] ?? '');
            $target_full_name = sanitize_input($_POST['staff_full_name'] ?? '');
            $target_role = sanitize_input($_POST['staff_role'] ?? 'cashier');
            $target_pass = $_POST['staff_password'] ?? '';

            if ($target_pass !== '' && !password_meets_policy($target_pass)) {
                http_response_code(400);
                $error = 'Password does not meet the minimum security requirements.';
            } elseif (update_staff_member($conn, $target_id, $target_username, $target_full_name, $target_role, $target_pass)) {
                $success = 'Staff member updated successfully!';
            } else {
                $error = 'Failed to update staff member. Ensure you are not attempting to demote the sole active administrator.';
            }
        } elseif ($action === 'delete_staff') {
            $target_id = (int)($_POST['staff_id'] ?? 0);

            if (delete_staff_member($conn, $target_id, $_SESSION['staff_id'])) {
                $success = 'Staff account deactivated successfully.';
            } else {
                $error = 'Failed to deactivate staff account. Self-deactivation and deactivation of the last active administrator are blocked.';
            }
        } elseif ($action === 'set_staff_active') {
            $target_id = (int)($_POST['staff_id'] ?? 0);
            $requested_active = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_INT);

            if (!in_array($requested_active, [0, 1], true)) {
                $error = 'Unable to change the staff account status.';
            } elseif (set_staff_active($conn, $target_id, $requested_active, $_SESSION['staff_id'])) {
                $success = $requested_active === 1
                    ? 'Staff account enabled successfully.'
                    : 'Staff account disabled successfully.';
            } else {
                $error = 'Unable to change the staff account status. Self-deactivation and disabling the last active administrator are blocked.';
            }
        }
    }
}

$page_title = 'Settings';
$active_page = 'settings';
$header_title = 'Staff Settings';
$extra_css = ['https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.min.css'];

require_once '../includes/layouts/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once '../includes/layouts/sidebar.php'; ?>
    <?php require_once '../includes/layouts/navbar.php'; ?>

    <div class="container-fluid px-4 py-5">

        <div class="row my-2 justify-content-center">
            <div class="col-lg-6 col-md-8">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h4 class="mb-0 text-secondary fw-bold"><i class="fas fa-user-edit me-2 text-primary"></i>My Profile Settings</h4>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <input type="hidden" name="action" value="update_profile">
                            
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

        <?php if (is_admin()): ?>
        <!-- Manage Staff accounts panel -->
        <div class="row my-4 justify-content-center">
            <div class="col-lg-10 col-md-12">
                
                <!-- System Backups (Admin Only) -->
                <div class="card shadow-sm border-0 rounded-4 mb-4 border-start border-danger border-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h4 class="mb-0 text-secondary fw-bold"><i class="fas fa-server me-2 text-danger"></i>System Administration & Backups</h4>
                    </div>
                    <div class="card-body p-4 bg-danger-subtle bg-opacity-10 rounded-bottom-4">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div>
                                <h5 class="fw-bold text-dark mb-1">Database Backup (.sql)</h5>
                                <p class="text-muted mb-0 small">Download a full snapshot of the entire database including products, orders, and one-way staff password hashes.</p>
                                <p class="text-danger small fw-bold mt-1 mb-0"><i class="fas fa-exclamation-triangle me-1"></i>Re-enter your current password. Keep the downloaded file highly secure.</p>
                            </div>
                            <form method="POST" action="backup_database.php" class="d-flex align-items-center gap-2">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                <label class="visually-hidden" for="backup_current_password">Current password</label>
                                <input type="password" class="form-control" id="backup_current_password" name="current_password" placeholder="Current password" autocomplete="current-password" required>
                                <button type="submit" class="btn btn-danger btn-lg shadow-sm fw-bold px-4 pulse-btn rounded-3">
                                    <i class="fas fa-download me-2"></i>Download Backup
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h4 class="mb-0 text-secondary fw-bold"><i class="fas fa-users me-2 text-primary"></i>Manage Staff Accounts</h4>
                        <button class="btn btn-primary btn-sm shadow-sm px-3 rounded-pill pulse-btn ui-transition" data-bs-toggle="modal" data-bs-target="#addStaffModal">
                            <i class="fas fa-user-plus me-1"></i> Add Staff
                        </button>
                    </div>
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="bg-light text-secondary">
                                    <tr>
                                        <th>Full Name</th>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Created At</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $members = get_staff_members($conn);
                                    foreach ($members as $m): 
                                    ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($m['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($m['username']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $m['role'] === 'admin' ? 'bg-danger' : 'bg-secondary'; ?> text-uppercase">
                                                <?php echo htmlspecialchars($m['role']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo (int)$m['is_active'] === 1 ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo (int)$m['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($m['created_at'])); ?></td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-info me-1 edit-staff-btn"
                                                    aria-label="Edit staff account" title="Edit staff account"
                                                    data-staff-id="<?php echo (int)$m['id']; ?>"
                                                    data-staff-full-name="<?php echo htmlspecialchars($m['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-staff-username="<?php echo htmlspecialchars($m['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-staff-role="<?php echo htmlspecialchars($m['role'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ((int)$m['id'] !== (int)$staff_id): ?>
                                            <form method="POST" action="settings.php" class="d-inline status-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="set_staff_active">
                                                <input type="hidden" name="staff_id" value="<?php echo (int)$m['id']; ?>">
                                                <input type="hidden" name="is_active" value="<?php echo (int)$m['is_active'] === 1 ? '0' : '1'; ?>">
                                                <button type="submit" class="btn btn-sm <?php echo (int)$m['is_active'] === 1 ? 'btn-outline-warning' : 'btn-outline-success'; ?> me-1" title="<?php echo (int)$m['is_active'] === 1 ? 'Disable account' : 'Enable account'; ?>">
                                                    <i class="fas <?php echo (int)$m['is_active'] === 1 ? 'fa-user-slash' : 'fa-user-check'; ?>"></i>
                                                </button>
                                            </form>
                                            <form method="POST" action="settings.php" class="d-inline delete-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="delete_staff">
                                                <input type="hidden" name="staff_id" value="<?php echo (int)$m['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Deactivate staff account" title="Deactivate staff account">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Staff Modal -->
        <div class="modal fade" id="addStaffModal" tabindex="-1" aria-labelledby="addStaffModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 rounded-4">
                    <div class="modal-header py-3">
                        <h5 class="modal-title fw-bold ui-modal-title" id="addStaffModalLabel"><i class="fas fa-user-plus me-2 text-primary"></i>Add New Staff Member</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_staff">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Full Name</label>
                                <input type="text" class="form-control" name="staff_full_name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Username</label>
                                <input type="text" class="form-control" name="staff_username" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Role</label>
                                <select class="form-select" name="staff_role" required>
                                    <option value="cashier">Cashier</option>
                                    <option value="admin">Administrator</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Password</label>
                                <input type="password" class="form-control" name="staff_password" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary rounded-3 px-4">Add Member</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Staff Modal -->
        <div class="modal fade" id="editStaffModal" tabindex="-1" aria-labelledby="editStaffModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 rounded-4">
                    <div class="modal-header py-3">
                        <h5 class="modal-title fw-bold ui-modal-title" id="editStaffModalLabel"><i class="fas fa-user-edit me-2 text-primary"></i>Edit Staff Member</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                        <input type="hidden" name="action" value="update_staff">
                        <input type="hidden" id="edit_staff_id" name="staff_id">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Full Name</label>
                                <input type="text" class="form-control" id="edit_staff_full_name" name="staff_full_name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Username</label>
                                <input type="text" class="form-control" id="edit_staff_username" name="staff_username" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Role</label>
                                <select class="form-select" id="edit_staff_role" name="staff_role" required>
                                    <option value="cashier">Cashier</option>
                                    <option value="admin">Administrator</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Password (leave empty to keep current)</label>
                                <input type="password" class="form-control" name="staff_password" placeholder="Enter new password">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary rounded-3 px-4">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
        function openEditStaffModal(button) {
            document.getElementById('edit_staff_id').value = button.dataset.staffId;
            document.getElementById('edit_staff_full_name').value = button.dataset.staffFullName;
            document.getElementById('edit_staff_username').value = button.dataset.staffUsername;
            document.getElementById('edit_staff_role').value = button.dataset.staffRole;
            
            var modal = new bootstrap.Modal(document.getElementById('editStaffModal'));
            modal.show();
        }

        document.querySelectorAll('.edit-staff-btn').forEach(function(button) {
            button.addEventListener('click', function() {
                openEditStaffModal(button);
            });
        });

        document.querySelectorAll('.delete-form').forEach(function(form) {
            form.addEventListener('submit', function(event) {
                event.preventDefault();
                Swal.fire({
                    title: 'Delete Staff Member?',
                    text: 'Are you sure you want to delete this staff account? This action cannot be undone.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: 'Yes, delete it!'
                }).then(function(result) {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            });
        });

        document.querySelectorAll('.status-form').forEach(function(form) {
            form.addEventListener('submit', function(event) {
                event.preventDefault();
                var activating = form.querySelector('input[name="is_active"]').value === '1';
                Swal.fire({
                    title: activating ? 'Enable Staff Member?' : 'Disable Staff Member?',
                    text: activating
                        ? 'This staff member will be allowed to sign in again.'
                        : 'This staff member will no longer be allowed to sign in.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: activating ? '#10b981' : '#f59e0b',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: activating ? 'Yes, enable it!' : 'Yes, disable it!'
                }).then(function(result) {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            });
        });

</script>
        <?php endif; ?>
    </div>

<?php
$extra_js = [
    'https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.all.min.js'
];
require_once '../includes/layouts/footer.php';
?>

<?php if (!empty($success)): ?>
<script nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
    window.addEventListener('load', function() {
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: document.body.dataset.feedbackSuccess,
            confirmButtonColor: '#10b981',
            timer: 3000,
            timerProgressBar: true
        });
    });
</script>
<?php endif; ?>

<?php if (!empty($error)): ?>
<script nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
    window.addEventListener('load', function() {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: document.body.dataset.feedbackError,
            confirmButtonColor: '#ef4444'
        });
    });
</script>
<?php endif; ?>
