<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

require_once 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['success' => false, 'message' => 'Method not allowed']);
  exit();
}

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
if ($user_id <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Invalid user_id']);
  exit();
}

try {
  // Verify user exists in users table
  $userCheckStmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
  $userCheckStmt->bind_param('i', $user_id);
  $userCheckStmt->execute();
  $userResult = $userCheckStmt->get_result();
  
  if ($userResult->num_rows === 0) {
    $userCheckStmt->close();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit();
  }
  $userCheckStmt->close();
  
  // Fetch push notifications where user_id matches the current user
  $stmt = $conn->prepare("SELECT 
    id, title, body, url, icon, product_id, service_ids, product_ids, 
    prize, target_user_ids, subscriptions, user_id, total_subscribers, 
    sent_count, failed_count, status, error_message, sent_at, 
    created_at, updated_at
    FROM push_notifications
    WHERE user_id = ?
    ORDER BY created_at DESC");
  
  $stmt->bind_param('i', $user_id);
  $stmt->execute();
  $result = $stmt->get_result();
  
  $notifications = [];
  while ($row = $result->fetch_assoc()) {
    $notifications[] = [
      'id' => $row['id'],
      'title' => $row['title'],
      'body' => $row['body'],
      'url' => $row['url'],
      'icon' => $row['icon'],
      'product_id' => $row['product_id'],
      'service_ids' => $row['service_ids'],
      'product_ids' => $row['product_ids'],
      'prize' => $row['prize'],
      'target_user_ids' => $row['target_user_ids'],
      'subscriptions' => $row['subscriptions'],
      'user_id' => $row['user_id'],
      'total_subscribers' => $row['total_subscribers'],
      'sent_count' => $row['sent_count'],
      'failed_count' => $row['failed_count'],
      'status' => $row['status'],
      'error_message' => $row['error_message'],
      'sent_at' => $row['sent_at'],
      'created_at' => $row['created_at'],
      'updated_at' => $row['updated_at'],
    ];
  }
  
  $stmt->close();
  
  echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'count' => count($notifications)
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

