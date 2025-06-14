<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'nmc_meal');

// Create database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}


// Helper functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit();
    }
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

function hasAnyRole($roles) {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], $roles);
}

function getCurrentWeekStart() {
    $timezone = new DateTimeZone('Asia/Dhaka');
    $now = new DateTime('now', $timezone);
    
    // Find the most recent Saturday (or today if it's Saturday)
    $dayOfWeek = $now->format('w'); // 0=Sunday, 6=Saturday
    
    if ($dayOfWeek == 6) {
        // Today is Saturday, so current week starts today
        return $now->format('Y-m-d');
    } else {
        // Calculate days since last Saturday
        $daysSinceLastSaturday = ($dayOfWeek + 1) % 7;
        $weekStart = clone $now;
        $weekStart->modify("-{$daysSinceLastSaturday} days");
        return $weekStart->format('Y-m-d');
    }
}

function getNextWeekStart() {
    $timezone = new DateTimeZone('Asia/Dhaka');
    $currentWeekStart = new DateTime(getCurrentWeekStart(), $timezone);
    $nextWeekStart = clone $currentWeekStart;
    $nextWeekStart->modify('+7 days');
    return $nextWeekStart->format('Y-m-d');
}

function canConfirmPlan($planName, $weekType) {
    if ($weekType === 'next') {
        return true; // No restrictions for next week
    }
    
    $now = new DateTime();
    $currentDay = $now->format('N'); // 1 (Monday) to 7 (Sunday)
    $currentHour = (int)$now->format('H');
    
    switch ($planName) {
        case 'First Half':
        case 'Full Week':
            // Must be confirmed before 12:00 AM on Friday of previous week
            // If today is Saturday (7) or later in the week, it's too late
            return $currentDay < 5 || ($currentDay === 5 && $currentHour < 24);
            
        case 'Second Half':
            // Must be confirmed before 12:00 AM on Monday of current week
            return $currentDay < 1 || ($currentDay === 1 && $currentHour < 24);
            
        case 'Friday Feast':
            // Must be confirmed before 12:00 AM on Thursday of current week
            return $currentDay < 4 || ($currentDay === 4 && $currentHour < 24);
            
        default:
            return false;
    }
}

function formatWeekRange($startDate) {
    $start = new DateTime($startDate);
    $end = clone $start;
    $end->add(new DateInterval('P6D'));
    
    return $start->format('M j') . ' - ' . $end->format('M j, Y');
}

function redirect($url) {
    header("Location: $url");
    exit();
}
?>