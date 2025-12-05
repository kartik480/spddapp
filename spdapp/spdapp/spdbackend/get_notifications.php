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
  // Fetch notifications for the user where recipient_type = 'user' and recipient_id = user_id
  $stmt = $conn->prepare("SELECT 
    id, type, title, message, recipient_type, recipient_id, 
    related_id, related_type, url, is_read, read_at, 
    created_at, updated_at
    FROM notifications
    WHERE recipient_type = 'user' AND recipient_id = ?
    ORDER BY created_at DESC");
  
  $stmt->bind_param('i', $user_id);
  $stmt->execute();
  $result = $stmt->get_result();
  
  $notifications = [];
  while ($row = $result->fetch_assoc()) {
    $notifications[] = [
      'id' => $row['id'],
      'type' => $row['type'],
      'title' => $row['title'],
      'message' => $row['message'],
      'recipient_type' => $row['recipient_type'],
      'recipient_id' => $row['recipient_id'],
      'related_id' => $row['related_id'],
      'related_type' => $row['related_type'],
      'url' => $row['url'],
      'is_read' => (bool)$row['is_read'],
      'read_at' => $row['read_at'],
      'created_at' => $row['created_at'],
      'updated_at' => $row['updated_at'],
    ];
  }
  
  $stmt->close();
  
  // Count unread notifications
  $unreadCount = 0;
  foreach ($notifications as $notification) {
    if (!$notification['is_read']) {
      $unreadCount++;
    }
  }
  
  echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'count' => count($notifications),
    'unread_count' => $unreadCount
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

