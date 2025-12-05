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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
  exit();
}

$user_id = isset($input['user_id']) ? intval($input['user_id']) : 0;
$endpoint = isset($input['endpoint']) ? trim($input['endpoint']) : '';
$keys = isset($input['keys']) ? json_encode($input['keys']) : '{}';
$player_id = isset($input['player_id']) ? trim($input['player_id']) : ''; // OneSignal player ID

if ($user_id <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Invalid user_id']);
  exit();
}

if (empty($endpoint) && empty($player_id)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Endpoint or player_id is required']);
  exit();
}

try {
  // Check if subscription already exists for this user
  // Check by player_id first (OneSignal), then by endpoint
  if (!empty($player_id)) {
    $checkStmt = $conn->prepare("SELECT id FROM push_subscriptions WHERE user_id = ? AND player_id = ?");
    $checkStmt->bind_param('is', $user_id, $player_id);
  } else {
    $checkStmt = $conn->prepare("SELECT id FROM push_subscriptions WHERE user_id = ? AND endpoint = ?");
    $checkStmt->bind_param('is', $user_id, $endpoint);
  }
  $checkStmt->execute();
  $result = $checkStmt->get_result();
  
  if ($result->num_rows > 0) {
    // Update existing subscription
    $row = $result->fetch_assoc();
    $subscriptionId = $row['id'];
    
    if (!empty($player_id)) {
      $updateStmt = $conn->prepare("UPDATE push_subscriptions SET player_id = ?, keys = ?, updated_at = NOW() WHERE id = ?");
      $updateStmt->bind_param('ssi', $player_id, $keys, $subscriptionId);
    } else {
      $updateStmt = $conn->prepare("UPDATE push_subscriptions SET keys = ?, updated_at = NOW() WHERE id = ?");
      $updateStmt->bind_param('si', $keys, $subscriptionId);
    }
    
    if ($updateStmt->execute()) {
      $updateStmt->close();
      $checkStmt->close();
      echo json_encode([
        'success' => true,
        'message' => 'Push subscription updated successfully',
        'subscription_id' => $subscriptionId
      ]);
    } else {
      $updateStmt->close();
      $checkStmt->close();
      throw new Exception('Failed to update push subscription');
    }
  } else {
    // Insert new subscription
    $checkStmt->close();
    
    if (!empty($player_id)) {
      $insertStmt = $conn->prepare("INSERT INTO push_subscriptions (user_id, endpoint, keys, player_id, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
      $insertStmt->bind_param('isss', $user_id, $endpoint, $keys, $player_id);
    } else {
      $insertStmt = $conn->prepare("INSERT INTO push_subscriptions (user_id, endpoint, keys, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
      $insertStmt->bind_param('iss', $user_id, $endpoint, $keys);
    }
    
    if ($insertStmt->execute()) {
      $subscriptionId = $conn->insert_id;
      $insertStmt->close();
      echo json_encode([
        'success' => true,
        'message' => 'Push subscription saved successfully',
        'subscription_id' => $subscriptionId
      ]);
    } else {
      $insertStmt->close();
      throw new Exception('Failed to save push subscription');
    }
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

