<?php
/**
 * Temporary cache clearing script for Laravel
 * Upload this to your web root and visit it in browser to clear caches
 * DELETE THIS FILE AFTER USE for security
 */

try {
    // Include Laravel bootstrap
    require_once __DIR__ . '/bootstrap/app.php';

    // Clear and cache routes
    echo "🔄 Clearing route cache...<br>";
    Artisan::call('route:clear');
    echo "✅ Route cache cleared<br>";

    echo "🔄 Caching routes...<br>";
    Artisan::call('route:cache');
    echo "✅ Routes cached<br>";

    // Clear config cache
    echo "🔄 Clearing config cache...<br>";
    Artisan::call('config:clear');
    echo "✅ Config cache cleared<br>";

    echo "🔄 Caching config...<br>";
    Artisan::call('config:cache');
    echo "✅ Config cached<br>";

    echo "<br>🎉 <strong>All caches cleared successfully!</strong><br>";
    echo "<br>✨ Your new endpoints should now work:<br>";
    echo "• Debug: <a href='/api/race-results/test-fetch-plans'>/api/race-results/test-fetch-plans</a><br>";
    echo "• Protected: /api/race-results/fetch-plans?event_id=1<br>";
    echo "<br>🗑️ <strong>IMPORTANT: DELETE THIS FILE (clear-cache.php) NOW FOR SECURITY!</strong>";

} catch (Exception $e) {
    echo "❌ <strong>Error:</strong> " . $e->getMessage() . "<br>";
    echo "📝 <strong>Details:</strong> " . $e->getFile() . ":" . $e->getLine() . "<br>";
    echo "<br>💡 <strong>Alternative:</strong> Try deleting these files manually via FTP:<br>";
    echo "• /bootstrap/cache/routes-v7.php<br>";
    echo "• /bootstrap/cache/config.php<br>";
}
?>