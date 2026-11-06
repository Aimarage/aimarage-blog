<?php
/*
 * Einbinden als Bibliothek:
 *   require '/pfad/zu/blog/src/autoload.php';
 *   $blog = new AimarageBlog(require '/pfad/zu/config.php');
 *   $r = $blog->handle($pfad);   // null, wenn die Adresse nicht zum Blog gehört
 */
foreach (['Blog', 'Markdown', 'Response', 'Pages', 'Api', 'Layout'] as $class) {
	require_once __DIR__ . '/' . $class . '.php';
}
if (!class_exists('AimarageBlog', false)) class_alias(AimarageBlog\Blog::class, 'AimarageBlog');
