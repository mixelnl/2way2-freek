<?php
require_once __DIR__ . '/../system/helperfunctions.php';

header('Content-Type: application/json');

// Start session
session_start();

// Get login URL from POST
$loginUrl = $_POST['loginUrl'] ?? '';

if (empty($loginUrl)) {
    echo json_encode(['success' => false, 'error' => 'Login URL is required']);
    exit;
}

// Validate URL
if (!filter_var($loginUrl, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid URL format']);
    exit;
}

try {
    // Create a more persistent cookie file with a unique name
    $cookieId = 'webscraper_' . uniqid() . '_' . time();
    $cookieFile = sys_get_temp_dir() . '/' . $cookieId . '.txt';
    
    // Step 1: Visit the magic link page
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $loginUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_AUTOREFERER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    
    curl_close($ch);
    
    if ($response === false) {
        cleanupCookieFile($cookieFile);
        echo json_encode(['success' => false, 'error' => 'Failed to fetch magic link page']);
        exit;
    }
    
    // Check if this is a Livewire page
    $isLivewirePage = strpos($response, 'wire:snapshot') !== false;
    $livewireSuccess = false;
    $csrfToken = '';
    $componentId = '';
    $wireSnapshot = '';
    
    if ($isLivewirePage) {
        // Extract the component ID
        if (preg_match('/wire:id="([^"]+)"/', $response, $matches)) {
            $componentId = $matches[1];
        }
        
        // Extract CSRF token from meta tag
        if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $response, $matches)) {
            $csrfToken = $matches[1];
        }
        
        // Extract CSRF token from hidden input
        if (empty($csrfToken) && preg_match('/<input[^>]*name=["\']_token["\'][^>]*value=["\']([^"\']+)["\']/', $response, $matches)) {
            $csrfToken = $matches[1];
        }
        
        // Extract the wire snapshot data
        if (preg_match('/wire:snapshot="([^"]+)"/', $response, $matches)) {
            $wireSnapshot = html_entity_decode($matches[1]);
        }
        
        // Build the Livewire update request
        if (!empty($componentId) && !empty($wireSnapshot)) {
            $baseUrl = parse_url($finalUrl, PHP_URL_SCHEME) . '://' . parse_url($finalUrl, PHP_URL_HOST);
            $livewireUpdateUrl = $baseUrl . '/livewire/update';
            
            // Build the payload structure based on the actual curl command
            $payload = [
                '_token' => $csrfToken,
                'components' => [
                    [
                        'snapshot' => $wireSnapshot,
                        'updates' => (object)[],  // Empty object for updates
                        'calls' => [
                            [
                                'path' => '',
                                'method' => 'submit',
                                'params' => []
                            ]
                        ]
                    ]
                ]
            ];
            
            $ch = curl_init();
            
            $headers = [
                'accept: */*',
                'accept-language: nl-NL,nl;q=0.9,en-US;q=0.8,en;q=0.7',
                'cache-control: no-cache',
                'content-type: application/json',
                'origin: ' . $baseUrl,
                'pragma: no-cache',
                'priority: u=1, i',
                'referer: ' . $finalUrl,
                'sec-ch-ua: "Chromium";v="142", "Google Chrome";v="142", "Not_A Brand";v="99"',
                'sec-ch-ua-mobile: ?0',
                'sec-ch-ua-platform: "macOS"',
                'sec-fetch-dest: empty',
                'sec-fetch-mode: cors',
                'sec-fetch-site: same-origin',
                'user-agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
                'x-livewire:'
            ];
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $livewireUpdateUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,  // Follow redirects after Livewire call
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
                CURLOPT_COOKIEFILE => $cookieFile,
                CURLOPT_COOKIEJAR => $cookieFile,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => $headers
            ]);
            
            $livewireResponse = curl_exec($ch);
            $livewireHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $livewireFinalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            
            curl_close($ch);
            
            if ($livewireResponse !== false) {
                $livewireSuccess = true;
                $redirectUrl = '';
                
                // Parse Livewire response for redirects
                $jsonResponse = json_decode($livewireResponse, true);
                
                // Check for redirect in effects (Livewire v3)
                if (isset($jsonResponse['effects']['redirect'])) {
                    $redirectUrl = $jsonResponse['effects']['redirect'];
                } 
                // Check for redirect in component effects
                elseif (isset($jsonResponse['components'][0]['effects']['redirect'])) {
                    $redirectUrl = $jsonResponse['components'][0]['effects']['redirect'];
                }
                
                // If we found a redirect instruction, follow it!
                if (!empty($redirectUrl)) {
                    // Handle relative URLs
                    if (strpos($redirectUrl, 'http') !== 0) {
                        $baseUrl = parse_url($finalUrl, PHP_URL_SCHEME) . '://' . parse_url($finalUrl, PHP_URL_HOST);
                        $redirectUrl = $baseUrl . (strpos($redirectUrl, '/') === 0 ? '' : '/') . $redirectUrl;
                    }
                    
                    $targetUrl = $redirectUrl;
                    $step3Message = 'Following Livewire redirect...';
                } else {
                    // If no explicit redirect, visit the current URL again to refresh state
                    $targetUrl = $finalUrl;
                    $step3Message = 'Refreshing page state...';
                }

                // Step 3: Follow redirect or refresh page
                $ch = curl_init();
                
                curl_setopt_array($ch, [
                    CURLOPT_URL => $targetUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
                    CURLOPT_COOKIEFILE => $cookieFile,
                    CURLOPT_COOKIEJAR => $cookieFile,
                    CURLOPT_HEADER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_AUTOREFERER => true
                ]);
                
                $finalResponse = curl_exec($ch);
                $finalHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $finalRedirectUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                
                curl_close($ch);
                
                if ($finalResponse !== false) {
                    // Update with the final authentication state
                    $response = $finalResponse;
                    $httpCode = $finalHttpCode;
                    $finalUrl = $finalRedirectUrl;
                }
            }
        }
    }
    
    // Check if we have authentication cookies
    $cookieContent = file_get_contents($cookieFile);
    $hasAuthCookies = !empty($cookieContent) && 
                     (strpos($cookieContent, 'session') !== false || 
                      strpos($cookieContent, 'token') !== false || 
                      strpos($cookieContent, 'auth') !== false ||
                      strpos($cookieContent, 'remember_') !== false ||
                      strlen($cookieContent) > 50);
    
    // Store session data with the cookie file path
    $_SESSION['login_success'] = true;
    $_SESSION['login_url'] = $loginUrl;
    $_SESSION['final_url'] = $finalUrl;
    $_SESSION['cookie_file'] = $cookieFile;
    $_SESSION['cookie_id'] = $cookieId;
    $_SESSION['http_code'] = $httpCode;
    $_SESSION['has_auth_cookies'] = $hasAuthCookies;
    $_SESSION['was_livewire'] = $isLivewirePage;
    $_SESSION['livewire_success'] = $livewireSuccess;
    
    echo json_encode([
        'success' => true,
        'message' => $isLivewirePage 
            ? ($livewireSuccess ? 'Livewire authentication completed!' : 'Livewire page detected - authentication attempted')
            : ($hasAuthCookies ? 'Login successful - authentication detected' : 'Login page loaded - cookies stored'),
        'final_url' => $finalUrl,
        'http_code' => $httpCode,
        'has_auth_cookies' => $hasAuthCookies,
        'was_livewire' => $isLivewirePage,
        'livewire_success' => $livewireSuccess
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
?>
