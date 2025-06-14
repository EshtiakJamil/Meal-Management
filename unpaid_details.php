<?php
session_start();
require_once 'config.php';

// Check if user is logged in and is a monitor
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'monitor') {
    header('Location: index.php');
    exit();
}

$user_gender = $_SESSION['gender'];

// Get current week dates
$current_week_start = date('Y-m-d', strtotime('last saturday'));
if (date('w') == 6) { // If today is Saturday
    $current_week_start = date('Y-m-d');
}

// Get unpaid students of the same gender INCLUDING the monitor
$stmt = $pdo->prepare("
    SELECT DISTINCT
        s.id,
        s.name,
        s.roll,
        s.batch,
        s.role,
        COUNT(mc.id) as confirmed_plans
    FROM students s
    LEFT JOIN meal_confirmations mc ON s.id = mc.student_id 
        AND mc.week_start_date = ? 
        AND mc.payment_status = 'unpaid'
    WHERE s.gender = ? AND (s.role = 'student' OR s.role = 'monitor' OR s.role = 'manager')
    GROUP BY s.id, s.name, s.roll, s.batch, s.role
    HAVING confirmed_plans > 0
    ORDER BY s.role DESC, s.batch, s.name
");
$stmt->execute([$current_week_start, $user_gender]);
$unpaid_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get detailed meal confirmations for unpaid students
$unpaid_details = [];
$batch_totals = [];
if (!empty($unpaid_students)) {
    $student_ids = array_column($unpaid_students, 'id');
    $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
    
    $stmt = $pdo->prepare("
        SELECT 
            mc.student_id,
            mp.name as meal_plan,
            mp.price,
            mc.confirmed_at,
            DATE(mc.confirmed_at) as confirmation_date
        FROM meal_confirmations mc
        JOIN meal_plans mp ON mc.meal_plan_id = mp.id
        WHERE mc.student_id IN ($placeholders) 
            AND mc.week_start_date = ? 
            AND mc.payment_status = 'unpaid'
        ORDER BY mc.student_id, mc.confirmed_at
    ");
    $stmt->execute(array_merge($student_ids, [$current_week_start]));
    
    $confirmations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($confirmations as $confirmation) {
        $unpaid_details[$confirmation['student_id']][] = $confirmation;
    }
}

// Calculate totals
$total_unpaid = 0;
$student_totals = [];
foreach ($unpaid_students as $student) {
    $student_total = 0;
    if (isset($unpaid_details[$student['id']])) {
        foreach ($unpaid_details[$student['id']] as $detail) {
            $student_total += $detail['price'];
        }
    }
    $student_totals[$student['id']] = $student_total;
    $total_unpaid += $student_total;
    
    // Calculate batch totals
    $batch = $student['batch'];
    if (!isset($batch_totals[$batch])) {
        $batch_totals[$batch] = ['count' => 0, 'amount' => 0];
    }
    $batch_totals[$batch]['count']++;
    $batch_totals[$batch]['amount'] += $student_total;
}

// Sort batch totals by batch name
ksort($batch_totals);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unpaid Details Report - Hostel Meal Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Arial', sans-serif; }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .print-break { page-break-after: always; }
        }
    </style>
</head>
<body class="bg-white min-h-screen">
    <!-- Header -->
    <header class="bg-black text-white p-4 no-print">
        <div class="container mx-auto flex justify-between items-center">
            <div class="flex items-center space-x-4">
                <a href="monitor_dashboard.php" class="bg-gray-700 px-4 py-2 rounded hover:bg-gray-600 text-sm">
                    ← Back to Dashboard
                </a>
                <h1 class="text-2xl font-bold">Unpaid Details Report</h1>
            </div>
            <div class="flex items-center space-x-4">
                <button onclick="window.print()" class="bg-gray-700 px-4 py-2 rounded hover:bg-gray-600 text-sm">
                    🖨️ Print Report
                </button>
                <div class="relative">
                    <button onclick="toggleDropdown()" class="bg-gray-700 px-4 py-2 rounded hover:bg-gray-600">
                        <?php echo htmlspecialchars($_SESSION['name']); ?> ▼
                    </button>
                    <div id="dropdown" class="hidden absolute right-0 mt-2 w-48 bg-white text-black rounded shadow-lg border">
                        <a href="dashboard.php" class="block px-4 py-2 hover:bg-gray-100">Student Dashboard</a>
                        <a href="logout.php" class="block px-4 py-2 hover:bg-gray-100 border-t">Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto p-6">
        <!-- Report Header -->
        <div class="bg-white rounded-lg shadow-md border-2 border-black p-6 mb-6">
            <div class="text-center mb-4">
                <h2 class="text-3xl font-bold text-black mb-2">Unpaid Meal Details Report</h2>
                <div class="text-gray-600">
                    <p class="text-lg">Week: <?php echo date('M d', strtotime($current_week_start)); ?> - <?php echo date('M d, Y', strtotime($current_week_start . ' +6 days')); ?></p>
                    <p>Monitor: <?php echo htmlspecialchars($_SESSION['name']); ?> (<?php echo ucfirst($user_gender); ?> Students)</p>
                    <p class="text-sm">Generated on: <?php echo date('M d, Y g:i A'); ?></p>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-center">
                <div class="bg-gray-100 p-4 rounded-lg border-2 border-gray-400">
                    <p class="text-3xl font-bold text-black"><?php echo count($unpaid_students); ?></p>
                    <p class="text-sm text-gray-600">Unpaid Students</p>
                </div>
                <div class="bg-gray-100 p-4 rounded-lg border-2 border-gray-400">
                    <p class="text-3xl font-bold text-black"><?php echo number_format($total_unpaid); ?></p>
                    <p class="text-sm text-gray-600">Total Amount (BDT)</p>
                </div>
                <div class="bg-gray-100 p-4 rounded-lg border-2 border-gray-400">
                    <p class="text-3xl font-bold text-black"><?php echo count($batch_totals); ?></p>
                    <p class="text-sm text-gray-600">Affected Batches</p>
                </div>
            </div>
        </div>

        <!-- Batch Summary -->
        <?php if (!empty($batch_totals)): ?>
        <div class="bg-white rounded-lg shadow-md border-2 border-gray-300 p-6 mb-6 no-print">
            <h3 class="text-xl font-bold mb-4 text-black">Summary by Batch</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($batch_totals as $batch => $totals): ?>
                    <div class="border-2 border-gray-400 rounded-lg p-4 bg-gray-100">
                        <div class="text-center">
                            <p class="text-lg font-bold text-black">Batch <?php echo htmlspecialchars($batch); ?></p>
                            <p class="text-2xl font-bold text-black"><?php echo number_format($totals['amount']); ?> BDT</p>
                            <p class="text-sm text-gray-600"><?php echo $totals['count']; ?> student(s)</p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="bg-white rounded-lg shadow-md border-2 border-gray-300 p-4 mb-6 no-print">
            <div class="flex justify-between items-center">
                <h3 class="text-lg font-bold text-black">Quick Actions</h3>
                <div class="flex space-x-2">
                    <button onclick="expandAll()" class="bg-black text-white px-3 py-1 rounded text-sm hover:bg-gray-800">
                        Expand All
                    </button>
                    <button onclick="collapseAll()" class="bg-gray-600 text-white px-3 py-1 rounded text-sm hover:bg-gray-700">
                        Collapse All
                    </button>
                    <button onclick="exportToCSV()" class="bg-gray-800 text-white px-3 py-1 rounded text-sm hover:bg-gray-900">
                        📊 Export CSV
                    </button>
                </div>
            </div>
        </div>

        <!-- Detailed Student List -->
        <?php if (!empty($unpaid_students)): ?>
            <div class="space-y-2">
                <?php foreach ($unpaid_students as $index => $student): ?>
                    <div class="bg-white rounded-lg shadow-md border-2 border-gray-300 overflow-hidden <?php echo ($student['role'] === 'monitor') ? 'border-black' : ''; ?>">
                        <!-- Student Header - More Compact -->
                        <div class="bg-<?php echo ($student['role'] === 'monitor') ? 'black' : 'gray-800'; ?> text-white p-3 cursor-pointer" 
                             onclick="toggleStudent(<?php echo $index; ?>)">
                            <div class="flex justify-between items-center">
                                <div class="flex items-center space-x-3">
                                    <div>
                                        <h4 class="text-lg font-bold flex items-center">
                                            <?php echo htmlspecialchars($student['name']); ?>
                                            <?php if ($student['role'] === 'monitor'): ?>
                                                <span class="bg-white text-black px-2 py-1 rounded text-xs ml-2 font-bold">
                                                    MONITOR
                                                </span>
                                            <?php endif; ?>
                                        </h4>
                                        <div class="text-xs opacity-90">
                                            <span class="mr-3">📋 <?php echo htmlspecialchars($student['roll']); ?></span>
                                            <span class="mr-3">🎓 Batch: <?php echo htmlspecialchars($student['batch']); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-xl font-bold"><?php echo number_format($student_totals[$student['id']]); ?> BDT</p>
                                    <p class="text-xs opacity-90"><?php echo $student['confirmed_plans']; ?> unpaid meal(s)</p>
                                    <p class="text-xs opacity-75">Click to expand ▼</p>
                                </div>
                            </div>
                        </div>

                        <!-- Student Details (Initially Hidden) -->
                        <div id="student-<?php echo $index; ?>" class="hidden p-4">
                            <?php if (isset($unpaid_details[$student['id']])): ?>
                                <div class="space-y-2">
                                    <h5 class="font-bold text-black mb-2 text-sm">Unpaid Meal Plans:</h5>
                                    <?php foreach ($unpaid_details[$student['id']] as $detail): ?>
                                        <div class="flex justify-between items-center bg-gray-50 p-3 rounded-lg border-l-4 border-black">
                                            <div class="flex-1">
                                                <p class="font-semibold text-base text-black"><?php echo htmlspecialchars($detail['meal_plan']); ?></p>
                                                <div class="text-xs text-gray-600 mt-1">
                                                    <span class="mr-3">📅 Confirmed: <?php echo date('M d, Y', strtotime($detail['confirmation_date'])); ?></span>
                                                    <span>🕐 Time: <?php echo date('g:i A', strtotime($detail['confirmed_at'])); ?></span>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-lg font-bold text-black"><?php echo number_format($detail['price']); ?> BDT</p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <div class="border-t pt-2 mt-3">
                                        <div class="flex justify-between items-center bg-gray-200 p-2 rounded-lg">
                                            <p class="font-bold text-black text-sm">Total for <?php echo htmlspecialchars($student['name']); ?>:</p>
                                            <p class="text-lg font-bold text-black"><?php echo number_format($student_totals[$student['id']]); ?> BDT</p>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Footer Summary -->
            <div class="bg-white rounded-lg shadow-md border-2 border-black p-6 mt-6">
                <div class="text-center">
                    <h3 class="text-2xl font-bold text-black mb-4">Total Summary</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <p class="text-4xl font-bold text-black"><?php echo count($unpaid_students); ?></p>
                            <p class="text-gray-600">Total Unpaid Students</p>
                        </div>
                        <div>
                            <p class="text-4xl font-bold text-black"><?php echo number_format($total_unpaid); ?> BDT</p>
                            <p class="text-gray-600">Total Outstanding Amount</p>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="bg-white rounded-lg shadow-md border-2 border-gray-300 p-12 text-center">
                <div class="text-6xl mb-4">🎉</div>
                <h3 class="text-2xl font-bold text-black mb-2">Excellent News!</h3>
                <p class="text-lg text-gray-600">All <?php echo $user_gender; ?> students have paid their meal fees!</p>
                <p class="text-sm text-gray-500 mt-2">No outstanding payments found for the current week.</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleDropdown() {
            document.getElementById('dropdown').classList.toggle('hidden');
        }

        function toggleStudent(index) {
            const element = document.getElementById('student-' + index);
            element.classList.toggle('hidden');
        }

        function expandAll() {
            const students = document.querySelectorAll('[id^="student-"]');
            students.forEach(student => student.classList.remove('hidden'));
        }

        function collapseAll() {
            const students = document.querySelectorAll('[id^="student-"]');
            students.forEach(student => student.classList.add('hidden'));
        }

        function exportToCSV() {
            const csvData = [
                ['Name', 'Roll', 'Batch', 'Room', 'Phone', 'Role', 'Meal Plan', 'Amount (BDT)', 'Confirmed Date', 'Confirmed Time']
            ];
            
            <?php foreach ($unpaid_students as $student): ?>
                <?php if (isset($unpaid_details[$student['id']])): ?>
                    <?php foreach ($unpaid_details[$student['id']] as $detail): ?>
                        csvData.push([
                            '<?php echo addslashes($student['name']); ?>',
                            '<?php echo addslashes($student['roll']); ?>',
                            '<?php echo addslashes($student['batch']); ?>',
                            '<?php echo addslashes($student['room_no'] ?? ''); ?>',
                            '<?php echo addslashes($student['phone'] ?? ''); ?>',
                            '<?php echo addslashes($student['role']); ?>',
                            '<?php echo addslashes($detail['meal_plan']); ?>',
                            '<?php echo $detail['price']; ?>',
                            '<?php echo date('Y-m-d', strtotime($detail['confirmation_date'])); ?>',
                            '<?php echo date('H:i:s', strtotime($detail['confirmed_at'])); ?>'
                        ]);
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>

            const csvContent = csvData.map(row => row.map(field => `"${field}"`).join(',')).join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'unpaid_details_<?php echo date('Y-m-d'); ?>.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }

        // Close dropdown when clicking outside
        window.onclick = function(event) {
            if (!event.target.matches('.bg-gray-700')) {
                var dropdown = document.getElementById('dropdown');
                if (!dropdown.classList.contains('hidden')) {
                    dropdown.classList.add('hidden');
                }
            }
        }
    </script>
</body>
</html>