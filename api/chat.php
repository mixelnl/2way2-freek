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

// Get inputs
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = $input['message'] ?? $_POST['message'] ?? '';
$history = $input['history'] ?? $_POST['history'] ?? [];

if (empty($userMessage)) {
    echo json_encode(['success' => false, 'error' => 'Message is required']);
    exit;
}

// Get cookie file
$cookieFile = $_SESSION['cookie_file'] ?? '';
if (empty($cookieFile) || !file_exists($cookieFile)) {
    echo json_encode(['success' => false, 'error' => 'Session expired']);
    exit;
}

// Get Gemini API Key
$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : getenv('GEMINI_API_KEY');
if (empty($apiKey)) {
    echo json_encode(['success' => false, 'error' => 'Gemini API Key not configured']);
    exit;
}

// --- Step 1: Ensure we have the Contracts List ---
// Always fetch fresh list (no caching)
$listResult = fetchContractsList($cookieFile);
if ($listResult['success']) {
    $contractsList = parseContractsList($listResult['body']);
} else {
    $contractsList = "Kon contractenlijst niet ophalen. Error: " . ($listResult['error'] ?? 'Unknown');
}

// --- Step 2: Ask Gemini (Turn 1 - Decide Action) ---

// Construct history for context
$historyText = "";
if (!empty($history)) {
    foreach ($history as $msg) {
        $role = ($msg['role'] === 'user') ? 'User' : 'AI';
        $historyText .= "$role: {$msg['content']}\n";
    }
}

$systemInstruction = "Je bent een slimme assistent voor wagenparkbeheer.
Hier is een lijst met beschikbare leasecontracten (Livewire table snapshot).
LET OP: Dit zijn slechts SAMENVATTINGEN (wie rijdt welke auto). Details zoals kilometers, voorwaarden, prijzen, eigen risico, etc. staan HIER NIET IN.

--- START CONTRACTEN ---
$contractsList
--- EINDE CONTRACTEN ---

Jouw taak is om de vraag van de gebruiker te beantwoorden.

STAPPENPLAN:
1. Identificeer over welk contract de vraag gaat (zoek op naam, kenteken, auto in de lijst).
2. Als de gebruiker vraagt naar details (kilometers, prijs, voorwaarden, looptijd, eigen risico, etc.), MOET je een verwijsactie antwoorden.
   Reageer met JSON naar de URL van de detailpagina: {\"action\": \"fetch\", \"url\": \"/contracts/123...\"}
3. Alleen als de vraag puur gaat over de lijst zelf (bijv. 'Welke auto rijdt Jan?'), mag je direct antwoorden.
   Reageer met JSON: {\"action\": \"answer\", \"text\": \"...\"}
4. Als je de url niet zeker weet, vraag dan om verduidelijking in de 'text'.

Houd antwoorden kort en bondig (KISS). Gebruik JSON output.";

// Prepare Prompt
$prompt = "Geschiedenis:\n$historyText\n\nHuidige vraag: $userMessage\n\nWat is de volgende stap? (JSON)";

$apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=' . $apiKey;

// Function to call Gemini
function callGemini($url, $payload) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// First call payload
$payload1 = [
    'contents' => [
        ['role' => 'user', 'parts' => [['text' => $systemInstruction . "\n\n" . $prompt]]]
    ],
    'generationConfig' => [
        'temperature' => 0.1, // Low temp for precise JSON
        'responseMimeType' => 'application/json'
    ]
];

$response1 = callGemini($apiUrl, $payload1);

// Parse Response 1
$aiText1 = $response1['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
$actionData = json_decode($aiText1, true);

if (!isset($actionData['action'])) {
    // Fallback if invalid JSON
    echo json_encode(['success' => true, 'message' => "Er ging iets mis met het verwerken van de vraag. (Invalid JSON) Raw: " . substr($aiText1, 0, 100)]);
    exit;
}

// --- Step 3: Execute Action ---

if ($actionData['action'] === 'fetch' && !empty($actionData['url'])) {
    // Action: Fetch Detail Page
    $urlToFetch = $actionData['url'];
    
    // Handle absolute vs relative URLs
    if (strpos($urlToFetch, 'http') === 0) {
         $targetUrl = $urlToFetch;
    } else {
         // Ensure /1/ prefix if missing
         $cleanPath = ltrim($urlToFetch, '/');
         if (strpos($cleanPath, '1/contracts/') !== 0) {
             $cleanPath = '1/' . $cleanPath;
         }
         $targetUrl = 'https://www.acc.fleet.nl/' . $cleanPath;
    }
    
    // Fetch
    $pageResult = fetchPageContent($targetUrl, $cookieFile);
    
    if (!$pageResult['success'] || $pageResult['httpCode'] == 404) {
        $errorMsg = "Kon de details niet ophalen van $targetUrl (HTTP {$pageResult['httpCode']}).";
        echo json_encode([
            'success' => true, 
            'message' => $errorMsg,
            'debug_url' => $targetUrl,
            'debug_contracts' => $contractsList
        ]);
        exit;
    }
    
    if ($pageResult['isLoginPage']) {
         echo json_encode(['success' => false, 'error' => 'Session expired', 'redirected_to_login' => true]);
         exit;
    }
    
    $pageContent = extractContent($pageResult['body']);
    
    // Second call to Gemini to answer based on content
    $prompt2 = "Ik heb de pagina opgehaald: $targetUrl.\n\nInhoud:\n$pageContent\n\nBeantwoord nu de oorspronkelijke vraag van de gebruiker: '$userMessage'.\nAntwoord in normale tekst (geen JSON meer nodig).";
    
    $payload2 = [
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $systemInstruction . "\n\n" . $prompt2]]]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
        ]
    ];
    
    $response2 = callGemini($apiUrl, $payload2);
    $finalAnswer = $response2['candidates'][0]['content']['parts'][0]['text'] ?? 'Geen antwoord ontvangen na ophalen gegevens.';
    
    echo json_encode([
        'success' => true,
        'message' => $finalAnswer,
        'debug_contracts' => $contractsList,
        'debug_action' => 'fetch',
        'debug_url' => $targetUrl,
        'debug_ai_raw_1' => $aiText1,
        'debug_ai_raw_2' => $finalAnswer
    ]);

} else {
    // Action: Answer directly
    $answer = $actionData['text'] ?? 'Ik begreep de actie niet.';
    echo json_encode([
        'success' => true,
        'message' => $answer,
        'debug_contracts' => $contractsList,
        'debug_action' => 'direct_answer',
        'debug_ai_raw_1' => $aiText1,
        'debug_ai_parsed' => $actionData
    ]);
}
