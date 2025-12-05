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
if (!isset($input['booking_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Booking ID is required']);
    exit();
}

$bookingId = intval($input['booking_id']);
$delayFromMonth = isset($input['delay_from_month']) && !empty($input['delay_from_month']) 
    ? mysqli_real_escape_string($conn, trim($input['delay_from_month'])) 
    : null;
$delayToMonth = isset($input['delay_to_month']) && !empty($input['delay_to_month']) 
    ? mysqli_real_escape_string($conn, trim($input['delay_to_month'])) 
    : null;
// delay_reason can be an empty string to clear it, so we check if the key exists rather than if it's empty
$delayReason = isset($input['delay_reason']) 
    ? mysqli_real_escape_string($conn, trim($input['delay_reason'])) 
    : null;

// Check if booking exists
$stmt = $conn->prepare("SELECT id FROM bookings WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit();
}

$stmt->bind_param("i", $bookingId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Booking not found']);
    $stmt->close();
    $conn->close();
    exit();
}

// Get user_id from booking for validation
$bookingRow = $result->fetch_assoc();
$stmt->close();

// Get user_id from booking
$stmt = $conn->prepare("SELECT user_id FROM bookings WHERE id = ?");
$stmt->bind_param("i", $bookingId);
$stmt->execute();
$result = $stmt->get_result();
$bookingData = $result->fetch_assoc();
$userId = $bookingData['user_id'] ?? null;
$stmt->close();

// Validation: If both dates are provided, validate them
if ($delayFromMonth !== null && $delayToMonth !== null) {
    // Parse dates
    try {
        $fromDate = new DateTime($delayFromMonth);
        $toDate = new DateTime($delayToMonth);
        
        // Validation 1: Check that date range does not exceed 7 days
        $daysDifference = $fromDate->diff($toDate)->days;
        if ($daysDifference > 7) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'The date range cannot exceed 7 days. Please select dates within 7 days.']);
            $conn->close();
            exit();
        }
        
        // Validation 2: Check if user has already applied for dates in the same week/month
        if ($userId !== null) {
            // Get week number of month for the from date
            $firstDayOfMonth = new DateTime($fromDate->format('Y-m-01'));
            $firstDayWeekday = (int)$firstDayOfMonth->format('N'); // 1 = Monday, 7 = Sunday
            $dayOfMonth = (int)$fromDate->format('d');
            $weekOfMonth = floor(($dayOfMonth + $firstDayWeekday - 2) / 7) + 1;
            $year = $fromDate->format('Y');
            $month = $fromDate->format('m');
            
            // Check for existing bookings with delay dates in the same week/month
            $checkStmt = $conn->prepare("
                SELECT id, delay_from_month, delay_to_month 
                FROM bookings 
                WHERE user_id = ? 
                AND id != ? 
                AND delay_from_month IS NOT NULL 
                AND delay_to_month IS NOT NULL
            ");
            $checkStmt->bind_param("ii", $userId, $bookingId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            while ($row = $checkResult->fetch_assoc()) {
                try {
                    $existingFrom = new DateTime($row['delay_from_month']);
                    $existingTo = new DateTime($row['delay_to_month']);
                    
                    // Check if in same month and year
                    if ($existingFrom->format('Y-m') === $fromDate->format('Y-m')) {
                        // Get week number for existing date
                        $existingFirstDay = new DateTime($existingFrom->format('Y-m-01'));
                        $existingFirstDayWeekday = (int)$existingFirstDay->format('N');
                        $existingDayOfMonth = (int)$existingFrom->format('d');
                        $existingWeekOfMonth = floor(($existingDayOfMonth + $existingFirstDayWeekday - 2) / 7) + 1;
                        
                        // Check if same week
                        if ($existingWeekOfMonth === $weekOfMonth) {
                            $checkStmt->close();
                            http_response_code(400);
                            echo json_encode(['success' => false, 'message' => 'You have already applied for dates in this week of this month. You can only apply once per week per month.']);
                            $conn->close();
                            exit();
                        }
                    }
                } catch (Exception $e) {
                    // Skip invalid dates
                    continue;
                }
            }
            $checkStmt->close();
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid date format']);
        $conn->close();
        exit();
    }
}

// Build update query dynamically based on provided fields
$updateFields = [];
$updateValues = [];
$types = '';

if ($delayFromMonth !== null) {
    $updateFields[] = "delay_from_month = ?";
    $updateValues[] = $delayFromMonth;
    $types .= 's';
}

if ($delayToMonth !== null) {
    $updateFields[] = "delay_to_month = ?";
    $updateValues[] = $delayToMonth;
    $types .= 's';
}

// delay_reason can be empty string to clear it, so we always update if it's set (not null)
if ($delayReason !== null) {
    $updateFields[] = "delay_reason = ?";
    $updateValues[] = $delayReason;
    $types .= 's';
}

// Always update updated_at
$updateFields[] = "updated_at = NOW()";

if (empty($updateFields)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'At least one field (delay_from_month, delay_to_month, or delay_reason) must be provided']);
    exit();
}

// Build and execute update query
$updateQuery = "UPDATE bookings SET " . implode(", ", $updateFields) . " WHERE id = ?";
$updateValues[] = $bookingId;
$types .= 'i';

$stmt = $conn->prepare($updateQuery);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit();
}

$stmt->bind_param($types, ...$updateValues);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    echo json_encode([
        'success' => true,
        'message' => 'Booking delay information updated successfully',
        'booking_id' => $bookingId
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update booking: ' . $stmt->error]);
    $stmt->close();
    $conn->close();
}
?>

