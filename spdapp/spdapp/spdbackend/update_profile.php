<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_config.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['user_id']) || !isset($input['name']) || !isset($input['email']) || !isset($input['phone']) || !isset($input['address'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User ID, name, email, phone, and address are required']);
    exit();
}

$userId = intval($input['user_id']);
$name = mysqli_real_escape_string($conn, trim($input['name']));
$email = mysqli_real_escape_string($conn, trim($input['email']));
$phone = mysqli_real_escape_string($conn, trim($input['phone']));
$address = mysqli_real_escape_string($conn, trim($input['address']));
$password = isset($input['password']) && !empty($input['password']) ? $input['password'] : null;

// Validate fields are not empty
if (empty($name) || empty($email) || empty($phone) || empty($address)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name, email, phone, and address cannot be empty']);
    exit();
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit();
}

// Validate password length if provided
if ($password !== null && strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
    exit();
}

// Check if user exists
$stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit();
}

$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    $stmt->close();
    $conn->close();
    exit();
}
$stmt->close();

// Check if phone is already taken by another user
$stmt = $conn->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit();
}

$stmt->bind_param("si", $phone, $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Phone number already registered to another user']);
    $stmt->close();
    $conn->close();
    exit();
}
$stmt->close();

// Check if email is already taken by another user
$stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit();
}

$stmt->bind_param("si", $email, $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Email already registered to another user']);
    $stmt->close();
    $conn->close();
    exit();
}
$stmt->close();

// Update user profile
if ($password !== null) {
    // Update with password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, phone = ?, address = ?, password = ?, updated_at = NOW() WHERE id = ?");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param("sssssi", $name, $email, $phone, $address, $hashedPassword, $userId);
} else {
    // Update without password
    $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, phone = ?, address = ?, updated_at = NOW() WHERE id = ?");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param("ssssi", $name, $email, $phone, $address, $userId);
}

$result = $stmt->execute();

if ($result) {
    // Fetch updated user data including profile_image
    $stmt2 = $conn->prepare("SELECT id, name, email, phone, address, profile_image, role FROM users WHERE id = ?");
    $stmt2->bind_param("i", $userId);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    $updatedUser = $result2->fetch_assoc();
    $stmt2->close();
    
    // Update successful
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully',
        'user' => [
            'id' => $updatedUser['id'] ?? null,
            'name' => $updatedUser['name'] ?? '',
            'email' => $updatedUser['email'] ?? '',
            'phone' => $updatedUser['phone'] ?? '',
            'address' => $updatedUser['address'] ?? '',
            'profile_image' => $updatedUser['profile_image'] ?? null,
            'role' => $updatedUser['role'] ?? ''
        ]
    ]);
} else {
    // Update failed
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update profile. Please try again.']);
}

$stmt->close();
$conn->close();
?>

