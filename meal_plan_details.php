<?php
session_start();
require_once 'config.php';

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['manager', 'higher_authority'])) {
    header('Location: index.php');
    exit();
}

// Get the meal plan name from URL
if (!isset($_GET['plan'])) {
    header('Location: manager_dashboard.php');
    exit();
}

$plan_name = $_GET['plan'];

// Validate meal plan name
$valid_plans = ['First Half', 'Second Half', 'Full Week', 'Friday Feast'];
if (!in_array($plan_name, $valid_plans)) {
    header('Location: manager_dashboard.php');
    exit();
}

// Get current week dates
$current_week_start = getCurrentWeekStart();

// Get detailed meal confirmations for the specific plan
$stmt = $pdo->prepare("
    SELECT 
        mc.id as confirmation_id,
        mc.payment_status,
        s.name,
        s.roll,
        s.batch,
        s.gender,
        mc.phone_number,
        mc.transaction_id,
        mc.confirmed_at,
        mp.price,
        mc.type
    FROM meal_confirmations mc
    JOIN students s ON mc.student_id = s.id
    JOIN meal_plans mp ON mc.meal_plan_id = mp.id
    WHERE mp.name = ? AND mc.week_start_date = ?
    ORDER BY mc.payment_status DESC, s.name ASC
");
$stmt->execute([$plan_name, $current_week_start]);
$confirmations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get meal plan details
$stmt = $pdo->prepare("SELECT * FROM meal_plans WHERE name = ?");
$stmt->execute([$plan_name]);
$meal_plan = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate statistics
$total_students = count($confirmations);
$paid_students = array_filter($confirmations, function($c) { return $c['payment_status'] === 'paid'; });
$unpaid_students = array_filter($confirmations, function($c) { return $c['payment_status'] === 'unpaid'; });
$total_paid = count($paid_students);
$total_unpaid = count($unpaid_students);
$total_revenue = $total_paid * ($meal_plan['price'] ?? 0);

// Group by gender
$male_students = array_filter($confirmations, function($c) { return $c['gender'] === 'male'; });
$female_students = array_filter($confirmations, function($c) { return $c['gender'] === 'female'; });

// Group by type (Fish/Egg)
$fish_students = array_filter($confirmations, function($c) { return $c['type'] === 'Fish'; });
$egg_students = array_filter($confirmations, function($c) { return $c['type'] === 'Egg'; });

// Group by batch
$batches = [];
foreach ($confirmations as $confirmation) {
    $batch = $confirmation['batch'];
    if (!isset($batches[$batch])) {
        $batches[$batch] = [];
    }
    $batches[$batch][] = $confirmation;
}
ksort($batches);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($plan_name); ?> - Meal Plan Details</title>
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
        
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .animate-fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .student-card {
            transition: all 0.3s ease;
        }
        
        .student-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
        }
    </style>
</head>

<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">
    <!-- Header -->
    <header class="bg-custom-dark text-white shadow-2xl no-print">
        <div class="container mx-auto px-6 py-4">
            <div class="flex justify-between items-center">
                <div class="flex items-center space-x-4">
                    <button onclick="goBack()" class="w-10 h-10 bg-custom-accent rounded-full flex items-center justify-center hover:bg-blue-600 transition-colors">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <div>
                        <h1 class="text-2xl font-bold"><?php echo htmlspecialchars($plan_name); ?> Details</h1>
                        <p class="text-gray-300 text-sm">Week: <?php echo date('M d', strtotime($current_week_start)); ?> - <?php echo date('M d, Y', strtotime($current_week_start . ' +6 days')); ?></p>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <button onclick="printPage()" class="flex items-center space-x-2 bg-custom-gray px-4 py-2 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-print"></i>
                        <span>Print</span>
                    </button>
                    <button onclick="exportData()" class="flex items-center space-x-2 bg-green-600 px-4 py-2 rounded-lg hover:bg-green-700 transition-colors">
                        <i class="fas fa-download"></i>
                        <span>Export</span>
                    </button>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-6 py-8">
        <!-- Statistics Overview -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-2xl shadow-lg p-6 animate-fade-in">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-700">Total Students</h3>
                        <p class="text-3xl font-bold text-custom-accent"><?php echo $total_students; ?></p>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-users text-custom-accent text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-lg p-6 animate-fade-in">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-700">Paid</h3>
                        <p class="text-3xl font-bold text-green-600"><?php echo $total_paid; ?></p>
                    </div>
                    <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-check-circle text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-lg p-6 animate-fade-in">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-700">Unpaid</h3>
                        <p class="text-3xl font-bold text-red-600"><?php echo $total_unpaid; ?></p>
                    </div>
                    <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-exclamation-circle text-red-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-lg p-6 animate-fade-in">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-700">Revenue</h3>
                        <p class="text-3xl font-bold text-purple-600">৳<?php echo number_format($total_revenue); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-money-bill-wave text-purple-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gender, Fish/Egg, and Batch Statistics -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
            <div class="bg-white rounded-2xl shadow-lg p-6 animate-fade-in">
                <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-venus-mars mr-3 text-custom-accent"></i>
                    Gender Distribution
                </h3>
                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Male Students</span>
                        <div class="flex items-center space-x-2">
                            <div class="w-32 bg-gray-200 rounded-full h-2">
                                <div class="bg-blue-500 h-2 rounded-full" style="width: <?php echo $total_students > 0 ? (count($male_students) / $total_students) * 100 : 0; ?>%"></div>
                            </div>
                            <span class="font-semibold text-blue-600"><?php echo count($male_students); ?></span>
                        </div>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Female Students</span>
                        <div class="flex items-center space-x-2">
                            <div class="w-32 bg-gray-200 rounded-full h-2">
                                <div class="bg-pink-500 h-2 rounded-full" style="width: <?php echo $total_students > 0 ? (count($female_students) / $total_students) * 100 : 0; ?>%"></div>
                            </div>
                            <span class="font-semibold text-pink-600"><?php echo count($female_students); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-lg p-6 animate-fade-in">
                <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-fish mr-3 text-custom-accent"></i>
                    Meal Type Distribution
                </h3>
                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Fish</span>
                        <div class="flex items-center space-x-2">
                            <div class="w-32 bg-gray-200 rounded-full h-2">
                                <div class="bg-orange-500 h-2 rounded-full" style="width: <?php echo $total_students > 0 ? (count($fish_students) / $total_students) * 100 : 0; ?>%"></div>
                            </div>
                            <span class="font-semibold text-orange-600"><?php echo count($fish_students); ?></span>
                        </div>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Egg</span>
                        <div class="flex items-center space-x-2">
                            <div class="w-32 bg-gray-200 rounded-full h-2">
                                <div class="bg-yellow-500 h-2 rounded-full" style="width: <?php echo $total_students > 0 ? (count($egg_students) / $total_students) * 100 : 0; ?>%"></div>
                            </div>
                            <span class="font-semibold text-yellow-600"><?php echo count($egg_students); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-lg p-6 animate-fade-in">
                <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-graduation-cap mr-3 text-custom-accent"></i>
                    Batch Distribution
                </h3>
                <div class="space-y-3 max-h-40 overflow-y-auto">
                    <?php foreach ($batches as $batch => $students): ?>
                        <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                            <span class="font-medium text-gray-700">Batch <?php echo htmlspecialchars($batch); ?></span>
                            <span class="bg-custom-accent text-white px-3 py-1 rounded-full text-sm font-semibold">
                                <?php echo count($students); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Filters and Search -->
        <div class="bg-white rounded-2xl shadow-lg p-6 mb-8 no-print animate-fade-in">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-search text-gray-500"></i>
                        <input type="text" id="searchInput" placeholder="Search by name or roll..." 
                               class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-custom-accent focus:border-custom-accent">
                    </div>
                    <select id="statusFilter" class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-custom-accent focus:border-custom-accent">
                        <option value="">All Status</option>
                        <option value="paid">Paid Only</option>
                        <option value="unpaid">Unpaid Only</option>
                    </select>
                    <select id="genderFilter" class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-custom-accent focus:border-custom-accent">
                        <option value="">All Genders</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                    <select id="typeFilter" class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-custom-accent focus:border-custom-accent">
                        <option value="">All Types</option>
                        <option value="Fish">Fish</option>
                        <option value="Egg">Egg</option>
                    </select>
                    <select id="batchFilter" class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-custom-accent focus:border-custom-accent">
                        <option value="">All Batches</option>
                        <?php foreach (array_keys($batches) as $batch): ?>
                            <option value="<?php echo htmlspecialchars($batch); ?>">Batch <?php echo htmlspecialchars($batch); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button onclick="clearFilters()" class="text-custom-accent hover:text-blue-700 font-semibold">
                    <i class="fas fa-times mr-1"></i>Clear Filters
                </button>
            </div>
        </div>

        <!-- Students List -->
        <div class="bg-white rounded-2xl shadow-lg animate-fade-in">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-2xl font-bold text-gray-800 flex items-center">
                    <i class="fas fa-list mr-3 text-custom-accent"></i>
                    Students List
                    <span id="studentCount" class="ml-3 text-lg font-normal text-gray-600">(<?php echo $total_students; ?> students)</span>
                </h3>
            </div>

            <?php if (empty($confirmations)): ?>
                <div class="p-12 text-center">
                    <i class="fas fa-users text-6xl text-gray-300 mb-4"></i>
                    <h4 class="text-xl font-semibold text-gray-600 mb-2">No Students Found</h4>
                    <p class="text-gray-500">No students have confirmed for this meal plan yet.</p>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200" id="studentsList">
                    <?php foreach ($confirmations as $index => $confirmation): ?>
                        <div class="student-card p-6 hover:bg-gray-50 transition-colors" 
                             data-name="<?php echo strtolower($confirmation['name']); ?>"
                             data-roll="<?php echo strtolower($confirmation['roll']); ?>"
                             data-status="<?php echo $confirmation['payment_status']; ?>"
                             data-gender="<?php echo $confirmation['gender']; ?>"
                             data-type="<?php echo $confirmation['type']; ?>"
                             data-batch="<?php echo $confirmation['batch']; ?>">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-4">
                                    <div class="w-12 h-12 bg-custom-accent rounded-full flex items-center justify-center text-white font-bold text-lg">
                                        <?php echo strtoupper(substr($confirmation['name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <h4 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($confirmation['name']); ?></h4>
                                        <div class="flex items-center space-x-4 text-sm text-gray-600">
                                            <span><i class="fas fa-id-card mr-1"></i><?php echo htmlspecialchars($confirmation['roll']); ?></span>
                                            <span><i class="fas fa-graduation-cap mr-1"></i>Batch <?php echo htmlspecialchars($confirmation['batch']); ?></span>
                                            <span><i class="fas fa-<?php echo $confirmation['gender'] === 'male' ? 'mars' : 'venus'; ?> mr-1"></i><?php echo ucfirst($confirmation['gender']); ?></span>
                                            <span><i class="fas fa-<?php echo $confirmation['type'] === 'Fish' ? 'fish' : 'egg'; ?> mr-1"></i><?php echo htmlspecialchars($confirmation['type']); ?></span>
                                        </div>
                                        <?php if ($confirmation['phone_number']): ?>
                                            <div class="text-sm text-gray-500 mt-1">
                                                <i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($confirmation['phone_number']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="text-right">
                                    <div class="flex items-center space-x-3 mb-2">
                                        <span class="px-4 py-2 rounded-full text-sm font-semibold <?php echo $confirmation['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <i class="fas fa-<?php echo $confirmation['payment_status'] === 'paid' ? 'check-circle' : 'exclamation-circle'; ?> mr-1"></i>
                                            <?php echo ucfirst($confirmation['payment_status']); ?>
                                        </span>
                                        <?php if ($meal_plan['price']): ?>
                                            <span class="text-lg font-bold text-gray-700">৳<?php echo number_format($meal_plan['price']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($confirmation['transaction_id']): ?>
                                        <div class="text-xs text-gray-500">
                                            <i class="fas fa-receipt mr-1"></i>TXN: <?php echo htmlspecialchars($confirmation['transaction_id']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <i class="fas fa-clock mr-1"></i>
                                        <?php echo date('M d, Y h:i A', strtotime($confirmation['confirmed_at'])); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function goBack() {
            window.location.href = 'manager_dashboard.php';
        }

        function printPage() {
            window.print();
        }

        function exportData() {
            const data = [];
            const students = document.querySelectorAll('.student-card:not([style*="display: none"])');
            
            students.forEach(student => {
                const name = student.querySelector('h4').textContent;
                const roll = student.getAttribute('data-roll').toUpperCase();
                const batch = student.getAttribute('data-batch');
                const gender = student.getAttribute('data-gender');
                const type = student.getAttribute('data-type');
                const status = student.getAttribute('data-status');
                
                data.push([name, roll, batch, gender, type, status]);
            });

            const csvContent = "data:text/csv;charset=utf-8," 
                + "Name,Roll,Batch,Gender,Type,Payment Status\n"
                + data.map(row => row.join(",")).join("\n");

            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "<?php echo $plan_name; ?>_students.csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function filterStudents() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            const genderFilter = document.getElementById('genderFilter').value;
            const typeFilter = document.getElementById('typeFilter').value;
            const batchFilter = document.getElementById('batchFilter').value;
            
            const students = document.querySelectorAll('.student-card');
            let visibleCount = 0;

            students.forEach(student => {
                const name = student.getAttribute('data-name');
                const roll = student.getAttribute('data-roll');
                const status = student.getAttribute('data-status');
                const gender = student.getAttribute('data-gender');
                const type = student.getAttribute('data-type');
                const batch = student.getAttribute('data-batch');

                const matchesSearch = name.includes(searchTerm) || roll.includes(searchTerm);
                const matchesStatus = !statusFilter || status === statusFilter;
                const matchesGender = !genderFilter || gender === genderFilter;
                const matchesType = !typeFilter || type === typeFilter;
                const matchesBatch = !batchFilter || batch === batchFilter;

                if (matchesSearch && matchesStatus && matchesGender && matchesType && matchesBatch) {
                    student.style.display = 'block';
                    visibleCount++;
                } else {
                    student.style.display = 'none';
                }
            });

            document.getElementById('studentCount').textContent = `(${visibleCount} students)`;
        }

        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('genderFilter').value = '';
            document.getElementById('typeFilter').value = '';
            document.getElementById('batchFilter').value = '';
            filterStudents();
        }

        // Add event listeners
        document.getElementById('searchInput').addEventListener('input', filterStudents);
        document.getElementById('statusFilter').addEventListener('change', filterStudents);
        document.getElementById('genderFilter').addEventListener('change', filterStudents);
        document.getElementById('typeFilter').addEventListener('change', filterStudents);
        document.getElementById('batchFilter').addEventListener('change', filterStudents);
    </script>
</body>

</html>