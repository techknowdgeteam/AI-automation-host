<?php
// users.php - User Interface for SceneIQ
session_start();

// Database configuration
$host = 'sql201.infinityfree.com';
$dbname = 'if0_40367004_automation_tree';
$username = 'if0_40367004';
$password = 'NkwFAH15FRIlvCf';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['sceneiq_user_id']) && isset($_SESSION['sceneiq_user_email']);

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($email && $password) {
        $stmt = $pdo->prepare("SELECT id, email, username, password FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['sceneiq_user_id'] = $user['id'];
            $_SESSION['sceneiq_user_email'] = $user['email'];
            $_SESSION['sceneiq_username'] = $user['username'];
            header('Location: users.php');
            exit;
        } else {
            $_SESSION['login_error'] = 'Invalid email or password.';
            header('Location: users.php');
            exit;
        }
    }
}

// Handle Logout
if (isset($_POST['action']) && $_POST['action'] === 'logout') {
    session_destroy();
    header('Location: users.php');
    exit;
}

// Handle Check Auth
if (isset($_GET['action']) && $_GET['action'] === 'check_auth') {
    $authenticated = isset($_SESSION['sceneiq_user_id']);
    echo json_encode(['authenticated' => $authenticated]);
    exit;
}

// Get user's dynamic fields
function getUserDynamicFields($pdo, $userId) {
    // First try to get user-specific config
    $stmt = $pdo->prepare("SELECT dynamic_fields FROM sceneiq_config WHERE user_id = :user_id ORDER BY id DESC LIMIT 1");
    $stmt->execute([':user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row && $row['dynamic_fields']) {
        $fields = json_decode($row['dynamic_fields'], true);
        if (is_array($fields) && !empty($fields)) {
            return $fields;
        }
    }
    
    // Fall back to global config
    $stmt = $pdo->prepare("SELECT dynamic_fields FROM sceneiq_config WHERE user_id = 0 ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row && $row['dynamic_fields']) {
        return json_decode($row['dynamic_fields'], true) ?: [];
    }
    
    return [];
}

// Get user's settings
function getUserSettings($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT settings FROM sceneiq_config WHERE user_id = :user_id ORDER BY id DESC LIMIT 1");
    $stmt->execute([':user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row && $row['settings']) {
        return json_decode($row['settings'], true) ?: [];
    }
    return [];
}

// Save user settings
function saveUserSettings($pdo, $userId, $settings) {
    $stmt = $pdo->prepare("SELECT id FROM sceneiq_config WHERE user_id = :user_id ORDER BY id DESC LIMIT 1");
    $stmt->execute([':user_id' => $userId]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($config) {
        $stmt = $pdo->prepare("UPDATE sceneiq_config SET settings = :settings WHERE id = :id");
        return $stmt->execute([
            ':settings' => json_encode($settings),
            ':id' => $config['id']
        ]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO sceneiq_config (user_id, settings) VALUES (:user_id, :settings)");
        return $stmt->execute([
            ':user_id' => $userId,
            ':settings' => json_encode($settings)
        ]);
    }
}

// If logged in, handle POST requests with PRG pattern
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = $_SESSION['sceneiq_user_id'];
    $redirect = false;
    
    // Handle Save config
    if (isset($_POST['save_config'])) {
        try {
            $configData = isset($_POST['config_data']) ? json_decode($_POST['config_data'], true) : [];
            
            if (!is_array($configData) || empty($configData)) {
                $_SESSION['flash_error'] = 'No config data provided.';
            } else {
                // Get current settings
                $settings = getUserSettings($pdo, $userId);
                if (!is_array($settings)) $settings = [];
                
                // Add new config with status
                $config = [
                    'dynamic_values' => $configData,
                    'status' => 'pending',
                    'operation_status' => 'config_created: New config submitted'
                ];
                
                $settings[] = $config;
                
                if (saveUserSettings($pdo, $userId, $settings)) {
                    $_SESSION['flash_success'] = 'config saved successfully!';
                } else {
                    $_SESSION['flash_error'] = 'Failed to save config.';
                }
            }
            $redirect = true;
        } catch (Exception $e) {
            $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
            $redirect = true;
        }
    }
    
    // Handle Delete config
    if (isset($_POST['delete_config'])) {
        try {
            $index = isset($_POST['index']) ? intval($_POST['index']) : -1;
            
            if ($index < 0) {
                $_SESSION['flash_error'] = 'Invalid request.';
            } else {
                $settings = getUserSettings($pdo, $userId);
                if (!is_array($settings) || $index >= count($settings)) {
                    $_SESSION['flash_error'] = 'config not found.';
                } else {
                    array_splice($settings, $index, 1);
                    
                    if (saveUserSettings($pdo, $userId, $settings)) {
                        $_SESSION['flash_success'] = 'config deleted successfully!';
                    } else {
                        $_SESSION['flash_error'] = 'Failed to delete config.';
                    }
                }
            }
            $redirect = true;
        } catch (Exception $e) {
            $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
            $redirect = true;
        }
    }
    
    if ($redirect) {
        header('Location: users.php');
        exit;
    }
}

// Get flash messages
$flashSuccess = isset($_SESSION['flash_success']) ? $_SESSION['flash_success'] : null;
$flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Get login error
$loginError = isset($_SESSION['login_error']) ? $_SESSION['login_error'] : null;
unset($_SESSION['login_error']);

// If logged in, get data
$dynamicFields = [];
$settings = [];
$groupedEntries = ['pending' => [], 'completed' => [], 'aborted' => []];
$statusLabels = ['pending' => 'Pending', 'completed' => 'Completed', 'aborted' => 'Aborted'];
$statusColors = ['pending' => '#f6ad55', 'completed' => '#48bb78', 'aborted' => '#fc8181'];
$allEntries = [];

if ($isLoggedIn) {
    $userId = $_SESSION['sceneiq_user_id'];
    $dynamicFields = getUserDynamicFields($pdo, $userId);
    $settings = getUserSettings($pdo, $userId);
    
    if (!is_array($settings)) $settings = [];
    
    foreach ($settings as $index => $config) {
        $status = isset($config['status']) ? strtolower($config['status']) : 'pending';
        if (!isset($groupedEntries[$status])) {
            $groupedEntries[$status] = [];
        }
        $config['_index'] = $index;
        $groupedEntries[$status][] = $config;
        $allEntries[] = $config;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>SceneIQ - User Interface</title>
<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    html, body {
        height: 100%;
        overflow: hidden;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: #f8fafc;
    }
    
    input, select, textarea {
        font-size: 16px !important;
    }
    
    .custom-body {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        overflow: hidden;
        background: #f8fafc;
        display: flex;
        flex-direction: column;
    }
    
    .main-header {
        flex-shrink: 0;
        z-index: 1000;
        background: linear-gradient(135deg, #00695c 0%, #00897b 50%, #26a69a 100%);
        color: white;
        padding: 15px 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: 0 4px 20px rgba(0,105,92,0.3);
        height: 65px;
    }
    
    .main-header h1 {
        font-size: 22px;
        font-weight: 600;
        text-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .main-header .header-actions {
        display: flex;
        gap: 10px;
        align-items: center;
    }
    
    .main-header .user-info {
        font-size: 14px;
        opacity: 0.9;
        margin-right: 10px;
    }
    
    .btn-logout {
        padding: 8px 20px;
        background: rgba(255,255,255,0.2);
        border: 1px solid rgba(255,255,255,0.3);
        color: white;
        border-radius: 8px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.3s;
        backdrop-filter: blur(5px);
    }
    
    .btn-logout:hover {
        background: rgba(255,255,255,0.3);
        transform: translateY(-2px);
    }
    
    /* Tab Navigation - FIXED HORIZONTAL SCROLL */
    .tab-navigation {
        flex-shrink: 0;
        background: white;
        border-bottom: 2px solid #e5e7eb;
        padding: 0 20px;
        display: flex;
        gap: 0;
        overflow-x: auto;
        overflow-y: hidden;
        position: sticky;
        top: 0;
        z-index: 100;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        white-space: nowrap;
        scrollbar-width: thin;
        -webkit-overflow-scrolling: touch;
        min-height: 52px;
        align-items: stretch;
    }
    
    .tab-navigation::-webkit-scrollbar {
        height: 3px;
    }
    
    .tab-navigation::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }
    
    .tab-navigation::-webkit-scrollbar-thumb {
        background: #00695c;
        border-radius: 10px;
    }
    
    .tab-navigation::-webkit-scrollbar-thumb:hover {
        background: #004d40;
    }
    
    .tab-btn {
        padding: 14px 28px;
        background: transparent;
        border: none;
        font-size: 15px;
        font-weight: 600;
        color: #6b7280;
        cursor: pointer;
        transition: all 0.3s;
        position: relative;
        white-space: nowrap;
        flex-shrink: 0;
        min-height: 52px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .tab-btn:hover {
        color: #1a1a2e;
        background: #f3f4f6;
    }
    
    .tab-btn.active {
        color: #00695c;
    }
    
    .tab-btn.active::after {
        content: '';
        position: absolute;
        bottom: -2px;
        left: 20px;
        right: 20px;
        height: 3px;
        background: linear-gradient(135deg, #00695c, #26a69a);
        border-radius: 3px 3px 0 0;
    }
    
    .tab-btn .badge {
        display: inline-block;
        background: #e5e7eb;
        color: #4b5563;
        padding: 1px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        margin-left: 4px;
        flex-shrink: 0;
    }
    
    .tab-btn.active .badge {
        background: #00695c;
        color: white;
    }
    
    .tab-btn .status-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        margin-right: 4px;
        flex-shrink: 0;
    }
    
    .scroll-body {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 20px;
        padding-bottom: 100px;
        scroll-behavior: smooth;
        background: #f8fafc;
    }
    
    .scroll-body::-webkit-scrollbar {
        width: 8px;
    }
    
    .scroll-body::-webkit-scrollbar-track {
        background: rgba(0,0,0,0.02);
        border-radius: 10px;
    }
    
    .scroll-body::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, #00695c, #26a69a);
        border-radius: 10px;
    }
    
    .scroll-body::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(135deg, #004d40, #00897b);
    }
    
    /* Login Styles */
    .login-container {
        max-width: 420px;
        margin: 60px auto;
        padding: 40px 35px;
        background: #ffffff;
        border-radius: 20px;
        box-shadow: 0 8px 40px rgba(0,0,0,0.08);
        border: 1px solid rgba(0,0,0,0.06);
    }
    
    .login-container .login-title {
        font-size: 28px;
        font-weight: 700;
        color: #1a1a2e;
        text-align: center;
        margin-bottom: 8px;
    }
    
    .login-container .login-subtitle {
        font-size: 14px;
        color: #6b7280;
        text-align: center;
        margin-bottom: 30px;
    }
    
    .login-container .login-icon {
        text-align: center;
        font-size: 48px;
        margin-bottom: 15px;
    }
    
    .login-container .form-group {
        margin-bottom: 20px;
    }
    
    .login-container .form-group label {
        display: block;
        margin-bottom: 6px;
        font-weight: 500;
        color: #1a1a2e;
        font-size: 14px;
    }
    
    .login-container .form-group input {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid #e5e7eb;
        border-radius: 10px;
        font-size: 14px;
        transition: all 0.3s;
        background: #fafafa;
    }
    
    .login-container .form-group input:focus {
        border-color: #00695c;
        outline: none;
        box-shadow: 0 0 0 4px rgba(0,105,92,0.1);
        background: white;
    }
    
    .login-container .btn-login {
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, #00695c, #26a69a);
        color: white;
        border: none;
        border-radius: 10px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 4px 20px rgba(0,105,92,0.3);
    }
    
    .login-container .btn-login:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 30px rgba(0,105,92,0.4);
    }
    
    .login-container .error-message {
        background: #fef2f2;
        color: #dc2626;
        padding: 10px 14px;
        border-radius: 8px;
        font-size: 14px;
        margin-bottom: 15px;
        border: 1px solid #fca5a5;
    }
    
    /* Content Areas */
    .tab-content {
        display: none;
    }
    
    .tab-content.active {
        display: block;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6b7280;
    }
    
    .empty-state .icon {
        font-size: 48px;
        margin-bottom: 15px;
    }
    
    .empty-state h3 {
        font-size: 20px;
        color: #1a1a2e;
        margin-bottom: 8px;
    }
    
    /* Config List Items */
    .config-list {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }
    
    .config-item {
        background: #ffffff;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        border-left: 5px solid #00695c;
        transition: all 0.3s;
        border: 1px solid rgba(0,0,0,0.04);
    }
    
    .config-item:hover {
        box-shadow: 0 6px 24px rgba(0,0,0,0.08);
        transform: translateX(4px);
    }
    
    .config-item .item-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .config-item .item-id {
        font-size: 14px;
        font-weight: 600;
        color: #00695c;
    }
    
    .config-item .item-details {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 10px 20px;
        margin-top: 10px;
    }
    
    .config-item .item-details .detail-label {
        font-size: 12px;
        color: #6b7280;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
    }
    
    .config-item .item-details .detail-value {
        font-size: 14px;
        color: #1a1a2e;
        word-break: break-word;
        overflow-wrap: break-word;
        max-width: 100%;
        line-height: 1.5;
        max-height: 120px;
        overflow-y: auto;
        padding-right: 5px;
    }
    
    .config-item .item-actions {
        display: flex;
        gap: 10px;
        margin-top: 15px;
        flex-wrap: wrap;
    }
    
    .config-item .item-actions .btn {
        padding: 8px 18px;
        font-size: 13px;
    }
    
    .status-badge {
        padding: 4px 14px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        color: white;
    }
    
    /* Floating Action Button */
    .fab {
        position: fixed;
        bottom: 30px;
        right: 30px;
        z-index: 999;
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: linear-gradient(135deg, #00695c, #26a69a);
        color: white;
        border: none;
        font-size: 32px;
        font-weight: 300;
        cursor: pointer;
        box-shadow: 0 6px 24px rgba(0,105,92,0.4);
        transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .fab:hover {
        transform: scale(1.1) rotate(90deg);
        box-shadow: 0 8px 32px rgba(0,105,92,0.5);
    }
    
    .fab:active {
        transform: scale(0.95);
    }
    
    /* Add Config View */
    #addconfigView {
        display: none;
        max-width: 900px;
        margin: 0 auto;
    }
    
    #addconfigView.active {
        display: block;
    }
    
    .detail-header {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-bottom: 30px;
        flex-wrap: wrap;
    }
    
    .btn-back {
        padding: 10px 25px;
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        font-size: 16px;
        font-weight: 500;
        color: #1a1a2e;
        cursor: pointer;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn-back:hover {
        background: #f3f4f6;
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        transform: translateX(-3px);
    }
    
    .detail-title {
        font-size: 24px;
        font-weight: 700;
        color: #1a1a2e;
    }
    
    .detail-title .count {
        font-size: 16px;
        font-weight: 400;
        color: #6b7280;
        margin-left: 10px;
    }
    
    .form-container {
        background: #ffffff;
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        border: 1px solid rgba(0,0,0,0.05);
    }
    
    .form-group {
        margin-bottom: 24px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #1a1a2e;
        font-size: 15px;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid #e5e7eb;
        border-radius: 10px;
        font-size: 14px;
        transition: all 0.3s;
        background: #fafafa;
        box-sizing: border-box;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        border-color: #00695c;
        outline: none;
        box-shadow: 0 0 0 4px rgba(0,105,92,0.1);
        background: white;
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 80px;
        font-family: inherit;
    }
    
    /* Object Field - Redesigned */
    .object-field-container {
        background: #f8fafc;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        border: 1px solid rgba(0,0,0,0.04);
    }
    
    .object-field-container .object-title {
        color: #00695c;
        margin-bottom: 20px;
        font-size: 17px;
        font-weight: 600;
        padding-bottom: 12px;
        border-bottom: 2px solid rgba(0,105,92,0.1);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .sub-field-group {
        background: #ffffff;
        border-radius: 12px;
        padding: 18px 20px;
        margin-bottom: 16px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        border: 1px solid rgba(0,0,0,0.04);
    }
    
    .sub-field-group:last-child {
        margin-bottom: 0;
    }
    
    .sub-field-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: #1a1a2e;
        font-size: 14px;
    }
    
    .sub-field-group input,
    .sub-field-group select,
    .sub-field-group textarea {
        width: 100%;
        padding: 10px 14px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s;
        background: #fafafa;
        box-sizing: border-box;
    }
    
    .sub-field-group input:focus,
    .sub-field-group select:focus,
    .sub-field-group textarea:focus {
        border-color: #00695c;
        outline: none;
        box-shadow: 0 0 0 4px rgba(0,105,92,0.08);
        background: white;
    }
    
    .sub-field-group .datetime-group {
        width: 100%;
    }
    
    .sub-field-group .datetime-group .date-input {
        width: 100%;
        padding: 10px 14px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        font-size: 14px;
        background: #fafafa;
        box-sizing: border-box;
    }
    
    .sub-field-group .datetime-group .date-input:focus {
        border-color: #00695c;
        outline: none;
        box-shadow: 0 0 0 4px rgba(0,105,92,0.08);
        background: white;
    }
    
    .sub-field-group .datetime-group .time-group {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
        margin-top: 8px;
    }
    
    .sub-field-group .datetime-group .time-group input[type="number"],
    .sub-field-group .datetime-group .time-group select {
        padding: 8px 12px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        font-size: 14px;
        background: #fafafa;
    }
    
    .sub-field-group .datetime-group .time-group input[type="number"]:focus,
    .sub-field-group .datetime-group .time-group select:focus {
        border-color: #00695c;
        outline: none;
    }
    
    .btn {
        padding: 10px 25px;
        border: none;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #00695c, #26a69a);
        color: white;
        box-shadow: 0 4px 20px rgba(0,105,92,0.25);
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 30px rgba(0,105,92,0.35);
    }
    
    .btn-success {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        box-shadow: 0 4px 20px rgba(16,185,129,0.25);
    }
    
    .btn-success:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 30px rgba(16,185,129,0.35);
    }
    
    .btn-danger {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: white;
        box-shadow: 0 4px 20px rgba(239,68,68,0.2);
    }
    
    .btn-danger:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 30px rgba(239,68,68,0.3);
    }
    
    .btn-secondary {
        background: #f3f4f6;
        color: #1a1a2e;
        border: 1px solid #e5e7eb;
    }
    
    .btn-secondary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    
    .form-actions {
        display: flex;
        gap: 12px;
        margin-top: 24px;
        flex-wrap: wrap;
    }
    
    .form-actions .btn {
        padding: 12px 30px;
    }
    
    .flash-message {
        padding: 12px 18px;
        border-radius: 10px;
        margin-bottom: 15px;
        font-size: 14px;
    }
    
    .flash-message.success {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #a7f3d0;
    }
    
    .flash-message.error {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fca5a5;
    }
    
    .no-fields-message {
        text-align: center;
        padding: 40px;
        color: #6b7280;
    }
    
    .no-fields-message .icon {
        font-size: 48px;
        margin-bottom: 15px;
    }
    
    .no-fields-message h3 {
        font-size: 20px;
        color: #1a1a2e;
        margin-bottom: 8px;
    }
    
    /* Dialog */
    .custom-dialog-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        justify-content: center;
        align-items: center;
        backdrop-filter: blur(5px);
        padding: 20px;
    }
    
    .custom-dialog-overlay.active {
        display: flex;
    }
    
    .custom-dialog-box {
        background: white;
        border-radius: 16px;
        padding: 30px;
        max-width: 600px;
        width: 100%;
        max-height: 85vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        animation: dialogSlideIn 0.3s ease;
        border: 1px solid rgba(0,105,92,0.1);
    }
    
    @keyframes dialogSlideIn {
        from {
            transform: translateY(-50px) scale(0.95);
            opacity: 0;
        }
        to {
            transform: translateY(0) scale(1);
            opacity: 1;
        }
    }
    
    .custom-dialog-box .dialog-title {
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 10px;
        color: #1a1a2e;
    }
    
    .custom-dialog-box .dialog-message {
        font-size: 16px;
        color: #6b7280;
        margin-bottom: 20px;
        line-height: 1.6;
        word-wrap: break-word;
    }
    
    .custom-dialog-box .dialog-content {
        margin-bottom: 15px;
    }
    
    .custom-dialog-box .dialog-content .form-group {
        margin-bottom: 15px;
    }
    
    .custom-dialog-box .dialog-content .form-group label {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
        color: #1a1a2e;
        font-size: 14px;
    }
    
    .custom-dialog-box .dialog-content .form-group input,
    .custom-dialog-box .dialog-content .form-group select,
    .custom-dialog-box .dialog-content .form-group textarea {
        width: 100%;
        padding: 10px 14px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        font-size: 14px;
        background: #fafafa;
        box-sizing: border-box;
    }
    
    .custom-dialog-box .dialog-content .form-group input:focus,
    .custom-dialog-box .dialog-content .form-group select:focus,
    .custom-dialog-box .dialog-content .form-group textarea:focus {
        border-color: #00695c;
        outline: none;
        box-shadow: 0 0 0 4px rgba(0,105,92,0.1);
        background: white;
    }
    
    .custom-dialog-box .dialog-buttons {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        flex-wrap: wrap;
    }
    
    .custom-dialog-box .dialog-buttons .btn {
        min-width: 80px;
        border-radius: 10px;
    }
    
    .custom-dialog-box .dialog-buttons .btn-confirm {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: white;
    }
    
    .custom-dialog-box .dialog-buttons .btn-confirm:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 20px rgba(239,68,68,0.3);
    }
    
    @media (max-width: 768px) {
        .main-header h1 {
            font-size: 17px;
        }
        .main-header {
            padding: 10px 15px;
            height: 55px;
        }
        .main-header .user-info {
            font-size: 12px;
            margin-right: 5px;
        }
        .tab-btn {
            padding: 12px 16px;
            font-size: 13px;
        }
        .config-item .item-details {
            grid-template-columns: 1fr;
        }
        .config-item .item-header {
            flex-direction: column;
            align-items: flex-start;
        }
        .detail-header {
            flex-direction: column;
            align-items: flex-start;
        }
        .login-container {
            margin: 30px 15px;
            padding: 30px 20px;
        }
        .form-container {
            padding: 20px;
        }
        .btn {
            padding: 10px 18px;
            font-size: 13px;
        }
        .custom-dialog-box {
            padding: 20px;
        }
        .object-field-container {
            padding: 16px;
        }
        .sub-field-group {
            padding: 14px 16px;
        }
        .fab {
            width: 50px;
            height: 50px;
            font-size: 28px;
            bottom: 20px;
            right: 20px;
        }
    }
    
    @media (max-width: 480px) {
        .tab-btn {
            padding: 10px 12px;
            font-size: 12px;
        }
        .custom-dialog-box {
            padding: 15px;
            max-width: 95%;
        }
        .config-item {
            padding: 18px;
        }
        .object-field-container {
            padding: 14px;
        }
        .sub-field-group {
            padding: 12px 14px;
        }
    }
</style>
</head>
<body>
    <div class="custom-body">
        <div class="main-header" id="mainHeader">
            <div style="display:flex; align-items:center; gap:15px;">
                <h1>SceneIQ</h1>
            </div>
            <div class="header-actions">
                <?php if ($isLoggedIn): ?>
                    <span class="user-info">👤 <?php echo htmlspecialchars($_SESSION['sceneiq_username'] ?? 'User'); ?></span>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn-logout">Logout</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($isLoggedIn): ?>
        <!-- Tab Navigation -->
        <div class="tab-navigation" id="tabNavigation">
            <button class="tab-btn active" data-tab="all" onclick="switchTab('all')">
                All <span class="badge"><?php echo count($allEntries); ?></span>
            </button>
            <button class="tab-btn" data-tab="pending" onclick="switchTab('pending')">
                <span class="status-dot" style="background:#f59e0b;"></span>Pending 
                <span class="badge"><?php echo count($groupedEntries['pending'] ?? []); ?></span>
            </button>
            <button class="tab-btn" data-tab="completed" onclick="switchTab('completed')">
                <span class="status-dot" style="background:#10b981;"></span>Completed 
                <span class="badge"><?php echo count($groupedEntries['completed'] ?? []); ?></span>
            </button>
            <button class="tab-btn" data-tab="aborted" onclick="switchTab('aborted')">
                <span class="status-dot" style="background:#ef4444;"></span>Aborted 
                <span class="badge"><?php echo count($groupedEntries['aborted'] ?? []); ?></span>
            </button>
        </div>
        <?php endif; ?>
        
        <div class="scroll-body" id="scrollBody">
            
            <?php if (!$isLoggedIn): ?>
            <div id="loginView">
                <div class="login-container">
                    <div class="login-icon">🔐</div>
                    <div class="login-title">Welcome Back</div>
                    <div class="login-subtitle">Sign in to access your SceneIQ dashboard</div>
                    
                    <?php if ($loginError): ?>
                        <div class="error-message"><?php echo htmlspecialchars($loginError); ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" action="">
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" placeholder="Enter your email" required>
                        </div>
                        <div class="form-group">
                            <label>Password</label>
                            <input type="password" name="password" placeholder="Enter your password" required>
                        </div>
                        <button type="submit" name="login" class="btn-login">Sign In</button>
                    </form>
                </div>
            </div>
            <?php else: ?>
            
            <!-- Tab Contents -->
            <div id="tabContentAll" class="tab-content active">
                <?php renderTabContent('all', $allEntries, $dynamicFields); ?>
            </div>
            <div id="tabContentPending" class="tab-content">
                <?php renderTabContent('pending', $groupedEntries['pending'] ?? [], $dynamicFields); ?>
            </div>
            <div id="tabContentCompleted" class="tab-content">
                <?php renderTabContent('completed', $groupedEntries['completed'] ?? [], $dynamicFields); ?>
            </div>
            <div id="tabContentAborted" class="tab-content">
                <?php renderTabContent('aborted', $groupedEntries['aborted'] ?? [], $dynamicFields); ?>
            </div>
            
            <!-- Add Config View -->
            <div id="addconfigView">
                <div class="detail-header">
                    <button class="btn-back" onclick="closeAddConfig()">← Back</button>
                    <div class="detail-title">New Project</div>
                </div>
                
                <div class="form-container">
                    <form method="POST" id="configForm" onsubmit="submitconfig(event)">
                        <input type="hidden" name="save_config" value="true">
                        <input type="hidden" name="config_data" id="configDataInput">
                        <div id="dynamicFieldsContainer"></div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-success">💾 Submit Project</button>
                            <button type="button" class="btn btn-secondary" onclick="closeAddConfig()">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Floating Action Button -->
            <button class="fab" onclick="openAddConfig()" title="Submit New Project">+</button>
            
            <?php endif; ?>
        </div>
    </div>
    
    <div class="custom-dialog-overlay" id="customDialog">
        <div class="custom-dialog-box">
            <div class="dialog-title" id="dialogTitle">Notification</div>
            <div class="dialog-message" id="dialogMessage">Message here</div>
            <div class="dialog-content" id="dialogContent"></div>
            <div class="dialog-buttons" id="dialogButtons">
                <button class="btn btn-primary" onclick="closeDialog()">OK</button>
            </div>
        </div>
    </div>

<?php
// Helper function to render tab content
function renderTabContent($tab, $entries, $dynamicFields) {
    if (empty($entries)) {
        $messages = [
            'all' => 'No projects found.',
            'pending' => 'No pending projects.',
            'completed' => 'No completed projects.',
            'aborted' => 'No aborted projects.'
        ];
        $icons = [
            'all' => '',
            'pending' => '',
            'completed' => '',
            'aborted' => ''
        ];
        echo '<div class="empty-state">';
        echo '<div class="icon">' . ($icons[$tab] ?? '📋') . '</div>';
        echo '<h3>' . ($messages[$tab] ?? 'No entries found.') . '</h3>';
        echo '<p>Submit a new project using the + button.</p>';
        echo '</div>';
        return;
    }
    
    echo '<div class="config-list">';
    foreach ($entries as $config) {
        $index = $config['_index'];
        $status = isset($config['status']) ? strtolower($config['status']) : 'pending';
        
        $statusColors = [
            'pending' => '#f59e0b',
            'completed' => '#10b981',
            'aborted' => '#ef4444'
        ];
        
        $statusLabels = [
            'pending' => 'Pending',
            'completed' => 'Completed',
            'aborted' => 'Aborted'
        ];
        
        echo '<div class="config-item" style="border-left-color: ' . ($statusColors[$status] ?? '#6b7280') . ';">';
        echo '<div class="item-header">';
        echo '<span class="item-id">Project #' . ($index + 1) . '</span>';
        echo '<span class="status-badge" style="background: ' . ($statusColors[$status] ?? '#6b7280') . ';">' . ($statusLabels[$status] ?? ucfirst($status)) . '</span>';
        echo '</div>';
        
        if (isset($config['dynamic_values']) && is_array($config['dynamic_values'])) {
            echo '<div class="item-details">';
            foreach ($config['dynamic_values'] as $key => $value) {
                if ($key === '_object') continue;
                $displayValue = $value;
                if (is_array($value)) {
                    unset($value['_object']);
                    $displayValue = implode(', ', array_filter(array_values($value), function($v) { return $v !== ''; }));
                    if (empty($displayValue)) continue;
                }
                if ($displayValue === '') continue;
                echo '<div>';
                echo '<div class="detail-label">' . htmlspecialchars($key) . '</div>';
                echo '<div class="detail-value">' . htmlspecialchars($displayValue) . '</div>';
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo '<div class="item-actions">';
        echo '<form method="POST" style="display:inline;">';
        echo '<input type="hidden" name="delete_config" value="true">';
        echo '<input type="hidden" name="index" value="' . $index . '">';
        echo '<button type="button" class="btn btn-danger" onclick="confirmDelete(this)">🗑️ Delete</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
}
?>

<?php if ($isLoggedIn): ?>
<script>
    let dynamicFields = <?php echo json_encode($dynamicFields); ?>;
    let settingsData = <?php echo json_encode($settings); ?>;
    let userId = <?php echo json_encode($_SESSION['sceneiq_user_id']); ?>;
    
    // ===== TAB SWITCHING =====
    function switchTab(tab) {
        // Update tab buttons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tab);
        });
        
        // Update tab contents
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.remove('active');
        });
        
        const targetContent = document.getElementById('tabContent' + tab.charAt(0).toUpperCase() + tab.slice(1));
        if (targetContent) {
            targetContent.classList.add('active');
        }
        
        // Hide add config view if open
        document.getElementById('addconfigView').classList.remove('active');
        document.getElementById('addconfigView').style.display = 'none';
    }
    
    // ===== ADD CONFIG =====
    function openAddConfig() {
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        
        document.getElementById('addconfigView').classList.add('active');
        document.getElementById('addconfigView').style.display = 'block';
        
        renderDynamicFields();
    }
    
    function closeAddConfig() {
        document.getElementById('addconfigView').classList.remove('active');
        document.getElementById('addconfigView').style.display = 'none';
        // Switch to All tab
        switchTab('all');
    }
    
    // ===== CUSTOM DIALOG =====
    function showDialog(title, message, buttons, content) {
        document.getElementById('dialogTitle').textContent = title;
        document.getElementById('dialogMessage').textContent = message;
        
        const contentDiv = document.getElementById('dialogContent');
        if (content) {
            contentDiv.innerHTML = content;
        } else {
            contentDiv.innerHTML = '';
        }
        
        const buttonsDiv = document.getElementById('dialogButtons');
        if (buttons && buttons.length > 0) {
            buttonsDiv.innerHTML = buttons.map((b, i) => 
                `<button class="btn ${b.class || 'btn-primary'}" onclick="dialogButtonClick(${i})">${b.label}</button>`
            ).join('');
            window._dialogCallbacks = buttons.map(b => b.callback || null);
        } else {
            buttonsDiv.innerHTML = `<button class="btn btn-primary" onclick="closeDialog()">OK</button>`;
            window._dialogCallbacks = [null];
        }
        
        document.getElementById('customDialog').classList.add('active');
    }
    
    function dialogButtonClick(index) {
        const callback = window._dialogCallbacks ? window._dialogCallbacks[index] : null;
        if (callback && typeof callback === 'function') {
            callback();
        }
        closeDialog();
    }
    
    function closeDialog() {
        document.getElementById('customDialog').classList.remove('active');
        document.getElementById('dialogContent').innerHTML = '';
        window._dialogCallbacks = null;
    }
    
    document.getElementById('customDialog').addEventListener('click', function(e) {
        if (e.target === this) closeDialog();
    });
    
    function confirmDelete(button) {
        const form = button.closest('form');
        showDialog('Delete Project', 'Are you sure you want to delete this project? This action cannot be undone.', [
            { label: 'Cancel', class: 'btn-secondary', callback: null },
            { label: 'Delete', class: 'btn-confirm', callback: function() { form.submit(); } }
        ]);
    }
    
    // ===== RENDER DYNAMIC FIELDS =====
    function renderDynamicFields() {
        const container = document.getElementById('dynamicFieldsContainer');
        container.innerHTML = '';
        
        const fields = dynamicFields || {};
        const fieldKeys = Object.keys(fields);
        
        if (fieldKeys.length === 0) {
            container.innerHTML = `
                <div class="no-fields-message">
                    <div class="icon">📋</div>
                    <h3>No Dynamic Fields Configured</h3>
                    <p>No dynamic fields have been set up yet. Please contact your administrator.</p>
                </div>
            `;
            return;
        }
        
        for (const key of fieldKeys) {
            const field = fields[key];
            
            if (field.field_type === 'objects') {
                // Object field with subfields
                const objectDiv = document.createElement('div');
                objectDiv.className = 'object-field-container';
                
                const title = document.createElement('div');
                title.className = 'object-title';
                title.textContent = '📦 ' + (field['field-title'] || key);
                objectDiv.appendChild(title);
                
                const subFields = field.fieldkeyvalue || {};
                const subKeys = Object.keys(subFields);
                
                for (const subKey of subKeys) {
                    const subField = subFields[subKey];
                    const subDiv = document.createElement('div');
                    subDiv.className = 'sub-field-group';
                    
                    const label = document.createElement('label');
                    label.textContent = subField['field-title'] || subKey;
                    subDiv.appendChild(label);
                    
                    const elementType = subField.element_type || 'input';
                    let inputElement;
                    const inputId = 'field_' + key + '_' + subKey;
                    
                    if (elementType === 'date-time-input') {
                        inputElement = createDateTimeInput(inputId);
                    } else if (elementType === 'textarea') {
                        inputElement = document.createElement('textarea');
                        inputElement.id = inputId;
                        inputElement.placeholder = 'Enter ' + (subField['field-title'] || subKey) + '...';
                        inputElement.rows = 3;
                    } else if (elementType === 'select') {
                        inputElement = document.createElement('select');
                        inputElement.id = inputId;
                        const emptyOption = document.createElement('option');
                        emptyOption.value = '';
                        emptyOption.textContent = 'Select ' + (subField['field-title'] || subKey) + '...';
                        inputElement.appendChild(emptyOption);
                        
                        const defaultValues = subField.default_values || [];
                        defaultValues.forEach(val => {
                            const option = document.createElement('option');
                            option.value = val;
                            option.textContent = val;
                            inputElement.appendChild(option);
                        });
                    } else {
                        inputElement = document.createElement('input');
                        inputElement.type = 'text';
                        inputElement.id = inputId;
                        inputElement.placeholder = 'Enter ' + (subField['field-title'] || subKey) + '...';
                    }
                    
                    subDiv.appendChild(inputElement);
                    objectDiv.appendChild(subDiv);
                }
                
                container.appendChild(objectDiv);
                
            } else {
                // Individual field
                const div = document.createElement('div');
                div.className = 'form-group';
                
                const label = document.createElement('label');
                label.textContent = field['field-title'] || key;
                div.appendChild(label);
                
                const elementType = field.element_type || 'input';
                const inputId = 'field_' + key;
                let inputElement;
                
                if (elementType === 'date-time-input') {
                    inputElement = createDateTimeInput(inputId);
                } else if (elementType === 'textarea') {
                    inputElement = document.createElement('textarea');
                    inputElement.id = inputId;
                    inputElement.placeholder = 'Enter ' + (field['field-title'] || key) + '...';
                    inputElement.rows = 3;
                } else if (elementType === 'select') {
                    inputElement = document.createElement('select');
                    inputElement.id = inputId;
                    const emptyOption = document.createElement('option');
                    emptyOption.value = '';
                    emptyOption.textContent = 'Select ' + (field['field-title'] || key) + '...';
                    inputElement.appendChild(emptyOption);
                    
                    const defaultValues = field.default_values || [];
                    defaultValues.forEach(val => {
                        const option = document.createElement('option');
                        option.value = val;
                        option.textContent = val;
                        inputElement.appendChild(option);
                    });
                } else {
                    inputElement = document.createElement('input');
                    inputElement.type = 'text';
                    inputElement.id = inputId;
                    inputElement.placeholder = 'Enter ' + (field['field-title'] || key) + '...';
                }
                
                div.appendChild(inputElement);
                container.appendChild(div);
            }
        }
    }
    
    function createDateTimeInput(id) {
        const wrapper = document.createElement('div');
        wrapper.className = 'datetime-group';
        wrapper.style.width = '100%';
        wrapper.style.boxSizing = 'border-box';
        
        const dateInput = document.createElement('input');
        dateInput.type = 'date';
        dateInput.id = id + '_date';
        dateInput.className = 'date-input';
        dateInput.style.width = '100%';
        dateInput.style.boxSizing = 'border-box';
        wrapper.appendChild(dateInput);
        
        const formatDiv = document.createElement('div');
        formatDiv.style.cssText = 'display:flex; align-items:center; gap:12px; margin-top:8px; padding:8px 12px; background:#f8f9fa; border-radius:8px; border:1px solid #e5e7eb; flex-wrap:wrap;';
        
        const formatLabel = document.createElement('label');
        formatLabel.textContent = 'Format:';
        formatLabel.style.marginBottom = '0';
        formatLabel.style.fontWeight = '500';
        formatLabel.style.color = '#1a1a2e';
        formatLabel.style.fontSize = '14px';
        
        const formatSelect = document.createElement('select');
        formatSelect.id = id + '_format';
        formatSelect.style.cssText = 'padding:6px 12px; border:2px solid #e5e7eb; border-radius:6px; font-size:14px; background:#fafafa; width:auto; min-width:80px;';
        
        const opt12 = document.createElement('option');
        opt12.value = '12h';
        opt12.textContent = '12h';
        formatSelect.appendChild(opt12);
        
        const opt24 = document.createElement('option');
        opt24.value = '24h';
        opt24.textContent = '24h';
        formatSelect.appendChild(opt24);
        formatSelect.value = '12h';
        
        formatDiv.appendChild(formatLabel);
        formatDiv.appendChild(formatSelect);
        wrapper.appendChild(formatDiv);
        
        const timeGroup = document.createElement('div');
        timeGroup.id = id + '_time_group';
        timeGroup.style.cssText = 'display:flex; gap:10px; align-items:center; flex-wrap:wrap; width:100%; margin-top:10px;';
        
        const twelveDiv = document.createElement('div');
        twelveDiv.id = id + '_12h_group';
        twelveDiv.style.cssText = 'display:flex; gap:10px; align-items:center; flex-wrap:wrap;';
        
        const h12 = document.createElement('input');
        h12.type = 'number';
        h12.id = id + '_hour_12';
        h12.placeholder = 'HH';
        h12.min = '1';
        h12.max = '12';
        h12.style.cssText = 'width:60px; padding:10px; border:2px solid #e5e7eb; border-radius:10px; background:#fafafa;';
        
        const c1 = document.createElement('span');
        c1.textContent = ':';
        
        const m12 = document.createElement('input');
        m12.type = 'number';
        m12.id = id + '_minute_12';
        m12.placeholder = 'MM';
        m12.min = '0';
        m12.max = '59';
        m12.style.cssText = 'width:60px; padding:10px; border:2px solid #e5e7eb; border-radius:10px; background:#fafafa;';
        
        const ampm = document.createElement('select');
        ampm.id = id + '_ampm';
        ampm.style.cssText = 'width:80px; padding:10px; border:2px solid #e5e7eb; border-radius:10px; background:#fafafa;';
        const amOpt = document.createElement('option');
        amOpt.value = 'AM';
        amOpt.textContent = 'AM';
        ampm.appendChild(amOpt);
        const pmOpt = document.createElement('option');
        pmOpt.value = 'PM';
        pmOpt.textContent = 'PM';
        ampm.appendChild(pmOpt);
        
        twelveDiv.appendChild(h12);
        twelveDiv.appendChild(c1);
        twelveDiv.appendChild(m12);
        twelveDiv.appendChild(ampm);
        
        const twentyFourDiv = document.createElement('div');
        twentyFourDiv.id = id + '_24h_group';
        twentyFourDiv.style.cssText = 'display:none; gap:10px; align-items:center; flex-wrap:wrap;';
        
        const h24 = document.createElement('input');
        h24.type = 'number';
        h24.id = id + '_hour_24';
        h24.placeholder = 'HH';
        h24.min = '0';
        h24.max = '23';
        h24.style.cssText = 'width:60px; padding:10px; border:2px solid #e5e7eb; border-radius:10px; background:#fafafa;';
        
        const c2 = document.createElement('span');
        c2.textContent = ':';
        
        const m24 = document.createElement('input');
        m24.type = 'number';
        m24.id = id + '_minute_24';
        m24.placeholder = 'MM';
        m24.min = '0';
        m24.max = '59';
        m24.style.cssText = 'width:60px; padding:10px; border:2px solid #e5e7eb; border-radius:10px; background:#fafafa;';
        
        twentyFourDiv.appendChild(h24);
        twentyFourDiv.appendChild(c2);
        twentyFourDiv.appendChild(m24);
        
        timeGroup.appendChild(twelveDiv);
        timeGroup.appendChild(twentyFourDiv);
        wrapper.appendChild(timeGroup);
        
        formatSelect.addEventListener('change', function() {
            if (this.value === '12h') {
                twelveDiv.style.display = 'flex';
                twentyFourDiv.style.display = 'none';
            } else {
                twelveDiv.style.display = 'none';
                twentyFourDiv.style.display = 'flex';
            }
        });
        
        return wrapper;
    }
    
    function getFieldValue(key, field) {
        const elementType = field.element_type || 'input';
        
        if (elementType === 'date-time-input') {
            const dateInput = document.getElementById('field_' + key + '_date');
            const formatSelect = document.getElementById('field_' + key + '_format');
            
            if (dateInput) {
                const date = dateInput.value;
                let timeStr = '';
                const format = formatSelect ? formatSelect.value : '12h';
                
                if (format === '12h') {
                    const h12 = document.getElementById('field_' + key + '_hour_12');
                    const m12 = document.getElementById('field_' + key + '_minute_12');
                    const ampm = document.getElementById('field_' + key + '_ampm');
                    if (h12 && m12 && ampm && h12.value && m12.value) {
                        timeStr = h12.value + ':' + String(m12.value).padStart(2, '0') + ' ' + ampm.value;
                    }
                } else {
                    const h24 = document.getElementById('field_' + key + '_hour_24');
                    const m24 = document.getElementById('field_' + key + '_minute_24');
                    if (h24 && m24 && h24.value && m24.value) {
                        timeStr = String(h24.value).padStart(2, '0') + ':' + String(m24.value).padStart(2, '0');
                    }
                }
                
                if (date || timeStr) {
                    let formattedDate = '';
                    if (date) {
                        const parts = date.split('-');
                        if (parts.length === 3) {
                            formattedDate = parts[2] + '/' + parts[1] + '/' + parts[0];
                        }
                    }
                    return formattedDate + (timeStr ? ' ' + timeStr : '');
                }
            }
            return '';
        } else {
            const input = document.getElementById('field_' + key);
            return input ? input.value : '';
        }
    }
    
    // ===== SUBMIT CONFIG =====
    function submitconfig(event) {
        event.preventDefault();
        
        const fields = dynamicFields || {};
        const fieldKeys = Object.keys(fields);
        const configData = {};
        
        for (const key of fieldKeys) {
            const field = fields[key];
            
            if (field.field_type === 'objects') {
                const subFields = field.fieldkeyvalue || {};
                const subKeys = Object.keys(subFields);
                const objectData = {};
                objectData['_object'] = true;
                
                for (const subKey of subKeys) {
                    const subField = subFields[subKey];
                    const fullKey = key + '_' + subKey;
                    const value = getFieldValue(fullKey, subField);
                    objectData[subKey] = value;
                }
                configData[key] = objectData;
            } else {
                configData[key] = getFieldValue(key, field);
            }
        }
        
        const hasData = Object.values(configData).some(v => {
            if (typeof v === 'object') {
                return Object.values(v).some(sv => sv !== '' && sv !== '_object');
            }
            return v !== '';
        });
        
        if (!hasData) {
            showDialog('Error', 'Please enter at least one field value.');
            return;
        }
        
        document.getElementById('configDataInput').value = JSON.stringify(configData);
        document.getElementById('configForm').submit();
    }
    
    // ===== AUTO-REFRESH AUTH =====
    function checkAuth() {
        fetch('?action=check_auth')
            .then(r => r.json())
            .then(data => {
                if (!data.authenticated) {
                    window.location.reload();
                }
            })
            .catch(() => {
                window.location.reload();
            });
    }
    
    setInterval(checkAuth, 60000);
    
    document.addEventListener('DOMContentLoaded', function() {
        // Show All tab by default
        switchTab('all');
    });
</script>
<?php endif; ?>
</body>
</html>