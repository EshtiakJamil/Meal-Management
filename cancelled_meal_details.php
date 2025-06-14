<?php
session_start();
require_once 'config.php';

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['manager', 'higher_authority', 'monitor'])) {
    header('Location: index.php');
    exit();
}

// Handle delete operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_meal_id'])) {
        // Delete individual meal
        $meal_id = (int)$_POST['delete_meal_id'];
        $stmt = $pdo->prepare("DELETE FROM cancelled_meals WHERE id = ?");
        if ($stmt->execute([$meal_id])) {
            $_SESSION['success_message'] = "Cancelled meal record deleted successfully.";
        } else {
            $_SESSION['error_message'] = "Failed to delete cancelled meal record.";
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit();
    }

    if (isset($_POST['delete_all'])) {
        // Delete all filtered meals
        $where_conditions = [];
        $params = [];

        // Apply same filters as the display query
        if ($_POST['batch_filter']) {
            $where_conditions[] = "s.batch = ?";
            $params[] = $_POST['batch_filter'];
        }
        if ($_POST['gender_filter']) {
            $where_conditions[] = "s.gender = ?";
            $params[] = $_POST['gender_filter'];
        }
        if ($_POST['week_filter']) {
            $where_conditions[] = "cm.week_type = ?";
            $params[] = $_POST['week_filter'];
        }
        if ($_POST['plan_filter']) {
            $where_conditions[] = "cm.plan_name = ?";
            $params[] = $_POST['plan_filter'];
        }
        if ($_POST['date_from']) {
            $where_conditions[] = "cm.cancelled_at >= ?";
            $params[] = $_POST['date_from'] . ' 00:00:00';
        }
        if ($_POST['date_to']) {
            $where_conditions[] = "cm.cancelled_at <= ?";
            $params[] = $_POST['date_to'] . ' 23:59:59';
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        $stmt = $pdo->prepare("DELETE cm FROM cancelled_meals cm JOIN students s ON cm.student_id = s.id $where_clause");
        if ($stmt->execute($params)) {
            $deleted_count = $stmt->rowCount();
            $_SESSION['success_message'] = "Successfully deleted $deleted_count cancelled meal records.";
        } else {
            $_SESSION['error_message'] = "Failed to delete cancelled meal records.";
        }
        header('Location: cancelled_meal_details.php');
        exit();
    }
}

// Get current week dates
$current_week_start = getCurrentWeekStart();

// Handle filters
$batch_filter = $_GET['batch'] ?? '';
$gender_filter = $_GET['gender'] ?? '';
$week_filter = $_GET['week'] ?? '';
$plan_filter = $_GET['plan'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build the query with filters
$where_conditions = [];
$params = [];

if ($batch_filter) {
    $where_conditions[] = "s.batch = ?";
    $params[] = $batch_filter;
}

if ($gender_filter) {
    $where_conditions[] = "s.gender = ?";
    $params[] = $gender_filter;
}

if ($week_filter) {
    $where_conditions[] = "cm.week_type = ?";
    $params[] = $week_filter;
}

if ($plan_filter) {
    $where_conditions[] = "cm.plan_name = ?";
    $params[] = $plan_filter;
}

if ($date_from) {
    $where_conditions[] = "cm.cancelled_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}

if ($date_to) {
    $where_conditions[] = "cm.cancelled_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get cancelled meals data
$stmt = $pdo->prepare("
    SELECT 
        cm.id,
        cm.plan_name,
        cm.week_type,
        cm.meal_type,
        cm.price,
        cm.payment_status,
        cm.cancelled_at,
        cm.cancellation_reason,
        cm.week_start_date,
        s.name,
        s.roll,
        s.batch,
        s.gender
    FROM cancelled_meals cm
    JOIN students s ON cm.student_id = s.id
    $where_clause
    ORDER BY cm.cancelled_at DESC, s.name
");
$stmt->execute($params);
$cancelled_meals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter options
$stmt = $pdo->prepare("SELECT DISTINCT batch FROM students ORDER BY batch");
$stmt->execute();
$batches = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare("SELECT DISTINCT plan_name FROM cancelled_meals ORDER BY plan_name");
$stmt->execute();
$meal_plans = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Calculate statistics
// Calculate statistics
$total_cancelled = count($cancelled_meals);
$total_amount = array_sum(array_map(function($meal) {
    return isset($meal['price']) ? (float)$meal['price'] : 0;
}, $cancelled_meals));
$current_week_cancellations = count(array_filter($cancelled_meals, function ($meal) {
    return $meal['week_type'] === 'current';
}));
$next_week_cancellations = count(array_filter($cancelled_meals, function ($meal) {
    return $meal['week_type'] === 'next';
}));

// Group by plan
$plan_stats = [];
foreach ($cancelled_meals as $meal) {
    if (!isset($plan_stats[$meal['plan_name']])) {
        $plan_stats[$meal['plan_name']] = ['count' => 0, 'amount' => 0];
    }
    $plan_stats[$meal['plan_name']]['count']++;
    $plan_stats[$meal['plan_name']]['amount'] += isset($meal['price']) ? (float)$meal['price'] : 0;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancelled Meal Details - Hostel Meal Management</title>
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

        .table-row {
            transition: all 0.2s ease;
        }

        .table-row:hover {
            background-color: #fef2f2;
            transform: translateX(4px);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>

<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">
    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="fixed top-4 right-4 bg-green-500 text-white px-6 py-4 rounded-lg shadow-lg z-50 animate-fade-in">
            <div class="flex items-center">
                <i class="fas fa-check-circle mr-2"></i>
                <?php echo $_SESSION['success_message'];
                unset($_SESSION['success_message']); ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="fixed top-4 right-4 bg-red-500 text-white px-6 py-4 rounded-lg shadow-lg z-50 animate-fade-in">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?php echo $_SESSION['error_message'];
                unset($_SESSION['error_message']); ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <header class="bg-custom-dark text-white shadow-2xl">
        <div class="container mx-auto px-6 py-4">
            <div class="flex justify-between items-center">
                <div class="flex items-center space-x-4">
                    <a href="manager_dashboard.php" class="w-12 h-12 bg-red-500 rounded-full flex items-center justify-center hover:bg-red-600 transition-colors">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <div>
                        <h1 class="text-2xl font-bold">Cancelled Meal Details</h1>
                        <p class="text-gray-300 text-sm">Track all cancelled meal confirmations</p>
                    </div>
                </div>
                <div class="flex items-center space-x-3">
                    <div class="text-right">
                        <div class="text-2xl font-bold text-red-400"><?php echo $total_cancelled; ?></div>
                        <p class="text-sm text-gray-300">Total Cancelled</p>
                    </div>
                    <div class="w-12 h-12 bg-red-500 rounded-full flex items-center justify-center">
                        <i class="fas fa-times-circle text-xl"></i>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-6 py-8">
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6 animate-fade-in">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Total Cancelled</h3>
                        <p class="text-2xl font-bold text-red-600"><?php echo $total_cancelled; ?></p>
                    </div>
                    <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-times-circle text-red-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 animate-fade-in">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Total Amount</h3>
                        <p class="text-2xl font-bold text-orange-600">৳<?php echo number_format($total_amount, 2); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-dollar-sign text-orange-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 animate-fade-in">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Current Week</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $current_week_cancellations; ?></p>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-calendar-week text-blue-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 animate-fade-in">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Next Week</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo $next_week_cancellations; ?></p>
                    </div>
                    <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-calendar-plus text-purple-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Plan Statistics -->
        <?php if (!empty($plan_stats)): ?>
            <div class="bg-white rounded-xl shadow-lg p-6 mb-8 animate-fade-in">
                <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-chart-pie text-red-500 mr-3"></i>
                    Cancellations by Meal Plan
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <?php foreach ($plan_stats as $plan => $stats): ?>
                        <div class="p-4 bg-red-50 rounded-lg border border-red-200">
                            <h4 class="font-semibold text-gray-800"><?php echo htmlspecialchars($plan); ?></h4>
                            <p class="text-red-600 font-bold text-lg"><?php echo $stats['count']; ?> cancellations</p>
                            <p class="text-sm text-gray-600">৳<?php echo number_format($stats['amount'], 2); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8 animate-fade-in">
            <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-filter text-custom-accent mr-3"></i>
                Filter Results
            </h3>

            <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Batch</label>
                    <select name="batch" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-custom-accent focus:border-custom-accent">
                        <option value="">All Batches</option>
                        <?php foreach ($batches as $batch): ?>
                            <option value="<?php echo htmlspecialchars($batch); ?>" <?php echo $batch_filter === $batch ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($batch); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Gender</label>
                    <select name="gender" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-custom-accent focus:border-custom-accent">
                        <option value="">All Genders</option>
                        <option value="male" <?php echo $gender_filter === 'male' ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo $gender_filter === 'female' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Week Type</label>
                    <select name="week" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-custom-accent focus:border-custom-accent">
                        <option value="">All Weeks</option>
                        <option value="current" <?php echo $week_filter === 'current' ? 'selected' : ''; ?>>Current Week</option>
                        <option value="next" <?php echo $week_filter === 'next' ? 'selected' : ''; ?>>Next Week</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Meal Plan</label>
                    <select name="plan" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-custom-accent focus:border-custom-accent">
                        <option value="">All Plans</option>
                        <?php foreach ($meal_plans as $plan): ?>
                            <option value="<?php echo htmlspecialchars($plan); ?>" <?php echo $plan_filter === $plan ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($plan); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                        class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-custom-accent focus:border-custom-accent">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                        class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-custom-accent focus:border-custom-accent">
                </div>

                <div class="flex items-end space-x-2">
                    <button type="submit" class="flex-1 bg-custom-accent text-white px-4 py-3 rounded-lg hover:bg-blue-600 transition-colors font-medium">
                        <i class="fas fa-search mr-2"></i>Apply Filters
                    </button>
                    <a href="cancelled_meal_details.php" class="bg-gray-500 text-white px-4 py-3 rounded-lg hover:bg-gray-600 transition-colors">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Cancelled Meals Table -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden animate-fade-in">
            <div class="px-6 py-4 bg-red-500 text-white flex justify-between items-center">
                <h3 class="text-xl font-bold flex items-center">
                    <i class="fas fa-list mr-3"></i>
                    Cancelled Meals List
                    <span class="ml-4 text-lg">Total: <?php echo count($cancelled_meals); ?></span>
                </h3>

                <?php if (!empty($cancelled_meals)): ?>
                    <button onclick="showDeleteAllModal()" class="bg-red-700 hover:bg-red-800 text-white px-4 py-2 rounded-lg transition-colors font-medium">
                        <i class="fas fa-trash-alt mr-2"></i>Delete All Filtered
                    </button>
                <?php endif; ?>
            </div>

            <?php if (!empty($cancelled_meals)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Meal Plan</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Week</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cancelled At</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reason</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($cancelled_meals as $meal): ?>
                                <tr class="table-row">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-red-500 rounded-full flex items-center justify-center text-white font-bold text-sm">
                                                <?php echo strtoupper(substr($meal['name'], 0, 1)); ?>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($meal['name']); ?></div>
                                                <div class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($meal['roll']); ?> •
                                                    <?php echo htmlspecialchars($meal['batch']); ?> •
                                                    <?php echo ucfirst($meal['gender']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($meal['plan_name']); ?></div>
                                        <div class="text-sm text-gray-500">Week: <?php echo date('M d', strtotime($meal['week_start_date'])); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $meal['week_type'] === 'current' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800'; ?>">
                                            <?php echo ucfirst($meal['week_type']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($meal['meal_type'] ?? 'Fish'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $meal['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo ucfirst($meal['payment_status'] ?? 'unpaid'); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo date('M d, Y', strtotime($meal['cancelled_at'])); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo date('h:i A', strtotime($meal['cancelled_at'])); ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-900 max-w-xs truncate" title="<?php echo htmlspecialchars($meal['cancellation_reason']); ?>">
                                            <?php echo htmlspecialchars($meal['cancellation_reason']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <button onclick="deleteMeal(<?php echo $meal['id']; ?>, '<?php echo htmlspecialchars($meal['name']); ?>')"
                                            class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm transition-colors">
                                            <i class="fas fa-trash mr-1"></i>Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-check-circle text-6xl text-gray-300 mb-4"></i>
                    <h4 class="text-xl font-semibold text-gray-500 mb-2">No Cancelled Meals Found</h4>
                    <p class="text-gray-400">No cancelled meals match your current filters</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Export Options -->
        <?php if (!empty($cancelled_meals)): ?>
            <div class="mt-8 flex justify-center space-x-4">
                <button onclick="exportToCSV()" class="bg-green-500 text-white px-6 py-3 rounded-lg hover:bg-green-600 transition-colors font-medium">
                    <i class="fas fa-file-csv mr-2"></i>Export to CSV
                </button>
                <button onclick="window.print()" class="bg-blue-500 text-white px-6 py-3 rounded-lg hover:bg-blue-600 transition-colors font-medium">
                    <i class="fas fa-print mr-2"></i>Print Report
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="bg-white rounded-lg p-6 max-w-md mx-4">
            <div class="text-center">
                <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                </div>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Delete Cancelled Meal</h3>
                <p class="text-gray-600 mb-6">Are you sure you want to delete this cancelled meal record for <strong id="studentName"></strong>? This action cannot be undone.</p>
                <div class="flex space-x-4">
                    <button onclick="closeDeleteModal()" class="flex-1 bg-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-400 transition-colors">
                        Cancel
                    </button>
                    <form id="deleteForm" method="POST" class="flex-1">
                        <input type="hidden" name="delete_meal_id" id="deleteMealId">
                        <button type="submit" class="w-full bg-red-500 text-white px-4 py-2 rounded-lg hover:bg-red-600 transition-colors">
                            Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete All Confirmation Modal -->
    <div id="deleteAllModal" class="modal">
        <div class="bg-white rounded-lg p-6 max-w-md mx-4">
            <div class="text-center">
                <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                </div>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Delete All Filtered Records</h3>
                <p class="text-gray-600 mb-6">Are you sure you want to delete all <strong><?php echo count($cancelled_meals); ?></strong> filtered cancelled meal records? This action cannot be undone.</p>
                <div class="flex space-x-4">
                    <button onclick="closeDeleteAllModal()" class="flex-1 bg-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-400 transition-colors">
                        Cancel
                    </button>
                    <form id="deleteAllForm" method="POST" class="flex-1">
                        <input type="hidden" name="delete_all" value="1">
                        <input type="hidden" name="batch_filter" value="<?php echo htmlspecialchars($batch_filter); ?>">
                        <input type="hidden" name="gender_filter" value="<?php echo htmlspecialchars($gender_filter); ?>">
                        <input type="hidden" name="week_filter" value="<?php echo htmlspecialchars($week_filter); ?>">
                        <input type="hidden" name="plan_filter" value="<?php echo htmlspecialchars($plan_filter); ?>">
                        <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        <button type="submit" class="w-full bg-red-500 text-white px-4 py-2 rounded-lg hover:bg-red-600 transition-colors">
                            Delete All
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function deleteMeal(mealId, studentName) {
            document.getElementById('deleteMealId').value = mealId;
            document.getElementById('studentName').textContent = studentName;
            document.getElementById('deleteModal').classList.add('show');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
        }

        function showDeleteAllModal() {
            document.getElementById('deleteAllModal').classList.add('show');
        }

        function closeDeleteAllModal() {
            document.getElementById('deleteAllModal').classList.remove('show');
        }

        // Close modals when clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });

        document.getElementById('deleteAllModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteAllModal();
            }
        });

        // Auto-hide success/error messages
        setTimeout(function() {
            const messages = document.querySelectorAll('.fixed.top-4.right-4');
            messages.forEach(function(message) {
                message.style.opacity = '0';
                setTimeout(function() {
                    message.remove();
                }, 300);
            });
        }, 5000);

        function exportToCSV() {
            const table = document.querySelector('table');
            const rows = Array.from(table.querySelectorAll('tr'));

            const csvContent = rows.map(row => {
                const cols = Array.from(row.querySelectorAll('th, td'));
                return cols.slice(0, -1).map(col => { // Exclude the Actions column
                    // Clean the text content
                    let text = col.textContent.trim();
                    // Remove extra whitespace and newlines
                    text = text.replace(/\s+/g, ' ');
                    // Escape quotes and wrap in quotes if necessary
                    if (text.includes(',') || text.includes('"') || text.includes('\n')) {
                        text = '"' + text.replace(/"/g, '""') + '"';
                    }
                    return text;
                }).join(',');
            }).join('\n');

            const blob = new Blob([csvContent], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'cancelled_meals_' + new Date().toISOString().split('T')[0] + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDeleteModal();
                closeDeleteAllModal();
            }
        });
    </script>
</body>

</html>