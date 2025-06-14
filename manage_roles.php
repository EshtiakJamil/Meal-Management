<?php
session_start();
require_once 'config.php';

// Check if Higher Authority is logged in
if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] != 'Higher Authority') {
    header('Location: index.php');
    exit();
}

$admin_name = $_SESSION['admin_name'];
$success_message = '';
$error_message = '';

// Handle role changes
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['change_role'])) {
        $admin_id = mysqli_real_escape_string($conn, $_POST['admin_id']);
        $new_role = mysqli_real_escape_string($conn, $_POST['new_role']);
        
        // Get current admin details
        $current_query = "SELECT * FROM admins WHERE id = '$admin_id'";
        $current_result = mysqli_query($conn, $current_query);
        $current_admin = mysqli_fetch_assoc($current_result);
        
        if ($current_admin) {
            $can_change = true;
            
            // Check role limits
            if ($new_role == 'Manager') {
                $count_query = "SELECT COUNT(*) as count FROM admins WHERE role = 'Manager' AND id != '$admin_id'";
                $count_result = mysqli_query($conn, $count_query);
                $count = mysqli_fetch_assoc($count_result)['count'];
                if ($count >= 2) {
                    $error_message = "Maximum 2 managers allowed!";
                    $can_change = false;
                }
            } elseif ($new_role == 'Monitor') {
                $gender = $current_admin['gender'];
                $count_query = "SELECT COUNT(*) as count FROM admins WHERE role = 'Monitor' AND gender = '$gender' AND id != '$admin_id'";
                $count_result = mysqli_query($conn, $count_query);
                $count = mysqli_fetch_assoc($count_result)['count'];
                if ($count >= 2) {
                    $error_message = "Maximum 2 " . strtolower($gender) . " monitors allowed!";
                    $can_change = false;
                }
            }
            
            if ($can_change) {
                $update_query = "UPDATE admins SET role = '$new_role' WHERE id = '$admin_id'";
                if (mysqli_query($conn, $update_query)) {
                    $success_message = "Role updated successfully!";
                } else {
                    $error_message = "Error updating role: " . mysqli_error($conn);
                }
            }
        } else {
            $error_message = "Admin not found!";
        }
    }
    
    if (isset($_POST['monitor_transfer'])) {
        $current_monitor_id = mysqli_real_escape_string($conn, $_POST['current_monitor_id']);
        $new_monitor_id = mysqli_real_escape_string($conn, $_POST['new_monitor_id']);
        
        // Get current monitor details
        $current_query = "SELECT * FROM admins WHERE id = '$current_monitor_id' AND role = 'Monitor'";
        $current_result = mysqli_query($conn, $current_query);
        $current_monitor = mysqli_fetch_assoc($current_result);
        
        // Get new monitor details
        $new_query = "SELECT * FROM students WHERE id = '$new_monitor_id'";
        $new_result = mysqli_query($conn, $new_query);
        $new_monitor = mysqli_fetch_assoc($new_result);
        
        if ($current_monitor && $new_monitor && $current_monitor['gender'] == $new_monitor['gender']) {
            // Check if new monitor gender matches current monitor gender
            $gender = $current_monitor['gender'];
            
            // Start transaction
            mysqli_autocommit($conn, FALSE);
            
            // Remove current monitor (change to student)
            $student_password = password_hash($current_monitor['roll'], PASSWORD_DEFAULT);
            $insert_student = "INSERT INTO students (name, roll, batch, gender, password) VALUES ('{$current_monitor['name']}', '{$current_monitor['roll']}', '{$current_monitor['batch']}', '{$current_monitor['gender']}', '$student_password')";
            
            $delete_admin = "DELETE FROM admins WHERE id = '$current_monitor_id'";
            
            // Add new monitor
            $admin_password = password_hash($new_monitor['roll'], PASSWORD_DEFAULT);
            $insert_admin = "INSERT INTO admins (name, batch, roll, role, gender, password) VALUES ('{$new_monitor['name']}', '{$new_monitor['batch']}', '{$new_monitor['roll']}', 'Monitor', '{$new_monitor['gender']}', '$admin_password')";
            
            $delete_student = "DELETE FROM students WHERE id = '$new_monitor_id'";
            
            if (mysqli_query($conn, $insert_student) && 
                mysqli_query($conn, $delete_admin) && 
                mysqli_query($conn, $insert_admin) && 
                mysqli_query($conn, $delete_student)) {
                
                mysqli_commit($conn);
                $success_message = "Monitor transferred successfully!";
            } else {
                mysqli_rollback($conn);
                $error_message = "Error transferring monitor: " . mysqli_error($conn);
            }
            
            mysqli_autocommit($conn, TRUE);
        } else {
            $error_message = "Invalid monitor transfer! Gender must match.";
        }
    }
}

// Get all admins
$admins_query = "SELECT * FROM admins WHERE role != 'Higher Authority' ORDER BY role, gender, batch";
$admins_result = mysqli_query($conn, $admins_query);

// Get all students for monitor selection
$students_query = "SELECT * FROM students ORDER BY batch, roll";
$students_result = mysqli_query($conn, $students_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Roles - NMC Meal Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .form-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }
        .navbar-brand {
            font-weight: bold;
        }
        .user-info {
            background: rgba(255,255,255,0.1);
            border-radius: 20px;
            padding: 8px 15px;
        }
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        .btn-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
        }
        .btn-gradient:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
            color: white;
        }
        .bg-pink { 
            background-color: #e83e8c !important; 
        }
    </style>
</head>
<body class="bg-light">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
        <div class="container">
            <a class="navbar-brand" href="admin_dashboard.php">
                <i class="fas fa-utensils me-2"></i>NMC Meal Management
            </a>
            <div class="navbar-nav ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle user-info" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($admin_name); ?>
                        <span class="badge bg-warning text-dark ms-2">Higher Authority</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="admin_dashboard.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
                        <li><a class="dropdown-item" href="add_users.php"><i class="fas fa-plus me-2"></i>Add Users</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col">
                <h2 class="text-dark">
                    <i class="fas fa-user-cog me-2"></i>Manage Roles
                </h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="admin_dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Manage Roles</li>
                    </ol>
                </nav>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Current Admins -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="form-card">
                    <h4 class="mb-4"><i class="fas fa-users-cog me-2"></i>Current Admins</h4>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Name</th>
                                    <th>Roll</th>
                                    <th>Batch</th>
                                    <th>Current Role</th>
                                    <th>Gender</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($admin = mysqli_fetch_assoc($admins_result)): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($admin['name']); ?></td>
                                    <td><span class="badge bg-primary"><?php echo htmlspecialchars($admin['roll']); ?></span></td>
                                    <td><?php echo htmlspecialchars($admin['batch']); ?></td>
                                    <td>
                                        <span class="badge 
                                            <?php 
                                            if ($admin['role'] == 'Manager') echo 'bg-success';
                                            else echo 'bg-secondary';
                                            ?>">
                                            <?php echo $admin['role']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $admin['gender'] == 'Male' ? 'bg-info' : 'bg-pink'; ?>">
                                            <?php echo $admin['gender']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <!-- Change Role Button -->
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#changeRoleModal<?php echo $admin['id']; ?>">
                                                <i class="fas fa-exchange-alt me-1"></i>Change Role
                                            </button>
                                            
                                            <?php if ($admin['role'] == 'Monitor'): ?>
                                            <!-- Transfer Monitor Button -->
                                            <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#transferMonitorModal<?php echo $admin['id']; ?>">
                                                <i class="fas fa-user-friends me-1"></i>Transfer
                                            </button>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Change Role Modal -->
                                        <div class="modal fade" id="changeRoleModal<?php echo $admin['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Change Role - <?php echo htmlspecialchars($admin['name']); ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">Current Role: <strong><?php echo $admin['role']; ?></strong></label>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="new_role<?php echo $admin['id']; ?>" class="form-label">New Role</label>
                                                                <select class="form-select" name="new_role" id="new_role<?php echo $admin['id']; ?>" required>
                                                                    <option value="">Select New Role</option>
                                                                    <option value="Manager" <?php echo $admin['role'] == 'Manager' ? 'selected' : ''; ?>>Manager</option>
                                                                    <option value="Monitor" <?php echo $admin['role'] == 'Monitor' ? 'selected' : ''; ?>>Monitor</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="change_role" class="btn btn-primary">Change Role</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                        <?php if ($admin['role'] == 'Monitor'): ?>
                                        <!-- Transfer Monitor Modal -->
                                        <div class="modal fade" id="transferMonitorModal<?php echo $admin['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Transfer Monitor Role - <?php echo htmlspecialchars($admin['name']); ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="alert alert-info">
                                                                <i class="fas fa-info-circle me-2"></i>
                                                                Select a <strong><?php echo $admin['gender']; ?></strong> student to transfer the monitor role to. 
                                                                The current monitor will become a regular student.
                                                            </div>
                                                            <div class="mb-3">
                                                                <label for="new_monitor<?php echo $admin['id']; ?>" class="form-label">Select New Monitor</label>
                                                                <select class="form-select" name="new_monitor_id" id="new_monitor<?php echo $admin['id']; ?>" required>
                                                                    <option value="">Select Student</option>
                                                                    <?php 
                                                                    mysqli_data_seek($students_result, 0);
                                                                    while ($student = mysqli_fetch_assoc($students_result)): 
                                                                        if ($student['gender'] == $admin['gender']):
                                                                    ?>
                                                                    <option value="<?php echo $student['id']; ?>">
                                                                        <?php echo htmlspecialchars($student['name']) . ' (' . $student['roll'] . ' - ' . $student['batch'] . ')'; ?>
                                                                    </option>
                                                                    <?php 
                                                                        endif;
                                                                    endwhile; 
                                                                    ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <input type="hidden" name="current_monitor_id" value="<?php echo $admin['id']; ?>">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="monitor_transfer" class="btn btn-warning">Transfer Role</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Role Limits Info -->
        <div class="row">
            <div class="col-12">
                <div class="alert alert-info">
                    <h6><i class="fas fa-info-circle me-2"></i>Role Management Rules:</h6>
                    <ul class="mb-0">
                        <li><strong>Managers:</strong> Maximum 2 allowed at any time</li>
                        <li><strong>Monitors:</strong> Maximum 2 male and 2 female monitors allowed</li>
                        <li><strong>Monitor Transfer:</strong> Only same-gender transfers are allowed</li>
                        <li><strong>Role Changes:</strong> Will be applied immediately</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>