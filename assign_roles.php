<?php
session_start();
require_once 'config.php';

// Check if user is higher authority
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'higher_authority') {
    header('Location: index.php');
    exit();
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        if ($action === 'assign_managers') {
            $manager_ids = $_POST['manager_ids'] ?? [];
            
            if (count($manager_ids) !== 2) {
                throw new Exception('Exactly 2 managers must be selected');
            }
            
            // Remove existing managers
            $stmt = $pdo->prepare("UPDATE students SET role = 'student' WHERE role = 'manager'");
            $stmt->execute();
            
            // Assign new managers
            $placeholders = str_repeat('?,', count($manager_ids) - 1) . '?';
            $stmt = $pdo->prepare("UPDATE students SET role = 'manager' WHERE id IN ($placeholders)");
            $stmt->execute($manager_ids);
            
            $response['success'] = true;
            $response['message'] = 'Managers assigned successfully!';
            
        } elseif ($action === 'assign_monitors') {
            $male_monitor_ids = $_POST['male_monitor_ids'] ?? [];
            $female_monitor_ids = $_POST['female_monitor_ids'] ?? [];
            
            if (count($male_monitor_ids) !== 2) {
                throw new Exception('Exactly 2 male monitors must be selected');
            }
            
            if (count($female_monitor_ids) !== 2) {
                throw new Exception('Exactly 2 female monitors must be selected');
            }
            
            // Remove existing monitors
            $stmt = $pdo->prepare("UPDATE students SET role = 'student' WHERE role = 'monitor'");
            $stmt->execute();
            
            // Assign new male monitors
            $all_monitor_ids = array_merge($male_monitor_ids, $female_monitor_ids);
            $placeholders = str_repeat('?,', count($all_monitor_ids) - 1) . '?';
            $stmt = $pdo->prepare("UPDATE students SET role = 'monitor' WHERE id IN ($placeholders)");
            $stmt->execute($all_monitor_ids);
            
            $response['success'] = true;
            $response['message'] = 'Monitors assigned successfully!';
            
        } elseif ($action === 'remove_role') {
            $user_id = $_POST['user_id'] ?? '';
            
            // Cannot remove higher authority role
            $stmt = $pdo->prepare("SELECT role FROM students WHERE id = ?");
            $stmt->execute([$user_id]);
            $current_role = $stmt->fetchColumn();
            
            if ($current_role === 'higher_authority') {
                throw new Exception('Cannot remove higher authority role');
            }
            
            $stmt = $pdo->prepare("UPDATE students SET role = 'student' WHERE id = ?");
            $stmt->execute([$user_id]);
            
            // Remove permissions if any
            $stmt = $pdo->prepare("DELETE FROM permissions WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            $response['success'] = true;
            $response['message'] = 'Role removed successfully!';
            
        } elseif ($action === 'change_role') {
            $user_id = $_POST['user_id'] ?? '';
            $new_role = $_POST['new_role'] ?? '';
            
            $valid_roles = ['student', 'monitor', 'manager'];
            if (!in_array($new_role, $valid_roles)) {
                throw new Exception('Invalid role specified');
            }
            
            // Check role limits
            if ($new_role === 'manager') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE role = 'manager'");
                $stmt->execute();
                if ($stmt->fetchColumn() >= 2) {
                    throw new Exception('Maximum 2 managers allowed');
                }
            } elseif ($new_role === 'monitor') {
                // Get user gender
                $stmt = $pdo->prepare("SELECT gender FROM students WHERE id = ?");
                $stmt->execute([$user_id]);
                $gender = $stmt->fetchColumn();
                
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE role = 'monitor' AND gender = ?");
                $stmt->execute([$gender]);
                if ($stmt->fetchColumn() >= 2) {
                    throw new Exception("Maximum 2 {$gender} monitors allowed");
                }
            }
            
            $stmt = $pdo->prepare("UPDATE students SET role = ? WHERE id = ?");
            $stmt->execute([$new_role, $user_id]);
            
            $response['success'] = true;
            $response['message'] = 'Role changed successfully!';
            
        } elseif ($action === 'grant_permission') {
            $user_id = $_POST['user_id'] ?? '';
            $permission_type = $_POST['permission_type'] ?? '';
            
            // Check if user is a monitor
            $stmt = $pdo->prepare("SELECT role FROM students WHERE id = ?");
            $stmt->execute([$user_id]);
            $role = $stmt->fetchColumn();
            
            if ($role !== 'monitor') {
                throw new Exception('Only monitors can be granted permissions');
            }
            
            // Check if permission already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM permissions WHERE user_id = ? AND permission_type = ?");
            $stmt->execute([$user_id, $permission_type]);
            
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('Permission already granted');
            }
            
            $stmt = $pdo->prepare("INSERT INTO permissions (user_id, permission_type, granted_by) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $permission_type, $_SESSION['user_id']]);
            
            $response['success'] = true;
            $response['message'] = 'Permission granted successfully!';
            
        } elseif ($action === 'revoke_permission') {
            $user_id = $_POST['user_id'] ?? '';
            $permission_type = $_POST['permission_type'] ?? '';
            
            $stmt = $pdo->prepare("DELETE FROM permissions WHERE user_id = ? AND permission_type = ?");
            $stmt->execute([$user_id, $permission_type]);
            
            $response['success'] = true;
            $response['message'] = 'Permission revoked successfully!';
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollback();
        $response['message'] = $e->getMessage();
    }
}

// Return JSON response for AJAX requests
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Redirect back to higher authority dashboard with message
$message = urlencode($response['message']);
$status = $response['success'] ? 'success' : 'error';
header("Location: higher_authority_dashboard.php?{$status}={$message}");
exit();
?>