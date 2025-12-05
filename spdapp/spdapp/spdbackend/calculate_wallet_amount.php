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

    if (!isset($input['user_id']) || !isset($input['from_date']) || !isset($input['to_date'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID, from_date, and to_date are required']);
        exit();
    }

    $userId = intval($input['user_id']);
    $fromDate = $input['from_date'];
    $toDate = $input['to_date'];

    // Get all bookings for the user within the date range
    // We'll calculate remaining amount based on service price and any unused/delayed services
    $stmt = $conn->prepare("
        SELECT 
            b.id,
            b.service_name,
            b.final_amount,
            b.booking_date,
            b.status,
            b.subscription_plan_details,
            b.delay_from_month,
            b.delay_to_month,
            s.price as service_price
        FROM bookings b
        LEFT JOIN services s ON b.service_id = s.id
        WHERE b.user_id = ?
        AND b.booking_date BETWEEN ? AND ?
        AND b.status IN ('pending', 'confirmed', 'completed', 'cancelled')
        ORDER BY b.booking_date DESC
    ");

    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }

    $stmt->bind_param("iss", $userId, $fromDate, $toDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $totalRemainingAmount = 0.00;
    $bookings = [];

    while ($row = $result->fetch_assoc()) {
        $remainingAmount = 0.00;
        $servicePrice = floatval($row['final_amount'] ?? $row['service_price'] ?? 0);
        
        // Check if service has delay dates (unused period)
        if (!empty($row['delay_from_month']) && !empty($row['delay_to_month'])) {
            // Service was delayed/unused - calculate remaining amount based on unused days
            $delayFrom = new DateTime($row['delay_from_month']);
            $delayTo = new DateTime($row['delay_to_month']);
            $daysDiff = $delayFrom->diff($delayTo)->days + 1;
            
            // Calculate remaining amount proportionally (if service was for a month, unused days = remaining amount)
            if ($daysDiff > 0) {
                // For monthly subscriptions, calculate based on unused days
                $subscriptionDetails = null;
                if (!empty($row['subscription_plan_details'])) {
                    $subscriptionDetails = json_decode($row['subscription_plan_details'], true);
                }
                
                if ($subscriptionDetails && isset($subscriptionDetails['start_date']) && isset($subscriptionDetails['end_date'])) {
                    $startDate = new DateTime($subscriptionDetails['start_date']);
                    $endDate = new DateTime($subscriptionDetails['end_date']);
                    $totalDays = $startDate->diff($endDate)->days + 1;
                    
                    if ($totalDays > 0) {
                        // Calculate remaining amount based on unused days
                        $remainingAmount = ($servicePrice / $totalDays) * $daysDiff;
                    }
                } else {
                    // Fallback: if service was cancelled or unused, use full amount
                    if ($row['status'] === 'cancelled') {
                        $remainingAmount = $servicePrice;
                    } else {
                        // For delayed services, calculate proportionally
                        $remainingAmount = ($servicePrice / 30) * $daysDiff; // Assuming monthly service
                    }
                }
            }
        } else if ($row['status'] === 'cancelled') {
            // If service was cancelled, full amount is remaining
            $remainingAmount = $servicePrice;
        } else {
            // For completed services, check if there's any refund or unused portion
            // This can be customized based on business logic
            // For now, we'll only consider cancelled or delayed services
            $remainingAmount = 0.00;
        }

        if ($remainingAmount > 0) {
            $totalRemainingAmount += $remainingAmount;
            $bookings[] = [
                'id' => $row['id'],
                'service_name' => $row['service_name'],
                'booking_date' => $row['booking_date'],
                'status' => $row['status'],
                'service_price' => $servicePrice,
                'remaining_amount' => round($remainingAmount, 2),
            ];
        }
    }

    $stmt->close();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'remaining_amount' => round($totalRemainingAmount, 2),
        'bookings' => $bookings,
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

