<?php
session_start();
require_once 'config.php';

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['manager', 'higher_authority'])) {
    header('Location: index.php');
    exit();
}

// Handle new manager assignment
if (isset($_POST['action']) && $_POST['action'] === 'assign_manager' && isset($_POST['new_manager_id'])) {
    try {
        $pdo->beginTransaction();

        // Remove current manager role
        $stmt = $pdo->prepare("UPDATE students SET role = 'student' WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);

        // Assign new manager
        $stmt = $pdo->prepare("UPDATE students SET role = 'manager' WHERE id = ?");
        $stmt->execute([$_POST['new_manager_id']]);

        $pdo->commit();

        // Logout current manager
        session_destroy();
        header('Location: index.php?message=Manager role transferred successfully');
        exit();
    } catch (Exception $e) {
        $pdo->rollback();
        $error = "Error transferring manager role: " . $e->getMessage();
    }
}

// Get current week dates

$current_week_start = getCurrentWeekStart();

// Get meal confirmations data
$meal_stats = [];
$meal_plans = ['First Half', 'Second Half', 'Full Week', 'Friday Feast'];

foreach ($meal_plans as $plan) {
    $stmt = $pdo->prepare("
        SELECT 
            mc.payment_status,
            s.name,
            s.roll,
            s.gender,
            mc.phone_number,
            mc.transaction_id,
            mc.confirmed_at
        FROM meal_confirmations mc
        JOIN students s ON mc.student_id = s.id
        JOIN meal_plans mp ON mc.meal_plan_id = mp.id
        WHERE mp.name = ? AND mc.week_start_date = ?
        ORDER BY s.name
    ");
    $stmt->execute([$plan, $current_week_start]);
    $meal_stats[$plan] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get guest meals data for current week
// Remove the date filter entirely to get all guest meals
$stmt = $pdo->prepare("
    SELECT 
        gm.payment_status,
        s.name,
        s.roll,
        s.gender,
        gm.phone_number,
        gm.transaction_id,
        gm.meal_date,
        gm.number_of_meals,
        gm.total_amount,
        gm.created_at
    FROM guest_meals gm
    JOIN students s ON gm.student_id = s.id
    ORDER BY gm.meal_date DESC, s.name
");
$stmt->execute();
$guest_meals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get cancelled meals data for current week
$stmt = $pdo->prepare("
    SELECT 
        cm.plan_name,
        cm.week_type,
        cm.meal_type,
        cm.price,
        cm.cancelled_at,
        cm.cancellation_reason,
        s.name,
        s.roll,
        s.gender
    FROM cancelled_meals cm
    JOIN students s ON cm.student_id = s.id
    WHERE cm.week_start_date = ?
    ORDER BY cm.cancelled_at DESC, s.name
");
$stmt->execute([$current_week_start]);
$cancelled_meals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Update total confirmations calculation
$total_confirmations = array_sum(array_map('count', $meal_stats)) + count($guest_meals) - count($cancelled_meals);

// Get students for manager assignment
$stmt = $pdo->prepare("SELECT id, name, roll, batch, gender FROM students WHERE role = 'student' ORDER BY batch, name");
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get batches for filtering
$stmt = $pdo->prepare("SELECT DISTINCT batch FROM students WHERE role = 'student' ORDER BY batch");
$stmt->execute();
$batches = $stmt->fetchAll(PDO::FETCH_COLUMN);

//Get the monitors information
$stmt = $pdo->prepare("SELECT id, name, roll, batch, gender FROM students WHERE role = 'monitor' ORDER BY batch, name");
$stmt->execute();
$monitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total confirmations including guest meals
$total_confirmations = array_sum(array_map('count', $meal_stats)) + count($guest_meals);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard - Hostel Meal Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'custom-dark': '#1a1a1a',
                        'custom-gray': '#2d2d2d',
                        'custom-accent': '#3b82f6',
                    }
                }
            }
        }
    </script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', 'Arial', sans-serif;
        }

        .glass-effect {
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .meal-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .meal-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }

        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .stat-card {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .guest-meal-bg {
            background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
        }

        .animate-fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">
    <!-- Header -->
    <header class="bg-custom-dark text-white shadow-2xl">
        <div class="container mx-auto px-6 py-4">
            <div class="flex justify-between items-center">
                <div class="flex items-center space-x-4">
                    <div class="w-12 h-12 bg-custom-accent rounded-full flex items-center justify-center">
                        <i class="fas fa-utensils text-xl"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold">Manager Dashboard</h1>
                        <p class="text-gray-300 text-sm">Hostel Meal Management System</p>
                    </div>
                </div>
                <div class="relative">
                    <button onclick="toggleDropdown()" class="flex items-center space-x-3 bg-custom-gray px-4 py-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <div class="w-8 h-8 bg-custom-accent rounded-full flex items-center justify-center">
                            <i class="fas fa-user text-sm"></i>
                        </div>
                        <span class="font-medium"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                        <i class="fas fa-chevron-down text-sm"></i>
                    </button>
                    <div id="dropdown" class="hidden absolute right-0 mt-2 w-56 bg-white text-black rounded-lg shadow-xl border border-gray-200">
                        <a href="dashboard.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-50 rounded-t-lg">
                            <i class="fas fa-tachometer-alt text-custom-accent"></i>
                            <span>Student Dashboard</span>
                        </a>
                        <div class="border-t border-gray-200"></div>
                        <a href="logout.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-50 rounded-b-lg text-red-600">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-6 py-8">
        <?php if (isset($error)): ?>
            <div class="bg-red-50 border-l-4 border-red-400 text-red-700 px-6 py-4 rounded-lg mb-6 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle mr-3"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Current Monitors Section -->
        <div class="bg-white rounded-2xl shadow-lg p-8 mb-8 animate-fade-in">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center">
                    <i class="fas fa-eye text-3xl text-purple-500 mr-4"></i>
                    <div>
                        <h3 class="text-2xl font-bold text-gray-800">Current Monitors</h3>
                        <p class="text-gray-600">Students with monitor privileges</p>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-3xl font-bold text-purple-600">
                        <?php echo count($monitors); ?>
                    </div>
                    <p class="text-sm text-gray-500">Total Monitors</p>
                </div>
            </div>

            <?php if (!empty($monitors)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($monitors as $monitor): ?>
                        <div class="p-4 bg-gradient-to-r from-purple-50 to-indigo-50 rounded-xl border border-purple-100">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 bg-purple-500 rounded-full flex items-center justify-center text-white font-bold text-lg">
                                    <?php echo strtoupper(substr($monitor['name'], 0, 1)); ?>
                                </div>
                                <div class="flex-1">
                                    <div class="font-semibold text-gray-800"><?php echo htmlspecialchars($monitor['name']); ?></div>
                                    <div class="text-sm text-gray-600">
                                        <?php echo htmlspecialchars($monitor['roll']); ?>
                                    </div>
                                    <div class="flex items-center space-x-2 mt-1">
                                        <span class="px-2 py-1 bg-purple-100 text-purple-700 text-xs rounded-full font-medium">
                                            <?php echo htmlspecialchars($monitor['batch']); ?>
                                        </span>
                                        <span class="px-2 py-1 bg-gray-100 text-gray-600 text-xs rounded-full">
                                            <?php echo ucfirst($monitor['gender']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-eye-slash text-6xl text-gray-300 mb-4"></i>
                    <h4 class="text-xl font-semibold text-gray-500 mb-2">No Monitors Assigned</h4>
                    <p class="text-gray-400">No students currently have monitor privileges</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Current Week Info -->
        <div class="gradient-bg rounded-2xl shadow-lg p-8 mb-8 text-white animate-fade-in">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-3xl font-bold mb-2">Current Week Overview</h2>
                    <p class="text-xl opacity-90">
                        <i class="fas fa-calendar-week mr-2"></i>
                        <?php echo date('M d', strtotime($current_week_start)); ?> - <?php echo date('M d, Y', strtotime($current_week_start . ' +6 days')); ?>
                    </p>
                </div>
                <div class="text-right">
                    <div class="text-4xl font-bold">
                        <?php echo $total_confirmations; ?>
                    </div>
                    <p class="text-lg opacity-90">Total Confirmations</p>
                </div>
            </div>
        </div>

        <!-- Meal Statistics -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <?php foreach ($meal_stats as $plan_name => $confirmations): ?>
                <div class="meal-card bg-white rounded-2xl shadow-lg p-6 animate-fade-in"
                    onclick="viewMealPlan('<?php echo htmlspecialchars($plan_name); ?>')">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-2xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-utensils mr-3 text-custom-accent"></i>
                            <?php echo $plan_name; ?>
                        </h3>
                        <i class="fas fa-arrow-right text-custom-accent text-xl"></i>
                    </div>

                    <div class="grid grid-cols-3 gap-4 mb-6">
                        <div class="text-center p-4 bg-blue-50 rounded-xl">
                            <div class="text-2xl font-bold text-blue-600"><?php echo count($confirmations); ?></div>
                            <div class="text-sm text-gray-600">Total</div>
                        </div>
                        <div class="text-center p-4 bg-green-50 rounded-xl">
                            <div class="text-2xl font-bold text-green-600">
                                <?php echo count(array_filter($confirmations, function ($c) {
                                    return $c['payment_status'] === 'paid';
                                })); ?>
                            </div>
                            <div class="text-sm text-gray-600">Paid</div>
                        </div>
                        <div class="text-center p-4 bg-red-50 rounded-xl">
                            <div class="text-2xl font-bold text-red-600">
                                <?php echo count(array_filter($confirmations, function ($c) {
                                    return $c['payment_status'] === 'unpaid';
                                })); ?>
                            </div>
                            <div class="text-sm text-gray-600">Unpaid</div>
                        </div>
                    </div>

                    <?php if (!empty($confirmations)): ?>
                        <div class="space-y-3 max-h-60 overflow-y-auto">
                            <?php foreach (array_slice($confirmations, 0, 3) as $confirmation): ?>
                                <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-10 h-10 bg-custom-accent rounded-full flex items-center justify-center text-white font-bold">
                                            <?php echo strtoupper(substr($confirmation['name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-gray-800"><?php echo htmlspecialchars($confirmation['name']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($confirmation['roll']); ?></div>
                                        </div>
                                    </div>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $confirmation['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo ucfirst($confirmation['payment_status']); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($confirmations) > 3): ?>
                                <div class="text-center py-2">
                                    <span class="text-custom-accent font-semibold">+<?php echo count($confirmations) - 3; ?> more students</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-users text-4xl text-gray-300 mb-4"></i>
                            <p class="text-gray-500">No confirmations yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <!-- Guest Meal Section -->
            <div class="meal-card bg-white rounded-2xl shadow-lg p-6 animate-fade-in"
                onclick="viewGuestMeals()">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-2xl font-bold text-gray-800 flex items-center">
                        <i class="fas fa-user-friends mr-3 text-pink-500"></i>
                        Guest Meal
                    </h3>
                    <i class="fas fa-arrow-right text-pink-500 text-xl"></i>
                </div>

                <div class="grid grid-cols-3 gap-4 mb-6">
                    <div class="text-center p-4 bg-pink-50 rounded-xl">
                        <div class="text-2xl font-bold text-pink-600"><?php echo count($guest_meals); ?></div>
                        <div class="text-sm text-gray-600">Total</div>
                    </div>
                    <div class="text-center p-4 bg-green-50 rounded-xl">
                        <div class="text-2xl font-bold text-green-600">
                            <?php echo count(array_filter($guest_meals, function ($g) {
                                return $g['payment_status'] === 'paid';
                            })); ?>
                        </div>
                        <div class="text-sm text-gray-600">Paid</div>
                    </div>
                    <div class="text-center p-4 bg-red-50 rounded-xl">
                        <div class="text-2xl font-bold text-red-600">
                            <?php echo count(array_filter($guest_meals, function ($g) {
                                return $g['payment_status'] === 'unpaid';
                            })); ?>
                        </div>
                        <div class="text-sm text-gray-600">Unpaid</div>
                    </div>
                </div>

                <?php if (!empty($guest_meals)): ?>
                    <div class="space-y-3 max-h-60 overflow-y-auto">
                        <?php foreach (array_slice($guest_meals, 0, 3) as $guest_meal): ?>
                            <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-pink-500 rounded-full flex items-center justify-center text-white font-bold">
                                        <?php echo strtoupper(substr($guest_meal['name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="font-semibold text-gray-800"><?php echo htmlspecialchars($guest_meal['name']); ?></div>
                                        <div class="text-sm text-gray-500">
                                            <?php echo htmlspecialchars($guest_meal['roll']); ?> •
                                            <?php echo date('M d', strtotime($guest_meal['meal_date'])); ?> •
                                            <?php echo $guest_meal['number_of_meals']; ?> meal(s) •
                                            ৳<?php echo number_format($guest_meal['total_amount'], 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $guest_meal['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo ucfirst($guest_meal['payment_status']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($guest_meals) > 3): ?>
                            <div class="text-center py-2">
                                <span class="text-pink-500 font-semibold">+<?php echo count($guest_meals) - 3; ?> more guest meals</span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-utensils text-4xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500">No guest meals this week</p>
                    </div>
                <?php endif; ?>
            </div>


            <!-- Cancelled Meals Section -->
            <div class="meal-card bg-white rounded-2xl shadow-lg p-6 animate-fade-in"
                onclick="viewCancelledMeals()">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-2xl font-bold text-gray-800 flex items-center">
                        <i class="fas fa-times-circle mr-3 text-red-500"></i>
                        Cancelled Meals
                    </h3>
                    <i class="fas fa-arrow-right text-red-500 text-xl"></i>
                </div>

                <div class="grid grid-cols-3 gap-4 mb-6">
                    <div class="text-center p-4 bg-red-50 rounded-xl">
                        <div class="text-2xl font-bold text-red-600"><?php echo count($cancelled_meals); ?></div>
                        <div class="text-sm text-gray-600">Total</div>
                    </div>
                    <div class="text-center p-4 bg-orange-50 rounded-xl">
                        <div class="text-2xl font-bold text-orange-600">
                            ৳<?php echo number_format(array_sum(array_column($cancelled_meals, 'price')), 2); ?>
                        </div>
                        <div class="text-sm text-gray-600">Amount</div>
                    </div>
                    <div class="text-center p-4 bg-gray-50 rounded-xl">
                        <div class="text-2xl font-bold text-gray-600">
                            <?php echo count(array_filter($cancelled_meals, function ($c) {
                                return $c['week_type'] === 'current';
                            })); ?>
                        </div>
                        <div class="text-sm text-gray-600">Current</div>
                    </div>
                </div>

                <?php if (!empty($cancelled_meals)): ?>
                    <div class="space-y-3 max-h-60 overflow-y-auto">
                        <?php foreach (array_slice($cancelled_meals, 0, 3) as $cancelled_meal): ?>
                            <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-red-500 rounded-full flex items-center justify-center text-white font-bold">
                                        <?php echo strtoupper(substr($cancelled_meal['name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="font-semibold text-gray-800"><?php echo htmlspecialchars($cancelled_meal['name']); ?></div>
                                        <div class="text-sm text-gray-500">
                                            <?php echo htmlspecialchars($cancelled_meal['roll']); ?> •
                                            <?php echo htmlspecialchars($cancelled_meal['plan_name']); ?> •
                                            <?php echo ucfirst($cancelled_meal['week_type']); ?> •
                                            ৳<?php echo number_format($cancelled_meal['price'], 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                                    Cancelled
                                </span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($cancelled_meals) > 3): ?>
                            <div class="text-center py-2">
                                <span class="text-red-500 font-semibold">+<?php echo count($cancelled_meals) - 3; ?> more cancelled meals</span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-check-circle text-4xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500">No cancelled meals this week</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        <!-- Assign New Manager -->
        <div class="bg-white rounded-2xl shadow-lg p-8 animate-fade-in">
            <div class="flex items-center mb-6">
                <i class="fas fa-user-cog text-3xl text-custom-accent mr-4"></i>
                <div>
                    <h3 class="text-2xl font-bold text-gray-800">Assign New Manager</h3>
                    <p class="text-gray-600">Transfer your manager privileges to another student</p>
                </div>
            </div>

            <div class="bg-amber-50 border-l-4 border-amber-400 p-4 mb-6 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-amber-500 mr-3"></i>
                    <p class="text-amber-700">
                        <span class="font-semibold">Important:</span> Once you assign a new manager, you will lose access to this dashboard and your manager role will be removed.
                    </p>
                </div>
            </div>

            <form method="POST" class="space-y-6">
                <input type="hidden" name="action" value="assign_manager">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-3">
                            <i class="fas fa-filter mr-2"></i>Filter by Batch
                        </label>
                        <select id="batchFilter" class="w-full p-4 border border-gray-300 rounded-xl focus:ring-2 focus:ring-custom-accent focus:border-custom-accent transition-colors">
                            <option value="">All Batches</option>
                            <?php foreach ($batches as $batch): ?>
                                <option value="<?php echo htmlspecialchars($batch); ?>"><?php echo htmlspecialchars($batch); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-3">
                            <i class="fas fa-venus-mars mr-2"></i>Filter by Gender
                        </label>
                        <select id="genderFilter" class="w-full p-4 border border-gray-300 rounded-xl focus:ring-2 focus:ring-custom-accent focus:border-custom-accent transition-colors">
                            <option value="">All Genders</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-3">
                        <i class="fas fa-user-plus mr-2"></i>Select New Manager
                    </label>
                    <select name="new_manager_id" id="studentSelect" required class="w-full p-4 border border-gray-300 rounded-xl focus:ring-2 focus:ring-custom-accent focus:border-custom-accent transition-colors">
                        <option value="">Choose a student</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?php echo $student['id']; ?>"
                                data-batch="<?php echo htmlspecialchars($student['batch']); ?>"
                                data-gender="<?php echo $student['gender']; ?>">
                                <?php echo htmlspecialchars($student['name']); ?> (<?php echo htmlspecialchars($student['roll']); ?>) - <?php echo htmlspecialchars($student['batch']); ?> - <?php echo ucfirst($student['gender']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" onclick="return confirm('Are you sure you want to transfer your manager role? This action cannot be undone.')"
                    class="w-full bg-gradient-to-r from-red-500 to-red-600 text-white px-8 py-4 rounded-xl hover:from-red-600 hover:to-red-700 font-semibold text-lg transition-all transform hover:scale-105 shadow-lg">
                    <i class="fas fa-exchange-alt mr-2"></i>
                    Transfer Manager Role
                </button>
            </form>
        </div>
    </div>

    <script>
        function toggleDropdown() {
            document.getElementById('dropdown').classList.toggle('hidden');
        }

        // Close dropdown when clicking outside
        window.onclick = function(event) {
            if (!event.target.closest('.relative')) {
                var dropdown = document.getElementById('dropdown');
                if (!dropdown.classList.contains('hidden')) {
                    dropdown.classList.add('hidden');
                }
            }
        }

        // Filter students based on batch and gender
        function filterStudents() {
            const batchFilter = document.getElementById('batchFilter').value;
            const genderFilter = document.getElementById('genderFilter').value;
            const studentSelect = document.getElementById('studentSelect');
            const options = studentSelect.querySelectorAll('option');

            options.forEach(option => {
                if (option.value === '') {
                    option.style.display = 'block';
                    return;
                }

                const batch = option.dataset.batch;
                const gender = option.dataset.gender;

                const batchMatch = !batchFilter || batch === batchFilter;
                const genderMatch = !genderFilter || gender === genderFilter;

                option.style.display = (batchMatch && genderMatch) ? 'block' : 'none';
            });

            // Reset selection if current selection is hidden
            if (studentSelect.selectedOptions[0] && studentSelect.selectedOptions[0].style.display === 'none') {
                studentSelect.value = '';
            }
        }

        function viewMealPlan(planName) {
            window.location.href = 'meal_plan_details.php?plan=' + encodeURIComponent(planName);
        }

        function viewGuestMeals() {
            window.location.href = 'guest_meal_details.php';
        }

        function viewCancelledMeals() {
            window.location.href = 'cancelled_meal_details.php';
        }

        document.getElementById('batchFilter').addEventListener('change', filterStudents);
        document.getElementById('genderFilter').addEventListener('change', filterStudents);
    </script>
</body>

</html>