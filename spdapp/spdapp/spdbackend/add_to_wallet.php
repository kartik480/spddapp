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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['user_id']) || !isset($input['amount']) || !isset($input['from_date']) || !isset($input['to_date'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID, amount, from_date, and to_date are required']);
        exit();
    }

    $userId = intval($input['user_id']);
    $amount = floatval($input['amount']);
    $fromDate = $input['from_date'];
    $toDate = $input['to_date'];

    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
        exit();
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Check if wallet exists, if not create it
        $stmt = $conn->prepare("SELECT id, balance FROM wallets WHERE user_id = ?");
        if (!$stmt) {
            throw new Exception('Database error: ' . $conn->error);
        }

        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            // Create wallet
            $stmt->close();
            $stmt = $conn->prepare("INSERT INTO wallets (user_id, balance, created_at, updated_at) VALUES (?, 0.00, NOW(), NOW())");
            if (!$stmt) {
                throw new Exception('Database error: ' . $conn->error);
            }
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $walletId = $conn->insert_id;
            $currentBalance = 0.00;
        } else {
            $wallet = $result->fetch_assoc();
            $walletId = $wallet['id'];
            $currentBalance = floatval($wallet['balance']);
        }
        $stmt->close();

        // Update wallet balance
        $newBalance = $currentBalance + $amount;
        $stmt = $conn->prepare("UPDATE wallets SET balance = ?, updated_at = NOW() WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Database error: ' . $conn->error);
        }
        $stmt->bind_param("di", $newBalance, $walletId);
        $stmt->execute();
        $stmt->close();

        // Create wallet transaction record
        $stmt = $conn->prepare("
            INSERT INTO wallet_transactions 
            (wallet_id, user_id, amount, transaction_type, description, from_date, to_date, created_at) 
            VALUES (?, ?, ?, 'credit', ?, ?, ?, NOW())
        ");
        if (!$stmt) {
            throw new Exception('Database error: ' . $conn->error);
        }
        
        $description = "Remaining amount from services (From: $fromDate To: $toDate)";
        $transactionType = 'credit';
        $stmt->bind_param("iidsss", $walletId, $userId, $amount, $transactionType, $description, $fromDate, $toDate);
        $stmt->execute();
        $stmt->close();

        // Commit transaction
        $conn->commit();

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Amount added to wallet successfully',
            'new_balance' => $newBalance,
            'amount_added' => $amount,
        ]);
    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        throw $e;
    }
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

