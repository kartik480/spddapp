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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit();
    }

    if (!isset($_GET['user_id']) || empty($_GET['user_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        exit();
    }

    $userId = intval($_GET['user_id']);

    // Check if wallet exists, if not create it
    $stmt = $conn->prepare("SELECT balance FROM wallets WHERE user_id = ?");
    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // Create wallet with zero balance
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO wallets (user_id, balance, created_at, updated_at) VALUES (?, 0.00, NOW(), NOW())");
        if (!$stmt) {
            throw new Exception('Database error: ' . $conn->error);
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $balance = 0.00;
    } else {
        $wallet = $result->fetch_assoc();
        $balance = floatval($wallet['balance']);
    }
    $stmt->close();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'balance' => $balance,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
} catch (Error $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A server error occurred. Please try again later.'
    ]);
}

$conn->close();
?>

