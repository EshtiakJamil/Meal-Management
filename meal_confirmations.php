<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meal Confirmations Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --primary-black: #000000;
            --secondary-black: #1a1a1a;
            --light-gray: #f5f5f5;
            --medium-gray: #e5e5e5;
            --dark-gray: #333333;
        }
        
        body {
            background: linear-gradient(135deg, #ffffff 0%, #f8f8f8 100%);
        }
        
        .table-container {
            background: white;
            border: 2px solid var(--primary-black);
            box-shadow: 8px 8px 0px var(--primary-black);
        }
        
        .btn-primary {
            background: var(--primary-black);
            color: white;
            border: 2px solid var(--primary-black);
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: white;
            color: var(--primary-black);
            transform: translateY(-2px);
            box-shadow: 4px 4px 0px var(--primary-black);
        }
        
        .btn-secondary {
            background: white;
            color: var(--primary-black);
            border: 2px solid var(--primary-black);
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            background: var(--primary-black);
            color: white;
            transform: translateY(-2px);
            box-shadow: 4px 4px 0px var(--dark-gray);
        }
        
        .input-field {
            border: 2px solid var(--primary-black);
            background: white;
            transition: all 0.3s ease;
        }
        
        .input-field:focus {
            outline: none;
            box-shadow: 4px 4px 0px var(--primary-black);
            transform: translateY(-1px);
        }
        
        .modal {
            backdrop-filter: blur(8px);
            background: rgba(0, 0, 0, 0.7);
        }
        
        .modal-content {
            background: white;
            border: 3px solid var(--primary-black);
            box-shadow: 12px 12px 0px var(--primary-black);
        }
        
        .status-paid {
            background: var(--primary-black);
            color: white;
        }
        
        .status-unpaid {
            background: white;
            color: var(--primary-black);
            border: 2px solid var(--primary-black);
        }
        
        .table-header {
            background: var(--primary-black);
            color: white;
        }
        
        .table-row:nth-child(even) {
            background: var(--light-gray);
        }
        
        .table-row:hover {
            background: var(--medium-gray);
            transform: translateX(4px);
            transition: all 0.2s ease;
        }
    </style>
</head>
<body class="min-h-screen p-6">
    <?php
    session_start();
    
    require_once 'config.php';
    
    
    // Check if user is logged in and has higher_authority role
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'higher_authority') {
        header('Location: index.php');
        exit();
    }
    
    // Handle CRUD operations
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'delete':
                    $stmt = $pdo->prepare("DELETE FROM meal_confirmations WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    break;
                    
                case 'delete_all':
                    // Build the same query as the display query for deletion
                    $deleteQuery = "DELETE FROM meal_confirmations WHERE 1=1";
                    $deleteParams = [];
                    
                    $single_date_post = $_POST['single_date'] ?? '';
                    $date_from_post = $_POST['date_from'] ?? '';
                    $date_to_post = $_POST['date_to'] ?? '';
                    
                    if ($single_date_post) {
                        $deleteQuery .= " AND DATE(week_start_date) = ?";
                        $deleteParams[] = $single_date_post;
                    } elseif ($date_from_post && $date_to_post) {
                        $deleteQuery .= " AND week_start_date BETWEEN ? AND ?";
                        $deleteParams[] = $date_from_post;
                        $deleteParams[] = $date_to_post;
                    } elseif ($date_from_post) {
                        $deleteQuery .= " AND week_start_date >= ?";
                        $deleteParams[] = $date_from_post;
                    } elseif ($date_to_post) {
                        $deleteQuery .= " AND week_start_date <= ?";
                        $deleteParams[] = $date_to_post;
                    }
                    
                    $stmt = $pdo->prepare($deleteQuery);
                    $stmt->execute($deleteParams);
                    $deletedCount = $stmt->rowCount();
                    
                    // Set success message
                    $deleteMessage = "Successfully deleted $deletedCount meal confirmation(s).";
                    break;
                    
                case 'edit':
                    $stmt = $pdo->prepare("UPDATE meal_confirmations SET 
                        student_id = ?, meal_plan_id = ?, week_start_date = ?, 
                        week_type = ?, payment_status = ?, phone_number = ?, 
                        transaction_id = ?, type = ? WHERE id = ?");
                    $stmt->execute([
                        $_POST['student_id'], $_POST['meal_plan_id'], $_POST['week_start_date'],
                        $_POST['week_type'], $_POST['payment_status'], $_POST['phone_number'],
                        $_POST['transaction_id'], $_POST['type'], $_POST['id']
                    ]);
                    break;
            }
        }
    }
    
    // Get filter parameters
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $single_date = $_GET['single_date'] ?? '';
    
    // Build query with filters
    $query = "SELECT mc.*, s.name as student_name, s.roll, mp.name as meal_plan_name, mp.price 
              FROM meal_confirmations mc 
              JOIN students s ON mc.student_id = s.id 
              JOIN meal_plans mp ON mc.meal_plan_id = mp.id WHERE 1=1";
    
    $params = [];
    
    if ($single_date) {
        $query .= " AND DATE(mc.week_start_date) = ?";
        $params[] = $single_date;
    } elseif ($date_from && $date_to) {
        $query .= " AND mc.week_start_date BETWEEN ? AND ?";
        $params[] = $date_from;
        $params[] = $date_to;
    } elseif ($date_from) {
        $query .= " AND mc.week_start_date >= ?";
        $params[] = $date_from;
    } elseif ($date_to) {
        $query .= " AND mc.week_start_date <= ?";
        $params[] = $date_to;
    }
    
    $query .= " ORDER BY mc.confirmed_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $confirmations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get students and meal plans for dropdowns
    $students = $pdo->query("SELECT id, name, roll FROM students ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $meal_plans = $pdo->query("SELECT id, name, price FROM meal_plans ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <div class="mb-8 text-center">
            <h1 class="text-4xl font-bold text-black mb-2" style="text-shadow: 4px 4px 0px #e5e5e5;">
                Meal Confirmations Management
            </h1>
            <p class="text-gray-600 text-lg">Manage and monitor meal confirmations</p>
        </div>

        <!-- Filters -->
        <div class="table-container p-6 mb-6">
            <h2 class="text-2xl font-bold mb-4">Filter Records</h2>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-bold mb-2">Single Date:</label>
                    <input type="date" name="single_date" value="<?= htmlspecialchars($single_date) ?>" 
                           class="input-field w-full p-3 rounded">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-2">From Date:</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" 
                           class="input-field w-full p-3 rounded">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-2">To Date:</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" 
                           class="input-field w-full p-3 rounded">
                </div>
                <div class="flex items-end space-x-2">
                    <button type="submit" class="btn-primary px-6 py-3 rounded font-bold">
                        Apply Filter
                    </button>
                    <a href="?" class="btn-secondary px-6 py-3 rounded font-bold inline-block">
                        Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- Results Summary -->
        <div class="mb-6 p-4 bg-white border-2 border-black">
            <?php if (isset($deleteMessage)): ?>
            <div class="mb-4 p-3 bg-green-100 border-2 border-green-500 rounded">
                <p class="text-green-700 font-semibold"><?= htmlspecialchars($deleteMessage) ?></p>
            </div>
            <?php endif; ?>
            
            <div class="flex justify-between items-center">
                <p class="text-lg font-semibold">
                    Total Records: <span class="text-2xl font-bold"><?= count($confirmations) ?></span>
                    <?php if ($single_date || $date_from || $date_to): ?>
                        | Filtered Results
                    <?php endif; ?>
                </p>
                
                <?php if (count($confirmations) > 0): ?>
                <div class="flex space-x-3">
                    <?php if ($single_date || $date_from || $date_to): ?>
                    <button onclick="deleteAllFiltered()" 
                            class="bg-red-600 text-white px-6 py-3 rounded font-bold hover:bg-red-700 border-2 border-red-600 transition-all duration-300 hover:transform hover:-translate-y-1 hover:shadow-lg">
                        🗑️ Delete All Filtered (<?= count($confirmations) ?>)
                    </button>
                    <?php endif; ?>
                    
                    <button onclick="deleteAllConfirmations()" 
                            class="bg-red-800 text-white px-6 py-3 rounded font-bold hover:bg-red-900 border-2 border-red-800 transition-all duration-300 hover:transform hover:-translate-y-1 hover:shadow-lg">
                        ⚠️ Delete All Records
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Table -->
        <div class="table-container overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="table-header">
                            <th class="px-4 py-3 text-left font-bold">ID</th>
                            <th class="px-4 py-3 text-left font-bold">Student</th>
                            <th class="px-4 py-3 text-left font-bold">Roll</th>
                            <th class="px-4 py-3 text-left font-bold">Meal Plan</th>
                            <th class="px-4 py-3 text-left font-bold">Price</th>
                            <th class="px-4 py-3 text-left font-bold">Week Start</th>
                            <th class="px-4 py-3 text-left font-bold">Week Type</th>
                            <th class="px-4 py-3 text-left font-bold">Type</th>
                            <th class="px-4 py-3 text-left font-bold">Payment</th>
                            <th class="px-4 py-3 text-left font-bold">Phone</th>
                            <th class="px-4 py-3 text-left font-bold">Transaction ID</th>
                            <th class="px-4 py-3 text-left font-bold">Confirmed At</th>
                            <th class="px-4 py-3 text-left font-bold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($confirmations as $confirmation): ?>
                        <tr class="table-row border-b-2 border-black">
                            <td class="px-4 py-3 font-semibold"><?= $confirmation['id'] ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($confirmation['student_name']) ?></td>
                            <td class="px-4 py-3 font-mono"><?= htmlspecialchars($confirmation['roll']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($confirmation['meal_plan_name']) ?></td>
                            <td class="px-4 py-3 font-bold">৳<?= number_format($confirmation['price'], 2) ?></td>
                            <td class="px-4 py-3"><?= date('M d, Y', strtotime($confirmation['week_start_date'])) ?></td>
                            <td class="px-4 py-3">
                                <span class="px-3 py-1 rounded font-semibold <?= $confirmation['week_type'] === 'current' ? 'bg-black text-white' : 'bg-white text-black border-2 border-black' ?>">
                                    <?= ucfirst($confirmation['week_type']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3"><?= htmlspecialchars($confirmation['type']) ?></td>
                            <td class="px-4 py-3">
                                <span class="px-3 py-1 rounded font-semibold <?= $confirmation['payment_status'] === 'paid' ? 'status-paid' : 'status-unpaid' ?>">
                                    <?= ucfirst($confirmation['payment_status']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3"><?= htmlspecialchars($confirmation['phone_number'] ?? 'N/A') ?></td>
                            <td class="px-4 py-3 font-mono text-sm"><?= htmlspecialchars($confirmation['transaction_id'] ?? 'N/A') ?></td>
                            <td class="px-4 py-3"><?= date('M d, Y H:i', strtotime($confirmation['confirmed_at'])) ?></td>
                            <td class="px-4 py-3">
                                <div class="flex space-x-2">
                                    <button onclick="editRecord(<?= htmlspecialchars(json_encode($confirmation)) ?>)" 
                                            class="btn-secondary px-3 py-1 text-sm rounded font-bold">
                                        Edit
                                    </button>
                                    <button onclick="deleteRecord(<?= $confirmation['id'] ?>)" 
                                            class="bg-red-600 text-white px-3 py-1 text-sm rounded font-bold hover:bg-red-700 border-2 border-red-600">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($confirmations)): ?>
                        <tr>
                            <td colspan="13" class="px-4 py-8 text-center text-gray-500 text-lg">
                                No meal confirmations found for the selected criteria.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal fixed inset-0 z-50 hidden">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="modal-content w-full max-w-2xl p-6 rounded-lg">
                <h2 class="text-2xl font-bold mb-6">Edit Meal Confirmation</h2>
                <form id="editForm" method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="editId">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-bold mb-2">Student:</label>
                            <select name="student_id" id="editStudentId" class="input-field w-full p-3 rounded" required>
                                <?php foreach ($students as $student): ?>
                                <option value="<?= $student['id'] ?>"><?= htmlspecialchars($student['name']) ?> (<?= htmlspecialchars($student['roll']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-bold mb-2">Meal Plan:</label>
                            <select name="meal_plan_id" id="editMealPlanId" class="input-field w-full p-3 rounded" required>
                                <?php foreach ($meal_plans as $plan): ?>
                                <option value="<?= $plan['id'] ?>"><?= htmlspecialchars($plan['name']) ?> - ৳<?= $plan['price'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-bold mb-2">Week Start Date:</label>
                            <input type="date" name="week_start_date" id="editWeekStartDate" class="input-field w-full p-3 rounded" required>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-bold mb-2">Week Type:</label>
                            <select name="week_type" id="editWeekType" class="input-field w-full p-3 rounded" required>
                                <option value="current">Current</option>
                                <option value="next">Next</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-bold mb-2">Payment Status:</label>
                            <select name="payment_status" id="editPaymentStatus" class="input-field w-full p-3 rounded" required>
                                <option value="unpaid">Unpaid</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-bold mb-2">Type:</label>
                            <input type="text" name="type" id="editType" class="input-field w-full p-3 rounded" maxlength="10">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-bold mb-2">Phone Number:</label>
                            <input type="text" name="phone_number" id="editPhoneNumber" class="input-field w-full p-3 rounded" maxlength="20">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-bold mb-2">Transaction ID:</label>
                            <input type="text" name="transaction_id" id="editTransactionId" class="input-field w-full p-3 rounded" maxlength="100">
                        </div>
                    </div>
                    
                    <div class="flex justify-end space-x-4">
                        <button type="button" onclick="closeEditModal()" class="btn-secondary px-6 py-3 rounded font-bold">
                            Cancel
                        </button>
                        <button type="submit" class="btn-primary px-6 py-3 rounded font-bold">
                            Update Record
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function editRecord(record) {
            document.getElementById('editId').value = record.id;
            document.getElementById('editStudentId').value = record.student_id;
            document.getElementById('editMealPlanId').value = record.meal_plan_id;
            document.getElementById('editWeekStartDate').value = record.week_start_date;
            document.getElementById('editWeekType').value = record.week_type;
            document.getElementById('editPaymentStatus').value = record.payment_status;
            document.getElementById('editPhoneNumber').value = record.phone_number || '';
            document.getElementById('editTransactionId').value = record.transaction_id || '';
            document.getElementById('editType').value = record.type || '';
            
            document.getElementById('editModal').classList.remove('hidden');
        }
        
        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }
        
        function deleteRecord(id) {
            if (confirm('Are you sure you want to delete this meal confirmation? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function deleteAllFiltered() {
            const urlParams = new URLSearchParams(window.location.search);
            const singleDate = urlParams.get('single_date') || '';
            const dateFrom = urlParams.get('date_from') || '';
            const dateTo = urlParams.get('date_to') || '';
            
            let filterDescription = '';
            if (singleDate) {
                filterDescription = `for date: ${singleDate}`;
            } else if (dateFrom && dateTo) {
                filterDescription = `from ${dateFrom} to ${dateTo}`;
            } else if (dateFrom) {
                filterDescription = `from ${dateFrom} onwards`;
            } else if (dateTo) {
                filterDescription = `up to ${dateTo}`;
            }
            
            const totalFiltered = <?= count($confirmations) ?>;
            
            if (confirm(`⚠️ DANGER: Are you absolutely sure you want to delete ALL ${totalFiltered} filtered meal confirmation records ${filterDescription}?\n\nThis action CANNOT be undone!\n\nClick OK to proceed with deletion.`)) {
                if (confirm(`🚨 FINAL WARNING: This will permanently delete ${totalFiltered} records. Are you 100% certain?`)) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="delete_all">
                        <input type="hidden" name="single_date" value="${singleDate}">
                        <input type="hidden" name="date_from" value="${dateFrom}">
                        <input type="hidden" name="date_to" value="${dateTo}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            }
        }
        
        function deleteAllConfirmations() {
            if (confirm('⚠️ EXTREME DANGER: Are you absolutely sure you want to delete ALL meal confirmation records from the entire database?\n\nThis will delete EVERYTHING and CANNOT be undone!\n\nClick OK only if you are 100% certain.')) {
                if (confirm('🚨 FINAL WARNING: This will permanently delete ALL records in the meal_confirmations table. Type "DELETE ALL" in the next prompt to continue.')) {
                    const confirmation = prompt('Type "DELETE ALL" to confirm this destructive action:');
                    if (confirmation === 'DELETE ALL') {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.innerHTML = `<input type="hidden" name="action" value="delete_all">`;
                        document.body.appendChild(form);
                        form.submit();
                    } else {
                        alert('Deletion cancelled. Records are safe.');
                    }
                }
            }
        }
        
        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
        
        // Add some smooth animations
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('.table-row');
            rows.forEach((row, index) => {
                row.style.opacity = '0';
                row.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    row.style.transition = 'all 0.5s ease';
                    row.style.opacity = '1';
                    row.style.transform = 'translateY(0)';
                }, index * 50);
            });
        });
    </script>
</body>
</html>