<?php

include(__DIR__ . '/config.php');
/**
 * Clean up temporary cookie files
 */
function cleanupCookieFile($cookieFile) {
    if (!empty($cookieFile) && file_exists($cookieFile)) {
        unlink($cookieFile);
    }
}

/**
 * Validate URL format
 */
function isValidUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Get domain from URL
 */
function getDomainFromUrl($url) {
    $parsedUrl = parse_url($url);
    return $parsedUrl['host'] ?? '';
}

/**
 * Check if URLs are from same domain
 */
function isSameDomain($url1, $url2) {
    $domain1 = getDomainFromUrl($url1);
    $domain2 = getDomainFromUrl($url2);
    
    return !empty($domain1) && !empty($domain2) && $domain1 === $domain2;
}

/**
 * Create cURL handle with common settings
 */
function createCurlHandle($url, $cookieFile = '') {
    $ch = curl_init();
    
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_TIMEOUT => 30
    ];
    
    if (!empty($cookieFile)) {
        $options[CURLOPT_COOKIEFILE] = $cookieFile;
        $options[CURLOPT_COOKIEJAR] = $cookieFile;
    }
    
    curl_setopt_array($ch, $options);
    
    return $ch;
}

/**
 * Send JSON response
 */
function sendJsonResponse($success, $message = '', $data = []) {
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if (!empty($data)) {
        $response = array_merge($response, $data);
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

/**
 * Log error message (for debugging)
 */
function logError($message) {
    error_log('[WebScraper] ' . $message);
}

/**
 * Generate temporary cookie file path
 */
function generateCookieFile() {
    return tempnam(sys_get_temp_dir(), 'webscraper_cookie_');
}

/**
 * Extract specific content from HTML
 */
function extractContent($html) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    
    // Convert non-ASCII characters to numeric HTML entities to ensure DOMDocument parses UTF-8 correctly
    $encodedBody = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
    $dom->loadHTML($encodedBody);
    
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    // Find elements containing the specific text
    $nodes = $xpath->query("//*[contains(text(), 'Versie aanmaken of muteren')]");
    
    if ($nodes->length > 0) {
        $node = $nodes->item(0);
        // Go 4 levels up
        for ($i = 0; $i < 4; $i++) {
            if ($node->parentNode) {
                $node = $node->parentNode;
            }
        }
        
        // Get text content of that container
        $extractedText = trim($node->textContent);
        
        // Clean up extra whitespace
        return preg_replace('/\s+/', ' ', $extractedText);
    }
    
    // Fallback: If specific content not found, just return the body text (cleaned)
    // Remove scripts and styles first
    $scripts = $dom->getElementsByTagName('script');
    while ($scripts->length > 0) {
        $scripts->item(0)->parentNode->removeChild($scripts->item(0));
    }
    $styles = $dom->getElementsByTagName('style');
    while ($styles->length > 0) {
        $styles->item(0)->parentNode->removeChild($styles->item(0));
    }
    
    $bodyText = trim($dom->getElementsByTagName('body')->item(0)->textContent);
    return preg_replace('/\s+/', ' ', $bodyText);
}

/**
 * Fetch page content with cookies and handle login detection
 */
function fetchPageContent($url, $cookieFile) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
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
        CURLOPT_AUTOREFERER => true,
        CURLOPT_VERBOSE => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    
    curl_close($ch);
    
    if ($response === false) {
        return ['success' => false, 'error' => 'Failed to fetch URL'];
    }
    
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    // Login detection logic
    $responseLower = strtolower($body);
    $urlLower = strtolower($finalUrl);
    $loginIndicators = ['login', 'signin', 'sign-in', 'log-in', 'authenticate'];
    $isLoginPage = false;
    
    foreach ($loginIndicators as $indicator) {
        if (strpos($urlLower, $indicator) !== false) {
            if (strpos($responseLower, '<form') !== false && strpos($responseLower, 'password') !== false) {
                $isLoginPage = true;
                break;
            }
        }
    }
    
    if (!$isLoginPage && strlen($body) < 5000) {
         foreach ($loginIndicators as $indicator) {
            if (strpos($responseLower, $indicator) !== false) {
                if (strpos($responseLower, '<form') !== false && strpos($responseLower, 'password') !== false) {
                    $isLoginPage = true;
                    break;
                }
            }
        }
    }
    
    return [
        'success' => true,
        'body' => $body,
        'headers' => $headers,
        'finalUrl' => $finalUrl,
        'httpCode' => $httpCode,
        'isLoginPage' => $isLoginPage
    ];
}

/**
 * Fetch contracts list
 * ALWAYS uses Livewire endpoint as requested.
 */
function fetchContractsList($cookieFile) {
    // We need a CSRF token first, usually from the main page meta tag.
    // Or we can try to use the one from the cookie if possible? 
    // Livewire usually checks the X-CSRF-TOKEN header which must match the session.
    // Let's quickly grab the main page JUST for the token.
    $mainUrl = 'https://www.acc.fleet.nl/1/contracts';
    $mainPage = fetchPageContent($mainUrl, $cookieFile);
    
    $csrfToken = '';
    if ($mainPage['success']) {
        if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $mainPage['body'], $matches)) {
            $csrfToken = $matches[1];
        }
    }
    
    // If we failed to get a token, we can try without or fail. 
    // But let's proceed with what we have.
    
    $url = 'https://www.acc.fleet.nl/livewire/update';
    
    $ch = curl_init();
    
    $headers = [
        'accept: */*',
        'accept-language: nl-NL,nl;q=0.9,en-US;q=0.8,en;q=0.7',
        'content-type: application/json',
        'origin: https://www.acc.fleet.nl',
        'referer: https://www.acc.fleet.nl/1/contracts',
        'x-livewire: true',
        'x-csrf-token: ' . $csrfToken
    ];

    // The Livewire payload (Snapshot of the table component)
    // Using the large payload provided by user, injecting token if we have one (or keeping existing if not)
    // Note: The "snapshot" part contains a checksum which might be invalid if we change data structure too much.
    // But we are only changing the request _token, not the component state.
    
    $rawPayload = '{"_token":"' . ($csrfToken ?: 'hRer2SgzVo4edtGecDSFXfoNw37WnhwKy0wwx84w') . '","components":[{"snapshot":"{\"data\":{\"isTableReordering\":false,\"tableFilters\":[{\"status\":[{\"value\":null},{\"s\":\"arr\"}],\"contract_type\":[{\"value\":null},{\"s\":\"arr\"}]},{\"s\":\"arr\"}],\"tableGrouping\":null,\"tableGroupingDirection\":null,\"tableSearch\":\"\",\"tableSortColumn\":null,\"tableSortDirection\":null,\"activeTab\":\"all\",\"mountedActions\":[[],{\"s\":\"arr\"}],\"mountedActionsArguments\":[[],{\"s\":\"arr\"}],\"mountedActionsData\":[[],{\"s\":\"arr\"}],\"defaultAction\":null,\"defaultActionArguments\":null,\"componentFileAttachments\":[[],{\"s\":\"arr\"}],\"areFormStateUpdateHooksDisabledForTesting\":false,\"mountedFormComponentActions\":[[],{\"s\":\"arr\"}],\"mountedFormComponentActionsArguments\":[[],{\"s\":\"arr\"}],\"mountedFormComponentActionsData\":[[],{\"s\":\"arr\"}],\"mountedFormComponentActionsComponents\":[[],{\"s\":\"arr\"}],\"mountedInfolistActions\":[[],{\"s\":\"arr\"}],\"mountedInfolistActionsData\":[[],{\"s\":\"arr\"}],\"mountedInfolistActionsComponent\":null,\"mountedInfolistActionsInfolist\":null,\"isTableLoaded\":false,\"tableRecordsPerPage\":\"50\",\"tableColumnSearches\":[[],{\"s\":\"arr\"}],\"toggledTableColumns\":[{\"supplier\":[{\"name\":true},{\"s\":\"arr\"}],\"conditions_count\":true},{\"s\":\"arr\"}],\"mountedTableActions\":[[],{\"s\":\"arr\"}],\"mountedTableActionsData\":[[],{\"s\":\"arr\"}],\"mountedTableActionsArguments\":[[],{\"s\":\"arr\"}],\"mountedTableActionRecord\":null,\"defaultTableAction\":null,\"defaultTableActionArguments\":null,\"defaultTableActionRecord\":null,\"selectedTableRecords\":[[],{\"s\":\"arr\"}],\"mountedTableBulkAction\":null,\"mountedTableBulkActionData\":[[],{\"s\":\"arr\"}],\"tableDeferredFilters\":null,\"paginators\":[{\"page\":1},{\"s\":\"arr\"}]},\"memo\":{\"id\":\"Dv8M6ieKAqa4V3F8YOfM\",\"name\":\"app.filament.two-way-two.resources.contract-resource.pages.list-contracts\",\"path\":\"1\\\\/contracts\",\"method\":\"GET\",\"children\":[],\"scripts\":[\"3104247318-0\"],\"assets\":[],\"errors\":[],\"locale\":\"nl\"},\"checksum\":\"246c797ee4a7935f9bee699b4c59bd5b113ef137cf64c086f0239e037741870f\"}","updates":{"tableRecordsPerPage":"100"},"calls":[]}]}';

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $rawPayload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => $error];
    }

    return ['success' => true, 'body' => $response];
}

/**
 * Parse contracts list from response (HTML or JSON)
 */
function parseContractsList($response) {
    $html = $response;
    
    // Try to decode as JSON first, in case it IS a Livewire response
    $data = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (isset($data['components'][0]['effects']['html'])) {
            $html = $data['components'][0]['effects']['html'];
        } elseif (isset($data['effects']['html'])) {
            $html = $data['effects']['html'];
        }
    }
    
    // Process HTML
    if (empty($html) || strlen($html) < 100) {
         return "Geen contractenlijst gevonden (lege of te korte HTML).";
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    // Use relaxed parsing options
    $dom->loadHTML(mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    
    // Find all rows or links
    // Pattern: /contracts/{number}
    $links = $xpath->query("//a[contains(@href, '/contracts/')]");
    
    $contracts = [];
    $seenUrls = [];
    
    if ($links->length === 0) {
        return "Geen contracten gevonden op de pagina.";
    }
    
    foreach ($links as $link) {
        $href = $link->getAttribute('href');
        // Ensure it ends with a number (ID)
        if (preg_match('/\/contracts\/\d+$/', $href)) {
            if (isset($seenUrls[$href])) continue;
            $seenUrls[$href] = true;
            
            // Get the row text (tr parent usually)
            $row = $link;
            $depth = 0;
            while ($row->nodeName !== 'tr' && $row->parentNode && $depth < 5) {
                $row = $row->parentNode;
                $depth++;
            }
            
            $text = trim($row->textContent);
            $text = preg_replace('/\s+/', ' ', $text); // Clean whitespace
            
            $contracts[] = "CONTRACT: $text | URL: $href";
        }
    }
    
    if (empty($contracts)) {
        return "Geen geldige contractlinks gevonden.";
    }
    
    return implode("\n", $contracts);
}

