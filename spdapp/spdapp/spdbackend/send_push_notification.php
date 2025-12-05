<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

require_once 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'message' => 'Method not allowed']);
  exit();
}

$input = json_decode(file_get_contents('php://input'), true);

// OneSignal API configuration
$onesignal_app_id = '63b3f137-b249-4425-8479-5419cd54b076';
$onesignal_rest_api_key = 'os_v2_app_moz7cn5sjfcclbdzkqm42vfqoyjcb4beai2eqv4pxnofvwy6eyul7ewmtwva5kyao2lfseffzteyaigyngx55f7zymkckj4hajjscsy';

// Get notification data from database
$notification_id = isset($input['notification_id']) ? intval($input['notification_id']) : 0;

if ($notification_id <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Invalid notification_id']);
  exit();
}

try {
  // Fetch notification from database
  $stmt = $conn->prepare("SELECT 
    id, title, body, url, icon, product_id, service_ids, product_ids, 
    prize, target_user_ids, subscriptions, user_id, total_subscribers, 
    sent_count, failed_count, status, error_message, sent_at, 
    created_at, updated_at
    FROM push_notifications
    WHERE id = ?");
  
  $stmt->bind_param('i', $notification_id);
  $stmt->execute();
  $result = $stmt->get_result();
  
  if ($result->num_rows === 0) {
    $stmt->close();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Notification not found']);
    exit();
  }
  
  $notification = $result->fetch_assoc();
  $stmt->close();
  
  // Get target user IDs
  $target_user_ids = [];
  if (!empty($notification['user_id'])) {
    // user_id contains comma-separated IDs
    $user_ids_str = $notification['user_id'];
    $target_user_ids = array_map('trim', explode(',', $user_ids_str));
    $target_user_ids = array_filter($target_user_ids, function($id) {
      return is_numeric($id) && intval($id) > 0;
    });
  }
  
  if (empty($target_user_ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No target users specified']);
    exit();
  }
  
  // Verify users exist in users table
  $placeholders = implode(',', array_fill(0, count($target_user_ids), '?'));
  $userCheckStmt = $conn->prepare("SELECT id FROM users WHERE id IN ($placeholders)");
  $userCheckStmt->bind_param(str_repeat('i', count($target_user_ids)), ...$target_user_ids);
  $userCheckStmt->execute();
  $userResult = $userCheckStmt->get_result();
  
  $valid_user_ids = [];
  while ($row = $userResult->fetch_assoc()) {
    $valid_user_ids[] = $row['id'];
  }
  $userCheckStmt->close();
  
  if (empty($valid_user_ids)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'No valid users found']);
    exit();
  }
  
  // Get OneSignal player IDs for these users from push_subscriptions table
  $player_ids = [];
  $placeholders = implode(',', array_fill(0, count($valid_user_ids), '?'));
  $subscriptionStmt = $conn->prepare("SELECT player_id FROM push_subscriptions WHERE user_id IN ($placeholders) AND player_id IS NOT NULL AND player_id != ''");
  $subscriptionStmt->bind_param(str_repeat('i', count($valid_user_ids)), ...$valid_user_ids);
  $subscriptionStmt->execute();
  $subscriptionResult = $subscriptionStmt->get_result();
  
  while ($row = $subscriptionResult->fetch_assoc()) {
    if (!empty($row['player_id'])) {
      $player_ids[] = $row['player_id'];
    }
  }
  $subscriptionStmt->close();
  
  if (empty($player_ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No active subscriptions found for target users']);
    exit();
  }
  
  // Prepare notification data for OneSignal
  $notification_data = [
    'app_id' => $onesignal_app_id,
    'include_player_ids' => $player_ids,
    'headings' => ['en' => $notification['title']],
    'contents' => ['en' => $notification['body']],
    'data' => [
      'notification_id' => $notification['id'],
      'url' => $notification['url'] ?? '',
      'product_id' => $notification['product_id'] ?? null,
      'service_ids' => $notification['service_ids'] ?? null,
      'product_ids' => $notification['product_ids'] ?? null,
      'prize' => $notification['prize'] ?? null,
    ],
  ];
  
  // Add icon if available
  if (!empty($notification['icon'])) {
    $notification_data['large_icon'] = $notification['icon'];
    $notification_data['big_picture'] = $notification['icon'];
  }
  
  // Send notification via OneSignal REST API
  // OneSignal uses Basic Auth with REST API Key as the password
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, 'https://onesignal.com/api/v1/notifications');
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json; charset=utf-8',
    'Authorization: Basic ' . base64_encode($onesignal_rest_api_key . ':')
  ]);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HEADER, false);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($notification_data));
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
  
  $response = curl_exec($ch);
  $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curl_error = curl_error($ch);
  curl_close($ch);
  
  if ($curl_error) {
    // Update notification status in database
    $updateStmt = $conn->prepare("UPDATE push_notifications SET 
      status = 'failed',
      error_message = ?,
      failed_count = failed_count + 1,
      updated_at = NOW()
      WHERE id = ?");
    $error_msg = 'CURL Error: ' . $curl_error;
    $updateStmt->bind_param('si', $error_msg, $notification_id);
    $updateStmt->execute();
    $updateStmt->close();
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to send notification: ' . $curl_error]);
    exit();
  }
  
  $response_data = json_decode($response, true);
  
  if ($http_code === 200 && isset($response_data['id'])) {
    // Success - update notification status
    $sent_count = count($player_ids);
    $updateStmt = $conn->prepare("UPDATE push_notifications SET 
      status = 'sent',
      sent_count = ?,
      total_subscribers = ?,
      sent_at = NOW(),
      updated_at = NOW()
      WHERE id = ?");
    $updateStmt->bind_param('iii', $sent_count, $sent_count, $notification_id);
    $updateStmt->execute();
    $updateStmt->close();
    
    echo json_encode([
      'success' => true,
      'message' => 'Notification sent successfully',
      'onesignal_id' => $response_data['id'],
      'recipients' => $sent_count
    ]);
  } else {
    // Failed - update notification status
    $error_message = isset($response_data['errors']) ? json_encode($response_data['errors']) : 'Unknown error';
    $updateStmt = $conn->prepare("UPDATE push_notifications SET 
      status = 'failed',
      error_message = ?,
      failed_count = failed_count + 1,
      updated_at = NOW()
      WHERE id = ?");
    $updateStmt->bind_param('si', $error_message, $notification_id);
    $updateStmt->execute();
    $updateStmt->close();
    
    http_response_code(500);
    echo json_encode([
      'success' => false,
      'message' => 'Failed to send notification',
      'error' => $response_data
    ]);
  }
  
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'message' => 'Server error: ' . $e->getMessage()
  ]);
}

$conn->close();
?>

