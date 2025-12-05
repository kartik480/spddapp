<?php
/**
 * This script processes pending push notifications from the database
 * and sends them via OneSignal API
 * 
 * Usage: Call this script via cron job or manually to process pending notifications
 * Example cron: */5 * * * * php /path/to/process_pending_notifications.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

require_once 'db_config.php';

// OneSignal API configuration
$onesignal_app_id = '63b3f137-b249-4425-8479-5419cd54b076';
$onesignal_rest_api_key = 'os_v2_app_moz7cn5sjfcclbdzkqm42vfqoyjcb4beai2eqv4pxnofvwy6eyul7ewmtwva5kyao2lfseffzteyaigyngx55f7zymkckj4hajjscsy';

try {
  // Fetch pending notifications (status = 'pending' or NULL)
  $stmt = $conn->prepare("SELECT 
    id, title, body, url, icon, product_id, service_ids, product_ids, 
    prize, target_user_ids, subscriptions, user_id, total_subscribers, 
    sent_count, failed_count, status, error_message, sent_at, 
    created_at, updated_at
    FROM push_notifications
    WHERE (status IS NULL OR status = 'pending' OR status = '')
    ORDER BY created_at ASC
    LIMIT 10"); // Process 10 at a time to avoid timeout
  
  $stmt->execute();
  $result = $stmt->get_result();
  
  $processed = 0;
  $successful = 0;
  $failed = 0;
  
  while ($notification = $result->fetch_assoc()) {
    $processed++;
    $notification_id = $notification['id'];
    
    // Get target user IDs
    $target_user_ids = [];
    if (!empty($notification['user_id'])) {
      $user_ids_str = $notification['user_id'];
      $target_user_ids = array_map('trim', explode(',', $user_ids_str));
      $target_user_ids = array_filter($target_user_ids, function($id) {
        return is_numeric($id) && intval($id) > 0;
      });
    }
    
    if (empty($target_user_ids)) {
      // Update status to failed - no target users
      $updateStmt = $conn->prepare("UPDATE push_notifications SET 
        status = 'failed',
        error_message = 'No target users specified',
        failed_count = failed_count + 1,
        updated_at = NOW()
        WHERE id = ?");
      $updateStmt->bind_param('i', $notification_id);
      $updateStmt->execute();
      $updateStmt->close();
      $failed++;
      continue;
    }
    
    // Verify users exist and get their OneSignal player IDs
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
      $updateStmt = $conn->prepare("UPDATE push_notifications SET 
        status = 'failed',
        error_message = 'No valid users found',
        failed_count = failed_count + 1,
        updated_at = NOW()
        WHERE id = ?");
      $updateStmt->bind_param('i', $notification_id);
      $updateStmt->execute();
      $updateStmt->close();
      $failed++;
      continue;
    }
    
    // Get OneSignal player IDs
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
      $updateStmt = $conn->prepare("UPDATE push_notifications SET 
        status = 'failed',
        error_message = 'No active subscriptions found for target users',
        failed_count = failed_count + 1,
        updated_at = NOW()
        WHERE id = ?");
      $updateStmt->bind_param('i', $notification_id);
      $updateStmt->execute();
      $updateStmt->close();
      $failed++;
      continue;
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
      $failed++;
      continue;
    }
    
    $response_data = json_decode($response, true);
    
    if ($http_code === 200 && isset($response_data['id'])) {
      // Success
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
      $successful++;
    } else {
      // Failed
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
      $failed++;
    }
  }
  
  $stmt->close();
  
  echo json_encode([
    'success' => true,
    'message' => 'Processing completed',
    'processed' => $processed,
    'successful' => $successful,
    'failed' => $failed
  ]);
  
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'message' => 'Server error: ' . $e->getMessage()
  ]);
}

$conn->close();
?>

