<?php
require_once 'config.php';
requireLogin();

if (!hasRole('higher_authority')) {
    redirect('dashboard.php');
}

$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'assign_manager') {
        $studentId = (int)$_POST['student_id'];

        try {
            // Check current manager count
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE role = 'manager'");
            $stmt->execute();
            $managerCount = $stmt->fetchColumn();

            if ($managerCount >= 2) {
                $message = "Maximum 2 managers allowed. Please remove a manager first.";
                $messageType = 'error';
            } else {
                // Assign manager role
                $stmt = $pdo->prepare("UPDATE students SET role = 'manager' WHERE id = ?");
                $stmt->execute([$studentId]);

                $message = "Manager assigned successfully!";
                $messageType = 'success';
            }
        } catch (PDOException $e) {
            $message = "Error assigning manager. Please try again.";
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'remove_manager') {
        $studentId = (int)$_POST['student_id'];

        try {
            $stmt = $pdo->prepare("UPDATE students SET role = 'student' WHERE id = ? AND role = 'manager'");
            $stmt->execute([$studentId]);

            $message = "Manager removed successfully!";
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = "Error removing manager. Please try again.";
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'assign_monitor') {
        $studentId = (int)$_POST['student_id'];

        try {
            // Check current monitor count
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE role = 'monitor'");
            $stmt->execute();
            $monitorCount = $stmt->fetchColumn();

            if ($monitorCount >= 4) {
                $message = "Maximum 4 monitors allowed. Please remove a monitor first.";
                $messageType = 'error';
            } else {
                // Check gender balance
                $stmt = $pdo->prepare("SELECT gender FROM students WHERE id = ?");
                $stmt->execute([$studentId]);
                $studentGender = $stmt->fetchColumn();

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE role = 'monitor' AND gender = ?");
                $stmt->execute([$studentGender]);
                $genderCount = $stmt->fetchColumn();

                if ($genderCount >= 2) {
                    $message = "Maximum 2 {$studentGender} monitors allowed.";
                    $messageType = 'error';
                } else {
                    // Assign monitor role
                    $stmt = $pdo->prepare("UPDATE students SET role = 'monitor' WHERE id = ?");
                    $stmt->execute([$studentId]);

                    $message = "Monitor assigned successfully!";
                    $messageType = 'success';
                }
            }
        } catch (PDOException $e) {
            $message = "Error assigning monitor. Please try again.";
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'remove_monitor') {
        $studentId = (int)$_POST['student_id'];

        try {
            $stmt = $pdo->prepare("UPDATE students SET role = 'student' WHERE id = ? AND role = 'monitor'");
            $stmt->execute([$studentId]);

            $message = "Monitor removed successfully!";
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = "Error removing monitor. Please try again.";
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'toggle_monitor_permission') {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'monitor_can_add_students'");
            $stmt->execute();
            $currentValue = $stmt->fetchColumn();

            $newValue = ($currentValue === 'true') ? 'false' : 'true';

            $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'monitor_can_add_students'");
            $stmt->execute([$newValue]);

            $message = "Monitor permission updated successfully!";
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = "Error updating permission. Please try again.";
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'add_student') {
        $name = trim($_POST['name']);
        $roll = trim($_POST['roll']);
        $gender = $_POST['gender'];
        $batch = trim($_POST['batch']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO students (name, roll, gender, batch, password) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $roll, $gender, $batch, $password]);

            $message = "Student added successfully!";
            $messageType = 'success';
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $message = "Roll number already exists.";
                $messageType = 'error';
            } else {
                $message = "Error adding student. Please try again.";
                $messageType = 'error';
            }
        }
    } elseif ($_POST['action'] === 'update_meal_plan') {
        $planId = (int)$_POST['plan_id'];
        $name = trim($_POST['plan_name']);
        $price = (float)$_POST['plan_price'];
        $description = trim($_POST['plan_description']);

        try {
            $stmt = $pdo->prepare("UPDATE meal_plans SET name = ?, price = ?, description = ? WHERE id = ?");
            $stmt->execute([$name, $price, $description, $planId]);

            $message = "Meal plan updated successfully!";
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = "Error updating meal plan. Please try again.";
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'add_payment_number') {
        $provider = $_POST['provider'];
        $number = trim($_POST['number']);

        // Validate Bangladeshi mobile number format
        if (!preg_match('/^01[3-9]\d{8}$/', $number)) {
            $message = "Invalid mobile number format. Please enter a valid Bangladeshi number.";
            $messageType = 'error';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO payment_numbers (provider, number) VALUES (?, ?)");
                $stmt->execute([$provider, $number]);

                $message = ucfirst($provider) . " number added successfully!";
                $messageType = 'success';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $message = "This " . ucfirst($provider) . " number already exists.";
                    $messageType = 'error';
                } else {
                    $message = "Error adding payment number. Please try again.";
                    $messageType = 'error';
                }
            }
        }
    } elseif ($_POST['action'] === 'delete_payment_number') {
        $numberId = (int)$_POST['number_id'];

        try {
            $stmt = $pdo->prepare("DELETE FROM payment_numbers WHERE id = ?");
            $stmt->execute([$numberId]);

            $message = "Payment number deleted successfully!";
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = "Error deleting payment number. Please try again.";
            $messageType = 'error';
        }
    }
}

// Get current managers and monitors
$stmt = $pdo->prepare("SELECT * FROM students WHERE role IN ('manager', 'monitor') ORDER BY role, name");
$stmt->execute();
$staffMembers = $stmt->fetchAll();

// Get meal plans for editing
$stmt = $pdo->prepare("SELECT * FROM meal_plans ORDER BY name");
$stmt->execute();
$mealPlans = $stmt->fetchAll();

// Get payment numbers for display
$stmt = $pdo->prepare("SELECT * FROM payment_numbers ORDER BY provider, created_at DESC");
$stmt->execute();
$paymentNumbers = $stmt->fetchAll();

// Get monitor permission status
$stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'monitor_can_add_students'");
$stmt->execute();
$monitorCanAddStudents = $stmt->fetchColumn() === 'true';

// Get students for assignment (filter functionality)
$batchFilter = $_GET['batch'] ?? '';
$genderFilter = $_GET['gender'] ?? '';

$whereConditions = ["role = 'student'"];
$params = [];

if ($batchFilter) {
    $whereConditions[] = "batch = ?";
    $params[] = $batchFilter;
}

if ($genderFilter) {
    $whereConditions[] = "gender = ?";
    $params[] = $genderFilter;
}

$whereClause = implode(' AND ', $whereConditions);
$stmt = $pdo->prepare("SELECT * FROM students WHERE $whereClause ORDER BY batch, name");
$stmt->execute($params);
$availableStudents = $stmt->fetchAll();

// Get distinct batches for filter
$stmt = $pdo->prepare("SELECT DISTINCT batch FROM students ORDER BY batch");
$stmt->execute();
$batches = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Higher Authority Dashboard - Hostel Meal Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Custom gradient background */
        .gradient-bg {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 50%, #000000 100%);
        }

        /* Header animations */
        .header-glow {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        /* Dropdown animations */
        .dropdown-container:hover .dropdown-menu {
            display: block;
            animation: slideDown 0.3s ease-out;

        }

        .dropdown-menu {
            display: none;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Mobile menu toggle */
        .mobile-menu-toggle {
            display: none;
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }

            .desktop-nav {
                display: none;
            }
        }

        /* Button hover effects */
        .btn-hover {
            transition: all 0.3s ease;
            transform: translateY(0);
        }

        .btn-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Card animations */
        .card-hover {
            transition: all 0.3s ease;
        }

        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        /* Add these styles to your existing CSS */

        /* Payment provider specific styling */
        .provider-bkash {
            background: linear-gradient(135deg, #e91e63 0%, #f06292 100%);
        }

        .provider-nagad {
            background: linear-gradient(135deg, #ff9800 0%, #ffc107 100%);
        }

        /* Payment number validation styling */
        input[name="number"]:invalid {
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }

        input[name="number"]:valid {
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }

        /* Enhanced card hover effect for payment numbers */
        .payment-number-card {
            transition: all 0.3s ease;
            transform: translateY(0);
        }

        .payment-number-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        /* Mobile responsive adjustments */
        @media (max-width: 640px) {
            .payment-numbers-grid {
                grid-template-columns: 1fr;
            }

            .payment-number-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .payment-number-actions {
                align-self: flex-end;
            }
        }

        /* Loading state for form submission */
        .form-loading {
            opacity: 0.7;
            pointer-events: none;
        }

        .form-loading button {
            position: relative;
        }

        .form-loading button::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            top: 50%;
            left: 50%;
            margin-left: -8px;
            margin-top: -8px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen">
    <!-- Enhanced Header -->
    <header class="gradient-bg text-white shadow-2xl header-glow relative overflow-hidden">
        <!-- Decorative background pattern -->
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 left-0 w-full h-full bg-gradient-to-r from-transparent via-white to-transparent transform -skew-x-12"></div>
        </div>

        <div class="container mx-auto px-4 py-6 relative z-10">
            <div class="flex justify-between items-center">
                <!-- Logo and Title Section -->
                <div class="flex items-center space-x-4">
                    <div class="bg-white bg-opacity-20 p-3 rounded-full backdrop-blur-sm">
                        <i class="fas fa-user-shield text-2xl text-white"></i>
                    </div>
                    <div>
                        <h1 class="text-xl md:text-2xl font-bold bg-gradient-to-r from-white to-gray-300 bg-clip-text text-transparent">
                            Higher Authority Dashboard
                        </h1>
                        <p class="text-xs md:text-sm text-gray-300 mt-1">Hostel Meal Management System</p>
                    </div>
                </div>

                <!-- Desktop Navigation -->
                <div class="desktop-nav flex items-center space-x-6">
                    <!-- Quick Stats -->
                    <div class="hidden lg:flex items-center space-x-4 text-sm">
                        <div class="bg-white bg-opacity-10 px-3 py-2 rounded-lg backdrop-blur-sm">
                            <i class="fas fa-users text-blue-300 mr-2"></i>
                            <span class="text-gray-200">Managers: <?php echo count(array_filter($staffMembers, function ($s) {
                                                                        return $s['role'] === 'manager';
                                                                    })); ?>/2</span>
                        </div>
                        <div class="bg-white bg-opacity-10 px-3 py-2 rounded-lg backdrop-blur-sm">
                            <i class="fas fa-eye text-green-300 mr-2"></i>
                            <span class="text-gray-200">Monitors: <?php echo count(array_filter($staffMembers, function ($s) {
                                                                        return $s['role'] === 'monitor';
                                                                    })); ?>/4</span>
                        </div>
                    </div>

                    <!-- User Menu -->
                    <div class="dropdown-container">
                        <button class="flex items-center space-x-3 bg-white bg-opacity-10 hover:bg-opacity-20 px-4 py-3 rounded-lg backdrop-blur-sm transition-all duration-300 btn-hover">
                            <div class="w-8 h-8 bg-gradient-to-r from-blue-400 to-purple-500 rounded-full flex items-center justify-center">
                                <i class="fas fa-user text-white text-sm"></i>
                            </div>
                            <div class="text-left hidden md:block">
                                <p class="font-medium text-white"><?php echo htmlspecialchars($_SESSION['name']); ?></p>
                                <p class="text-xs text-gray-300">Administrator</p>
                            </div>
                            <svg class="w-4 h-4 text-gray-300 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div class="dropdown-menu relative right-0 mt-2 w-56 bg-white rounded-xl shadow-2xl py-2 z-50 border border-gray-100">
                            <div class="px-4 py-3 border-b border-gray-100">
                                <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($_SESSION['name']); ?></p>
                                <p class="text-xs text-gray-500">Higher Authority</p>
                            </div>
                            <a href="manager_dashboard.php" class="flex items-center px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 transition-colors duration-200">
                                <i class="fas fa-tachometer-alt mr-3 text-gray-400"></i>
                                Manager Dashboard
                            </a>
                            <a href="dashboard.php" class="flex items-center px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 transition-colors duration-200">
                                <i class="fas fa-tachometer-alt mr-3 text-gray-400"></i>
                                Student Dashboard
                            </a>
                            <a href="logout.php" class="flex items-center px-4 py-3 text-sm text-red-600 hover:bg-red-50 transition-colors duration-200">
                                <i class="fas fa-sign-out-alt mr-3 text-red-500"></i>
                                Logout
                            </a>
                        </div>
                    </div>
                </div>

                <div class="bg-white bg-opacity-10 px-3 py-2 rounded-lg backdrop-blur-sm">
                    <button onclick="openMealPlanModal()" class="text-gray-200 hover:text-white transition-colors">
                        <i class="fas fa-utensils text-orange-300 mr-2"></i>
                        <span>Edit Meal Plans</span>
                    </button>
                </div>
            </div>

            <!-- Mobile Menu -->
            <div id="mobileMenu" class="hidden mt-4 pb-4 border-t border-gray-600">
                <div class="flex flex-col space-y-3 mt-4">
                    <div class="bg-white bg-opacity-10 p-3 rounded-lg">
                        <p class="text-white font-medium"><?php echo htmlspecialchars($_SESSION['name']); ?></p>
                        <p class="text-xs text-gray-300">Administrator</p>
                    </div>
                    <a href="dashboard.php" class="flex items-center text-white hover:bg-white hover:bg-opacity-10 p-3 rounded-lg transition-colors">
                        <i class="fas fa-tachometer-alt mr-3"></i>
                        Student Dashboard
                    </a>
                    <a href="logout.php" class="flex items-center text-red-300 hover:bg-red-500 hover:bg-opacity-20 p-3 rounded-lg transition-colors">
                        <i class="fas fa-sign-out-alt mr-3"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-4 py-6 md:py-8">
        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg border-l-4 <?php echo $messageType === 'success' ? 'bg-green-50 text-green-800 border-green-400' : 'bg-red-50 text-red-800 border-red-400'; ?>">
                <div class="flex items-center">
                    <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle text-green-500' : 'fa-exclamation-triangle text-red-500'; ?> mr-3"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 md:gap-8">
            <!-- Current Staff -->
            <div class="bg-white rounded-xl shadow-lg p-6 card-hover">
                <div class="flex items-center mb-6">
                    <div class="bg-gradient-to-r from-blue-500 to-purple-600 p-3 rounded-lg mr-4">
                        <i class="fas fa-users text-white text-xl"></i>
                    </div>
                    <h2 class="text-xl md:text-2xl font-bold text-gray-800">Current Staff</h2>
                </div>

                <div class="space-y-6">
                    <!-- Managers Section -->
                    <div>
                        <div class="flex items-center mb-3">
                            <i class="fas fa-user-tie text-blue-500 mr-2"></i>
                            <h3 class="text-lg font-semibold text-gray-800">Managers</h3>
                            <span class="ml-auto text-sm text-gray-500">
                                <?php echo count(array_filter($staffMembers, function ($s) {
                                    return $s['role'] === 'manager';
                                })); ?>/2
                            </span>
                        </div>
                        <?php
                        $managers = array_filter($staffMembers, function ($s) {
                            return $s['role'] === 'manager';
                        });
                        if (empty($managers)):
                        ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-user-plus text-3xl mb-2"></i>
                                <p>No managers assigned</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($managers as $manager): ?>
                                    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center bg-gradient-to-r from-blue-50 to-indigo-50 p-4 rounded-lg border border-blue-100">
                                        <div class="mb-2 sm:mb-0">
                                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($manager['name']); ?></p>
                                            <p class="text-sm text-gray-600">
                                                <i class="fas fa-id-badge mr-1"></i>
                                                <?php echo htmlspecialchars($manager['roll']); ?> - <?php echo htmlspecialchars($manager['batch']); ?>
                                            </p>
                                        </div>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="remove_manager">
                                            <input type="hidden" name="student_id" value="<?php echo $manager['id']; ?>">
                                            <button type="submit" class="w-full sm:w-auto bg-red-500 text-white px-4 py-2 rounded-lg text-sm hover:bg-red-600 transition-colors btn-hover">
                                                <i class="fas fa-trash-alt mr-1"></i>
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Monitors Section -->
                    <div>
                        <div class="flex items-center mb-3">
                            <i class="fas fa-eye text-green-500 mr-2"></i>
                            <h3 class="text-lg font-semibold text-gray-800">Monitors</h3>
                            <span class="ml-auto text-sm text-gray-500">
                                <?php echo count(array_filter($staffMembers, function ($s) {
                                    return $s['role'] === 'monitor';
                                })); ?>/4
                            </span>
                        </div>
                        <?php
                        $monitors = array_filter($staffMembers, function ($s) {
                            return $s['role'] === 'monitor';
                        });
                        if (empty($monitors)):
                        ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-user-plus text-3xl mb-2"></i>
                                <p>No monitors assigned</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($monitors as $monitor): ?>
                                    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center bg-gradient-to-r from-green-50 to-emerald-50 p-4 rounded-lg border border-green-100">
                                        <div class="mb-2 sm:mb-0">
                                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($monitor['name']); ?></p>
                                            <p class="text-sm text-gray-600">
                                                <i class="fas fa-id-badge mr-1"></i>
                                                <?php echo htmlspecialchars($monitor['roll']); ?> - <?php echo htmlspecialchars($monitor['batch']); ?>
                                                <span class="inline-block px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs ml-2">
                                                    <i class="fas <?php echo $monitor['gender'] === 'male' ? 'fa-mars' : 'fa-venus'; ?> mr-1"></i>
                                                    <?php echo ucfirst($monitor['gender']); ?>
                                                </span>
                                            </p>
                                        </div>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="remove_monitor">
                                            <input type="hidden" name="student_id" value="<?php echo $monitor['id']; ?>">
                                            <button type="submit" class="w-full sm:w-auto bg-red-500 text-white px-4 py-2 rounded-lg text-sm hover:bg-red-600 transition-colors btn-hover">
                                                <i class="fas fa-trash-alt mr-1"></i>
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Monitor Permissions -->
                <div class="mt-8 pt-6 border-t border-gray-200">
                    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center space-y-4 sm:space-y-0">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                                <i class="fas fa-key text-yellow-500 mr-2"></i>
                                Student Permissions
                            </h3>
                            <p class="text-sm text-gray-600 mt-1">Allow students to add their information</p>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="toggle_monitor_permission">
                            <button type="submit" class="px-6 py-3 rounded-lg text-sm font-medium transition-all duration-300 btn-hover <?php echo $monitorCanAddStudents ? 'bg-green-500 text-white hover:bg-green-600' : 'bg-gray-300 text-gray-700 hover:bg-gray-400'; ?>">
                                <i class="fas <?php echo $monitorCanAddStudents ? 'fa-toggle-on' : 'fa-toggle-off'; ?> mr-2"></i>
                                <?php echo $monitorCanAddStudents ? 'Enabled' : 'Disabled'; ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Assign New Staff -->
            <div class="bg-white rounded-xl shadow-lg p-6 card-hover">
                <div class="flex items-center mb-6">
                    <div class="bg-gradient-to-r from-green-500 to-teal-600 p-3 rounded-lg mr-4">
                        <i class="fas fa-user-plus text-white text-xl"></i>
                    </div>
                    <h2 class="text-xl md:text-2xl font-bold text-gray-800">Assign Staff</h2>
                </div>

                <!-- Filters -->
                <form method="GET" class="mb-6 bg-gray-50 p-4 rounded-lg">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-filter mr-1"></i>
                                Batch
                            </label>
                            <select name="batch" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-black focus:border-black transition-all">
                                <option value="">All Batches</option>
                                <?php foreach ($batches as $batch): ?>
                                    <option value="<?php echo htmlspecialchars($batch); ?>" <?php echo $batchFilter === $batch ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($batch); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-venus-mars mr-1"></i>
                                Gender
                            </label>
                            <select name="gender" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-black focus:border-black transition-all">
                                <option value="">All Genders</option>
                                <option value="male" <?php echo $genderFilter === 'male' ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo $genderFilter === 'female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="mt-4 bg-black text-white px-6 py-2 rounded-lg hover:bg-gray-800 transition-colors btn-hover">
                        <i class="fas fa-search mr-2"></i>
                        Filter
                    </button>
                </form>

                <!-- Available Students -->
                <div class="max-h-96 overflow-y-auto">
                    <?php if (empty($availableStudents)): ?>
                        <div class="text-center py-12 text-gray-500">
                            <i class="fas fa-user-slash text-4xl mb-4"></i>
                            <p class="text-lg">No students available for assignment</p>
                            <p class="text-sm">Try adjusting your filter criteria</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($availableStudents as $student): ?>
                                <div class="flex flex-col lg:flex-row lg:justify-between lg:items-center bg-gray-50 p-4 rounded-lg border hover:shadow-md transition-all">
                                    <div class="mb-3 lg:mb-0">
                                        <p class="font-medium text-gray-800"><?php echo htmlspecialchars($student['name']); ?></p>
                                        <p class="text-sm text-gray-600">
                                            <i class="fas fa-id-badge mr-1"></i>
                                            <?php echo htmlspecialchars($student['roll']); ?> - <?php echo htmlspecialchars($student['batch']); ?>
                                            <span class="inline-block px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs ml-2">
                                                <i class="fas <?php echo $student['gender'] === 'male' ? 'fa-mars' : 'fa-venus'; ?> mr-1"></i>
                                                <?php echo ucfirst($student['gender']); ?>
                                            </span>
                                        </p>
                                    </div>
                                    <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-2">
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="assign_manager">
                                            <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                            <button type="submit" class="w-full sm:w-auto bg-blue-500 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-600 transition-colors btn-hover">
                                                <i class="fas fa-user-tie mr-1"></i>
                                                Manager
                                            </button>
                                        </form>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="assign_monitor">
                                            <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                            <button type="submit" class="w-full sm:w-auto bg-green-500 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-600 transition-colors btn-hover">
                                                <i class="fas fa-eye mr-1"></i>
                                                Monitor
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Add this section before the "Add New Student" form -->
        <div class="mt-8 bg-white rounded-xl shadow-lg p-6 card-hover">
            <div class="flex items-center mb-6">
                <div class="bg-gradient-to-r from-emerald-500 to-teal-600 p-3 rounded-lg mr-4">
                    <i class="fas fa-mobile-alt text-white text-xl"></i>
                </div>
                <h2 class="text-xl md:text-2xl font-bold text-gray-800">Payment Numbers Management</h2>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <!-- Add Payment Number Form -->
                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 p-6 rounded-lg border border-blue-100">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-plus-circle text-blue-500 mr-2"></i>
                        Add Payment Number
                    </h3>

                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="add_payment_number">

                        <div>
                            <label for="provider" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-credit-card mr-1"></i>
                                Payment Provider
                            </label>
                            <select
                                id="provider"
                                name="provider"
                                required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                                <option value="">Select Provider</option>
                                <option value="bkash">bKash</option>
                                <option value="nagad">Nagad</option>
                            </select>
                        </div>

                        <div>
                            <label for="payment_number" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-phone mr-1"></i>
                                Mobile Number
                            </label>
                            <input
                                type="text"
                                id="payment_number"
                                name="number"
                                required
                                pattern="01[3-9]\d{8}"
                                maxlength="11"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all"
                                placeholder="01XXXXXXXXX">
                            <p class="text-xs text-gray-500 mt-1">Format: 01XXXXXXXXX (11 digits)</p>
                        </div>

                        <button
                            type="submit"
                            class="w-full bg-gradient-to-r from-blue-500 to-indigo-600 text-white py-3 px-4 rounded-lg hover:from-blue-600 hover:to-indigo-700 transition-all duration-300 btn-hover font-medium">
                            <i class="fas fa-plus mr-2"></i>
                            Add Number
                        </button>
                    </form>
                </div>

                <!-- Current Payment Numbers -->
                <div class="bg-gradient-to-br from-green-50 to-emerald-50 p-6 rounded-lg border border-green-100">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-list text-green-500 mr-2"></i>
                        Current Payment Numbers
                    </h3>

                    <div class="max-h-64 overflow-y-auto">
                        <?php if (empty($paymentNumbers)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-mobile-alt text-3xl mb-2"></i>
                                <p>No payment numbers added yet</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($paymentNumbers as $paymentNumber): ?>
                                    <div class="flex justify-between items-center bg-white p-4 rounded-lg border shadow-sm hover:shadow-md transition-all">
                                        <div class="flex items-center space-x-3">
                                            <div class="flex-shrink-0">
                                                <?php if ($paymentNumber['provider'] === 'bkash'): ?>
                                                    <div class="w-10 h-10 bg-gradient-to-r from-pink-500 to-red-500 rounded-lg flex items-center justify-center">
                                                        <i class="fas fa-mobile-alt text-white text-sm"></i>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="w-10 h-10 bg-gradient-to-r from-orange-500 to-yellow-500 rounded-lg flex items-center justify-center">
                                                        <i class="fas fa-mobile-alt text-white text-sm"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <p class="font-medium text-gray-800">
                                                    <?php echo ucfirst($paymentNumber['provider']); ?>
                                                </p>
                                                <p class="text-sm text-gray-600 font-mono">
                                                    <?php echo htmlspecialchars($paymentNumber['number']); ?>
                                                </p>
                                                <p class="text-xs text-gray-400">
                                                    Added: <?php echo date('M d, Y', strtotime($paymentNumber['created_at'])); ?>
                                                </p>
                                            </div>
                                        </div>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="delete_payment_number">
                                            <input type="hidden" name="number_id" value="<?php echo $paymentNumber['id']; ?>">
                                            <button
                                                type="submit"
                                                onclick="return confirm('Are you sure you want to delete this payment number?')"
                                                class="bg-red-500 text-white p-2 rounded-lg hover:bg-red-600 transition-colors btn-hover">
                                                <i class="fas fa-trash-alt text-sm"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Student Form -->
        <div class="mt-8 bg-white rounded-xl shadow-lg p-6 card-hover">
            <div class="flex items-center mb-6">
                <div class="bg-gradient-to-r from-purple-500 to-pink-600 p-3 rounded-lg mr-4">
                    <i class="fas fa-user-graduate text-white text-xl"></i>
                </div>
                <h2 class="text-xl md:text-2xl font-bold text-gray-800">Add New Student</h2>
            </div>

            <form method="POST" class="max-w-4xl">
                <input type="hidden" name="action" value="add_student">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-user mr-1"></i>
                            Full Name
                        </label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-black focus:border-black transition-all"
                            placeholder="Enter full name">
                    </div>

                    <div>
                        <label for="roll" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-id-badge mr-1"></i>
                            Roll Number
                        </label>
                        <input
                            type="text"
                            id="roll"
                            name="roll"
                            required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-black focus:border-black transition-all"
                            placeholder="Enter roll number">
                    </div>

                    <div>
                        <label for="gender" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-venus-mars mr-1"></i>
                            Gender
                        </label>
                        <select
                            id="gender"
                            name="gender"
                            required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-black focus:border-black transition-all">
                            <option value="">Select Gender</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                    </div>

                    <div>
                        <label for="batch" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-graduation-cap mr-1"></i>
                            Batch
                        </label>
                        <input
                            type="text"
                            id="batch"
                            name="batch"
                            required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-black focus:border-black transition-all"
                            placeholder="Enter batch (e.g., 2024)">
                    </div>

                    <div class="md:col-span-2">
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-lock mr-1"></i>
                            Password
                        </label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-black focus:border-black transition-all"
                            placeholder="Enter password">
                    </div>
                </div>

                <button
                    type="submit"
                    class="mt-8 bg-gradient-to-r from-black to-gray-800 text-white py-3 px-8 rounded-lg hover:from-gray-800 hover:to-black transition-all duration-300 btn-hover font-medium">
                    <i class="fas fa-plus mr-2"></i>
                    Add Student
                </button>
            </form>
        </div>
    </div>

    <!-- Meal Plan Modal -->
    <div id="mealPlanModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center p-6 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-800 flex items-center">
                    <i class="fas fa-utensils text-orange-500 mr-3"></i>
                    Manage Meal Plans
                </h3>
                <button onclick="closeMealPlanModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <div class="p-6">
                <div class="grid gap-4">
                    <?php foreach ($mealPlans as $plan): ?>
                        <div class="bg-gray-50 p-4 rounded-lg border hover:shadow-md transition-all">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <h4 class="font-semibold text-gray-800 text-lg"><?php echo htmlspecialchars($plan['name']); ?></h4>
                                    <p class="text-green-600 font-bold text-xl">৳<?php echo number_format($plan['price'], 2); ?></p>
                                    <p class="text-gray-600 text-sm mt-1"><?php echo htmlspecialchars($plan['description']); ?></p>
                                </div>
                                <button onclick="editMealPlan(<?php echo $plan['id']; ?>, '<?php echo addslashes($plan['name']); ?>', <?php echo $plan['price']; ?>, '<?php echo addslashes($plan['description']); ?>')"
                                    class="bg-blue-500 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-600 transition-colors btn-hover">
                                    <i class="fas fa-edit mr-1"></i>
                                    Edit
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Meal Plan Form Modal -->
    <div id="editMealPlanModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full">
            <div class="flex justify-between items-center p-6 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-800">Edit Meal Plan</h3>
                <button onclick="closeEditMealPlanModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form method="POST" class="p-6">
                <input type="hidden" name="action" value="update_meal_plan">
                <input type="hidden" name="plan_id" id="editPlanId">

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Plan Name</label>
                        <input type="text" name="plan_name" id="editPlanName" required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-black focus:border-black transition-all">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Price (৳)</label>
                        <input type="number" step="0.01" name="plan_price" id="editPlanPrice" required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-black focus:border-black transition-all">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea name="plan_description" id="editPlanDescription" rows="3"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-black focus:border-black transition-all"></textarea>
                    </div>
                </div>

                <div class="flex space-x-3 mt-6">
                    <button type="submit" class="flex-1 bg-blue-500 text-white py-3 px-4 rounded-lg hover:bg-blue-600 transition-colors btn-hover">
                        <i class="fas fa-save mr-2"></i>
                        Update Plan
                    </button>
                    <button type="button" onclick="closeEditMealPlanModal()" class="flex-1 bg-gray-300 text-gray-700 py-3 px-4 rounded-lg hover:bg-gray-400 transition-colors">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>


    <script>
        function toggleMobileMenu() {
            const menu = document.getElementById('mobileMenu');
            menu.classList.toggle('hidden');
        }

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            const menu = document.getElementById('mobileMenu');
            const button = event.target.closest('.mobile-menu-toggle');

            if (!button && !menu.contains(event.target)) {
                menu.classList.add('hidden');
            }
        });

        function openMealPlanModal() {
            document.getElementById('mealPlanModal').classList.remove('hidden');
        }

        function closeMealPlanModal() {
            document.getElementById('mealPlanModal').classList.add('hidden');
        }

        function editMealPlan(id, name, price, description) {
            document.getElementById('editPlanId').value = id;
            document.getElementById('editPlanName').value = name;
            document.getElementById('editPlanPrice').value = price;
            document.getElementById('editPlanDescription').value = description;

            closeMealPlanModal();
            document.getElementById('editMealPlanModal').classList.remove('hidden');
        }

        function closeEditMealPlanModal() {
            document.getElementById('editMealPlanModal').classList.add('hidden');
        }

        // Close modals when clicking outside
        document.addEventListener('click', function(event) {
            const mealPlanModal = document.getElementById('mealPlanModal');
            const editMealPlanModal = document.getElementById('editMealPlanModal');

            if (event.target === mealPlanModal) {
                closeMealPlanModal();
            }
            if (event.target === editMealPlanModal) {
                closeEditMealPlanModal();
            }
        });
    </script>
</body>

</html>