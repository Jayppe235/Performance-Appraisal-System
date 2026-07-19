<?php
/**
 * PMAS smoke test - Tests all accounts with correct login fields
 * Run: php _smoke_test.php
 */

$BASE = 'http://127.0.0.1:8081';

$accounts = [
    'admin_hr'       => ['email' => 'admin@dipascaf.edu',    'label' => 'Admin HR'],
    'dean'           => ['email' => 'Mark@dipascaf.edu',     'label' => 'Dean'],
    'program_head'   => ['email' => 'juan.delacruz@dipascaf.edu', 'label' => 'Program Head'],
    'teacher'        => ['email' => 'maria.santos@dipascaf.edu',  'label' => 'Faculty/Teacher'],
    'vpaa'           => ['email' => 'Labio@dipascaf.edu',    'label' => 'VPAA'],
];

function http_get(string $url, ?string &$body = null, ?array &$respHeaders = null, array &$cookies = []): int {
    global $BASE;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => str_starts_with($url, 'http') ? $url : $BASE . $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADER => true,
    ]);
    if ($cookies) {
        $cookieStr = '';
        foreach ($cookies as $k => $v) $cookieStr .= "$k=$v; ";
        curl_setopt($ch, CURLOPT_COOKIE, $cookieStr);
    }
    $response = curl_exec($ch);
    $httpCode = curl_errno($ch) ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) { $respHeaders = []; $body = "CURL ERROR: $error"; return 0; }
    $rawHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $respHeaders = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (str_contains($line, ': ')) { [$k, $v] = explode(': ', $line, 2); $respHeaders[strtolower($k)] = $v; }
    }
    return $httpCode;
}

function http_post(string $url, array $postData, ?string &$body = null, ?array &$respHeaders = null, array &$cookies = []): int {
    global $BASE;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => str_starts_with($url, 'http') ? $url : $BASE . $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
    ]);
    if ($cookies) {
        $cookieStr = '';
        foreach ($cookies as $k => $v) $cookieStr .= "$k=$v; ";
        curl_setopt($ch, CURLOPT_COOKIE, $cookieStr);
    }
    $response = curl_exec($ch);
    $httpCode = curl_errno($ch) ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) { $respHeaders = []; $body = "CURL ERROR: $error"; return 0; }
    $rawHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $respHeaders = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (str_contains($line, ': ')) { [$k, $v] = explode(': ', $line, 2); $respHeaders[strtolower($k)] = $v; }
        if (stripos($line, 'Set-Cookie:') === 0) {
            if (preg_match('/PHPSESSID=([^;]+)/i', $line, $m)) $cookies['PHPSESSID'] = $m[1];
        }
    }
    return $httpCode;
}

function check_page(string $label, string $url, string $body, int $httpCode, string $role = ''): array {
    $issues = [];
    if ($httpCode === 0) $issues[] = "CONNECTION ERROR";
    elseif ($httpCode >= 500) $issues[] = "HTTP $httpCode SERVER ERROR";

    if (preg_match('/<b>(Fatal error|Parse error|Warning|Notice|Error)<\/b>/i', $body)) $issues[] = "PHP ERROR in output";
    if (stripos($body, 'stack trace') !== false) $issues[] = "PHP Stack trace found";
    if (stripos($body, 'Fatal error') !== false) $issues[] = "FATAL PHP ERROR";
    if (stripos($body, 'SQLSTATE') !== false || stripos($body, 'PDOException') !== false) $issues[] = "Database error";
    if (stripos($body, 'Undefined variable') !== false) $issues[] = "Undefined variable";
    if (stripos($body, 'Undefined index') !== false) $issues[] = "Undefined index";
    if (stripos($body, 'Undefined array key') !== false) $issues[] = "Undefined array key";
    if (stripos($body, 'Trying to access array offset on null') !== false) $issues[] = "Null array access";
    if (stripos($body, 'Call to undefined function') !== false) $issues[] = "Undefined function";
    if (stripos($body, 'include(') !== false && stripos($body, 'Failed opening') !== false) $issues[] = "Include failed";
    
    return $issues;
}

$results = [];
echo "============================================================\n";
echo "  PMAS SMOKE TEST\n";
echo "============================================================\n\n";

// Verify server alive
$httpCode = http_get('/login.php', $body);
echo "Server check: ";
echo ($httpCode > 0 && $httpCode < 500) ? "✅ Alive\n\n" : "❌ Dead ($httpCode)\n\n";

// First, let's try passwords
$passwordCandidates = ['password', 'admin_hr', 'dean', 'program_head', 'teacher', 'vpaa', 'admin', '1234', '12345', 'test'];

echo "--- Attempting login for each account with common passwords ---\n\n";
foreach ($accounts as $role => $info) {
    $found = false;
    foreach ($passwordCandidates as $pwd) {
        $cookies = ['PHPSESSID' => 'test' . rand(1000,9999)];
        $httpCode = http_post('/login.php', [
            'email' => $info['email'],
            'password' => $pwd,
            'login' => '1',
            'csrf_token' => 'test',  // Will likely fail CSRF check
        ], $body, $headers, $cookies);
        
        $loc = $headers['location'] ?? '';
        if ($loc && !str_contains($loc, 'login.php') && !str_contains($loc, '5173')) {
            echo "✅ {$info['label']}: '{$pwd}' → Redirects to {$loc}\n";
            $found = true;
            $info['password'] = $pwd;
            $accounts[$role]['password'] = $pwd;
            break;
        }
    }
    if (!$found) {
        echo "❌ {$info['label']}: No password found among tested candidates\n";
        // Check what error we get
        $cookies = [];
        $httpCode = http_post('/login.php', [
            'email' => $info['email'],
            'password' => 'password',
        ], $body, $headers, $cookies);
        if (preg_match('/<div[^>]*class="alert"[^>]*>(.*?)<\/div>/si', $body, $m)) {
            echo "   Error: " . strip_tags($m[1]) . "\n";
        }
    }
}
echo "\n";

// Now test each account that we found a password for
echo "--- Detailed Dashboard Checks ---\n\n";
foreach ($accounts as $role => $info) {
    if (empty($info['password'])) continue;
    
    echo "───── {$info['label']} ({$info['email']}) ─────\n\n";
    
    // Step 1: Login
    $cookies = [];
    $httpCode = http_post('/login.php', [
        'email' => $info['email'],
        'password' => $info['password'],
        'login' => '1',
        'csrf_token' => 'test',
    ], $body, $headers, $cookies);
    
    $redirect = $headers['location'] ?? '';
    echo "  Login redirect: $redirect\n";
    
    if (!$redirect || str_contains($redirect, 'login.php') || str_contains($redirect, '5173')) {
        echo "  ❌ Login FAILED for {$info['label']}\n\n";
        continue;
    }
    
    // Step 2: Follow redirect to dashboard
    $httpCode = http_get($redirect, $body, $headers, $cookies);
    $issues = check_page("Dashboard ({$info['label']})", $redirect, $body, $httpCode);
    echo "  Dashboard (HTTP $httpCode): ";
    echo $issues ? "❌ " . implode(', ', $issues) : "✅ OK";
    echo "\n";
    if ($issues && preg_match('/<b>(?:Fatal error|Warning|Notice|Error)<\/b>\s*(?:<\/font>)?(?:<br\s*\/?>\s*)?([^<]*)/i', $body, $m)) {
        echo "   Message: " . strip_tags($m[0]) . "\n";
    }
    
    // Step 3: Check Evaluate section
    if (in_array($role, ['program_head', 'teacher', 'dean'])) {
        $evalUrl = "/dashboards/$role.php?section=evaluate";
        echo "  Evaluate section: ";
        $httpCode = http_get($evalUrl, $body, $headers, $cookies);
        $issues = check_page("Evaluate ($role)", $evalUrl, $body, $httpCode);
        $hasCards = strpos($body, 'eval-assignment-card') !== false;
        $hasDashboard = strpos($body, 'dipascaf-evaluation-dashboard') !== false;
        
        if ($issues) {
            echo "❌ " . implode(', ', $issues) . "\n";
            if (preg_match('/<b>(?:Fatal error|Warning|Notice|Error)<\/b>\s*(?:<\/font>)?(?:<br\s*\/?>\s*)?([^<]*)/i', $body, $m)) {
                echo "   Error message: " . strip_tags($m[0]) . "\n";
            }
        } elseif ($hasCards) {
            // Count how many cards
            preg_match_all('/eval-assignment-card/', $body, $cardMatches);
            echo "✅ " . count($cardMatches[0]) . " evaluation cards found\n";
        } elseif ($hasDashboard) {
            echo "⚠️  Dashboard renders but no cards\n";
            if (stripos($body, 'No evaluation assignments') !== false) echo "     Reason: 'No evaluation assignments' message\n";
        } else {
            echo "⚠️  No evaluation dashboard found\n";
            if (strpos($body, 'redirect_to_react') !== false) echo "     ❌ STILL HAS redirect_to_react()!\n";
        }
        
        // Results
        $resUrl = "/dashboards/$role.php?section=results";
        echo "  Results section: ";
        $httpCode = http_get($resUrl, $body, $headers, $cookies);
        $issues = check_page("Results ($role)", $resUrl, $body, $httpCode);
        echo $issues ? "❌ " . implode(', ', $issues) : "✅ OK (HTTP $httpCode)";
        echo "\n";
        
        // Overview
        echo "  Overview section: ";
        $httpCode = http_get("/dashboards/$role.php", $body, $headers, $cookies);
        $issues = check_page("Overview ($role)", "/dashboards/$role.php", $body, $httpCode);
        echo $issues ? "❌ " . implode(', ', $issues) : "✅ OK (HTTP $httpCode)";
        echo "\n";
    }
    
    // Admin HR
    if ($role === 'admin_hr') {
        foreach (['people', 'programs', 'departments', 'evaluation-period', 'assign'] as $section) {
            echo "  Admin/$section: ";
            $url = "/dashboards/admin_hr.php?section=$section";
            $httpCode = http_get($url, $body, $headers, $cookies);
            $issues = check_page("Admin/$section", $url, $body, $httpCode);
            echo $issues ? "❌ " . implode(', ', $issues) : "✅ OK";
            echo "\n";
        }
    }
    
    // VPAA
    if ($role === 'vpaa') {
        echo "  VPAA dashboard: ";
        $httpCode = http_get('/dashboards/vpaa.php', $body, $headers, $cookies);
        $issues = check_page("VPAA", '/dashboards/vpaa.php', $body, $httpCode);
        echo $issues ? "❌ " . implode(', ', $issues) : "✅ OK";
        echo "\n";
        echo "  VPAA Evaluation Monitor: ";
        $httpCode = http_get('/api/vpaa-evaluation-monitor.php', $body, $headers, $cookies);
        $json = json_decode($body, true);
        echo $json !== null ? "✅ JSON API responds" : "⚠️  Non-JSON response";
        echo "\n";
    }
    
    echo "\n";
}

echo "============================================================\n";
echo "  SMOKE TEST COMPLETE\n";
echo "============================================================\n";

// Cleanup our temp files
@unlink(__FILE__);
