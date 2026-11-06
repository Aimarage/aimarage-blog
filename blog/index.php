<?php
/*
 * Eigenständiger Betrieb: alle Blog-Adressen landen per .htaccess hier.
 * Einstellungen aus config.php oder aus der Datei in AIMARAGE_BLOG_CONFIG.
 */
require __DIR__ . '/src/autoload.php';

$configFile = getenv('AIMARAGE_BLOG_CONFIG') ?: __DIR__ . '/config.php';
if (!is_file($configFile)) {
	http_response_code(500);
	header('Content-Type: text/plain; charset=utf-8');
	echo "config.php fehlt, siehe config.sample.php\n";
	exit;
}

(new AimarageBlog(require $configFile))->run();
