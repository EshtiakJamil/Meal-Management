<?php
session_start();
require_once 'config.php';

// Check if user is logged in and is a monitor
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'monitor') {
    header('Location: index.php');
    exit();
}

$user_gender = $_SESSION['gender'];

// Handle transfer monitor action
if (isset($_POST['action']) && $_POST['action'] === 'transfer_monitor' && isset($_POST['new_monitor_id'])) {
    try {
        $pdo->beginTransaction();
        
        // Update the selected student to be the new monitor
        $stmt = $pdo->prepare("UPDATE students SET role = 'monitor' WHERE id = ? AND gender = ?");
        $stmt->execute([$_POST['new_monitor_id'], $user_gender]);
        
        if ($stmt->rowCount() > 0) {
            // Update current monitor to be a regular student
            $stmt = $pdo->prepare("UPDATE students SET role = 'student' WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            
            // Remove all permissions from current monitor
            $stmt = $pdo->prepare("DELETE FROM permissions WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            
            $pdo->commit();
            
            // Destroy session and redirect to login
            session_destroy();
            header('Location: index.php?message=Monitor role transferred successfully. Please login again.');
            exit();
        } else {
            $pdo->rollback();
            $error = "Error: Selected student not found or invalid selection.";
        }
    } catch (Exception $e) {
        $pdo->rollback();
        $error = "Error transferring monitor role: " . $e->getMessage();
    }
}

// Get current week dates
$current_week_start = getCurrentWeekStart();

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

// Get all managers (no gender constraint)
$stmt = $pdo->prepare("
    SELECT 
        id,
        name,
        roll,
        batch,
        gender,
        created_at
    FROM students 
    WHERE role = 'manager' 
    ORDER BY batch, name
");
$stmt->execute();
$managers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all students of the same gender for transfer monitor functionality (excluding current monitor)
$stmt = $pdo->prepare("SELECT id, name, roll, batch FROM students WHERE gender = ? AND role = 'student' ORDER BY batch, name");
$stmt->execute([$user_gender]);
$transfer_candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique batches for filtering
$stmt = $pdo->prepare("SELECT DISTINCT batch FROM students WHERE gender = ? AND role = 'student' ORDER BY batch");
$stmt->execute([$user_gender]);
$available_batches = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Calculate total unpaid amount
$total_unpaid = 0;
foreach ($unpaid_details as $student_details) {
    foreach ($student_details as $detail) {
        $total_unpaid += $detail['price'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor Dashboard - Hostel Meal Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Arial', sans-serif; }
        .close:hover {
            color: black;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Header -->
    <header class="bg-black text-white p-4">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-bold"><?php echo ucfirst($user_gender); ?> Monitor Dashboard</h1>
            <div class="relative">
                <button onclick="toggleDropdown()" class="bg-gray-800 px-4 py-2 rounded hover:bg-gray-700">
                    <?php echo htmlspecialchars($_SESSION['name']); ?> 
                </button>
                <div id="dropdown" class="hidden absolute right-0 mt-2 w-48 bg-white text-black rounded shadow-lg">
                    <a href="dashboard.php" class="block px-4 py-2 hover:bg-gray-100">Student Dashboard</a>
                    <a href="logout.php" class="block px-4 py-2 hover:bg-gray-100 border-t">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto p-6">
        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($success)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Current Week Info -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-bold mb-2">Current Week: <?php echo date('M d', strtotime($current_week_start)); ?> - <?php echo date('M d, Y', strtotime($current_week_start . ' +6 days')); ?></h2>
            <div class="flex justify-between items-center">
                <p class="text-gray-600">Monitoring: <?php echo ucfirst($user_gender); ?> Students</p>
                <?php if ($total_unpaid > 0): ?>
                    <p class="text-lg font-bold text-red-600">Total Unpaid: <?php echo number_format($total_unpaid); ?> BDT</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Managers Section -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h3 class="text-lg font-bold mb-4 flex items-center">
                <span class="bg-blue-100 text-blue-800 rounded-full px-3 py-1 text-sm mr-3">
                    <?php echo count($managers); ?>
                </span>
                All Managers
            </h3>

            <?php if (!empty($managers)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($managers as $manager): ?>
                        <div class="border border-gray-200 rounded-lg p-4 bg-blue-50">
                            <div class="flex justify-between items-start mb-2">
                                <div>
                                    <h4 class="font-medium text-lg text-blue-900"><?php echo htmlspecialchars($manager['name']); ?></h4>
                                    <p class="text-gray-600 text-sm"><?php echo htmlspecialchars($manager['roll']); ?></p>
                                </div>
                                <div class="text-right">
                                    <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs font-medium block mb-1">
                                        Manager
                                    </span>
                                    <span class="bg-gray-100 text-gray-700 px-2 py-1 rounded text-xs">
                                        <?php echo ucfirst($manager['gender']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="text-sm text-gray-700">
                                <p><strong>Batch:</strong> <?php echo htmlspecialchars($manager['batch']); ?></p>
                                <?php if ($manager['created_at']): ?>
                                    <p class="text-xs text-gray-500 mt-1">
                                        <strong>Since:</strong> <?php echo date('M d, Y', strtotime($manager['created_at'])); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-8 text-gray-500">
                    <p class="text-lg">👤 No managers found</p>
                    <p class="text-sm mt-2">There are currently no managers in the system.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Unpaid Students Summary -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold flex items-center">
                    <span class="bg-red-100 text-red-800 rounded-full px-3 py-1 text-sm mr-3">
                        <?php echo count($unpaid_students); ?>
                    </span>
                    Unpaid <?php echo ucfirst($user_gender); ?> Students
                </h3>
                <?php if (!empty($unpaid_students)): ?>
                    <a href="unpaid_details.php" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm font-medium inline-block">
                        📊 View Detailed Report
                    </a>
                <?php endif; ?>
            </div>

            <?php if (!empty($unpaid_students)): ?>
                <div class="space-y-4">
                    <?php foreach ($unpaid_students as $student): ?>
                        <div class="border border-gray-200 rounded-lg p-4 <?php echo ($student['role'] === 'monitor') ? 'bg-yellow-50 border-yellow-300' : ''; ?>">
                            <div class="flex justify-between items-start mb-2">
                                <div>
                                    <h4 class="font-medium text-lg flex items-center">
                                        <?php echo htmlspecialchars($student['name']); ?>
                                        <?php if ($student['role'] === 'monitor'): ?>
                                            <span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded text-xs ml-2 font-medium">
                                                (Monitor)
                                            </span>
                                        <?php endif; ?>
                                    </h4>
                                    <p class="text-gray-600"><?php echo htmlspecialchars($student['roll']); ?> | Batch: <?php echo htmlspecialchars($student['batch']); ?></p>
                                </div>
                                <div class="text-right">
                                    <span class="bg-red-100 text-red-800 px-2 py-1 rounded text-sm block mb-1">
                                        <?php echo $student['confirmed_plans']; ?> unpaid plan(s)
                                    </span>
                                    <?php if (isset($unpaid_details[$student['id']])): ?>
                                        <?php 
                                        $student_total = 0;
                                        foreach ($unpaid_details[$student['id']] as $detail) {
                                            $student_total += $detail['price'];
                                        }
                                        ?>
                                        <span class="text-sm font-bold text-red-600">
                                            Total: <?php echo number_format($student_total); ?> BDT
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (isset($unpaid_details[$student['id']])): ?>
                                <div class="mt-3 space-y-2">
                                    <p class="text-sm font-medium text-gray-700">Unpaid Meal Plans:</p>
                                    <?php foreach ($unpaid_details[$student['id']] as $detail): ?>
                                        <div class="flex justify-between items-center bg-gray-50 p-2 rounded">
                                            <div>
                                                <span class="text-sm font-medium"><?php echo htmlspecialchars($detail['meal_plan']); ?></span>
                                                <span class="text-xs text-gray-500 block">
                                                    Confirmed: <?php echo date('M d, Y g:i A', strtotime($detail['confirmed_at'])); ?>
                                                </span>
                                            </div>
                                            <span class="text-sm font-medium"><?php echo number_format($detail['price']); ?> BDT</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-8 text-gray-500">
                    <p class="text-lg">🎉 All <?php echo $user_gender; ?> students have paid!</p>
                    <p class="text-sm mt-2">No unpaid meal confirmations found.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Transfer Monitor Section -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-bold mb-4 text-orange-600">Transfer Monitor Role</h3>
            <div class="bg-orange-50 border border-orange-200 rounded p-4 mb-4">
                <p class="text-orange-800 text-sm">
                    ⚠️ <strong>Warning:</strong> Transferring monitor role will immediately revoke your monitor privileges and you will be logged out. 
                    Choose carefully as this action cannot be undone.
                </p>
            </div>

            <?php if (!empty($transfer_candidates)): ?>
                <form method="POST" id="transferForm" class="space-y-4">
                    <input type="hidden" name="action" value="transfer_monitor">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Batch</label>
                            <select id="batchFilter" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-orange-500">
                                <option value="">All Batches</option>
                                <?php foreach ($available_batches as $batch): ?>
                                    <option value="<?php echo htmlspecialchars($batch); ?>"><?php echo htmlspecialchars($batch); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Select New Monitor</label>
                            <select name="new_monitor_id" id="studentSelect" required class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-orange-500">
                                <option value="">Choose a student...</option>
                                <?php foreach ($transfer_candidates as $candidate): ?>
                                    <option value="<?php echo $candidate['id']; ?>" data-batch="<?php echo htmlspecialchars($candidate['batch']); ?>">
                                        <?php echo htmlspecialchars($candidate['name']); ?> (<?php echo htmlspecialchars($candidate['roll']); ?>) - Batch: <?php echo htmlspecialchars($candidate['batch']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center space-x-4">
                        <label class="flex items-center">
                            <input type="checkbox" id="confirmTransfer" class="mr-2">
                            <span class="text-sm text-gray-700">I confirm that I want to transfer my monitor role to the selected student</span>
                        </label>
                    </div>

                    <button type="submit" id="transferBtn" disabled 
                            class="bg-orange-600 text-white px-6 py-2 rounded hover:bg-orange-700 font-medium disabled:bg-gray-400 disabled:cursor-not-allowed">
                        Transfer Monitor Role
                    </button>
                </form>
            <?php else: ?>
                <div class="text-center py-8 text-gray-500">
                    <p class="text-lg">No eligible students found</p>
                    <p class="text-sm mt-2">There are no other <?php echo $user_gender; ?> students available for monitor transfer.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleDropdown() {
            document.getElementById('dropdown').classList.toggle('hidden');
        }

        // Close dropdown when clicking outside
        window.onclick = function(event) {
            if (!event.target.matches('.bg-gray-800')) {
                var dropdown = document.getElementById('dropdown');
                if (!dropdown.classList.contains('hidden')) {
                    dropdown.classList.add('hidden');
                }
            }
        }

        // Filter students by batch
        document.getElementById('batchFilter').addEventListener('change', function() {
            const selectedBatch = this.value;
            const studentSelect = document.getElementById('studentSelect');
            const options = studentSelect.getElementsByTagName('option');
            
            for (let i = 1; i < options.length; i++) { // Skip the first "Choose a student..." option
                const option = options[i];
                const optionBatch = option.getAttribute('data-batch');
                
                if (selectedBatch === '' || optionBatch === selectedBatch) {
                    option.style.display = 'block';
                } else {
                    option.style.display = 'none';
                }
            }
            
            // Reset selection
            studentSelect.value = '';
            checkTransferForm();
        });

        // Enable/disable transfer button based on form completion
        function checkTransferForm() {
            const studentSelected = document.getElementById('studentSelect').value !== '';
            const confirmChecked = document.getElementById('confirmTransfer').checked;
            const transferBtn = document.getElementById('transferBtn');
            
            transferBtn.disabled = !(studentSelected && confirmChecked);
        }

        document.getElementById('studentSelect').addEventListener('change', checkTransferForm);
        document.getElementById('confirmTransfer').addEventListener('change', checkTransferForm);

        // Confirm before transfer
        document.getElementById('transferForm').addEventListener('submit', function(e) {
            const selectedOption = document.getElementById('studentSelect').selectedOptions[0];
            const studentName = selectedOption.text.split(' (')[0];
            
            if (!confirm(`Are you absolutely sure you want to transfer monitor role to ${studentName}? This action cannot be undone and you will be logged out immediately.`)) {
                e.preventDefault();
            }
        });

        // Modal functions
        function showDetailedView() {
            document.getElementById('detailModal').style.display = 'block';
        }

        function closeDetailModal() {
            document.getElementById('detailModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('detailModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>