<?php
require_once 'config.php';

// Check if monitor can add students setting is enabled
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'monitor_can_add_students'");
    $stmt->execute();
    $monitorCanAddStudents = $stmt->fetchColumn() === 'true';
    
    if (!$monitorCanAddStudents) {
        // If disabled, redirect to login or show error
        header('HTTP/1.1 403 Forbidden');
        exit('<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - Hostel Meal Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-lg shadow-lg max-w-md w-full mx-4">
        <div class="text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4">
                <svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 18.5c-.77.833.192 2.5 1.732 2.5z" />
                </svg>
            </div>
            <h2 class="text-xl font-bold text-gray-900 mb-2">Access Denied</h2>
            <p class="text-gray-600 mb-6">Student registration is currently disabled by the higher authority.</p>
            <a href="index.php" class="bg-black text-white px-4 py-2 rounded hover:bg-gray-800 transition duration-200">
                Go to Login
            </a>
        </div>
    </div>
</body>
</html>');
    }
} catch (PDOException $e) {
    // If there's an error checking the setting, deny access
    header('HTTP/1.1 500 Internal Server Error');
    exit('Internal Server Error');
}

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $roll = trim($_POST['roll']);
    $gender = $_POST['gender'];
    $batch = trim($_POST['batch']);
    $password = trim($_POST['password']);
    $confirmPassword = trim($_POST['confirm_password']);
    
    // Validation
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Name is required.";
    }
    
    if (empty($roll)) {
        $errors[] = "Roll number is required.";
    }
    
    if (empty($gender) || !in_array($gender, ['male', 'female'])) {
        $errors[] = "Please select a valid gender.";
    }
    
    if (empty($batch)) {
        $errors[] = "Batch is required.";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }
    
    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }
    
    if (empty($errors)) {
        try {
            // Check if roll number already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE roll = ?");
            $stmt->execute([$roll]);
            $rollExists = $stmt->fetchColumn() > 0;
            
            if ($rollExists) {
                $message = "Roll number already exists. Please use a different roll number.";
                $messageType = 'error';
            } else {
                // Hash password and insert student
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO students (name, roll, gender, batch, password) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $roll, $gender, $batch, $hashedPassword]);
                
                $message = "Registration successful! You can now login with your roll number and password.";
                $messageType = 'success';
                
                // Clear form data after successful submission
                $name = $roll = $gender = $batch = '';
            }
        } catch (PDOException $e) {
            $message = "Registration failed. Please try again.";
            $messageType = 'error';
        }
    } else {
        $message = implode(' ', $errors);
        $messageType = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - Hostel Meal Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Header -->
    <header class="bg-black text-white shadow-lg">
        <div class="container mx-auto px-4 py-4">
            <div class="flex justify-between items-center">
                <h1 class="text-xl font-bold">Student Registration</h1>
                <a href="index.php" class="bg-gray-700 hover:bg-gray-600 px-4 py-2 rounded transition duration-200">
                    Login
                </a>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-4 py-8">
        <div class="max-w-md mx-auto bg-white rounded-lg shadow-lg p-6">
            <div class="text-center mb-6">
                <h2 class="text-2xl font-bold text-gray-900">Register as Student</h2>
                <p class="text-gray-600 mt-2">Create your account to access the hostel meal system</p>
            </div>

            <?php if ($message): ?>
                <div class="mb-6 p-4 rounded-md <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700 border border-green-400' : 'bg-red-100 text-red-700 border border-red-400'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Full Name</label>
                    <input 
                        type="text" 
                        id="name" 
                        name="name" 
                        value="<?php echo htmlspecialchars($name ?? ''); ?>"
                        required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-black focus:border-black"
                        placeholder="Enter your full name"
                    >
                </div>

                <div>
                    <label for="roll" class="block text-sm font-medium text-gray-700 mb-2">Roll Number</label>
                    <input 
                        type="text" 
                        id="roll" 
                        name="roll" 
                        value="<?php echo htmlspecialchars($roll ?? ''); ?>"
                        required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-black focus:border-black"
                        placeholder="Enter your roll number"
                    >
                </div>

                <div>
                    <label for="gender" class="block text-sm font-medium text-gray-700 mb-2">Gender</label>
                    <select 
                        id="gender" 
                        name="gender" 
                        required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-black focus:border-black"
                    >
                        <option value="">Select Gender</option>
                        <option value="male" <?php echo (isset($gender) && $gender === 'male') ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo (isset($gender) && $gender === 'female') ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>

                <div>
                    <label for="batch" class="block text-sm font-medium text-gray-700 mb-2">Batch</label>
                    <input 
                        type="text" 
                        id="batch" 
                        name="batch" 
                        value="<?php echo htmlspecialchars($batch ?? ''); ?>"
                        required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-black focus:border-black"
                        placeholder="Enter your batch (e.g., 2024)"
                    >
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        required
                        minlength="6"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-black focus:border-black"
                        placeholder="Enter password (minimum 6 characters)"
                    >
                </div>

                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
                    <input 
                        type="password" 
                        id="confirm_password" 
                        name="confirm_password" 
                        required
                        minlength="6"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-black focus:border-black"
                        placeholder="Confirm your password"
                    >
                </div>

                <button 
                    type="submit" 
                    class="w-full bg-black text-white py-2 px-4 rounded-md hover:bg-gray-800 transition duration-200 font-medium"
                >
                    Register
                </button>
            </form>

            <div class="mt-6 text-center">
                <p class="text-sm text-gray-600">
                    Already have an account? 
                    <a href="index.php" class="text-black hover:underline font-medium">Login here</a>
                </p>
            </div>
        </div>
    </div>

    <script>
        // Client-side password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (password !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Also check when password field changes
        document.getElementById('password').addEventListener('input', function() {
            const confirmPassword = document.getElementById('confirm_password').value;
            const confirmPasswordField = document.getElementById('confirm_password');
            
            if (confirmPassword && this.value !== confirmPassword) {
                confirmPasswordField.setCustomValidity('Passwords do not match');
            } else {
                confirmPasswordField.setCustomValidity('');
            }
        });
    </script>
</body>
</html>