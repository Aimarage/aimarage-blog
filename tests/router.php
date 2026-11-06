<?php
/* Router für php -S, ersetzt die .htaccess bei den Tests */
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/blog/assets/') && is_file(__DIR__ . '/../blog' . substr($path, 5))) return false;
require __DIR__ . '/../blog/index.php';
