<?php
// spdbackend/orders_create.php
// Version: 2.0 - Fixed duplicate order number issue with retry mechanism
// Last updated: 2025-11-21

// Start output buffering to prevent any accidental output
ob_start();

// CORS and JSON headers (adjust Access-Control-Allow-Origin as needed)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
  ob_end_clean();
  http_response_code(200); 
  exit; 
}

// Read JSON request body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
  exit;
}

// Use db_config.php for database connection
include_once __DIR__ . '/db_config.php';

$table = 'orders'; // target table

// Check if PDO connection exists from db_config.php
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'DB connection not available']);
  exit;
}

// Check required fields
if (!isset($data['user_id']) || $data['user_id'] === null || $data['user_id'] === '') {
  http_response_code(422);
  echo json_encode(['success' => false, 'message' => 'Missing required: user_id']);
  exit;
}

if (!isset($data['total_amount']) || $data['total_amount'] === null || $data['total_amount'] === '') {
  http_response_code(422);
  echo json_encode(['success' => false, 'message' => 'Missing required: total_amount']);
  exit;
}

// Check if we have items array (multiple products) or single product
$items = [];
if (isset($data['items']) && is_array($data['items']) && count($data['items']) > 0) {
  // Multiple items from cart
  $items = $data['items'];
} elseif (isset($data['product_id']) && $data['product_id'] !== null) {
  // Single product order
  $items = [[
    'product_id' => $data['product_id'],
    'quantity' => $data['quantity'] ?? 1,
    'price' => $data['price'] ?? $data['total_amount'] ?? 0,
  ]];
} else {
  http_response_code(422);
  echo json_encode(['success' => false, 'message' => 'Missing required: items array or product_id']);
  exit;
}

// Auto timestamps
$now = date('Y-m-d H:i:s');

// Generate unique order number function with maximum entropy
// Uses multiple entropy sources to ensure uniqueness even under high concurrency
function generateOrderNumber($retryCount = 0) {
  // Use multiple sources of entropy for maximum uniqueness
  // Format: ORD + YYYYMMDD + HHMMSS + microseconds (6) + uniqid (13) + random (10) + process (4) + retry (2) + counter (4)
  $date = date('YmdHis'); // 14 digits: YYYYMMDDHHMMSS
  $microtime = microtime(true);
  $microseconds = str_pad(floor(($microtime - floor($microtime)) * 1000000), 6, '0', STR_PAD_LEFT);
  $uniqueId = uniqid('', true); // Returns something like "507f1f77bcf86cd799439011.12345678"
  $uniquePart = str_replace('.', '', substr($uniqueId, -13)); // Get last 13 chars, remove dot
  $random1 = str_pad(rand(0, 9999999999), 10, '0', STR_PAD_LEFT); // 10 digit random
  $processId = str_pad(getmypid() % 10000, 4, '0', STR_PAD_LEFT); // Process ID
  $retrySuffix = str_pad($retryCount, 2, '0', STR_PAD_LEFT); // Retry count
  // Add a counter based on current time in nanoseconds (last 4 digits)
  $nanoseconds = substr(str_replace('.', '', (string)$microtime), -4);
  $orderNumber = 'ORD' . $date . $microseconds . $uniquePart . $random1 . $processId . $retrySuffix . $nanoseconds;
  // Total: 3 + 14 + 6 + 13 + 10 + 4 + 2 + 4 = 56 chars
  
  return $orderNumber;
}

// Function to check if order number exists (used for validation, but insertion will be the final check)
function checkOrderNumberExists($pdo, $table, $orderNumber) {
  $checkStmt = $pdo->prepare("SELECT COUNT(*) as count FROM `$table` WHERE order_number = :order_number");
  $checkStmt->execute([':order_number' => $orderNumber]);
  $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
  return intval($result['count']) > 0;
}

// Retry logic for handling duplicate key errors
$maxRetries = 5;
$retryCount = 0;
$orderIds = [];
$success = false;
$lastError = null; // Store last error message

while ($retryCount < $maxRetries && !$success) {
  try {
    // Start transaction
    $pdo->beginTransaction();
    
    $paymentId = $data['payment_id'] ?? 'payment_' . time();
    $paymentMethod = $data['payment_method'] ?? 'razorpay';
    $paymentStatus = $data['payment_status'] ?? 'paid';
    $orderStatus = $data['order_status'] ?? $data['status'] ?? 'pending';
    
    // Generate unique order number with maximum entropy
    // The database unique constraint will catch any duplicates, and we'll retry with a new number
    $orderNumber = null;
    
    if (isset($data['order_number']) && $retryCount == 0) {
      // Only use provided order_number on first attempt (retryCount == 0)
      $orderNumber = $data['order_number'];
      // Check if it already exists (though the INSERT will be the final check)
      if (checkOrderNumberExists($pdo, $table, $orderNumber)) {
        // Provided order number already exists, generate a new one
        $orderNumber = null;
      }
    }
    
    // Generate a unique order number if not provided or if provided one was taken
    if ($orderNumber === null) {
      // Generate with retry count to ensure uniqueness on each retry attempt
      $orderNumber = generateOrderNumber($retryCount);
    }

    $primaryOrderNumber = $orderNumber; // Keep the base number for reference/support
    $orderIds = []; // Reset for this attempt
    $generatedOrderNumbers = []; // Track actual numbers written to DB per item
    $totalItems = count($items);
    $itemIndex = 0;

    // Process each item
    foreach ($items as $item) {
      $itemIndex++;
      $isSingleItemOrder = $totalItems === 1;
      $itemOrderNumber = $isSingleItemOrder
        ? $primaryOrderNumber
        : $primaryOrderNumber . '-' . str_pad($itemIndex, 2, '0', STR_PAD_LEFT);

      $productId = $item['product_id'] ?? null;
      $quantity = intval($item['quantity'] ?? 1);
      $pricePerUnit = floatval($item['price'] ?? $item['price_per_unit'] ?? 0);
    
      if ($productId === null) {
        throw new Exception("Invalid product_id in item");
      }
    
    // Check product stock before creating order
      $stockCheck = $pdo->prepare("SELECT stock_quantity FROM products WHERE id = :product_id");
      $stockCheck->execute([':product_id' => $productId]);
      $product = $stockCheck->fetch(PDO::FETCH_ASSOC);
    
      if (!$product) {
        throw new Exception("Product with id $productId not found");
      }
    
      $currentStock = intval($product['stock_quantity'] ?? 0);
      if ($currentStock < $quantity) {
        throw new Exception("Insufficient stock for product id $productId. Available: $currentStock, Requested: $quantity");
      }
    
    // Calculate total amount for this item (use provided total_amount if available, otherwise calculate)
      $itemTotalAmount = isset($item['total_amount']) ? floatval($item['total_amount']) : ($pricePerUnit * $quantity);
    
    // Create order record with all required and optional columns
    // Columns: id (auto), user_id, product_id, order_number, quantity, price_per_unit, total_amount,
    //          batch_index, batch_number, payment_method, payment_status, order_status,
    //          razorpay_order_id, razorpay_payment_id, razorpay_signature, gateway_response,
    //          payment_completed_at, created_at, updated_at
      $insert = [
        'user_id' => intval($data['user_id']),
        'product_id' => intval($productId),
        'order_number' => $itemOrderNumber,
        'quantity' => $quantity,
        'price_per_unit' => $pricePerUnit,
        'total_amount' => $itemTotalAmount,
        'payment_method' => $paymentMethod,
        'payment_status' => $paymentStatus,
        'order_status' => $orderStatus,
        'razorpay_payment_id' => $data['razorpay_payment_id'] ?? $paymentId,
        'created_at' => $now,
        'updated_at' => $now,
      ];
    
    // Add optional fields if present in request data
      if (isset($data['razorpay_order_id']) && $data['razorpay_order_id'] !== null && $data['razorpay_order_id'] !== '') {
        $insert['razorpay_order_id'] = $data['razorpay_order_id'];
      }
      if (isset($data['razorpay_signature']) && $data['razorpay_signature'] !== null && $data['razorpay_signature'] !== '') {
        $insert['razorpay_signature'] = $data['razorpay_signature'];
      }
      if (isset($data['gateway_response']) && $data['gateway_response'] !== null) {
        $insert['gateway_response'] = is_string($data['gateway_response']) ? $data['gateway_response'] : json_encode($data['gateway_response']);
      }
      if (isset($data['payment_completed_at']) && $data['payment_completed_at'] !== null && $data['payment_completed_at'] !== '') {
        $insert['payment_completed_at'] = $data['payment_completed_at'];
      }
      // Handle batch_index and batch_number from item or main data
      if (isset($item['batch_index']) && $item['batch_index'] !== null) {
        $insert['batch_index'] = intval($item['batch_index']);
      } elseif (isset($data['batch_index']) && $data['batch_index'] !== null) {
        $insert['batch_index'] = intval($data['batch_index']);
      }
      if ($totalItems > 1) {
        // For multi-item orders, store group/order info automatically
        $insert['batch_index'] = $insert['batch_index'] ?? $itemIndex;
        $insert['batch_number'] = $insert['batch_number'] ?? $primaryOrderNumber;
      }

      if (isset($item['batch_number']) && $item['batch_number'] !== null && $item['batch_number'] !== '') {
        $insert['batch_number'] = $item['batch_number'];
      } elseif (isset($data['batch_number']) && $data['batch_number'] !== null && $data['batch_number'] !== '') {
        $insert['batch_number'] = $data['batch_number'];
      }
    
    // Build INSERT query
      $cols = array_keys($insert);
      $phs = array_map(fn($c) => ':' . $c, $cols);
      $sql = 'INSERT INTO `' . $table . '` (' . implode(',', array_map(fn($c) => '`'.$c.'`', $cols)) . ') VALUES (' . implode(',', $phs) . ')';

      $stmt = $pdo->prepare($sql);
      foreach ($insert as $k => $v) {
        if (is_int($v))          { $type = PDO::PARAM_INT; }
        elseif (is_bool($v))     { $type = PDO::PARAM_BOOL; }
        elseif ($v === null)     { $type = PDO::PARAM_NULL; }
        else                     { $type = PDO::PARAM_STR; }
        $stmt->bindValue(':' . $k, $v, $type);
      }
      $stmt->execute();
      $orderId = $pdo->lastInsertId();
      $orderIds[] = $orderId;
      $generatedOrderNumbers[] = $itemOrderNumber;

      // Update product stock
      $newStock = $currentStock - $quantity;
      $updateStock = $pdo->prepare("UPDATE products SET stock_quantity = :new_stock, updated_at = :updated_at WHERE id = :product_id");
      $updateStock->execute([
        ':new_stock' => $newStock,
        ':updated_at' => $now,
        ':product_id' => $productId,
      ]);
    }
  
    // Commit transaction
    $pdo->commit();
    $success = true;
    
    // Clear any output buffer and send success response
    ob_end_clean();
    echo json_encode([
      'success' => true,
      'order_ids' => $orderIds,
      'message' => 'Order(s) created successfully and stock updated',
      'order_number' => $primaryOrderNumber,
      'generated_order_numbers' => $generatedOrderNumbers,
      'version' => '2.0' // Version identifier to verify new code is running
    ]);
    exit; // Exit immediately after success
    
  } catch (PDOException $e) {
    // Rollback transaction on database error
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    
    // Check if it's a duplicate key error (error code 23000 for integrity constraint violation, 1062 for duplicate entry)
    $errorCode = $e->getCode();
    $errorMessage = $e->getMessage();
    
    // Get error info - PDOException has errorInfo as a property
    $errorInfo = isset($e->errorInfo) && is_array($e->errorInfo) ? $e->errorInfo : [];
    $mysqlErrorCode = isset($errorInfo[1]) ? (int)$errorInfo[1] : 0;
    
    // Check multiple ways to identify duplicate key errors
    // PDO error code can be string '23000' or integer 23000
    $isDuplicateKey = ($errorCode == 23000 || $errorCode === '23000' || (string)$errorCode === '23000') || 
                      $mysqlErrorCode == 1062 || 
                      stripos($errorMessage, 'Duplicate entry') !== false || 
                      stripos($errorMessage, '1062') !== false ||
                      stripos($errorMessage, 'orders_order_number_unique') !== false ||
                      stripos($errorMessage, 'Integrity constraint violation') !== false;
    
    if ($isDuplicateKey && $retryCount < $maxRetries - 1) {
      // Retry with a new order number - don't output JSON yet
      $retryCount++;
      $lastError = $e->getMessage(); // Store error for logging
      error_log("Duplicate order number detected, retrying (attempt $retryCount/$maxRetries): " . $lastError);
      // Longer delay on retry to reduce collision probability
      usleep(rand(50000, 200000)); // Random delay between 50-200ms to avoid collision
      continue; // Retry the loop
    } else {
      // Not a duplicate key error, or max retries reached - store error and exit
      $lastError = $e->getMessage();
      $success = false; // Mark as failed
      break; // Exit loop to output error below
    }
  } catch (Throwable $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    $lastError = $e->getMessage();
    $success = false; // Mark as failed
    break; // Exit loop to output error below
  }
}

// Output single response based on success status
if (!$success) {
  // Clear any output buffer and send error response
  ob_end_clean();
  http_response_code(500);
  error_log('Order creation failed after retries: ' . ($lastError ?? 'Unknown error'));
  echo json_encode([
    'success' => false,
    'message' => $retryCount >= $maxRetries ? 'Failed to create order after multiple attempts. Please try again.' : 'Failed to create order. Please try again.',
    'error' => $retryCount >= $maxRetries ? 'Order number generation failed after multiple attempts' : ($lastError ?? 'Unknown error'),
    'retry_count' => $retryCount,
    'max_retries' => $maxRetries,
    'version' => '2.0' // Version identifier to verify new code is running
  ]);
  exit;
}
