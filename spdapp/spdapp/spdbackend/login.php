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

// Check if this is a Google Sign-In request
$isGoogleSignIn = isset($input['google_sign_in']) && $input['google_sign_in'] === true;

if ($isGoogleSignIn) {
    // Google Sign-In flow
    if (!isset($input['email'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email is required for Google Sign-In']);
        exit();
    }

    $email = mysqli_real_escape_string($conn, trim($input['email']));
    
    // Log the email being used for login (for debugging)
    error_log("🔐 [GOOGLE SIGN-IN LOGIN] Email received from Google: " . $email);
    
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email cannot be empty']);
        exit();
    }

    // Check if user exists by email
    $stmt = $conn->prepare("SELECT id, name, email, phone, password, role, is_active, profile_image FROM users WHERE email = ?");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit();
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // User doesn't exist, return error so frontend can register
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found. Please register first.']);
        $stmt->close();
        exit();
    }

    $user = $result->fetch_assoc();

    // Check if user is active
    if (isset($user['is_active']) && $user['is_active'] != 1) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your account is inactive. Please contact administrator.']);
        $stmt->close();
        exit();
    }

    // Log successful login with email
    error_log("✅ [GOOGLE SIGN-IN LOGIN] User found in database! ID: {$user['id']}, Email: {$user['email']}");

    // Google Sign-In successful (no password verification needed)
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'] ?? '',
            'email' => $user['email'] ?? '',
            'phone' => $user['phone'] ?? '',
            'role' => $user['role'] ?? '',
            'profile_image' => $user['profile_image'] ?? null
        ]
    ]);
    $stmt->close();
} else {
    // Regular phone/password login
    if (!isset($input['phone']) || !isset($input['password'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Phone number and password are required']);
        exit();
    }

    $phone = mysqli_real_escape_string($conn, trim($input['phone']));
    $password = $input['password'];

    // Validate phone is not empty
    if (empty($phone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Phone number cannot be empty']);
        exit();
    }

    // Prepare and execute query - check phone and password columns
    $stmt = $conn->prepare("SELECT id, name, email, phone, password, role, is_active, profile_image FROM users WHERE phone = ?");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit();
    }

    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid phone number or password']);
        $stmt->close();
        exit();
    }

    $user = $result->fetch_assoc();

    // Check if user is active
    if (isset($user['is_active']) && $user['is_active'] != 1) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your account is inactive. Please contact administrator.']);
        $stmt->close();
        exit();
    }

    // Verify password (assuming passwords are hashed using password_hash)
    if (password_verify($password, $user['password'])) {
        // Login successful
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'] ?? '',
                'email' => $user['email'] ?? '',
                'phone' => $user['phone'],
                'role' => $user['role'] ?? '',
                'profile_image' => $user['profile_image'] ?? null
            ]
        ]);
    } else {
        // Invalid password
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid phone number or password']);
    }
    $stmt->close();
}

$conn->close();
?>

