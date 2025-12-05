<?php
// Set error reporting to prevent HTML output on errors
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, we'll handle them

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Wrap everything in try-catch to ensure JSON response
try {
    require_once 'db_config.php';
    
    // Check if db_config.php loaded successfully
    if (!isset($conn)) {
        throw new Exception('Database connection not available');
    }

    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit();
    }

    // Check if user_id is provided
    if (!isset($_POST['user_id']) || empty($_POST['user_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        exit();
    }

    $userId = intval($_POST['user_id']);

    // Validate user exists
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

    // Check if file was uploaded
    if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No image file uploaded or upload error']);
        exit();
    }

    $file = $_FILES['profile_image'];

    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $fileType = mime_content_type($file['tmp_name']);
    if (!in_array($fileType, $allowedTypes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed']);
        exit();
    }

    // Validate file size (max 5MB)
    $maxSize = 5 * 1024 * 1024; // 5MB in bytes
    if ($file['size'] > $maxSize) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit']);
        exit();
    }

    // Generate filename based on the original upload
    $originalName = $file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $cleanBaseName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $baseName);
    $cleanBaseName = preg_replace('/^scaled_+/i', '', $cleanBaseName);
    if (empty($cleanBaseName)) {
        $cleanBaseName = 'image';
    }
    $filename = $cleanBaseName . '.' . $extension;

    // Define upload directory - store in public/storage/profiles/
    // Try multiple possible paths (prioritize paths with "public" folder)
    $possiblePaths = [
        '/home/u276261179/domains/superdailys.com/public_html/public/storage/profiles/',
        __DIR__ . '/../public/storage/profiles/',
        dirname(__DIR__) . '/public/storage/profiles/',
        '/home/u276261179/domains/superdailys.com/public_html/storage/profiles/',
        __DIR__ . '/../storage/profiles/',
        dirname(__DIR__) . '/storage/profiles/',
    ];
    
    $uploadDir = null;
    foreach ($possiblePaths as $path) {
        if (is_dir($path) || is_writable(dirname($path))) {
            $uploadDir = $path;
            break;
        }
    }
    
    // If no existing directory found, use the first one (with "public")
    if ($uploadDir === null) {
        $uploadDir = $possiblePaths[0];
    }

    // Check for duplicate filenames
    $duplicateCounter = 1;
    $originalFilename = $filename;
    while (file_exists($uploadDir . $filename)) {
        $filename = $cleanBaseName . '_' . $duplicateCounter . '.' . $extension;
        $duplicateCounter++;
    }

    // Create directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to create upload directory: ' . $uploadDir]);
            exit();
        }
    }

    // Check if directory is writable
    if (!is_writable($uploadDir)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Upload directory is not writable: ' . $uploadDir]);
        exit();
    }

    $filePath = $uploadDir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        $error = error_get_last();
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to save uploaded file. Error: ' . ($error ? $error['message'] : 'Unknown error'),
            'debug' => [
                'tmp_name' => $file['tmp_name'],
                'destination' => $filePath,
                'upload_dir' => $uploadDir,
                'is_writable' => is_writable($uploadDir),
                'file_exists' => file_exists($file['tmp_name'])
            ]
        ]);
        exit();
    }

    // Build paths for storage (relative) and public URL
    // Use path with "public" to match public/storage/profiles/
    $relativePath = 'public/storage/profiles/' . $filename;
    $publicUrl = 'https://superdailys.com/public/storage/profiles/' . $filename;

    // Update database with full profile_image URL
    $stmt = $conn->prepare("UPDATE users SET profile_image = ?, updated_at = NOW() WHERE id = ?");
    if (!$stmt) {
        // Delete uploaded file if database update fails
        @unlink($filePath);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit();
    }

    $stmt->bind_param("si", $relativePath, $userId);
    $result = $stmt->execute();

    if ($result) {
        // Fetch updated user data
        $stmt2 = $conn->prepare("SELECT id, name, email, phone, address, profile_image, role FROM users WHERE id = ?");
        $stmt2->bind_param("i", $userId);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        $updatedUser = $result2->fetch_assoc();
        $stmt2->close();
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Profile image updated successfully',
            'image_url' => $publicUrl,
            'profile_image' => $relativePath, // For existing backend/website logic
            'profile_image_public' => $publicUrl, // Explicit public URL for apps
            'user' => $updatedUser
        ]);
    } else {
        // Delete uploaded file if database update fails
        @unlink($filePath);
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to update profile image in database: ' . $stmt->error
        ]);
    }

    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    // Ensure we always return JSON, even on unexpected errors
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
    exit();
} catch (Error $e) {
    // Catch PHP 7+ errors
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A server error occurred. Please try again later.'
    ]);
    exit();
}
?>

