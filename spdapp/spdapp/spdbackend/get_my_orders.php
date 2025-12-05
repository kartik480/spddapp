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

// Validate user_id against the users table
$user_check_stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
$user_check_stmt->bind_param('i', $user_id);
$user_check_stmt->execute();
$user_result = $user_check_stmt->get_result();
if ($user_result->num_rows === 0) {
  http_response_code(404);
  echo json_encode(['success' => false, 'message' => 'User not found']);
  $user_check_stmt->close();
  exit();
}
$user_check_stmt->close();

try {
  // Fetch all orders for the user with product information
  // Using the exact column names from the orders table
  $stmt = $conn->prepare("SELECT 
    o.id, 
    o.user_id, 
    o.product_id, 
    o.order_number,
    o.quantity, 
    o.price_per_unit, 
    o.total_amount,
    o.batch_index,
    o.batch_number,
    o.payment_method, 
    o.payment_status, 
    o.order_status,
    o.razorpay_order_id,
    o.razorpay_payment_id,
    o.razorpay_signature,
    o.gateway_response,
    o.payment_completed_at,
    o.created_at, 
    o.updated_at,
    p.id as product_table_id, 
    p.name as product_name, 
    p.image as product_image,
    p.selling_price, 
    p.mrp_price, 
    p.description as product_description
    FROM orders o
    LEFT JOIN products p ON o.product_id = p.id
    WHERE o.user_id = ? 
    ORDER BY o.created_at DESC");
  
  if (!$stmt) {
    http_response_code(500);
    echo json_encode([
      'success' => false,
      'message' => 'Database error: ' . $conn->error
    ]);
    exit();
  }
  
  $stmt->bind_param('i', $user_id);
  $stmt->execute();
  $result = $stmt->get_result();
  
  $orders = [];
  while ($row = $result->fetch_assoc()) {
    $orderData = [
      'id' => $row['id'],
      'user_id' => $row['user_id'],
      'product_id' => $row['product_id'],
      'order_number' => $row['order_number'],
      'quantity' => $row['quantity'],
      'price_per_unit' => $row['price_per_unit'],
      'total_amount' => $row['total_amount'],
      'batch_index' => $row['batch_index'],
      'batch_number' => $row['batch_number'],
      'payment_method' => $row['payment_method'],
      'payment_status' => $row['payment_status'],
      'order_status' => $row['order_status'],
      'razorpay_order_id' => $row['razorpay_order_id'],
      'razorpay_payment_id' => $row['razorpay_payment_id'],
      'razorpay_signature' => $row['razorpay_signature'],
      'gateway_response' => $row['gateway_response'],
      'payment_completed_at' => $row['payment_completed_at'],
      'created_at' => $row['created_at'],
      'updated_at' => $row['updated_at'],
    ];
    
    // Add product information if available
    if (!empty($row['product_id']) && !empty($row['product_name'])) {
      $orderData['product'] = [
        'id' => $row['product_table_id'],
        'name' => $row['product_name'],
        'image' => $row['product_image'],
        'selling_price' => $row['selling_price'],
        'mrp_price' => $row['mrp_price'],
        'description' => $row['product_description'],
      ];
    } else {
      $orderData['product'] = null;
    }
    
    $orders[] = $orderData;
  }
  
  $stmt->close();
  
  echo json_encode([
    'success' => true,
    'orders' => $orders,
    'count' => count($orders)
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
