<?php
require_once 'config.php';
requireLogin();

$message = '';
$messageType = '';

// Enhanced function to check if a plan can be confirmed based on specific deadlines
function canConfirmPlanWithDeadlines($planName, $weekType)
{
    if ($weekType !== 'current') {
        return true; // No restrictions for next week
    }

    // Set timezone explicitly (adjust to your timezone - using Bangladesh timezone)
    $timezone = new DateTimeZone('Asia/Dhaka');

    // Get current date and time with explicit timezone
    $now = new DateTime('now', $timezone);
    $currentWeekStart = new DateTime(getCurrentWeekStart(), $timezone); // Saturday

    // Calculate deadline dates for current week
    $fridayBeforeWeekStart = clone $currentWeekStart;
    $fridayBeforeWeekStart->modify('-1 day'); // Friday before Saturday
    $fridayBeforeWeekStart->setTime(23, 59, 59); // End of Friday

    $mondayOfCurrentWeek = clone $currentWeekStart;
    $mondayOfCurrentWeek->modify('+2 days'); // Monday of current week
    $mondayOfCurrentWeek->setTime(23, 59, 59); // End of Monday

    $thursdayOfCurrentWeek = clone $currentWeekStart;
    $thursdayOfCurrentWeek->modify('+5 days'); // Thursday of current week
    $thursdayOfCurrentWeek->setTime(23, 59, 59); // End of Thursday

    // DEBUG: Uncomment these lines temporarily to debug Friday Feast issue
    /*
    if ($planName === 'Friday Feast') {
        error_log("DEBUG Friday Feast:");
        error_log("Current time: " . $now->format('Y-m-d H:i:s T'));
        error_log("Thursday deadline: " . $thursdayOfCurrentWeek->format('Y-m-d H:i:s T'));
        error_log("Can confirm: " . ($now <= $thursdayOfCurrentWeek ? 'YES' : 'NO'));
    }
    */

    switch ($planName) {
        case 'First Half':
        case 'Full Week':
            // Must be confirmed before Saturday (i.e., by end of Friday)
            return $now <= $fridayBeforeWeekStart;

        case 'Second Half':
            // Must be confirmed before Tuesday (i.e., by end of Monday)
            return $now <= $mondayOfCurrentWeek;

        case 'Friday Feast':
            // Must be confirmed before Friday (i.e., by end of Thursday)
            return $now <= $thursdayOfCurrentWeek;

        default:
            return true;
    }
}

// Handle meal plan confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'confirm_meal') {
        $planId = (int)$_POST['plan_id'];
        $weekType = $_POST['week_type'];
        $mealType = isset($_POST['meal_type']) ? $_POST['meal_type'] : 'Fish';

        try {
            // Get plan details
            $stmt = $pdo->prepare("SELECT * FROM meal_plans WHERE id = ?");
            $stmt->execute([$planId]);
            $plan = $stmt->fetch();

            if ($plan) {
                $weekStart = ($weekType === 'current') ? getCurrentWeekStart() : getNextWeekStart();

                // Check if can confirm this plan with new deadline rules
                if (!canConfirmPlanWithDeadlines($plan['name'], $weekType)) {
                    $deadlineMessage = '';
                    switch ($plan['name']) {
                        case 'First Half':
                        case 'Full Week':
                            $deadlineMessage = "Must be confirmed before Saturday (by end of Friday)";
                            break;
                        case 'Second Half':
                            $deadlineMessage = "Must be confirmed before Tuesday (by end of Monday)";
                            break;
                        case 'Friday Feast':
                            $deadlineMessage = "Must be confirmed before Friday (by end of Thursday)";
                            break;
                    }

                    $message = "Cannot confirm {$plan['name']} - deadline has passed. {$deadlineMessage}.";
                    $messageType = 'error';
                } else {
                    // Check if Full Week is already confirmed
                    $stmt = $pdo->prepare("
                    SELECT mp.name FROM meal_confirmations mc 
                    JOIN meal_plans mp ON mc.meal_plan_id = mp.id 
                    WHERE mc.student_id = ? AND mc.week_start_date = ? AND mp.name = 'Full Week'
                ");
                    $stmt->execute([$_SESSION['user_id'], $weekStart]);
                    $fullWeekExists = $stmt->fetch();

                    // Check if Second Half is already confirmed (for Friday Feast restriction)
                    $stmt = $pdo->prepare("
                    SELECT mp.name FROM meal_confirmations mc 
                    JOIN meal_plans mp ON mc.meal_plan_id = mp.id 
                    WHERE mc.student_id = ? AND mc.week_start_date = ? AND mp.name = 'Second Half'
                ");
                    $stmt->execute([$_SESSION['user_id'], $weekStart]);
                    $secondHalfExists = $stmt->fetch();

                    // Check if confirming Full Week when other plans exist
                    if ($plan['name'] === 'Full Week') {
                        $stmt = $pdo->prepare("
                        SELECT COUNT(*) as count FROM meal_confirmations mc 
                        JOIN meal_plans mp ON mc.meal_plan_id = mp.id 
                        WHERE mc.student_id = ? AND mc.week_start_date = ? AND mp.name != 'Full Week'
                    ");
                        $stmt->execute([$_SESSION['user_id'], $weekStart]);
                        $otherPlansCount = $stmt->fetchColumn();

                        if ($otherPlansCount > 0) {
                            $message = "Cannot confirm Full Week - you have other plans for this week.";
                            $messageType = 'error';
                        }
                    } elseif ($fullWeekExists) {
                        $message = "Cannot confirm {$plan['name']} - Full Week is already confirmed.";
                        $messageType = 'error';
                    } elseif ($plan['name'] === 'Friday Feast' && $secondHalfExists) {
                        // NEW: Prevent Friday Feast if Second Half is confirmed
                        $message = "Cannot confirm Friday Feast - Second Half is already confirmed for this week.";
                        $messageType = 'error';
                    } elseif ($plan['name'] === 'Second Half') {
                        // NEW: Check if Friday Feast is already confirmed when trying to confirm Second Half
                        $stmt = $pdo->prepare("
                        SELECT mp.name FROM meal_confirmations mc 
                        JOIN meal_plans mp ON mc.meal_plan_id = mp.id 
                        WHERE mc.student_id = ? AND mc.week_start_date = ? AND mp.name = 'Friday Feast'
                    ");
                        $stmt->execute([$_SESSION['user_id'], $weekStart]);
                        $fridayFeastExists = $stmt->fetch();

                        if ($fridayFeastExists) {
                            $message = "Cannot confirm Second Half - Friday Feast is already confirmed for this week.";
                            $messageType = 'error';
                        }
                    }

                    if (empty($message)) {
                        // Insert confirmation
                        $stmt = $pdo->prepare("
                    INSERT INTO meal_confirmations (student_id, meal_plan_id, week_start_date, week_type, type) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                        $stmt->execute([$_SESSION['user_id'], $planId, $weekStart, $weekType, $mealType]);

                        $message = "{$plan['name']} confirmed successfully!";
                        $messageType = 'success';
                    }
                }
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $message = "You have already confirmed this plan for this week.";
                $messageType = 'error';
            } else {
                $message = "Error confirming meal plan. Please try again.";
                $messageType = 'error';
            }
        }
    } elseif ($_POST['action'] === 'cancel_meal') {
        $confirmationId = (int)$_POST['confirmation_id'];

        try {
            // Get confirmation details
            $stmt = $pdo->prepare("
            SELECT mc.*, mp.name as plan_name, mp.price 
            FROM meal_confirmations mc 
            JOIN meal_plans mp ON mc.meal_plan_id = mp.id 
            WHERE mc.id = ? AND mc.student_id = ?
        ");
            $stmt->execute([$confirmationId, $_SESSION['user_id']]);
            $confirmation = $stmt->fetch();

            if ($confirmation) {
                // Option 1: Allow cancellation regardless of payment status
                // Comment out the payment check entirely

                // Check if the plan is currently active (running)
                // Check if the plan is currently active (running)
                $isActive = isPlanCurrentlyActive($confirmation['plan_name'], $confirmation['week_start_date']);

                if ($isActive) {
                    // Plan is active - store cancellation details before deleting
                    $stmt = $pdo->prepare("
                    INSERT INTO cancelled_meals 
                    (student_id, meal_plan_id, plan_name, week_start_date, week_type, meal_type, payment_status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                    $stmt->execute([
                        $_SESSION['user_id'],
                        $confirmation['meal_plan_id'],
                        $confirmation['plan_name'],
                        $confirmation['week_start_date'],
                        $confirmation['week_type'],
                        $confirmation['type'],
                        $confirmation['payment_status']
                    ]);
                }

                // Delete the confirmation
                $stmt = $pdo->prepare("DELETE FROM meal_confirmations WHERE id = ? AND student_id = ?");
                $stmt->execute([$confirmationId, $_SESSION['user_id']]);

                $message = "Meal plan cancelled successfully!";
                $messageType = 'success';

                // Option 2: If you want to prevent cancellation for paid meals, use this instead:
                /*
            if ($confirmation['payment_status'] === 'paid') {
                $message = "Cannot cancel {$confirmation['plan_name']} - payment has already been made.";
                $messageType = 'error';
            } else {
                // Check if the plan is currently active (running)
                $isActive = isPlanCurrentlyActive($confirmation['plan_name'], $confirmation['week_type']);

                if ($isActive) {
                    // Plan is active - store cancellation details before deleting
                    $stmt = $pdo->prepare("
                        INSERT INTO cancelled_meals 
                        (student_id, meal_plan_id, plan_name, week_start_date, week_type, meal_type, payment_status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_SESSION['user_id'],
                        $confirmation['meal_plan_id'],
                        $confirmation['plan_name'],
                        $confirmation['week_start_date'],
                        $confirmation['week_type'],
                        $confirmation['type'],
                        $confirmation['payment_status']
                    ]);
                }

                // Delete the confirmation
                $stmt = $pdo->prepare("DELETE FROM meal_confirmations WHERE id = ? AND student_id = ?");
                $stmt->execute([$confirmationId, $_SESSION['user_id']]);

                $message = "Meal plan cancelled successfully!";
                $messageType = 'success';
            }
            */
            } else {
                $message = "Meal plan confirmation not found.";
                $messageType = 'error';
            }
        } catch (PDOException $e) {
            $message = "Error cancelling meal plan. Please try again.";
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'payment') {
        $phone = trim($_POST['phone_number']);
        $transactionId = trim($_POST['transaction_id']);
        $confirmationId = (int)$_POST['confirmation_id'];

        if (!empty($phone) && !empty($transactionId)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE meal_confirmations 
                    SET payment_status = 'paid', phone_number = ?, transaction_id = ?, paid_at = NOW() 
                    WHERE id = ? AND student_id = ?
                ");
                $stmt->execute([$phone, $transactionId, $confirmationId, $_SESSION['user_id']]);

                $message = "Payment information updated successfully!";
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = "Error updating payment information. Please try again.";
                $messageType = 'error';
            }
        } else {
            $message = "Please provide both phone number and transaction ID.";
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'add_guest_meal') {
        $mealDate = $_POST['meal_date'];
        $numberOfMeals = (int)$_POST['number_of_meals'];
        $pricePerMeal = 50.00;
        $totalAmount = $numberOfMeals * $pricePerMeal;

        try {
            $stmt = $pdo->prepare("
            INSERT INTO guest_meals (student_id, meal_date, number_of_meals, price_per_meal, total_amount) 
            VALUES (?, ?, ?, ?, ?)
        ");
            $stmt->execute([$_SESSION['user_id'], $mealDate, $numberOfMeals, $pricePerMeal, $totalAmount]);

            $message = "Guest meal added successfully for " . date('M d, Y', strtotime($mealDate)) . "!";
            $messageType = 'success';
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $message = "Guest meal already exists for this date.";
                $messageType = 'error';
            } else {
                $message = "Error adding guest meal. Please try again.";
                $messageType = 'error';
            }
        }
    } elseif ($_POST['action'] === 'cancel_guest_meal') {
        $guestMealId = (int)$_POST['guest_meal_id'];

        try {
            $stmt = $pdo->prepare("
            SELECT payment_status, meal_date FROM guest_meals 
            WHERE id = ? AND student_id = ?
        ");
            $stmt->execute([$guestMealId, $_SESSION['user_id']]);
            $guestMeal = $stmt->fetch();

            if ($guestMeal) {
                if ($guestMeal['payment_status'] === 'paid') {
                    $message = "Cannot cancel guest meal - payment has already been made.";
                    $messageType = 'error';
                } else {
                    // Check if the meal date has passed or is today
                    $timezone = new DateTimeZone('Asia/Dhaka');
                    $today = new DateTime('now', $timezone);
                    $mealDate = new DateTime($guestMeal['meal_date'], $timezone);

                    if ($mealDate <= $today) {
                        $message = "Cannot cancel guest meal - the meal date has passed or is today.";
                        $messageType = 'error';
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM guest_meals WHERE id = ? AND student_id = ?");
                        $stmt->execute([$guestMealId, $_SESSION['user_id']]);

                        $message = "Guest meal cancelled successfully!";
                        $messageType = 'success';
                    }
                }
            } else {
                $message = "Guest meal not found.";
                $messageType = 'error';
            }
        } catch (PDOException $e) {
            $message = "Error cancelling guest meal. Please try again.";
            $messageType = 'error';
        }
    } elseif ($_POST['action'] === 'guest_payment') {
        $phone = trim($_POST['phone_number']);
        $transactionId = trim($_POST['transaction_id']);
        $guestMealId = (int)$_POST['guest_meal_id'];

        if (!empty($phone) && !empty($transactionId)) {
            try {
                $stmt = $pdo->prepare("
                UPDATE guest_meals 
                SET payment_status = 'paid', phone_number = ?, transaction_id = ?, paid_at = NOW() 
                WHERE id = ? AND student_id = ?
            ");
                $stmt->execute([$phone, $transactionId, $guestMealId, $_SESSION['user_id']]);

                $message = "Guest meal payment updated successfully!";
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = "Error updating guest meal payment. Please try again.";
                $messageType = 'error';
            }
        } else {
            $message = "Please provide both phone number and transaction ID.";
            $messageType = 'error';
        }
    }
}

// Function to get deadline text for display
function getDeadlineText($planName)
{
    switch ($planName) {
        case 'First Half':
        case 'Full Week':
            return "Deadline: Friday 11:59 PM";
        case 'Second Half':
            return "Deadline: Monday 11:59 PM";
        case 'Friday Feast':
            return "Deadline: Thursday 11:59 PM";
        default:
            return "";
    }
}

function isPlanCurrentlyActive($planName, $weekStartDate)
{
    $timezone = new DateTimeZone('Asia/Dhaka');
    $now = new DateTime('now', $timezone);
    $weekStart = new DateTime($weekStartDate, $timezone);

    switch ($planName) {
        case 'First Half':
            // Active from Saturday to Tuesday
            $startDate = clone $weekStart; // Saturday
            $endDate = clone $weekStart;
            $endDate->modify('+3 days'); // Tuesday
            return $now >= $startDate && $now <= $endDate;

        case 'Second Half':
            // Active from Wednesday to Friday
            $startDate = clone $weekStart;
            $startDate->modify('+3 days'); // Wednesday
            $endDate = clone $weekStart;
            $endDate->modify('+6 days'); // Friday
            return $now >= $startDate && $now <= $endDate;

        case 'Full Week':
            // Active from Saturday to Friday
            $startDate = clone $weekStart; // Saturday
            $endDate = clone $weekStart;
            $endDate->modify('+6 days'); // Friday
            return $now >= $startDate && $now <= $endDate;

        case 'Friday Feast':
            // Active only on Friday
            $fridayDate = clone $weekStart;
            $fridayDate->modify('+6 days'); // Friday
            return $now->format('Y-m-d') === $fridayDate->format('Y-m-d');

        default:
            return false;
    }
}
// Get meal plans
$stmt = $pdo->prepare("SELECT * FROM meal_plans ORDER BY id");
$stmt->execute();
$mealPlans = $stmt->fetchAll();

// Get payment numbers
$stmt = $pdo->prepare("
    SELECT provider, number 
    FROM payment_numbers 
    ORDER BY provider, id
");
$stmt->execute();
$paymentNumbers = $stmt->fetchAll();

// Group by provider
$bkashNumbers = [];
$nagadNumbers = [];
foreach ($paymentNumbers as $payment) {
    if ($payment['provider'] === 'bkash') {
        $bkashNumbers[] = $payment['number'];
    } else {
        $nagadNumbers[] = $payment['number'];
    }
}

// Get current confirmations
$currentWeekStart = getCurrentWeekStart();
$nextWeekStart = getNextWeekStart();

$stmt = $pdo->prepare("
    SELECT mc.*, mp.name as plan_name, mp.price 
    FROM meal_confirmations mc 
    JOIN meal_plans mp ON mc.meal_plan_id = mp.id 
    WHERE mc.student_id = ? AND (mc.week_start_date = ? OR mc.week_start_date = ?)
    ORDER BY mc.week_start_date, mp.id
");
$stmt->execute([$_SESSION['user_id'], $currentWeekStart, $nextWeekStart]);
$confirmations = $stmt->fetchAll();

// Get guest meals for current week
// Get guest meals for current week - FIXED VERSION
$stmt = $pdo->prepare("
    SELECT * FROM guest_meals 
    WHERE student_id = ? AND meal_date >= ?
    ORDER BY meal_date
");
// Changed from date range to just >= current week start to include future dates
$stmt->execute([$_SESSION['user_id'], $currentWeekStart]);
$guestMeals = $stmt->fetchAll();

// Get meal managers and monitors filtered by gender for monitors
$stmt = $pdo->prepare("
    SELECT name, role FROM students 
    WHERE role = 'manager'
    ORDER BY name
");
$stmt->execute();
$managers = $stmt->fetchAll();

// Get monitors filtered by current user's gender
$stmt = $pdo->prepare("
    SELECT name, role FROM students 
    WHERE role = 'monitor' AND gender = (
        SELECT gender FROM students WHERE id = ?
    )
    ORDER BY name
");
$stmt->execute([$_SESSION['user_id']]);
$monitors = $stmt->fetchAll();

// Separate confirmations by week
$currentWeekConfirmations = array_filter($confirmations, function ($c) use ($currentWeekStart) {
    return $c['week_start_date'] === $currentWeekStart;
});

$nextWeekConfirmations = array_filter($confirmations, function ($c) use ($nextWeekStart) {
    return $c['week_start_date'] === $nextWeekStart;
});
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Hostel Meal Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen">
    <!-- Header -->
    <header class="bg-black text-white shadow-lg">
        <div class="container mx-auto px-4 py-4">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-xl font-bold">Current Week: <?php echo formatWeekRange($currentWeekStart); ?></h1>
                </div>
                <div class="relative">
                    <button id="userMenuButton" class="flex items-center space-x-2 hover:bg-gray-800 px-3 py-2 rounded focus:outline-none">
                        <span><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                        <svg class="w-4 h-4 transition-transform duration-200" id="userMenuArrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div id="userMenuDropdown" class="absolute right-0 mt-1 w-48 bg-white rounded-md shadow-lg py-1 z-10 hidden">
                        <?php if (hasAnyRole(['monitor', 'manager'])): ?>
                            <?php if (hasRole('monitor')): ?>
                                <a href="monitor_dashboard.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">Monitor Dashboard</a>
                            <?php endif; ?>
                            <?php if (hasRole('manager')): ?>
                                <a href="manager_dashboard.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">Manager Dashboard</a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <a href="logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-4 py-8">
        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-md <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700 border border-green-400' : 'bg-red-100 text-red-700 border border-red-400'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Staff Information -->
        <div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Meal Managers -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-xl font-bold text-black mb-4 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                    Meal Managers
                </h3>
                <?php if (!empty($managers)): ?>
                    <div class="space-y-2">
                        <?php foreach ($managers as $manager): ?>
                            <div class="flex items-center p-3 bg-blue-50 rounded-lg">
                                <div class="w-2 h-2 bg-blue-600 rounded-full mr-3"></div>
                                <span class="text-gray-800 font-medium"><?php echo htmlspecialchars($manager['name']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500 italic">No meal managers assigned</p>
                <?php endif; ?>
            </div>

            <!-- Week Monitors -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-xl font-bold text-black mb-4 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Week Monitors
                </h3>
                <?php if (!empty($monitors)): ?>
                    <div class="space-y-2">
                        <?php foreach ($monitors as $monitor): ?>
                            <div class="flex items-center p-3 bg-green-50 rounded-lg">
                                <div class="w-2 h-2 bg-green-600 rounded-full mr-3"></div>
                                <span class="text-gray-800 font-medium"><?php echo htmlspecialchars($monitor['name']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500 italic">No monitors assigned</p>
                <?php endif; ?>
            </div>
        </div>

        <br>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Current Week -->
            <!-- Current Week -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-2xl font-bold text-black mb-6">Current Week</h2>
                <p class="text-gray-600 mb-4"><?php echo formatWeekRange($currentWeekStart); ?></p>

                <div class="space-y-4">
                    <?php foreach ($mealPlans as $plan): ?>
                        <?php
                        $isConfirmed = false;
                        $confirmationId = null;
                        $paymentStatus = 'unpaid';
                        foreach ($currentWeekConfirmations as $conf) {
                            if ($conf['meal_plan_id'] == $plan['id']) {
                                $isConfirmed = true;
                                $confirmationId = $conf['id'];
                                $paymentStatus = $conf['payment_status'];
                                break;
                            }
                        }
                        $canConfirm = canConfirmPlanWithDeadlines($plan['name'], 'current');
                        $deadlineText = getDeadlineText($plan['name']);

                        // DEBUG: Uncomment this line temporarily to see the confirmation status for Friday Feast
                        // if ($plan['name'] === 'Friday Feast') echo "<!-- Friday Feast can confirm: " . ($canConfirm ? 'YES' : 'NO') . " -->";
                        ?>
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="flex justify-between items-center">
                                <div>
                                    <h3 class="font-semibold text-lg"><?php echo htmlspecialchars($plan['name']); ?></h3>
                                    <p class="text-gray-600"><?php echo $plan['price']; ?> BDT</p>
                                    <?php if ($plan['description']): ?>
                                        <p class="text-sm text-gray-500"><?php echo htmlspecialchars($plan['description']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($deadlineText): ?>
                                        <p class="text-xs text-blue-600 mt-1"><?php echo $deadlineText; ?></p>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if ($isConfirmed): ?>
                                        <form method="POST" class="inline" onsubmit="return confirmCancellation('<?php echo htmlspecialchars($plan['name']); ?>')">
                                            <input type="hidden" name="action" value="cancel_meal">
                                            <input type="hidden" name="confirmation_id" value="<?php echo $confirmationId; ?>">
                                            <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">
                                                Cancel
                                            </button>
                                        </form>
                                    <?php elseif ($canConfirm): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="confirm_meal">
                                            <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                            <input type="hidden" name="week_type" value="current">

                                            <?php if (in_array($plan['name'], ['First Half', 'Second Half', 'Full Week'])): ?>
                                                <div class="flex items-center space-x-2">
                                                    <select name="meal_type" required class="px-2 py-1 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-black">
                                                        <option value="Fish">Fish</option>
                                                        <option value="Egg">Egg</option>
                                                    </select>
                                                    <button type="submit" class="bg-black text-white px-4 py-2 rounded hover:bg-gray-800">
                                                        Confirm
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <button type="submit" class="bg-black text-white px-4 py-2 rounded hover:bg-gray-800">
                                                    Confirm
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-red-600 text-sm font-medium">Deadline Passed</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Add this after the closing </div> of the meal plans space-y-4 div -->
                <div class="mt-6 pt-4 border-t border-gray-200">
                    <button onclick="openGuestMealModal()" class="w-full bg-green-600 text-white py-3 px-4 rounded-lg hover:bg-green-700 transition duration-200">
                        <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Add Guest Meal
                    </button>
                </div>
            </div>

            <!-- Next Week -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-2xl font-bold text-black mb-6">Next Week</h2>
                <p class="text-gray-600 mb-4"><?php echo formatWeekRange($nextWeekStart); ?></p>

                <div class="space-y-4">
                    <?php foreach ($mealPlans as $plan): ?>
                        <?php
                        $isConfirmed = false;
                        $confirmationId = null;
                        $paymentStatus = 'unpaid';
                        foreach ($nextWeekConfirmations as $conf) {
                            if ($conf['meal_plan_id'] == $plan['id']) {
                                $isConfirmed = true;
                                $confirmationId = $conf['id'];
                                $paymentStatus = $conf['payment_status'];
                                break;
                            }
                        }
                        ?>
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="flex justify-between items-center">
                                <div>
                                    <h3 class="font-semibold text-lg"><?php echo htmlspecialchars($plan['name']); ?></h3>
                                    <p class="text-gray-600"><?php echo $plan['price']; ?> BDT</p>
                                    <?php if ($plan['description']): ?>
                                        <p class="text-sm text-gray-500"><?php echo htmlspecialchars($plan['description']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if ($isConfirmed): ?>
                                        <form method="POST" class="inline" onsubmit="return confirmCancellation('<?php echo htmlspecialchars($plan['name']); ?>')">
                                            <input type="hidden" name="action" value="cancel_meal">
                                            <input type="hidden" name="confirmation_id" value="<?php echo $confirmationId; ?>">
                                            <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">
                                                Cancel
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="confirm_meal">
                                            <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                            <input type="hidden" name="week_type" value="next">

                                            <?php if (in_array($plan['name'], ['First Half', 'Second Half', 'Full Week'])): ?>
                                                <div class="flex items-center space-x-2">
                                                    <select name="meal_type" required class="px-2 py-1 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-black">
                                                        <option value="Fish">Fish</option>
                                                        <option value="Egg">Egg</option>
                                                    </select>
                                                    <button type="submit" class="bg-black text-white px-4 py-2 rounded hover:bg-gray-800">
                                                        Confirm
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <button type="submit" class="bg-black text-white px-4 py-2 rounded hover:bg-gray-800">
                                                    Confirm
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Add this HTML section before the Meal Status section -->
            <?php if (!empty($paymentNumbers)): ?>
                <div class="mt-8 bg-white rounded-lg shadow-lg p-6">
                    <h2 class="text-2xl font-bold text-black mb-6 flex items-center">
                        <svg class="w-6 h-6 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                        </svg>
                        Payment Numbers
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- bKash Numbers -->
                        <?php if (!empty($bkashNumbers)): ?>
                            <div class="bg-gradient-to-r from-pink-50 to-red-50 rounded-lg p-4 border border-pink-200">
                                <div class="flex items-center mb-3">
                                    <div class="w-8 h-8 bg-pink-600 rounded-lg flex items-center justify-center mr-3">
                                        <span class="text-white font-bold text-sm">bK</span>
                                    </div>
                                    <h3 class="text-lg font-semibold text-pink-800">bKash</h3>
                                </div>
                                <div class="space-y-2">
                                    <?php foreach ($bkashNumbers as $number): ?>
                                        <div class="flex items-center justify-between bg-white rounded-lg p-3 border border-pink-200">
                                            <span class="font-mono text-gray-800 text-lg"><?php echo htmlspecialchars($number); ?></span>
                                            <button
                                                onclick="copyToClipboard('<?php echo htmlspecialchars($number); ?>', this)"
                                                class="bg-pink-600 text-white px-3 py-1 rounded-md hover:bg-pink-700 transition duration-200 text-sm flex items-center space-x-1">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                                </svg>
                                                <span>Copy</span>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Nagad Numbers -->
                        <?php if (!empty($nagadNumbers)): ?>
                            <div class="bg-gradient-to-r from-orange-50 to-yellow-50 rounded-lg p-4 border border-orange-200">
                                <div class="flex items-center mb-3">
                                    <div class="w-8 h-8 bg-orange-600 rounded-lg flex items-center justify-center mr-3">
                                        <span class="text-white font-bold text-sm">N</span>
                                    </div>
                                    <h3 class="text-lg font-semibold text-orange-800">Nagad</h3>
                                </div>
                                <div class="space-y-2">
                                    <?php foreach ($nagadNumbers as $number): ?>
                                        <div class="flex items-center justify-between bg-white rounded-lg p-3 border border-orange-200">
                                            <span class="font-mono text-gray-800 text-lg"><?php echo htmlspecialchars($number); ?></span>
                                            <button
                                                onclick="copyToClipboard('<?php echo htmlspecialchars($number); ?>', this)"
                                                class="bg-orange-600 text-white px-3 py-1 rounded-md hover:bg-orange-700 transition duration-200 text-sm flex items-center space-x-1">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                                </svg>
                                                <span>Copy</span>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Copy success message -->
                    <div id="copyMessage" class="hidden mt-4 p-3 bg-green-100 border border-green-400 text-green-700 rounded-lg">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Number copied to clipboard!</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>


            <!-- Meal Status -->
            <?php if (!empty($confirmations) || !empty($guestMeals)): ?>
        </div>
        <div class="mt-8 bg-white rounded-lg shadow-lg p-6">
            <h2 class="text-2xl font-bold text-black mb-6">Meal Status</h2>
            <div class="overflow-x-auto">
                <table class="w-full table-auto">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="px-4 py-2 text-left">Type</th>
                            <th class="px-4 py-2 text-left">Plan/Date</th>
                            <th class="px-4 py-2 text-left">Details</th>
                            <th class="px-4 py-2 text-left">Price</th>
                            <th class="px-4 py-2 text-left">Payment Status</th>
                            <th class="px-4 py-2 text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($confirmations as $conf): ?>
                            <tr class="border-t">
                                <td class="px-4 py-2">
                                    <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs">Meal Plan</span>
                                </td>
                                <td class="px-4 py-2"><?php echo htmlspecialchars($conf['plan_name']); ?>
                                    <?php if ($conf['type']): ?>
                                        <span class="ml-2 text-xs bg-gray-200 text-gray-700 px-2 py-1 rounded"><?php echo htmlspecialchars($conf['type']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2"><?php echo formatWeekRange($conf['week_start_date']); ?></td>
                                <td class="px-4 py-2"><?php echo $conf['price']; ?> BDT</td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-1 rounded text-sm <?php echo $conf['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                        <?php echo ucfirst($conf['payment_status']); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-2">
                                    <?php if ($conf['payment_status'] === 'unpaid'): ?>
                                        <button onclick="openPaymentModal(<?php echo $conf['id']; ?>, '<?php echo htmlspecialchars($conf['plan_name']); ?>', 'meal')" class="bg-blue-600 text-white px-3 py-1 rounded text-sm hover:bg-blue-700">
                                            Pay
                                        </button>
                                    <?php else: ?>
                                        <span class="text-green-600 text-sm">Paid</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php foreach ($guestMeals as $guest): ?>
                            <tr class="border-t">
                                <td class="px-4 py-2">
                                    <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-xs">Guest Meal</span>
                                </td>
                                <td class="px-4 py-2"><?php echo date('M d, Y', strtotime($guest['meal_date'])); ?></td>
                                <td class="px-4 py-2"><?php echo $guest['number_of_meals']; ?> meal(s)</td>
                                <td class="px-4 py-2"><?php echo $guest['total_amount']; ?> BDT</td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-1 rounded text-sm <?php echo $guest['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                        <?php echo ucfirst($guest['payment_status']); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-2">
                                    <?php if ($guest['payment_status'] === 'unpaid'): ?>
                                        <?php
                                        // Check if meal date has passed or is today
                                        $timezone = new DateTimeZone('Asia/Dhaka');
                                        $today = new DateTime('now', $timezone);
                                        $mealDate = new DateTime($guest['meal_date'], $timezone);
                                        $canCancel = $mealDate > $today;
                                        ?>
                                        <div class="flex space-x-2">
                                            <button onclick="openPaymentModal(<?php echo $guest['id']; ?>, 'Guest Meal - <?php echo date('M d', strtotime($guest['meal_date'])); ?>', 'guest')" class="bg-blue-600 text-white px-3 py-1 rounded text-sm hover:bg-blue-700">
                                                Pay
                                            </button>
                                            <?php if ($canCancel): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="action" value="cancel_guest_meal">
                                                    <input type="hidden" name="guest_meal_id" value="<?php echo $guest['id']; ?>">
                                                    <button type="submit" class="bg-red-600 text-white px-3 py-1 rounded text-sm hover:bg-red-700">
                                                        Cancel
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-gray-500 text-sm">Cannot Cancel</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-green-600 text-sm">Paid</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Payment Modal -->
    <div id="paymentModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-md mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Payment for <span id="modalPlanName"></span></h3>
                <button onclick="closePaymentModal()" class="text-gray-500 hover:text-gray-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="payment">
                <input type="hidden" name="confirmation_id" id="modalConfirmationId">

                <div class="space-y-4">
                    <div>
                        <label for="modal_phone_number" class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                        <input
                            type="tel"
                            id="modal_phone_number"
                            name="phone_number"
                            required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-black focus:border-black"
                            placeholder="Enter phone number">
                    </div>

                    <div>
                        <label for="modal_transaction_id" class="block text-sm font-medium text-gray-700 mb-2">Transaction ID</label>
                        <input
                            type="text"
                            id="modal_transaction_id"
                            name="transaction_id"
                            required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-black focus:border-black"
                            placeholder="Enter transaction ID">
                    </div>

                    <div class="flex space-x-3">
                        <button
                            type="submit"
                            class="flex-1 bg-black text-white py-2 px-4 rounded-md hover:bg-gray-800 transition duration-200">
                            Submit Payment
                        </button>
                        <button
                            type="button"
                            onclick="closePaymentModal()"
                            class="flex-1 bg-gray-300 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-400 transition duration-200">
                            Cancel
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Guest Meal Modal -->
    <div id="guestMealModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-md mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Add Guest Meal(Must be paid through bKash/Nagad)</h3>
                <button onclick="closeGuestMealModal()" class="text-gray-500 hover:text-gray-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="add_guest_meal">

                <div class="space-y-4">
                    <div>
                        <label for="guest_meal_date" class="block text-sm font-medium text-gray-700 mb-2">Select Date</label>
                        <select id="guest_meal_date" name="meal_date" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-black focus:border-black">
                            <option value="">Choose a date</option>
                            <?php
                            // Replace the existing date generation code in the guest meal modal with this:
                            // Set timezone to match your application
                            $timezone = new DateTimeZone('Asia/Dhaka');
                            $today = new DateTime('now', $timezone);

                            // Start from tomorrow and show next 2 dates (tomorrow and day after tomorrow)
                            $current = clone $today;
                            $current->modify('+1 day'); // Start from tomorrow

                            for ($i = 0; $i < 2; $i++) {
                                echo '<option value="' . $current->format('Y-m-d') . '">' . $current->format('M d, Y (D)') . '</option>';
                                $current->modify('+1 day');
                            }
                            ?>
                        </select>
                    </div>

                    <div>
                        <label for="guest_number_of_meals" class="block text-sm font-medium text-gray-700 mb-2">Number of Meals</label>
                        <input
                            type="number"
                            id="guest_number_of_meals"
                            name="number_of_meals"
                            min="1"
                            max="10"
                            value="1"
                            required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-black focus:border-black"
                            oninput="calculateGuestTotal()">
                    </div>

                    <div class="bg-gray-50 p-3 rounded-md">
                        <div class="flex justify-between text-sm">
                            <span>Price per meal:</span>
                            <span>50 BDT</span>
                        </div>
                        <div class="flex justify-between font-semibold mt-2">
                            <span>Total Amount:</span>
                            <span id="guestTotalAmount">50 BDT</span>
                        </div>
                    </div>

                    <div class="flex space-x-3">
                        <button
                            type="submit"
                            class="flex-1 bg-green-600 text-white py-2 px-4 rounded-md hover:bg-green-700 transition duration-200">
                            Confirm Guest Meal
                        </button>
                        <button
                            type="button"
                            onclick="closeGuestMealModal()"
                            class="flex-1 bg-gray-300 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-400 transition duration-200">
                            Cancel
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Guest Meal Modal Functions
        function openGuestMealModal() {
            document.getElementById('guestMealModal').classList.remove('hidden');
            document.getElementById('guestMealModal').classList.add('flex');
        }

        function closeGuestMealModal() {
            document.getElementById('guestMealModal').classList.add('hidden');
            document.getElementById('guestMealModal').classList.remove('flex');
            document.getElementById('guest_meal_date').value = '';
            document.getElementById('guest_number_of_meals').value = '1';
            calculateGuestTotal();
        }

        function calculateGuestTotal() {
            const numberOfMeals = parseInt(document.getElementById('guest_number_of_meals').value) || 1;
            const total = numberOfMeals * 50;
            document.getElementById('guestTotalAmount').textContent = total + ' BDT';
        }

        function confirmCancellation(planName) {
            return confirm('Are you sure you want to cancel "' + planName + '"?');
        }

        // Update the existing openPaymentModal function to handle both meal plans and guest meals
        function openPaymentModal(id, name, type) {
            document.getElementById('modalConfirmationId').value = id;
            document.getElementById('modalPlanName').textContent = name;

            // Update the form action based on type
            const form = document.querySelector('#paymentModal form');
            const actionInput = form.querySelector('input[name="action"]');

            if (type === 'guest') {
                actionInput.value = 'guest_payment';
                // Add guest_meal_id field
                let guestIdInput = form.querySelector('input[name="guest_meal_id"]');
                if (!guestIdInput) {
                    guestIdInput = document.createElement('input');
                    guestIdInput.type = 'hidden';
                    guestIdInput.name = 'guest_meal_id';
                    form.appendChild(guestIdInput);
                }
                guestIdInput.value = id;
                // Remove confirmation_id field
                const confIdInput = form.querySelector('input[name="confirmation_id"]');
                if (confIdInput) confIdInput.remove();
            } else {
                actionInput.value = 'payment';
                // Add confirmation_id field
                let confIdInput = form.querySelector('input[name="confirmation_id"]');
                if (!confIdInput) {
                    confIdInput = document.createElement('input');
                    confIdInput.type = 'hidden';
                    confIdInput.name = 'confirmation_id';
                    form.appendChild(confIdInput);
                }
                confIdInput.value = id;
                // Remove guest_meal_id field
                const guestIdInput = form.querySelector('input[name="guest_meal_id"]');
                if (guestIdInput) guestIdInput.remove();
            }

            document.getElementById('paymentModal').classList.remove('hidden');
            document.getElementById('paymentModal').classList.add('flex');
        }

        // Close guest meal modal when clicking outside
        document.getElementById('guestMealModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeGuestMealModal();
            }
        });
        // User menu dropdown functionality
        const userMenuButton = document.getElementById('userMenuButton');
        const userMenuDropdown = document.getElementById('userMenuDropdown');
        const userMenuArrow = document.getElementById('userMenuArrow');
        let isMenuOpen = false;

        function toggleUserMenu() {
            isMenuOpen = !isMenuOpen;
            if (isMenuOpen) {
                userMenuDropdown.classList.remove('hidden');
                userMenuArrow.style.transform = 'rotate(180deg)';
            } else {
                userMenuDropdown.classList.add('hidden');
                userMenuArrow.style.transform = 'rotate(0deg)';
            }
        }

        function closeUserMenu() {
            isMenuOpen = false;
            userMenuDropdown.classList.add('hidden');
            userMenuArrow.style.transform = 'rotate(0deg)';
        }

        // Toggle menu on button click
        userMenuButton.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleUserMenu();
        });

        // Close menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!userMenuButton.contains(e.target) && !userMenuDropdown.contains(e.target)) {
                closeUserMenu();
            }
        });

        // Keep menu open when hovering over dropdown
        userMenuDropdown.addEventListener('mouseenter', function() {
            if (!isMenuOpen) {
                userMenuDropdown.classList.remove('hidden');
                userMenuArrow.style.transform = 'rotate(180deg)';
            }
        });

        // Optional: Open menu on hover over button
        userMenuButton.addEventListener('mouseenter', function() {
            if (!isMenuOpen) {
                userMenuDropdown.classList.remove('hidden');
                userMenuArrow.style.transform = 'rotate(180deg)';
            }
        });

        // Close menu when mouse leaves the entire menu area
        const userMenuContainer = userMenuButton.parentElement;
        userMenuContainer.addEventListener('mouseleave', function() {
            if (!isMenuOpen) {
                setTimeout(() => {
                    if (!isMenuOpen) {
                        userMenuDropdown.classList.add('hidden');
                        userMenuArrow.style.transform = 'rotate(0deg)';
                    }
                }, 100);
            }
        });

        // Payment Modal Functions
        function openPaymentModal(confirmationId, planName) {
            document.getElementById('modalConfirmationId').value = confirmationId;
            document.getElementById('modalPlanName').textContent = planName;
            document.getElementById('paymentModal').classList.remove('hidden');
            document.getElementById('paymentModal').classList.add('flex');
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.add('hidden');
            document.getElementById('paymentModal').classList.remove('flex');
            document.getElementById('modal_phone_number').value = '';
            document.getElementById('modal_transaction_id').value = '';
        }

        // Close modal when clicking outside
        document.getElementById('paymentModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePaymentModal();
            }
        });

        function copyToClipboard(text, button) {
            // Create a temporary textarea element
            const tempTextarea = document.createElement('textarea');
            tempTextarea.value = text;
            document.body.appendChild(tempTextarea);

            // Select and copy the text
            tempTextarea.select();
            tempTextarea.setSelectionRange(0, 99999); // For mobile devices

            try {
                document.execCommand('copy');

                // Show success message
                const copyMessage = document.getElementById('copyMessage');
                copyMessage.classList.remove('hidden');

                // Change button text temporarily
                const originalText = button.innerHTML;
                button.innerHTML = `
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <span>Copied!</span>
        `;
                button.classList.add('bg-green-600', 'hover:bg-green-700');
                button.classList.remove('bg-pink-600', 'hover:bg-pink-700', 'bg-orange-600', 'hover:bg-orange-700');

                // Reset button and hide message after 2 seconds
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.classList.remove('bg-green-600', 'hover:bg-green-700');
                    // Re-add original colors based on parent container
                    if (button.closest('.from-pink-50')) {
                        button.classList.add('bg-pink-600', 'hover:bg-pink-700');
                    } else {
                        button.classList.add('bg-orange-600', 'hover:bg-orange-700');
                    }
                    copyMessage.classList.add('hidden');
                }, 2000);

            } catch (err) {
                console.error('Failed to copy text: ', err);
                // Fallback for older browsers
                alert('Number: ' + text);
            }

            // Remove the temporary textarea
            document.body.removeChild(tempTextarea);
        }

        // Alternative modern approach using Clipboard API (for newer browsers)
        async function copyToClipboardModern(text, button) {
            try {
                await navigator.clipboard.writeText(text);
                // Same success handling as above
                const copyMessage = document.getElementById('copyMessage');
                copyMessage.classList.remove('hidden');

                const originalText = button.innerHTML;
                button.innerHTML = `
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <span>Copied!</span>
        `;
                button.classList.add('bg-green-600', 'hover:bg-green-700');
                button.classList.remove('bg-pink-600', 'hover:bg-pink-700', 'bg-orange-600', 'hover:bg-orange-700');

                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.classList.remove('bg-green-600', 'hover:bg-green-700');
                    if (button.closest('.from-pink-50')) {
                        button.classList.add('bg-pink-600', 'hover:bg-pink-700');
                    } else {
                        button.classList.add('bg-orange-600', 'hover:bg-orange-700');
                    }
                    copyMessage.classList.add('hidden');
                }, 2000);

            } catch (err) {
                // Fallback to older method
                copyToClipboard(text, button);
            }
        }

        // Check if Clipboard API is supported and use it, otherwise use fallback
        if (navigator.clipboard && window.isSecureContext) {
            // Use the modern approach
            window.copyToClipboard = copyToClipboardModern;
        }
    </script>
    </div>
</body>

</html>