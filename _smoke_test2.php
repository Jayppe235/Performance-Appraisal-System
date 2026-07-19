<?php
/**
 * PMAS Quick Smoke Test
 * Tests all accounts and reports errors
 */
$BASE = 'http://127.0.0.1:8081';

$accounts = [
    'admin_hr'       => ['email' => 'admin@dipascaf.edu',       'pwd' => 'admin',            'label' => 'Admin HR'],
    'dean'           => ['email' => 'Mark@dipascaf.edu',        'pwd' => '123456',           'label' => 'Dean'],
    'program_head'   => ['email' => 'juan.delacruz@dipascaf.edu', 'pwd' => 'password123',    'label' => 'Program Head'],
    'teacher'        => ['email' => 'maria.santos@dipascaf.edu',  'pwd' => 'password123',    'label' => 'Faculty'],
    'vpaa'           => ['email' => 'Labio@dipascaf.edu',        'pwd' => 'vpaa',            'label' => 'VPAA'],
];

function req(string $method, string $url, array $data = [], array &$jar = []): array {
    global $BASE;
    $ch = curl_init();
    $fullUrl = str_starts_with($url, 'http') ? $url : $BASE . $url;
    curl_setopt_array($ch, [
        CURLOPT_URL => $fullUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HEADER => true,
    ]);
    if ($method === 'POST') {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    }
    if ($jar) {
        $cs = '';
        foreach ($jar as $k => $v) $cs .= "$k=$v; ";
        curl_setopt($ch, CURLOPT_COOKIE, $cs);
    }
    $res = curl_exec($ch);
    $code = curl_errno($ch) ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if (!$code) return ['code' => 0, 'body' => '', 'headers' => [], 'loc' => ''];
    $raw = substr($res, 0, $hs);
    $body = substr($res, $hs);
    $headers = [];
    foreach (explode("\r\n", $raw) as $line) {
        if (str_contains($line, ': ')) { [$k, $v] = explode(': ', $line, 2); $headers[strtolower($k)] = $v; }
        if (preg_match('/PHPSESSID=([^;]+)/i', $line, $m)) $jar['PHPSESSID'] = $m[1];
    }
    return ['code' => $code, 'body' => $body, 'headers' => $headers, 'loc' => $headers['location'] ?? ''];
}

function errors(string $body): array {
    $e = [];
    if (preg_match('/<b>(?:Fatal error|Parse error|Warning|Notice|Error)<\/b>/i', $body)) $e[] = 'PHP_ERROR';
    if (stripos($body, 'SQLSTATE') !== false || stripos($body, 'PDOException') !== false) $e[] = 'DB_ERROR';
    if (stripos($body, 'Fatal error') !== false) $e[] = 'FATAL';
    if (stripos($body, 'stack trace') !== false) $e[] = 'STACK_TRACE';
    if (stripos($body, 'Undefined') !== false) $e[] = 'UNDEFINED';
    if (stripos($body, 'Call to undefined') !== false) $e[] = 'UNDEFINED_CALL';
    if (preg_match('/Failed opening.*require/', $body)) $e[] = 'REQUIRE_FAIL';
    return $e;
}

echo "=== PMAS Smoke Test ===\n";
echo "Server: $BASE\n\n";

// Server check
$r = req('GET', '/login.php');
if ($r['code'] === 0) { echo "❌ SERVER DOWN\n"; exit(1); }
echo "✅ Server OK (HTTP {$r['code']})\n\n";

foreach ($accounts as $role => $acc) {
    echo "──── {$acc['label']} ────\n";
    
    // Login
    $jar = [];
    $r = req('POST', '/login.php', ['email' => $acc['email'], 'password' => $acc['pwd'], 'login' => '1'], $jar);
    $loc = $r['loc'];
    
    if (!$loc || str_contains($loc, 'login.php')) {
        echo "  ❌ LOGIN FAILED\n";
        if (preg_match('/<div[^>]*alert[^>]*>(.*?)<\/div>/si', $r['body'], $m)) {
            echo "     Error: " . strip_tags($m[1]) . "\n\n";
        } else {
            echo "     No redirect, HTTP {$r['code']}\n\n";
        }
        continue;
    }
    
    echo "  ✅ Logged in → $loc\n";
    
    // Dashboard
    $r = req('GET', $loc, [], $jar);
    $errs = errors($r['body']);
    if ($errs) echo "  ⚠️  Dashboard errors: " . implode(',', $errs) . "\n";
    else echo "  ✅ Dashboard (HTTP {$r['code']})\n";
    
    // Extract error message if present
    if ($errs && preg_match('/<b>(?:Fatal error|Warning|Notice|Error)<\/b>\s*(?:<\/font>)?(?:<br\s*\/?>\s*)?([^<\n]+)/i', $r['body'], $m)) {
        echo "     Msg: " . trim(strip_tags($m[0])) . "\n";
    }
    
    // Evaluate section
    if (in_array($role, ['program_head', 'teacher', 'dean'])) {
        $evalUrl = "/dashboards/$role.php?section=evaluate";
        $r = req('GET', $evalUrl, [], $jar);
        $errs = errors($r['body']);
        $hasCards = strpos($r['body'], 'eval-assignment-card') !== false;
        $hasDash = strpos($r['body'], 'dipascaf-evaluation-dashboard') !== false;
        
        if ($errs) echo "  ⚠️  Evaluate errors: " . implode(',', $errs) . "\n";
        elseif ($hasCards) { preg_match_all('/eval-assignment-card/', $r['body'], $m); echo "  ✅ Evaluate: " . count($m[0]) . " cards\n"; }
        elseif ($hasDash) { echo "  ⚠️  Evaluate: dashboard shows but no cards\n"; if (stripos($r['body'], 'No evaluation assignments') !== false) echo "     → 'No assignments' message\n"; }
        else { echo "  ⚠️  Evaluate: no dashboard found\n"; }
        
        // Results
        $r = req('GET', "/dashboards/$role.php?section=results", [], $jar);
        $errs = errors($r['body']);
        echo ($errs ? "  ⚠️  Results errors: " . implode(',', $errs) : "  ✅ Results OK") . "\n";
        
        // Overview
        $r = req('GET', "/dashboards/$role.php", [], $jar);
        $errs = errors($r['body']);
        echo ($errs ? "  ⚠️  Overview errors: " . implode(',', $errs) : "  ✅ Overview OK") . "\n";
    }
    
    if ($role === 'admin_hr') {
        foreach (['people', 'programs', 'departments', 'evaluation-period', 'assign'] as $sec) {
            $r = req('GET', "/dashboards/admin_hr.php?section=$sec", [], $jar);
            $errs = errors($r['body']);
            echo ($errs ? "  ⚠️  $sec: " . implode(',', $errs) : "  ✅ $sec OK") . "\n";
        }
    }
    
    if ($role === 'vpaa') {
        $r = req('GET', '/dashboards/vpaa.php', [], $jar);
        $errs = errors($r['body']);
        echo ($errs ? "  ⚠️  VPAA dashboard: " . implode(',', $errs) : "  ✅ VPAA dashboard OK") . "\n";
        
        $r = req('GET', '/api/vpaa-evaluation-monitor.php', [], $jar);
        $json = json_decode($r['body'], true);
        echo ($json !== null ? "  ✅ VPAA API JSON OK" : "  ⚠️  VPAA API non-JSON") . "\n";
    }
    
    echo "\n";
}

echo "=== SMOKE TEST DONE ===\n";
