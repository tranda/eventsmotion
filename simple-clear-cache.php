<?php
/**
 * Simple cache clearing script - just deletes cache files
 * Upload to web root, visit in browser, then DELETE this file
 */

echo "<h2>🧹 Simple Cache Clear</h2>";

$cacheFiles = [
    'bootstrap/cache/routes-v7.php',
    'bootstrap/cache/config.php',
    'bootstrap/cache/packages.php',
    'bootstrap/cache/services.php'
];

foreach ($cacheFiles as $file) {
    $fullPath = __DIR__ . '/' . $file;
    if (file_exists($fullPath)) {
        if (unlink($fullPath)) {
            echo "✅ Deleted: {$file}<br>";
        } else {
            echo "❌ Failed to delete: {$file}<br>";
        }
    } else {
        echo "ℹ️ Not found: {$file}<br>";
    }
}

echo "<br>🎉 <strong>Cache files deleted!</strong><br>";
echo "<br>✨ Test your endpoint now: <a href='/api/race-results/test-fetch-plans'>/api/race-results/test-fetch-plans</a><br>";
echo "<br>🗑️ <strong>DELETE THIS FILE NOW!</strong>";
?>