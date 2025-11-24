<?php
require_once __DIR__ . '/../system/helperfunctions.php';

header('Content-Type: application/json');

// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['login_success']) || $_SESSION['login_success'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Please login first']);
    exit;
}

// Get fetch URL from POST
$fetchUrl = $_POST['fetchUrl'] ?? '';

if (empty($fetchUrl)) {
    echo json_encode(['success' => false, 'error' => 'Fetch URL is required']);
    exit;
}

// Validate URL
if (!filter_var($fetchUrl, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid URL format']);
    exit;
}

// Get cookie file from session
$cookieFile = $_SESSION['cookie_file'] ?? '';

if (empty($cookieFile) || !file_exists($cookieFile)) {
    echo json_encode(['success' => false, 'error' => 'Session expired, please login again']);
    exit;
}

try {
    // Use helper function to fetch content
    $result = fetchPageContent($fetchUrl, $cookieFile);
    
    if (!$result['success']) {
        echo json_encode(['success' => false, 'error' => $result['error']]);
        exit;
    }
    
    $body = $result['body'];
    $finalUrl = $result['finalUrl'];
    $httpCode = $result['httpCode'];
    $isLoginPage = $result['isLoginPage'];
    
    // Extract specific content
    $extractedText = extractContent($body);
    
    if ($isLoginPage) {
        // Store debug info
        $_SESSION['fetched_content'] = $body;
        $_SESSION['fetch_url'] = $fetchUrl;
        $_SESSION['fetch_final_url'] = $finalUrl;
        $_SESSION['fetch_http_code'] = $httpCode;
        
        echo json_encode([
            'success' => false, 
            'error' => 'Session appears to have expired. Please login again.',
            'redirected_to_login' => true,
            'debug_info' => [
                'http_code' => $httpCode,
                'final_url' => $finalUrl
            ]
        ]);
        exit;
    }
    
    // Store extracted content
    $_SESSION['fetched_content'] = $extractedText;
    $_SESSION['fetch_url'] = $fetchUrl;
    $_SESSION['fetch_final_url'] = $finalUrl;
    $_SESSION['fetch_http_code'] = $httpCode;
    
    echo json_encode([
        'success' => true,
        'message' => 'Content fetched successfully',
        'content' => $extractedText,
        'final_url' => $finalUrl,
        'http_code' => $httpCode,
        'content_length' => strlen($extractedText)
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
?>
