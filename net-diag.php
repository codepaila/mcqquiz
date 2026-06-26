<?php
/**
 * net-diag.php — standalone outbound-connectivity diagnostic.
 *
 * Upload this single file anywhere on quiznosis.free.nf (e.g. the site
 * root) and open it directly in a browser:
 *   https://quiznosis.free.nf/net-diag.php
 *
 * It checks, independently, whether:
 *   1. allow_url_fopen is enabled (needed for file_get_contents on https://)
 *   2. the cURL extension is loaded
 *   3. file_get_contents() can actually reach api.telegram.org
 *   4. cURL can actually reach api.telegram.org
 *   5. DNS resolution for api.telegram.org works at all
 *
 * DELETE THIS FILE after you're done — it has no auth and shouldn't be
 * left on a public server long-term.
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== Quiznosis outbound connectivity diagnostic ===\n\n";

echo "PHP version: " . PHP_VERSION . "\n\n";

// 1. allow_url_fopen
$allowUrlFopen = ini_get('allow_url_fopen');
echo "[1] allow_url_fopen: " . ($allowUrlFopen ? "ON" : "OFF") . "\n";

// 2. cURL extension
$hasCurl = function_exists('curl_init');
echo "[2] cURL extension loaded: " . ($hasCurl ? "YES" : "NO") . "\n\n";

// 3. DNS resolution
echo "[3] DNS lookup for api.telegram.org:\n";
$ip = @gethostbyname('api.telegram.org');
if ($ip && $ip !== 'api.telegram.org') {
    echo "    -> resolved to {$ip}\n\n";
} else {
    echo "    -> FAILED to resolve (DNS itself is blocked or unavailable)\n\n";
}

// 4. file_get_contents over HTTPS
echo "[4] file_get_contents() test (https://api.telegram.org):\n";
if (!$allowUrlFopen) {
    echo "    -> SKIPPED (allow_url_fopen is OFF, this will always fail)\n\n";
} else {
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
    $start = microtime(true);
    $result = @file_get_contents('https://api.telegram.org', false, $ctx);
    $elapsed = round((microtime(true) - $start) * 1000);
    if ($result === false) {
        $err = error_get_last();
        echo "    -> FAILED after {$elapsed}ms\n";
        echo "    -> PHP error: " . ($err['message'] ?? 'none captured') . "\n\n";
    } else {
        echo "    -> SUCCESS after {$elapsed}ms (" . strlen($result) . " bytes received)\n\n";
    }
}

// 5. cURL
echo "[5] cURL test (https://api.telegram.org):\n";
if (!$hasCurl) {
    echo "    -> SKIPPED (cURL extension not loaded)\n\n";
} else {
    $ch = curl_init('https://api.telegram.org');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $start = microtime(true);
    $result = curl_exec($ch);
    $elapsed = round((microtime(true) - $start) * 1000);
    if ($result === false) {
        echo "    -> FAILED after {$elapsed}ms\n";
        echo "    -> cURL error: " . curl_error($ch) . " (errno " . curl_errno($ch) . ")\n\n";
    } else {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        echo "    -> SUCCESS after {$elapsed}ms (HTTP {$code}, " . strlen($result) . " bytes)\n\n";
    }
    curl_close($ch);
}

// 6. A control test against a domain already known to work from this server
// (your app already calls this successfully elsewhere, e.g. font/CDN fetches
// from the BROWSER don't count — this is specifically a same-origin PHP test)
echo "[6] Control test — fetch your own site's homepage via file_get_contents:\n";
if ($allowUrlFopen) {
    $self = @file_get_contents('https://quiznosis.free.nf/', false,
        stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]));
    echo "    -> " . ($self === false ? "FAILED" : "SUCCESS (" . strlen($self) . " bytes)") . "\n\n";
} else {
    echo "    -> SKIPPED (allow_url_fopen is OFF)\n\n";
}

echo "=== End of diagnostic — delete this file when done ===\n";
