<?php
session_start();
require_once 'config.php';

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['manager', 'higher_authority'])) {
    header('Location: index.php');
    exit();
}

// Handle payment status update
if (isset($_POST['action']) && $_POST['action'] === 'update_payment' && isset($_POST['guest_meal_id'])) {
    try {
        $guest_meal_id = $_POST['guest_meal_id'];
        $new_status = $_POST['payment_status'];
        
        if ($new_status === 'paid') {
            $stmt = $pdo->prepare("UPDATE guest_meals SET payment_status = 'paid', paid_at = NOW() WHERE id = ?");
        } else {
            $stmt = $pdo->prepare("UPDATE guest_meals SET payment_status = 'unpaid', paid_at = NULL WHERE id = ?");
        }
        
        $stmt->execute([$guest_meal_id]);
        $success = "Payment status updated successfully!";
    } catch (Exception $e) {
        $error = "Error updating payment status: " . $e->getMessage();
    }
}

// Handle guest meal deletion
if (isset($_POST['action']) && $_POST['action'] === 'delete_guest_meal' && isset($_POST['guest_meal_id'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM guest_meals WHERE id = ?");
        $stmt->execute([$_POST['guest_meal_id']]);
        $success = "Guest meal deleted successfully!";
    } catch (Exception $e) {
        $error = "Error deleting guest meal: " . $e->getMessage();
    }
}

// Get filter parameters
$date_filter = $_GET['date'] ?? '';
$status_filter = $_GET['status'] ?? '';
$student_filter = $_GET['student'] ?? '';

// Build the query with filters
$where_conditions = [];
$params = [];

if ($date_filter) {
    $where_conditions[] = "gm.meal_date = ?";
    $params[] = $date_filter;
}

if ($status_filter) {
    $where_conditions[] = "gm.payment_status = ?";
    $params[] = $status_filter;
}

if ($student_filter) {
    $where_conditions[] = "(s.name LIKE ? OR s.roll LIKE ?)";
    $params[] = "%$student_filter%";
    $params[] = "%$student_filter%";
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Get guest meals with filters
$stmt = $pdo->prepare("
    SELECT 
        gm.id,
        gm.payment_status,
        gm.meal_date,
        gm.number_of_meals,
        gm.price_per_meal,
        gm.total_amount,
        gm.phone_number,
        gm.transaction_id,
        gm.created_at,
        gm.paid_at,
        s.name,
        s.roll,
        s.batch,
        s.gender
    FROM guest_meals gm
    JOIN students s ON gm.student_id = s.id
    $where_clause
    ORDER BY gm.meal_date DESC, gm.created_at DESC
");
$stmt->execute($params);
$guest_meals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_orders,
        SUM(number_of_meals) as total_meals,
        SUM(total_amount) as total_amount,
        SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as paid_amount,
        SUM(CASE WHEN payment_status = 'unpaid' THEN total_amount ELSE 0 END) as unpaid_amount,
        COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as paid_orders,
        COUNT(CASE WHEN payment_status = 'unpaid' THEN 1 END) as unpaid_orders
    FROM guest_meals gm
    JOIN students s ON gm.student_id = s.id
    $where_clause
");
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get unique dates for filter dropdown
$dates_stmt = $pdo->prepare("SELECT DISTINCT meal_date FROM guest_meals ORDER BY meal_date DESC");
$dates_stmt->execute();
$available_dates = $dates_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Meal Details - Manager Dashboard</title>
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

        .guest-meal-bg {
            background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
        }
    </style>
</head>

<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">
    <!-- Header -->
    <header class="bg-custom-dark text-white shadow-2xl">
        <div class="container mx-auto px-6 py-4">
            <div class="flex justify-between items-center">
                <div class="flex items-center space-x-4">
                    <a href="manager_dashboard.php" class="w-12 h-12 bg-custom-accent rounded-full flex items-center justify-center hover:bg-blue-600 transition-colors">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <div>
                        <h1 class="text-2xl font-bold">Guest Meal Details</h1>
                        <p class="text-gray-300 text-sm">Manage guest meal orders and payments</p>
                    </div>
                </div>
                <div class="flex items-center space-x-3 bg-custom-gray px-4 py-3 rounded-lg">
                    <div class="w-8 h-8 bg-pink-500 rounded-full flex items-center justify-center">
                        <i class="fas fa-user-friends text-sm"></i>
                    </div>
                    <span class="font-medium"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-6 py-8">
        <?php if (isset($success)): ?>
            <div class="bg-green-50 border-l-4 border-green-400 text-green-700 px-6 py-4 rounded-lg mb-6 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-3"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="bg-red-50 border-l-4 border-red-400 text-red-700 px-6 py-4 rounded-lg mb-6 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle mr-3"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statistics Overview -->
        <div class="guest-meal-bg rounded-2xl shadow-lg p-8 mb-8 text-white animate-fade-in">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="text-center">
                    <div class="text-3xl font-bold mb-2"><?php echo $stats['total_orders']; ?></div>
                    <div class="text-lg opacity-90">Total Orders</div>
                </div>
                <div class="text-center">
                    <div class="text-3xl font-bold mb-2"><?php echo $stats['total_meals']; ?></div>
                    <div class="text-lg opacity-90">Total Meals</div>
                </div>
                <div class="text-center">
                    <div class="text-3xl font-bold mb-2">৳<?php echo number_format($stats['total_amount'], 2); ?></div>
                    <div class="text-lg opacity-90">Total Amount</div>
                </div>
                <div class="text-center">
                    <div class="text-3xl font-bold mb-2">৳<?php echo number_format($stats['paid_amount'], 2); ?></div>
                    <div class="text-lg opacity-90">Paid Amount</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-2xl shadow-lg p-6 mb-8 animate-fade-in">
            <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-filter mr-3 text-pink-500"></i>
                Filter Guest Meals
            </h3>
            
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Date</label>
                    <select name="date" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500">
                        <option value="">All Dates</option>
                        <?php foreach ($available_dates as $date): ?>
                            <option value="<?php echo $date; ?>" <?php echo $date_filter === $date ? 'selected' : ''; ?>>
                                <?php echo date('M d, Y', strtotime($date)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Payment Status</label>
                    <select name="status" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500">
                        <option value="">All Status</option>
                        <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="unpaid" <?php echo $status_filter === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Student</label>
                    <input type="text" name="student" placeholder="Name or Roll" value="<?php echo htmlspecialchars($student_filter); ?>"
                        class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500">
                </div>
                
                <div class="flex items-end space-x-2">
                    <button type="submit" class="bg-pink-500 text-white px-6 py-3 rounded-lg hover:bg-pink-600 transition-colors flex-1">
                        <i class="fas fa-search mr-2"></i>Filter
                    </button>
                    <a href="guest_meal_details.php" class="bg-gray-500 text-white px-4 py-3 rounded-lg hover:bg-gray-600 transition-colors">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Guest Meals Table -->
        <div class="bg-white rounded-2xl shadow-lg overflow-hidden animate-fade-in">
            <div class="p-6 border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <h3 class="text-xl font-bold text-gray-800 flex items-center">
                        <i class="fas fa-list mr-3 text-pink-500"></i>
                        Guest Meal Orders
                        <span class="ml-2 text-sm bg-pink-100 text-pink-800 px-3 py-1 rounded-full">
                            <?php echo count($guest_meals); ?> orders
                        </span>
                    </h3>
                    <div class="flex space-x-2">
                        <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm font-semibold">
                            Paid: <?php echo $stats['paid_orders']; ?>
                        </span>
                        <span class="px-3 py-1 bg-red-100 text-red-800 rounded-full text-sm font-semibold">
                            Unpaid: <?php echo $stats['unpaid_orders']; ?>
                        </span>
                    </div>
                </div>
            </div>

            <?php if (!empty($guest_meals)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Student</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Date</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Meals</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Amount</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Payment</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Contact</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($guest_meals as $meal): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-10 h-10 bg-pink-500 rounded-full flex items-center justify-center text-white font-bold">
                                                <?php echo strtoupper(substr($meal['name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div class="font-semibold text-gray-800"><?php echo htmlspecialchars($meal['name']); ?></div>
                                                <div class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($meal['roll']); ?> • <?php echo htmlspecialchars($meal['batch']); ?> • <?php echo ucfirst($meal['gender']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-gray-800"><?php echo date('M d, Y', strtotime($meal['meal_date'])); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo date('l', strtotime($meal['meal_date'])); ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-gray-800"><?php echo $meal['number_of_meals']; ?> meal(s)</div>
                                        <div class="text-sm text-gray-500">৳<?php echo number_format($meal['price_per_meal'], 2); ?> per meal</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-bold text-lg text-gray-800">৳<?php echo number_format($meal['total_amount'], 2); ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex flex-col space-y-2">
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $meal['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo ucfirst($meal['payment_status']); ?>
                                            </span>
                                            <?php if ($meal['payment_status'] === 'paid' && $meal['paid_at']): ?>
                                                <div class="text-xs text-gray-500">
                                                    Paid: <?php echo date('M d, H:i', strtotime($meal['paid_at'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if ($meal['phone_number']): ?>
                                            <div class="text-sm text-gray-800">
                                                <i class="fas fa-phone mr-1"></i>
                                                <?php echo htmlspecialchars($meal['phone_number']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($meal['transaction_id']): ?>
                                            <div class="text-xs text-gray-500 mt-1">
                                                TxID: <?php echo htmlspecialchars($meal['transaction_id']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex space-x-2">
                                            <!-- Payment Status Toggle -->
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="update_payment">
                                                <input type="hidden" name="guest_meal_id" value="<?php echo $meal['id']; ?>">
                                                <input type="hidden" name="payment_status" value="<?php echo $meal['payment_status'] === 'paid' ? 'unpaid' : 'paid'; ?>">
                                                <button type="submit" 
                                                    class="px-3 py-2 rounded-lg text-xs font-semibold transition-colors <?php echo $meal['payment_status'] === 'paid' ? 'bg-red-100 text-red-700 hover:bg-red-200' : 'bg-green-100 text-green-700 hover:bg-green-200'; ?>"
                                                    onclick="return confirm('Are you sure you want to <?php echo $meal['payment_status'] === 'paid' ? 'mark as unpaid' : 'mark as paid'; ?>?')">
                                                    <i class="fas fa-<?php echo $meal['payment_status'] === 'paid' ? 'times' : 'check'; ?> mr-1"></i>
                                                    <?php echo $meal['payment_status'] === 'paid' ? 'Mark Unpaid' : 'Mark Paid'; ?>
                                                </button>
                                            </form>
                                            
                                            <!-- Delete Button -->
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="delete_guest_meal">
                                                <input type="hidden" name="guest_meal_id" value="<?php echo $meal['id']; ?>">
                                                <button type="submit" 
                                                    class="px-3 py-2 bg-red-100 text-red-700 hover:bg-red-200 rounded-lg text-xs font-semibold transition-colors"
                                                    onclick="return confirm('Are you sure you want to delete this guest meal order? This action cannot be undone.')">
                                                    <i class="fas fa-trash mr-1"></i>
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-utensils text-6xl text-gray-300 mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">No Guest Meals Found</h3>
                    <p class="text-gray-500">
                        <?php if ($date_filter || $status_filter || $student_filter): ?>
                            Try adjusting your filters to see more results.
                        <?php else: ?>
                            No guest meal orders have been placed yet.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-refresh page every 30 seconds to show real-time updates
        setTimeout(function() {
            location.reload();
        }, 30000);
    </script>
</body>

</html>