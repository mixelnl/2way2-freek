<?php
require_once __DIR__ . '/../system/helperfunctions.php';

header('Content-Type: application/json');

// Start session
session_start();

try {
    // Clean up cookie file if exists
    $cookieFile = $_SESSION['cookie_file'] ?? '';
    if (!empty($cookieFile) && file_exists($cookieFile)) {
        cleanupCookieFile($cookieFile);
    }
    
    // Clear all session data
    $_SESSION = [];
    
    // Destroy the session
    session_destroy();
    
    echo json_encode([
        'success' => true,
        'message' => 'Session reset successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
?>
