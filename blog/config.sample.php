<?php
/*
 * Einstellungen des Blogs. Diese Datei als config.php kopieren und anpassen.
 * Alle Pfade in "path", "api_path" usw. sind Adressen ab der Domain.
 */
return [

	// Name der Website, erscheint im Titel, in Open Graph und im Feed
	'site_name' => 'Meine Website',

	// Adresse der Website ohne Schrägstrich am Ende
	'base_url' => 'https://www.example.com',

	// Sprachen mit ihren Blog-Adressen. Die erste ist die Standardsprache (x-default).
	// Nur eine Sprache? Einfach die zweite Zeile löschen.
	'languages' => [
		'de' => ['path' => '/blog', 'title' => 'Blog', 'description' => 'Neuigkeiten und Praxiswissen.', 'locale' => 'de_AT'],
		// 'en' => ['path' => '/en/blog', 'title' => 'Blog', 'description' => 'News and practical guides.', 'locale' => 'en_GB'],
	],

	// Adresse der Schnittstelle für Aimarage (diese Adresse kommt in Aimarage ins Feld "Adresse")
	'api_path' => '/blog/api',

	// Sitemap nur mit den Blog-Seiten. In die robots.txt oder den Sitemap-Index der Website eintragen.
	'sitemap_path' => '/blog/sitemap.xml',

	// SHA-256 des API-Tokens (nicht das Token selbst). Leer = Schnittstelle aus.
	// Alternativ als Umgebungsvariable AIMARAGE_BLOG_TOKEN_HASH (z.B. SetEnv in .htaccess).
	'token_hash' => '',

	// Ordner für Artikel, Bilder und Zwischenspeicher. Der Webserver braucht Schreibrechte.
	'content_dir' => __DIR__ . '/content',
	'media_dir'   => __DIR__ . '/media',
	'media_url'   => '/blog/media',
	'cache_dir'   => __DIR__ . '/cache',

	// Design: 'builtin' = eigenes schlichtes Layout mit assets/blog.css
	// oder Kopf und Fuß der Website einbinden (absolute Dateipfade auf dem Server)
	'layout' => 'builtin',
	// 'layout' => [
	// 	'bootstrap' => '/var/www/site/init.php',   // optional, wird vorher geladen
	// 	'header'    => '/var/www/site/header.php',
	// 	'footer'    => '/var/www/site/footer.php',
	// ],

	// Stylesheet für die Artikel. Im eigenen Layout immer dabei, mit Kopf/Fuß der Website optional.
	'stylesheet' => '/blog/assets/blog.css',

	// Bild für Open Graph, wenn ein Artikel keines hat (1200 x 630)
	'default_image' => '',

	'author'    => ['type' => 'Person', 'name' => 'Vorname Nachname'],
	'publisher' => ['name' => 'Firma', 'logo' => ''],

	'per_page' => 10,

	// Fertige Seiten zwischenspeichern (Sekunden, 0 = aus). Neue Artikel löschen den Speicher sofort.
	'html_cache' => 600,

	// Bilder: Größe in Byte, erlaubt sind JPEG, PNG, WebP und GIF
	'image_max_bytes' => 5 * 1024 * 1024,

	// Anfragen an die Schnittstelle je Minute und Adresse, Fehlversuche je 10 Minuten
	'rate_limit'      => 60,
	'auth_fail_limit' => 10,

	// Eigene Texte, überschreibt die eingebauten (de, en)
	'texts' => [],
];
