<?php
// spdbackend/bookings_create.php

// CORS and JSON headers (adjust Access-Control-Allow-Origin as needed)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

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

$table = 'bookings'; // target table

// Check if PDO connection exists from db_config.php
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'DB connection not available']);
  exit;
}

/**
 * Normalize supported payment methods so the DB only stores canonical values.
 */
function normalize_payment_method($value) {
  if ($value === null) {
    return null;
  }
  $val = strtolower(trim((string)$value));
  if ($val === '') {
    return null;
  }
  $map = [
    'razorpay' => 'razorpay',
    'razor_pay' => 'razorpay',
    'rp' => 'razorpay',
    'cod' => 'cod',
    'cash_on_delivery' => 'cod',
    'cashondelivery' => 'cod',
    'cash' => 'cod',
  ];
  return $map[$val] ?? null;
}

/**
 * Normalize payment statuses, allowing only a fixed set of values.
 */
function normalize_payment_status($value) {
  if ($value === null) {
    return null;
  }
  $val = strtolower(trim((string)$value));
  if ($val === '') {
    return null;
  }
  $allowed = ['pending','paid','failed','processing','refunded'];
  return in_array($val, $allowed, true) ? $val : null;
}

// Whitelist of columns allowed to be inserted (matching actual database schema)
$allowed = [
  'user_id','maid_id','assigned_at','assigned_by','assignment_notes',
  'partner_id','service_id','service_name','booking_reference',
  'booking_date','booking_time','time_slot','address','phone','special_instructions',
  'duration_hours','total_amount','discount_amount','final_amount',
  'selected_price_option','status',
  'payment_method','payment_status','payment_id','transaction_id','gateway_response',
  'billing_name','billing_phone','billing_address','payment_completed_at','payment_failed_at',
  'subscription_plan','subscription_plan_details',
  'customer_notes','maid_notes','admin_notes','address_details','service_requirements',
  'confirmed_at','started_at','completed_at','allocated_at','cancelled_at',
  'created_at','updated_at'
];

// Minimal required fields
$required = ['user_id','service_id','booking_date','booking_time','final_amount'];
foreach ($required as $r) {
  if (!array_key_exists($r, $data) || $data[$r] === null || $data[$r] === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => "Missing required: $r"]);
    exit;
  }
}

// Filter payload
$insert = [];
foreach ($allowed as $col) {
  if (array_key_exists($col, $data)) {
    $insert[$col] = $data[$col];
  }
}

// Normalize payment details so Razorpay and COD values are consistent
$rawPaymentMethod = $insert['payment_method'] ?? ($data['payment_method'] ?? null);
$normalizedPaymentMethod = normalize_payment_method($rawPaymentMethod);

if ($normalizedPaymentMethod === null) {
  if ($rawPaymentMethod === null) {
    // Default to Razorpay when client didn't pass any payment method
    $normalizedPaymentMethod = 'razorpay';
  } else {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Unsupported payment_method. Use razorpay or cod.']);
    exit;
  }
}

$insert['payment_method'] = $normalizedPaymentMethod;

$rawPaymentStatus = $insert['payment_status'] ?? ($data['payment_status'] ?? null);
$normalizedPaymentStatus = normalize_payment_status($rawPaymentStatus);

if ($normalizedPaymentStatus === null) {
  $normalizedPaymentStatus = $normalizedPaymentMethod === 'cod' ? 'pending' : 'paid';
}

$insert['payment_status'] = $normalizedPaymentStatus;

if ($normalizedPaymentMethod === 'cod') {
  // COD should not be forced to send gateway identifiers
  $insert['payment_id'] = array_key_exists('payment_id', $insert) ? ($insert['payment_id'] ?: null) : null;
  $insert['transaction_id'] = array_key_exists('transaction_id', $insert) ? ($insert['transaction_id'] ?: null) : null;
  $insert['gateway_response'] = array_key_exists('gateway_response', $insert) ? ($insert['gateway_response'] ?: null) : null;
} else {
  // For online payments make sure empty strings are not stored
  if (array_key_exists('payment_id', $insert) && $insert['payment_id'] !== null) {
    $insert['payment_id'] = trim((string)$insert['payment_id']);
  }
  if (array_key_exists('transaction_id', $insert) && $insert['transaction_id'] !== null) {
    $insert['transaction_id'] = trim((string)$insert['transaction_id']);
  }
}

// Normalize JSON columns if your schema uses JSON type with CHECK(JSON_VALID(...))
// Handle address_details
if (array_key_exists('address_details', $insert)) {
  $v = $insert['address_details'];
  if (is_array($v)) {
    $insert['address_details'] = json_encode($v, JSON_UNESCAPED_UNICODE);
  } elseif (is_string($v)) {
    $trim = trim($v);
    if ($trim === '') {
      $insert['address_details'] = null;
    } else {
      // If not already a valid JSON string, wrap it
      json_decode($trim, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        $insert['address_details'] = json_encode(['address' => $v], JSON_UNESCAPED_UNICODE);
      } else {
        $insert['address_details'] = $trim;
      }
    }
  }
}

// Handle service_requirements (also a JSON column)
if (array_key_exists('service_requirements', $insert)) {
  $v = $insert['service_requirements'];
  if (is_array($v)) {
    $insert['service_requirements'] = json_encode($v, JSON_UNESCAPED_UNICODE);
  } elseif (is_string($v)) {
    $trim = trim($v);
    if ($trim === '') {
      $insert['service_requirements'] = null;
    } else {
      // Validate JSON
      json_decode($trim, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        // If not valid JSON, wrap it
        $insert['service_requirements'] = json_encode(['requirements' => $v], JSON_UNESCAPED_UNICODE);
      } else {
        $insert['service_requirements'] = $trim;
      }
    }
  }
}

// Handle subscription_plan_details (also a JSON column)
if (array_key_exists('subscription_plan_details', $insert)) {
  $v = $insert['subscription_plan_details'];
  if (is_array($v)) {
    $insert['subscription_plan_details'] = json_encode($v, JSON_UNESCAPED_UNICODE);
  } elseif (is_string($v)) {
    $trim = trim($v);
    if ($trim === '') {
      $insert['subscription_plan_details'] = null;
    } else {
      // Validate JSON
      json_decode($trim, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        // If not valid JSON, wrap it
        $insert['subscription_plan_details'] = json_encode(['details' => $v], JSON_UNESCAPED_UNICODE);
      } else {
        $insert['subscription_plan_details'] = $trim;
      }
    }
  }
}

// Auto timestamps if not set (convert ISO8601 to MySQL datetime format if needed)
$now = date('Y-m-d H:i:s');
if (!isset($insert['created_at']) || empty($insert['created_at'])) {
  $insert['created_at'] = $now;
} elseif (is_string($insert['created_at']) && strpos($insert['created_at'], 'T') !== false) {
  // Convert ISO8601 format to MySQL datetime
  try {
    $dt = new DateTime($insert['created_at']);
    $insert['created_at'] = $dt->format('Y-m-d H:i:s');
  } catch (Exception $e) {
    $insert['created_at'] = $now;
  }
}
if (!isset($insert['updated_at']) || empty($insert['updated_at'])) {
  $insert['updated_at'] = $now;
} elseif (is_string($insert['updated_at']) && strpos($insert['updated_at'], 'T') !== false) {
  // Convert ISO8601 format to MySQL datetime
  try {
    $dt = new DateTime($insert['updated_at']);
    $insert['updated_at'] = $dt->format('Y-m-d H:i:s');
  } catch (Exception $e) {
    $insert['updated_at'] = $now;
  }
}

// Build INSERT
$cols = array_keys($insert);
$phs  = array_map(fn($c) => ':' . $c, $cols);
$sql  = 'INSERT INTO `' . $table . '` (' . implode(',', array_map(fn($c) => '`'.$c.'`', $cols)) . ') VALUES (' . implode(',', $phs) . ')';

try {
  $stmt = $pdo->prepare($sql);
  foreach ($insert as $k => $v) {
    if (is_int($v))          { $type = PDO::PARAM_INT; }
    elseif (is_bool($v))     { $type = PDO::PARAM_BOOL; }
    elseif ($v === null)     { $type = PDO::PARAM_NULL; }
    else                     { $type = PDO::PARAM_STR; }
    $stmt->bindValue(':' . $k, $v, $type);
  }
  $stmt->execute();
  $id = $pdo->lastInsertId();
  echo json_encode(['success' => true, 'id' => $id]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Insert failed', 'error' => $e->getMessage()]);
}

