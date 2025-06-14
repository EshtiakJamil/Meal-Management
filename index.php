<?php
require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $roll = trim($_POST['roll']);
    $password = $_POST['password'];
    
    if (empty($roll) || empty($password)) {
        $error = 'Please fill in all fields';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, name, roll, gender, batch, password, role FROM students WHERE roll = ?");
            $stmt->execute([$roll]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['roll'] = $user['roll'];
                $_SESSION['gender'] = $user['gender'];
                $_SESSION['batch'] = $user['batch'];
                $_SESSION['role'] = $user['role'];
                
                // Redirect based on role
                switch ($user['role']) {
                    case 'higher_authority':
                        redirect('higher_authority_dashboard.php');
                        break;
                    case 'manager':
                        redirect('manager_dashboard.php');
                        break;
                    case 'monitor':
                        redirect('monitor_dashboard.php');
                        break;
                    default:
                        redirect('dashboard.php');
                }
            } else {
                $error = 'Invalid roll number or password';
            }
        } catch (PDOException $e) {
            $error = 'Login failed. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hostel Meal Management - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background: linear-gradient(135deg, #000000 0%, #1a1a1a 100%);
        }
        
        /* Custom responsive styles */
        @media (max-width: 640px) {
            .login-container {
                margin: 1rem;
                padding: 1.5rem;
            }
        }
        
        /* Developer button hover effect */
        .dev-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(56, 189, 248, 0.3);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-2xl login-container w-full max-w-md sm:max-w-lg md:max-w-md p-6 sm:p-8">
        <div class="text-center mb-6 sm:mb-8">
            <h1 class="text-2xl sm:text-3xl font-bold text-black mb-2">Meal Management System</h1>
            <p class="text-gray-600 text-sm sm:text-base">Noakhali Medical College</p>
        </div>
        
        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-3 py-2 sm:px-4 sm:py-3 rounded mb-4 text-sm sm:text-base">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="space-y-4 sm:space-y-6">
            <div>
                <label for="roll" class="block text-sm font-medium text-gray-700 mb-2">Roll Number</label>
                <input 
                    type="text" 
                    id="roll" 
                    name="roll" 
                    required
                    class="w-full px-3 py-2 sm:py-3 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-black focus:border-black text-sm sm:text-base"
                    placeholder="Enter your roll number"
                >
            </div>
            
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    required
                    class="w-full px-3 py-2 sm:py-3 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-black focus:border-black text-sm sm:text-base"
                    placeholder="Enter your password"
                >
            </div>
            
            <button 
                type="submit" 
                class="w-full bg-black text-white py-2 sm:py-3 px-4 rounded-md hover:bg-gray-800 transition duration-200 font-medium text-sm sm:text-base"
            >
                Login
            </button>
            
        </form>
        
        <div class="mt-4 sm:mt-6 text-center">
            <p class="text-xs sm:text-sm text-gray-600 px-2">
                Login with your roll number (eg. 1507: 15 = batch & 07 = roll) and password
            </p>
            <div class="mt-4 space-y-2">
                <div>
                    <a href="add_student.php" class="text-green-600 hover:text-green-700 text-xs underline transition duration-200">
                        Create Account
                    </a>
                </div>
                <div>
                    <a href="developer.html" class="text-sky-400 hover:text-sky-500 text-xs underline transition duration-200">
                        developer
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Add touch feedback for mobile devices
        if ('ontouchstart' in window) {
            document.querySelectorAll('button').forEach(button => {
                button.addEventListener('touchstart', function() {
                    this.style.transform = 'scale(0.98)';
                });
                button.addEventListener('touchend', function() {
                    this.style.transform = 'scale(1)';
                });
            });
        }
    </script>
</body>
</html>