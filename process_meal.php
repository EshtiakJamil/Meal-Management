<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $meal_plan_id = $_POST['meal_plan_id'] ?? '';
    $week_type = $_POST['week_type'] ?? '';
    
    try {
        if ($action === 'confirm') {
            // Get meal plan details
            $stmt = $pdo->prepare("SELECT name, price FROM meal_plans WHERE id = ?");
            $stmt->execute([$meal_plan_id]);
            $meal_plan = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$meal_plan) {
                throw new Exception('Invalid meal plan');
            }
            
            // Calculate week start date
            if ($week_type === 'current') {
                $week_start = date('Y-m-d', strtotime('last saturday'));
                if (date('w') == 6) { // If today is Saturday
                    $week_start = date('Y-m-d');
                }
            } else {
                $week_start = date('Y-m-d', strtotime('next saturday'));
                if (date('w') == 6) { // If today is Saturday
                    $week_start = date('Y-m-d', strtotime('+7 days'));
                }
            }
            
            // Check time restrictions for current week
            if ($week_type === 'current') {
                $current_day = date('w'); // 0 = Sunday, 6 = Saturday
                $current_time = date('H:i');
                
                switch ($meal_plan['name']) {
                    case 'First Half':
                    case 'Full Week':
                        // Must be confirmed before 12:00 AM on Friday of previous week
                        if ($current_day > 5 || ($current_day == 5 && $current_time >= '00:00')) {
                            // If it's Saturday or after Friday midnight, check if we're in the confirmation window
                            $last_friday = date('Y-m-d', strtotime('last friday'));
                            $today = date('Y-m-d');
                            
                            if (strtotime($today) > strtotime($last_friday)) {
                                throw new Exception('Deadline passed for ' . $meal_plan['name'] . '. Must be confirmed before 12:00 AM on Friday of previous week.');
                            }
                        }
                        break;
                        
                    case 'Second Half':
                        // Must be confirmed before 12:00 AM on Monday of current week
                        if ($current_day > 1 || ($current_day == 1 && $current_time >= '00:00')) {
                            throw new Exception('Deadline passed for Second Half. Must be confirmed before 12:00 AM on Monday of current week.');
                        }
                        break;
                        
                    case 'Friday Feast':
                        // Must be confirmed before 12:00 AM on Thursday of current week
                        if ($current_day > 4 || ($current_day == 4 && $current_time >= '00:00')) {
                            throw new Exception('Deadline passed for Friday Feast. Must be confirmed before 12:00 AM on Thursday of current week.');
                        }
                        break;
                }
            }
            
            // Check if Full Week is already confirmed
            if ($meal_plan['name'] !== 'Full Week') {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM meal_confirmations mc
                    JOIN meal_plans mp ON mc.meal_plan_id = mp.id
                    WHERE mc.student_id = ? AND mc.week_start_date = ? AND mp.name = 'Full Week'
                ");
                $stmt->execute([$_SESSION['user_id'], $week_start]);
                
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('You cannot confirm other plans when Full Week is already confirmed.');
                }
            }
            
            // Check if trying to confirm Full Week when other plans exist
            if ($meal_plan['name'] === 'Full Week') {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM meal_confirmations mc
                    JOIN meal_plans mp ON mc.meal_plan_id = mp.id
                    WHERE mc.student_id = ? AND mc.week_start_date = ? AND mp.name != 'Full Week'
                ");
                $stmt->execute([$_SESSION['user_id'], $week_start]);
                
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('You cannot confirm Full Week when other meal plans are already confirmed. Please cancel other plans first.');
                }
            }
            
            // Insert meal confirmation
            $stmt = $pdo->prepare("
                INSERT INTO meal_confirmations (student_id, meal_plan_id, week_start_date, week_type) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$_SESSION['user_id'], $meal_plan_id, $week_start, $week_type]);
            
            $response['success'] = true;
            $response['message'] = $meal_plan['name'] . ' confirmed successfully!';
            
        } elseif ($action === 'cancel') {
            // Cancel meal confirmation
            $confirmation_id = $_POST['confirmation_id'] ?? '';
            
            $stmt = $pdo->prepare("
                DELETE FROM meal_confirmations 
                WHERE id = ? AND student_id = ?
            ");
            $stmt->execute([$confirmation_id, $_SESSION['user_id']]);
            
            if ($stmt->rowCount() > 0) {
                $response['success'] = true;
                $response['message'] = 'Meal plan cancelled successfully!';
            } else {
                throw new Exception('Unable to cancel meal plan');
            }
            
        } elseif ($action === 'payment') {
            // Process payment information
            $phone_number = $_POST['phone_number'] ?? '';
            $transaction_id = $_POST['transaction_id'] ?? '';
            
            if (empty($phone_number) || empty($transaction_id)) {
                throw new Exception('Phone number and transaction ID are required');
            }
            
            // Update all unpaid confirmations for current week
            $current_week_start = date('Y-m-d', strtotime('last saturday'));
            if (date('w') == 6) {
                $current_week_start = date('Y-m-d');
            }
            
            $stmt = $pdo->prepare("
                UPDATE meal_confirmations 
                SET payment_status = 'paid', phone_number = ?, transaction_id = ?, paid_at = NOW()
                WHERE student_id = ? AND week_start_date = ? AND payment_status = 'unpaid'
            ");
            $stmt->execute([$phone_number, $transaction_id, $_SESSION['user_id'], $current_week_start]);
            
            if ($stmt->rowCount() > 0) {
                $response['success'] = true;
                $response['message'] = 'Payment information updated successfully!';
            } else {
                $response['message'] = 'No unpaid meal plans found to update';
            }
        }
        
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
}

// Return JSON response for AJAX requests
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Redirect back to dashboard with message
$message = urlencode($response['message']);
$status = $response['success'] ? 'success' : 'error';
header("Location: dashboard.php?{$status}={$message}");
exit();
?>