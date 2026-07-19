<?php
/**
 * PMAS Final Smoke Test - proper CSRF token flow
 */
$BASE = 'http://127.0.0.1:8081';

$accounts = [
    'admin_hr'       => ['email' => 'admin@dipascaf.edu',        'pwd' => 'password123',      'label' => 'Admin HR'],
    'dean'           => ['email' => 'Mark@dipascaf.edu',         'pwd' => '123456',           'label' => 'Dean'],
    'program_head'   => ['email' => 'juan.delacruz@dipascaf.edu', 'pwd' => 'password123',    'label' => 'Program Head'],
    'teacher'        => ['email' => 'maria.santos@dipascaf.edu',  'pwd' => 'password123',    'label' => 'Faculty'],
    'vpaa'           => ['email' => 'Labio@dipascaf.edu',         'pwd' => 'password123',     'label' => 'VPAA'],
];

function get(string $url, array &$jar): array {
    global $BASE;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => str_starts_with($url, 'http') ? $url : $BASE . $url,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 8, CURLOPT_HEADER => true,
    ]);
    if ($jar) {
        $cs = '';
        foreach ($jar as $k => $v) $cs .= "$k=$v; ";
        curl_setopt($ch, CURLOPT_COOKIE, $cs);
    }
    $res = curl_exec($ch);
    $code = curl_errno($ch) ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $raw = substr($res, 0, $hs);
    $body = substr($res, $hs);
    if (preg_match('/PHPSESSID=([^;]+)/i', $raw, $m)) $jar['PHPSESSID'] = $m[1];
    return ['code' => $code, 'body' => $body];
}

function post(string $url, array $data, array &$jar): array {
    global $BASE;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => str_starts_with($url, 'http') ? $url : $BASE . $url,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 8, CURLOPT_HEADER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
    ]);
    if ($jar) {
        $cs = '';
        foreach ($jar as $k => $v) $cs .= "$k=$v; ";
        curl_setopt($ch, CURLOPT_COOKIE, $cs);
    }
    $res = curl_exec($ch);
    $code = curl_errno($ch) ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $raw = substr($res, 0, $hs);
    $body = substr($res, $hs);
    $loc = '';
    foreach (explode("\r\n", $raw) as $line) {
        if (stripos($line, 'Location:') === 0) $loc = trim(substr($line, 9));
        if (preg_match('/PHPSESSID=([^;]+)/i', $line, $m)) $jar['PHPSESSID'] = $m[1];
    }
    return ['code' => $code, 'body' => $body, 'loc' => $loc];
}

function check_errors(string $body): array {
    $e = [];
    if (preg_match('/<b>(?:Fatal error|Parse error|Warning|Notice|Error)<\/b>/i', $body)) $e[] = 'PHP_ERROR';
    if (stripos($body, 'SQLSTATE') !== false || stripos($body, 'PDOException') !== false) $e[] = 'DB_ERROR';
    if (stripos($body, 'Fatal error') !== false) $e[] = 'FATAL';
    if (stripos($body, 'Undefined') !== false) $e[] = 'UNDEFINED';
    if (stripos($body, 'Call to undefined') !== false) $e[] = 'UNDEFINED_CALL';
    if (preg_match('/Failed opening.*require/', $body)) $e[] = 'REQUIRE_FAIL';
    return $e;
}

$totalIssues = [];
$accountResults = [];

echo "=== PMAS Smoke Test (v3 - with CSRF) ===\n";
echo "Server: $BASE\n\n";

foreach ($accounts as $role => $acc) {
    echo "──── {$acc['label']} ({$acc['email']}) ────\n";
    $issues = [];
    
    // Step 1: GET login page to get CSRF token + session
    $jar = [];
    $r = get('/login.php', $jar);
    $errs = check_errors($r['body']);
    if ($errs) $issues = array_merge($issues, $errs);
    
    $csrf = '';
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/i', $r['body'], $m)) {
        $csrf = $m[1];
    }
    
    $hasSession = isset($jar['PHPSESSID']);
    if (!$hasSession) $issues[] = 'NO_SESSION_AFTER_GET';
    if (!$csrf) $issues[] = 'NO_CSRF_TOKEN';
    
    echo "  Login form: " . ($hasSession && $csrf ? "✅ Session+CSRF OK" : "❌ " . implode(',', $issues)) . "\n";
    
    // Try multiple passwords
    $passwords = [$acc['pwd'], 'password', 'admin_hr', 'dean', 'program_head', 'teacher', 'vpaa', '123456', 'password123', 'admin'];
    $loginOk = false;
    $loginLoc = '';
    
    foreach ($passwords as $pwd) {
        $jar2 = $jar;
        $r = post('/login.php', [
            'email' => $acc['email'],
            'password' => $pwd,
            'csrf_token' => $csrf,
        ], $jar2);
        
        if ($r['loc'] && !str_contains($r['loc'], 'login.php') && !str_contains($r['loc'], '5173')) {
            $loginOk = true;
            $loginLoc = $r['loc'];
            $jar = $jar2;
            break;
        }
        
        // Check if error is about CSRF (session issue) or invalid credentials
        if (stripos($r['body'], 'Invalid email or password') !== false) {
            // Wrong password, try next
            continue;
        }
        if (stripos($r['body'], 'Your session expired') !== false) {
            // Need to re-get login page for fresh CSRF
            break;
        }
    }
    
    if ($loginOk) {
        echo "  Login: ✅ Redirected to $loginLoc\n";
    } else {
        // Show the actual error
        $r = post('/login.php', ['email' => $acc['email'], 'password' => $acc['pwd'], 'csrf_token' => $csrf], $jar);
        $errMsg = '';
        if (preg_match('/<div[^>]*alert[^>]*>(.*?)<\/div>/si', $r['body'], $m)) {
            $errMsg = trim(strip_tags($m[1]));
        }
        echo "  Login: ❌ $errMsg\n";
        $totalIssues[] = "{$acc['label']}: Login failed - $errMsg";
        $accountResults[$role] = 'LOGIN_FAIL';
        echo "\n";
        continue;
    }
    
    // Step 3: Follow redirect to dashboard
    $r = get($loginLoc, $jar);
    $errs = check_errors($r['body']);
    $httpOk = $r['code'] >= 200 && $r['code'] < 400;
    
    if ($errs) {
        echo "  Dashboard: ⚠️ Errors: " . implode(',', $errs) . "\n";
        $totalIssues[] = "{$acc['label']}: Dashboard errors - " . implode(',', $errs);
        if (preg_match('/<b>(?:Fatal error|Warning|Notice|Error)<\/b>\s*(?:<\/font>)?(?:<br\s*\/?>\s*)?([^<\n]+)/i', $r['body'], $m)) {
            echo "     " . trim(strip_tags($m[0])) . "\n";
        }
    } elseif (!$httpOk) {
        echo "  Dashboard: ⚠️ HTTP {$r['code']}\n";
    } else {
        echo "  Dashboard: ✅ HTTP {$r['code']}\n";
    }
    
    // Step 4: Check Evaluate section
    if (in_array($role, ['program_head', 'teacher', 'dean'])) {
        $r = get("/dashboards/$role.php?section=evaluate", $jar);
        $errs = check_errors($r['body']);
        $hasCards = strpos($r['body'], 'eval-assignment-card') !== false;
        $hasDash = strpos($r['body'], 'dipascaf-evaluation-dashboard') !== false;
        $hasNoAssignments = stripos($r['body'], 'No evaluation assignments') !== false;
        $hasReactRedirect = stripos($r['body'], 'redirect_to_react') !== false;
        
        if ($errs) {
            echo "  Evaluate: ❌ " . implode(',', $errs) . "\n";
            if (preg_match('/<b>(?:Fatal error|Warning|Notice|Error)<\/b>\s*(?:<\/font>)?(?:<br\s*\/?>\s*)?([^<\n]+)/i', $r['body'], $m)) {
                echo "     " . trim(strip_tags($m[0])) . "\n";
            }
        } elseif ($hasCards) {
            preg_match_all('/eval-assignment-card/', $r['body'], $m);
            echo "  Evaluate: ✅ " . count($m[0]) . " cards\n";
        } elseif ($hasDash) {
            echo "  Evaluate: ⚠️ Dashboard renders but no cards" . ($hasNoAssignments ? " (no assignments)" : "") . "\n";
        } elseif ($hasReactRedirect) {
            echo "  Evaluate: ❌ Still using redirect_to_react()!\n";
            $totalIssues[] = "{$acc['label']}: Evaluate still has redirect_to_react";
        } else {
            echo "  Evaluate: ⚠️ No evaluation dashboard found (HTTP {$r['code']})\n";
        }
        
        // Results
        $r = get("/dashboards/$role.php?section=results", $jar);
        $errs = check_errors($r['body']);
        echo "  Results: " . ($errs ? "❌ " . implode(',', $errs) : "✅ OK") . "\n";
        if ($errs && preg_match('/<b>(?:Fatal error|Warning|Notice|Error)<\/b>\s*(?:<\/font>)?(?:<br\s*\/?>\s*)?([^<\n]+)/i', $r['body'], $m)) {
            echo "     " . trim(strip_tags($m[0])) . "\n";
        }
        
        // Overview
        $r = get("/dashboards/$role.php", $jar);
        $errs = check_errors($r['body']);
        echo "  Overview: " . ($errs ? "❌ " . implode(',', $errs) : "✅ OK") . "\n";
        if ($errs && preg_match('/<b>(?:Fatal error|Warning|Notice|Error)<\/b>\s*(?:<\/font>)?(?:<br\s*\/?>\s*)?([^<\n]+)/i', $r['body'], $m)) {
            echo "     " . trim(strip_tags($m[0])) . "\n";
        }
    }
    
    // Admin HR specific
    if ($role === 'admin_hr') {
        foreach (['', 'people', 'programs', 'departments', 'evaluation-period', 'assign'] as $sec) {
            $label = $sec ?: 'overview';
            $url = $sec ? "/dashboards/admin_hr.php?section=$sec" : "/dashboards/admin_hr.php";
            $r = get($url, $jar);
            $errs = check_errors($r['body']);
            echo "  Admin/$label: " . ($errs ? "❌ " . implode(',', $errs) : "✅ OK (HTTP {$r['code']})") . "\n";
        }
    }
    
    // VPAA specific
    if ($role === 'vpaa') {
        $r = get('/dashboards/vpaa.php', $jar);
        $errs = check_errors($r['body']);
        echo "  Dashboard: " . ($errs ? "❌ " . implode(',', $errs) : "✅ OK") . "\n";
        
        $r = get('/api/vpaa-evaluation-monitor.php', $jar);
        $json = json_decode($r['body'], true);
        echo "  Eval Monitor: " . ($json !== null ? "✅ JSON API" : "⚠️ Non-JSON") . "\n";
        if ($json && !($json['ok'] ?? $json['success'] ?? false)) {
            $totalIssues[] = "VPAA Monitor API returned error: " . ($json['message'] ?? 'unknown');
        }
    }
    
    $accountResults[$role] = isset($errs) && $errs ? 'HAS_ERRORS' : 'OK';
    echo "\n";
}

echo "=== SMOKE TEST SUMMARY ===\n";
foreach ($accountResults as $role => $status) {
    $label = $accounts[$role]['label'];
    echo ($status === 'OK' ? "✅" : "❌") . " $label: $status\n";
}
echo "\n";
if ($totalIssues) {
    echo "Issues found:\n";
    foreach ($totalIssues as $i) echo "  - $i\n";
} else {
    echo "No issues found!\n";
}
echo "\n=== DONE ===\n";
