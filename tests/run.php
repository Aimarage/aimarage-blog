<?php
/*
 * Tests: php tests/run.php
 * Startet zwei lokale Server (php -S) mit Test-Einstellungen in einem
 * temporären Ordner und prüft Schnittstelle, Seiten und Sicherheit.
 */
require __DIR__ . '/../blog/src/autoload.php';

$failed = 0;
$passed = 0;
function ok(bool $cond, string $name, string $info = ''): void {
	global $failed, $passed;
	if ($cond) {
		$passed++;
		return;
	}
	$failed++;
	echo "FEHLER: $name" . ($info !== '' ? "\n  " . substr($info, 0, 400) : '') . "\n";
}

function has(string $hay, string $needle): bool {
	return str_contains($hay, $needle);
}

const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';

function tmpdir(string $name): string {
	$d = sys_get_temp_dir() . '/aimarage-blog-test-' . $name . '-' . getmypid();
	exec('rm -rf ' . escapeshellarg($d));
	foreach (['content', 'media', 'cache', 'site'] as $s) mkdir("$d/$s", 0775, true);
	return $d;
}

function config(string $dir, array $c): string {
	$file = "$dir/config.php";
	file_put_contents($file, '<?php return ' . var_export($c, true) . ';');
	return $file;
}

function server(string $config, int $port) {
	$cmd = 'AIMARAGE_BLOG_CONFIG=' . escapeshellarg($config) . ' exec php -d display_errors=stderr -S 127.0.0.1:' . $port
		. ' -t ' . escapeshellarg(dirname(__DIR__)) . ' ' . escapeshellarg(__DIR__ . '/router.php');
	$p = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', dirname($config) . '/server.log', 'w']], $pipes);
	for ($i = 0; $i < 50; $i++) {
		if (@fsockopen('127.0.0.1', $port)) return $p;
		usleep(100000);
	}
	die("Server auf Port $port startet nicht\n");
}

function stop($p): void {
	proc_terminate($p);
	proc_close($p);
}

function http(string $method, string $url, array $headers = [], ?string $body = null, ?array $multipart = null): array {
	$ch = curl_init($url);
	$h = [];
	curl_setopt_array($ch, [
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER => false,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$h) {
			if (str_contains($line, ':')) {
				[$k, $v] = explode(':', $line, 2);
				$h[strtolower(trim($k))] = trim($v);
			}
			return strlen($line);
		},
	]);
	if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
	if ($multipart !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
	elseif ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
	$res = (string) curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	return [$status, $h, $res, json_decode($res, true)];
}

function api(string $method, string $url, $data = null, string $token = TOKEN, array $extra = []): array {
	$h = preg_grep('/^content-type:/i', $extra) ? $extra : array_merge(['Content-Type: application/json'], $extra);
	if ($token !== '') $h[] = 'Authorization: Bearer ' . $token;
	return http($method, $url, $h, $data === null ? null : (is_string($data) ? $data : json_encode($data)));
}

function article(array $over = []): array {
	return $over + [
		'key' => 'erster-artikel', 'lang' => 'de', 'slug' => 'erster-artikel',
		'title' => 'Erster Artikel', 'description' => 'Eine kurze Beschreibung.',
		'date' => '2026-10-01', 'updated' => '2026-10-01',
		'body' => "Einleitung mit **fett** und [Link](https://example.org).\n\n## Abschnitt\n\n- Punkt eins\n- Punkt zwei",
	];
}

function png(int $w = 4, int $h = 3): string {
	$im = imagecreatetruecolor($w, $h);
	ob_start();
	imagepng($im);
	return (string) ob_get_clean();
}

/* Markdown */

$md = new AimarageBlog\Markdown('https://www.example.com');
$h = $md->render("# Titel\n\nText <script>alert(1)</script> & mehr\n\n<img src=x onerror=alert(1)>");
ok(has($h, '<h2>Titel</h2>'), 'md: # wird h2');
ok(!has($h, '<script>') && has($h, '&lt;script&gt;'), 'md: script maskiert', $h);
ok(!has($h, '<img src=x'), 'md: rohes img maskiert', $h);
$h = $md->render('[klick](javascript:alert(1)) [ok](https://example.org/a?b=1&c=2) [intern](/seite) [mail](mailto:a@b.at) [x](data:text/html,1)');
ok(!has($h, 'javascript:') && !has($h, 'data:'), 'md: gefährliche Links entfernt', $h);
ok(has($h, 'href="https://example.org/a?b=1&amp;c=2" rel="noopener"'), 'md: externer Link', $h);
ok(has($h, 'href="/seite">intern'), 'md: interner Link', $h);
ok(has($h, 'href="mailto:a@b.at"'), 'md: mailto', $h);
$h = $md->render('[a](https://www.example.com/x)');
ok(!has($h, 'noopener'), 'md: eigener Link ohne noopener', $h);
$h = $md->render("```\n<b>code</b>\n\n**nicht fett**\n```");
ok(has($h, '<pre><code>&lt;b&gt;code&lt;/b&gt;' . "\n\n" . '**nicht fett**</code></pre>'), 'md: Codeblock', $h);
$h = $md->render("`**x**` und **y**");
ok(has($h, '<code>**x**</code>') && has($h, '<strong>y</strong>'), 'md: Inline-Code', $h);
$h = $md->render("![Bild](/blog/media/a.png \"Unterschrift\")\n\n![x](javascript:alert(1))\n\n![\" onerror=\"x](/a.png)");
ok(has($h, '<figure><img src="/blog/media/a.png" alt="Bild"') && has($h, '<figcaption>Unterschrift</figcaption>'), 'md: Bild', $h);
ok(!has($h, 'javascript') && !has($h, '" onerror="'), 'md: Bild sicher', $h);
$h = $md->render("| A | B |\n|---|---|\n| 1 | <i>2</i> |\n\n1. eins\n2. zwei\n\n> Zitat\n\n---");
ok(has($h, '<th>A</th>') && has($h, '<td>&lt;i&gt;2&lt;/i&gt;</td>'), 'md: Tabelle', $h);
ok(has($h, '<ol><li>eins</li><li>zwei</li></ol>') && has($h, '<blockquote>') && has($h, '<hr>'), 'md: Liste, Zitat, Linie', $h);
$h = $md->render("Text\n## Gleich danach");
ok(has($h, '<p>Text</p>') && has($h, '<h2>Gleich danach</h2>'), 'md: Überschrift ohne Leerzeile', $h);

/* Server 1: zwei Sprachen, eigene Adressen für Übersicht und Schnittstelle */

$d1 = tmpdir('a');
$p1 = 18431;
$B = "http://127.0.0.1:$p1";
$c1 = config($d1, [
	'site_name' => 'Testseite', 'base_url' => $B,
	'languages' => [
		'en' => ['path' => '/guides', 'title' => 'Guides', 'description' => 'Guides.', 'locale' => 'en_GB'],
		'de' => ['path' => '/de/ratgeber', 'title' => 'Ratgeber', 'description' => 'Ratgeber.', 'locale' => 'de_AT'],
	],
	'api_path' => '/api/guides', 'sitemap_path' => '/sitemap-blog.xml',
	'token_hash' => hash('sha256', TOKEN),
	'content_dir' => "$d1/content", 'media_dir' => "$d1/media", 'media_url' => '/blog-media', 'cache_dir' => "$d1/cache",
	'default_image' => '/img/og.png', 'per_page' => 2, 'html_cache' => 600,
	'author' => ['type' => 'Person', 'name' => 'Alex Muster'], 'publisher' => ['name' => 'Firma', 'logo' => '/logo.png'],
	'rate_limit' => 1000, 'auth_fail_limit' => 1000, 'image_max_bytes' => 200000,
]);
$s1 = server($c1, $p1);
$API = "$B/api/guides";

// Zugang
[$st, , , $j] = api('POST', $API, article(), '');
ok($st === 401 && ($j['error'] ?? '') === 'unauthorized', 'api: ohne Token 401', "$st");
[$st] = api('POST', $API, article(), 'falsch');
ok($st === 401, 'api: falsches Token 401');
[$st] = api('PUT', $API, article());
ok($st === 405, 'api: PUT 405');
[$st, , , $j] = api('POST', $API, []);
ok($st === 422, 'api: leere Probe 422 (Verbindungstest von Aimarage)', "$st");
[$st, , , $j] = api('POST', $API, 'kein json');
ok($st === 400 && $j['error'] === 'invalid json', 'api: kaputtes JSON 400');

// Anlegen, Ersetzen
[$st, $hd, , $j] = api('POST', $API, article());
ok($st === 201 && $j['ok'] === true && $j['url'] === "$B/de/ratgeber/erster-artikel", 'api: anlegen 201 mit Adresse', json_encode($j));
ok(($hd['cache-control'] ?? '') === 'no-store', 'api: no-store');
ok(is_file("$d1/content/erster-artikel/de.md"), 'api: Datei angelegt');
[$st, , , $j] = api('POST', $API, article(['title' => 'Erster Artikel neu', 'updated' => '2026-10-02']));
ok($st === 200 && $j['url'] === "$B/de/ratgeber/erster-artikel", 'api: gleicher key = ersetzen 200');
[$st, , , $j] = api('POST', $API, article(['title' => 'Erster Artikel neu', 'updated' => '2026-10-02']));
ok($st === 200, 'api: idempotent');
[$st, , , $j] = api('POST', $API, article(['lang' => 'en', 'slug' => 'first-article', 'title' => 'First article', 'description' => 'Short.', 'body' => 'Hello.']));
ok($st === 201 && $j['url'] === "$B/guides/first-article", 'api: zweite Sprache', json_encode($j));
[$st, , , $j] = api('POST', $API, article(['key' => 'anderer', 'slug' => 'erster-artikel']));
ok($st === 409 && $j['key'] === 'erster-artikel', 'api: Slug eines anderen Artikels 409');

// Validierung und Pfade
foreach ([
	['key' => '../etc'], ['key' => 'a/b'], ['key' => 'A'], ['slug' => '..'], ['slug' => 'x/../../y'], ['slug' => str_repeat('a', 121)],
	['lang' => 'fr'], ['lang' => '../de'], ['title' => ''], ['description' => ' '], ['body' => ''], ['date' => '2026-13-01'],
	['date' => '1.1.2026'], ['ai_generated' => 'ja'], ['image' => 'javascript:alert(1)'], ['image' => '/../../etc/passwd'], ['image' => 'http://x.at/a.png'], ['key' => ['x']],
] as $bad) {
	[$st, , , $j] = api('POST', $API, article($bad));
	$f = array_key_first($bad);
	ok($st === 422 && in_array($f, $j['fields'] ?? [], true), "api: ungültig $f=" . json_encode($bad[$f]), "$st " . json_encode($j));
}
ok(count(glob("$d1/content/*")) === 1, 'api: keine Dateien außerhalb angelegt');

// XSS über die Schnittstelle
api('POST', $API, article(['key' => 'xss', 'slug' => 'xss', 'title' => 'T <script>alert(1)</script>', 'description' => 'D "><img src=x onerror=1>',
	'body' => "<script>alert(2)</script>\n\n[x](javascript:alert(3))\n\nzeile\n---\nslug: boese", 'date' => '2026-09-01', 'image' => 'https://cdn.example.org/a.png']));
[$st, , $html] = http('GET', "$B/de/ratgeber/xss");
ok($st === 200, 'xss: Seite da');
ok(!has($html, '<script>alert') && !has($html, '<img src=x') && !has($html, 'javascript:'), 'xss: alles maskiert', $html);
ok(has($html, 'property="og:image" content="https://cdn.example.org/a.png"'), 'xss: Bild aus https');
$raw = file_get_contents("$d1/content/xss/de.md");
ok(substr_count($raw, "\n---\n") >= 1 && Blog_parse_ok($raw), 'xss: Frontmatter nicht überschreibbar');
function Blog_parse_ok(string $raw): bool {
	$a = AimarageBlog\Blog::parse($raw, 'xss', 'de');
	return $a && $a['slug'] === 'xss';
}

// KI-Kennzeichnung
[$st] = api('POST', $API, article(['key' => 'ki-text', 'slug' => 'ki-text', 'ai_generated' => true]));
ok($st === 201, 'ki: Artikel mit ai_generated angelegt', "$st");
ok(has((string) file_get_contents("$d1/content/ki-text/de.md"), "ai_generated: true\n"), 'ki: im Frontmatter');
[$st, $hd, $html] = http('GET', "$B/de/ratgeber/ki-text");
ok(has($html, '<meta name="ai-generated" content="true">') && has($html, '"digitalSourceType":"https://schema.org/TrainedAlgorithmicMediaDigitalSource"'), 'ki: Meta-Tag und schema.org', $html);
ok(($hd['ai-disclosure'] ?? '') === 'mode=ai-originated', 'ki: HTTP-Header');
[$st, , , $j] = api('GET', "$API?key=ki-text&lang=de");
ok(($j['articles'][0]['ai_generated'] ?? null) === true, 'ki: in der Liste');
api('DELETE', "$API?key=ki-text");

// Seiten
[$st, $hd, $html] = http('GET', "$B/de/ratgeber/erster-artikel");
ok(!has($html, 'ai-generated') && !has($html, 'digitalSourceType') && !isset($hd['ai-disclosure']), 'seite: ohne KI-Kennzeichnung');
ok($st === 200 && has($html, '<h1>Erster Artikel neu</h1>'), 'seite: Artikel', "$st");
ok(has($html, '<title>Erster Artikel neu | Testseite</title>'), 'seite: title');
ok(has($html, '<meta name="description" content="Eine kurze Beschreibung.">'), 'seite: description');
ok(has($html, '<link rel="canonical" href="' . $B . '/de/ratgeber/erster-artikel">'), 'seite: canonical');
ok(has($html, 'hreflang="en" href="' . $B . '/guides/first-article"') && has($html, 'hreflang="x-default" href="' . $B . '/guides/first-article"'), 'seite: hreflang', $html);
ok(has($html, 'og:image" content="' . $B . '/img/og.png"') && has($html, 'summary_large_image'), 'seite: Open Graph Standardbild');
ok(has($html, '"@type":"BlogPosting"') && has($html, '"@type":"BreadcrumbList"') && has($html, '"dateModified":"2026-10-02"'), 'seite: schema.org');
ok(has($html, '<strong>fett</strong>') && has($html, '<li>Punkt eins</li>'), 'seite: Markdown');
ok(has($html, 'Aktualisiert'), 'seite: deutsche Texte');
ok(isset($hd['etag']) && str_contains($hd['cache-control'] ?? '', 'max-age'), 'seite: Cache-Header');
ok(has($html, '/blog/assets/blog.css'), 'seite: Stylesheet');
[$st] = http('GET', "$B/de/ratgeber/erster-artikel", ['If-None-Match: ' . $hd['etag']]);
ok($st === 304, 'seite: 304 bei gleicher ETag', "$st");
[$st, , $html] = http('GET', "$B/guides/first-article");
ok($st === 200 && has($html, 'lang="en"') && has($html, 'Published'), 'seite: englisch');
[$st, $hd] = http('GET', "$B/de/ratgeber/Erster-Artikel");
ok($st === 301 && $hd['location'] === '/de/ratgeber/erster-artikel', 'seite: Großbuchstaben 301');
[$st, $hd] = http('GET', "$B/de/ratgeber/erster-artikel/");
ok($st === 301 && $hd['location'] === '/de/ratgeber/erster-artikel', 'seite: Schrägstrich 301');
[$st, , $html] = http('GET', "$B/de/ratgeber/gibt-es-nicht");
ok($st === 404 && has($html, 'noindex') && has($html, 'Seite nicht gefunden'), 'seite: 404');
[$st] = http('GET', "$B/de/ratgeber/..%2f..%2fconfig");
ok($st === 404, 'seite: Pfad-Trick 404');
[$st] = http('POST', "$B/de/ratgeber/erster-artikel");
ok($st === 405, 'seite: POST 405');

// Slug ändern: alte Adresse leitet weiter
api('POST', $API, article(['slug' => 'erster-artikel-neu', 'title' => 'Erster Artikel neu', 'updated' => '2026-10-02']));
[$st, $hd] = http('GET', "$B/de/ratgeber/erster-artikel");
ok($st === 301 && $hd['location'] === '/de/ratgeber/erster-artikel-neu', 'seite: alter Slug 301', "$st");

// Übersicht und Seiten
api('POST', $API, article(['key' => 'dritter', 'slug' => 'dritter', 'title' => 'Dritter', 'date' => '2026-10-05']));
[$st, , $html] = http('GET', "$B/de/ratgeber");
ok($st === 200 && strpos($html, 'Dritter') < strpos($html, 'Erster Artikel neu'), 'liste: neueste zuerst');
ok(has($html, 'rel="next" href="/de/ratgeber/page/2"') && !has($html, 'xss</h2>'), 'liste: Seiten', $html);
ok(has($html, 'hreflang="en" href="' . $B . '/guides"'), 'liste: hreflang');
ok(has($html, '"@type":"Blog"'), 'liste: schema.org Blog');
[$st, , $html] = http('GET', "$B/de/ratgeber/page/2");
ok($st === 200 && has($html, 'T &lt;script&gt;') && has($html, 'rel="prev"'), 'liste: Seite 2');
[$st] = http('GET', "$B/de/ratgeber/page/9");
ok($st === 404, 'liste: Seite zu groß 404');
[$st, $hd] = http('GET', "$B/de/ratgeber/page/1");
ok($st === 301, 'liste: Seite 1 301');
[$st, , $html] = http('GET', "$B/guides");
ok($st === 200 && has($html, 'First article') && !has($html, 'Dritter'), 'liste: nur eigene Sprache');

// Feeds und Sitemap
[$st, $hd, $xml] = http('GET', "$B/de/ratgeber/feed.xml");
$x = @simplexml_load_string($xml);
ok($st === 200 && $x && count($x->entry) === 3 && str_contains($hd['content-type'], 'atom'), 'feed: Atom gültig', $xml);
ok(!has($xml, '<script>'), 'feed: maskiert');
[$st, , $xml] = http('GET', "$B/guides/rss.xml");
$x = @simplexml_load_string($xml);
ok($st === 200 && $x && count($x->channel->item) === 1, 'feed: RSS gültig', $xml);
[$st, , $xml] = http('GET', "$B/sitemap-blog.xml");
$x = @simplexml_load_string($xml);
ok($st === 200 && $x && count($x->url) === 6, 'sitemap: 2 Übersichten + 4 Artikel', $xml);
ok(has($xml, '<loc>' . $B . '/de/ratgeber/erster-artikel-neu</loc>') && has($xml, 'hreflang="en" href="' . $B . '/guides/first-article"'), 'sitemap: hreflang');

// Liste und Einzelabruf per Schnittstelle
[$st, , , $j] = api('GET', $API);
ok($st === 200 && count($j['articles']) === 4, 'api: Liste', json_encode($j));
[$st, , , $j] = api('GET', "$API?lang=en");
ok($st === 200 && count($j['articles']) === 1 && $j['articles'][0]['slug'] === 'first-article', 'api: Liste je Sprache');
[$st, , , $j] = api('GET', "$API?key=erster-artikel");
ok($st === 200 && count($j['articles']) === 2 && isset($j['articles'][0]['body']), 'api: ein Artikel mit Text');
[$st] = api('GET', $API, null, '');
ok($st === 401, 'api: Liste nur mit Token');

// Bilder
[$st, , , $j] = http('POST', "$API/images", ['Authorization: Bearer ' . TOKEN], null, ['file' => new CURLStringFile(png(), 'Mein Bild.png', 'image/png')]);
ok($st === 201 && preg_match('#^' . preg_quote($B) . '/blog-media/mein-bild-[0-9a-f]{10}\.png$#', $j['url'] ?? ''), 'bild: multipart', json_encode($j));
$path = $j['path'] ?? '';
[$st, $hd, $bin] = http('GET', $B . $path);
ok($st === 200 && $hd['content-type'] === 'image/png' && $bin === png() && str_contains($hd['cache-control'], 'immutable'), 'bild: Auslieferung über PHP', "$st");
[$st, , , $j] = api('POST', "$API/images?name=roh", png(8, 8), TOKEN, ['Content-Type: image/png']);
ok($st === 201 && $j['width'] === 8, 'bild: rohe Daten', json_encode($j));
[$st, , , $j] = api('POST', "$API/images", ['name' => 'json-bild', 'data' => base64_encode(png(2, 2))]);
ok($st === 201 && str_contains($j['path'], 'json-bild-'), 'bild: JSON base64', json_encode($j));
[$st, , , $j] = api('POST', "$API/images", ['name' => 'json-bild', 'data' => base64_encode(png(2, 2))]);
ok($st === 200, 'bild: gleiches Bild nochmal 200');
[$st] = http('POST', "$API/images", ['Authorization: Bearer ' . TOKEN], null, ['file' => new CURLStringFile("<?php echo 1;", 'x.php', 'image/png')]);
ok($st === 415, 'bild: PHP als Bild abgelehnt');
[$st] = http('POST', "$API/images", ['Authorization: Bearer ' . TOKEN], null, ['file' => new CURLStringFile('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.svg', 'image/svg+xml')]);
ok($st === 415, 'bild: SVG abgelehnt');
[$st] = http('POST', "$API/images", ['Authorization: Bearer ' . TOKEN], null, ['file' => new CURLStringFile(png() . str_repeat('x', 200001), 'gross.png', 'image/png')]);
ok($st === 413, 'bild: zu groß 413', "$st");
[$st] = http('POST', "$API/images", [], null, ['file' => new CURLStringFile(png(), 'a.png', 'image/png')]);
ok($st === 401, 'bild: ohne Token 401');
ok(!glob("$d1/media/*.php") && !glob("$d1/media/*.svg"), 'bild: keine gefährlichen Dateien');
[$st] = http('GET', "$B/blog-media/..%2fconfig.php");
ok($st === 404, 'bild: Pfad-Trick 404');
[$st, , , $j] = api('POST', $API, article(['key' => 'mit-bild', 'slug' => 'mit-bild', 'title' => 'Mit Bild', 'image' => $path, 'date' => '2026-08-01']));
ok($st === 201, 'bild: als Beitragsbild', json_encode($j));
[$st, , $html] = http('GET', "$B/de/ratgeber/mit-bild");
ok(has($html, 'og:image" content="' . $B . $path . '"') && has($html, 'class="blog-cover" src="' . $path . '"'), 'bild: og:image und Titelbild');

// Zurückziehen
[$st, , , $j] = api('DELETE', $API, ['key' => 'mit-bild', 'lang' => 'de']);
ok($st === 200 && $j['deleted'] === ['de'], 'api: zurückziehen', json_encode($j));
[$st] = http('GET', "$B/de/ratgeber/mit-bild");
ok($st === 404, 'api: zurückgezogen = 404');
ok(count(glob("$d1/content/.trash/mit-bild.de.*.md")) === 1 && !is_dir("$d1/content/mit-bild"), 'api: im Papierkorb');
[$st] = api('DELETE', $API, ['key' => 'mit-bild']);
ok($st === 404, 'api: nochmal zurückziehen 404');
[$st, , , $j] = api('DELETE', "$API?key=erster-artikel");
ok($st === 200 && count($j['deleted']) === 2, 'api: alle Sprachen per Query', json_encode($j));
[$st] = api('DELETE', $API, ['key' => '../content']);
ok($st === 422, 'api: zurückziehen mit Pfad-Trick 422');

// Abschalten ohne Token
$cfg = require $c1;
stop($s1);
$cfg['token_hash'] = '';
config($d1, $cfg);
$s1 = server($c1, $p1);
[$st, , , $j] = api('POST', $API, article());
ok($st === 404 && $j['error'] === 'disabled', 'api: ohne Hash abgeschaltet');
stop($s1);

/* Server 2: eine Sprache unter /blog, Kopf und Fuß der Website, Bremse */

$d2 = tmpdir('b');
$p2 = 18432;
$B2 = "http://127.0.0.1:$p2";
file_put_contents("$d2/site/init.php", '<?php define("SITE_OK", "ja"); $siteNav = "Menue";');
file_put_contents("$d2/site/header.php", '<!doctype html><html><head><meta charset="utf-8"><title>Alte Seite</title>'
	. '<meta name="description" content="alt"><link rel="canonical" href="https://alt/"><meta property="og:title" content="alt">'
	. '<link rel="stylesheet" href="/site.css"></head><body><nav><?= SITE_OK ?> <?= $siteNav ?></nav><main>');
file_put_contents("$d2/site/footer.php", '</main><footer>Fusszeile</footer></body></html>');
$c2 = config($d2, [
	'site_name' => 'Kiln Studio', 'base_url' => $B2,
	'languages' => ['en' => ['path' => '/blog', 'title' => 'Blog', 'description' => 'News.', 'intro' => 'About **this** blog.', 'locale' => 'en_GB']],
	'api_path' => '/blog/api', 'sitemap_path' => '/sitemap-blog.xml',
	'token_hash' => hash('sha256', TOKEN),
	'content_dir' => "$d2/content", 'media_dir' => "$d2/media", 'media_url' => '/blog/media', 'cache_dir' => "$d2/cache",
	'layout' => ['bootstrap' => "$d2/site/init.php", 'header' => "$d2/site/header.php", 'footer' => "$d2/site/footer.php"],
	'stylesheet' => '', 'html_cache' => 0, 'rate_limit' => 8, 'auth_fail_limit' => 3,
]);
$s2 = server($c2, $p2);
[$st, , , $j] = api('POST', "$B2/blog/api", article(['lang' => 'en', 'slug' => 'hello', 'key' => 'hello', 'title' => 'Hello']));
ok($st === 201 && $j['url'] === "$B2/blog/hello", 'site: anlegen', json_encode($j));
[$st, , , $j] = api('POST', "$B2/blog/api", article(['lang' => 'de']));
ok($st === 422 && $j['fields'] === ['lang'], 'site: nur konfigurierte Sprachen');
[$st, , $html] = http('GET', "$B2/blog/hello");
ok($st === 200 && has($html, '<nav>ja Menue</nav>') && has($html, 'Fusszeile') && has($html, '<h1>Hello</h1>'), 'site: Kopf und Fuß der Website', $html);
ok(has($html, '<title>Hello | Kiln Studio</title>') && !has($html, 'Alte Seite') && !has($html, 'https://alt/') && !has($html, 'content="alt"'), 'site: Kopfangaben ersetzt', $html);
ok(has($html, '<link rel="canonical" href="' . $B2 . '/blog/hello">') && has($html, '/site.css') && !has($html, 'hreflang'), 'site: canonical, eigenes CSS, kein hreflang');
[$st, , $html] = http('GET', "$B2/blog");
ok($st === 200 && has($html, 'Hello'), 'site: Übersicht');
[$st] = http('GET', "$B2/blogger");
ok($st === 404, 'site: fremde Adresse nicht vom Blog');

// Bremse: 3 Fehlversuche, danach 429 auch mit richtigem Token
for ($i = 0; $i < 3; $i++) api('POST', "$B2/blog/api", [], 'falsch');
[$st, $hd] = api('POST', "$B2/blog/api", []);
ok($st === 429 && isset($hd['retry-after']), 'bremse: nach Fehlversuchen 429', "$st");
stop($s2);
array_map('unlink', glob("$d2/cache/rate/*"));
$s2 = server($c2, $p2);
$codes = [];
for ($i = 0; $i < 10; $i++) $codes[] = api('POST', "$B2/blog/api", [])[0];
ok($codes[7] === 422 && $codes[8] === 429, 'bremse: Anfragen je Minute', json_encode($codes));
stop($s2);

/* Bibliothek: handle() ohne Server */

$blog = new AimarageBlog(require $c2);
$r = $blog->handle('/blog/hello', 'GET');
ok($r && $r->isPage() && $r->status === 200 && has($r->html, '<h1>Hello</h1>') && !has($r->html, '<html'), 'lib: Fragment', $r?->html ?? '');
ok($r->title === 'Hello | Kiln Studio' && $r->description === 'Eine kurze Beschreibung.' && has($r->head, 'rel="canonical"') && has($r->head, 'BlogPosting'), 'lib: Kopfangaben');
$r = $blog->handle('/sitemap-blog.xml', 'GET');
ok($r && !$r->isPage() && has($r->body, '/blog/hello'), 'lib: Sitemap');
ok($blog->handle('/pricing', 'GET') === null, 'lib: fremde Adresse null');
$r = $blog->handle('/blog/nix', 'GET');
ok($r && $r->status === 404 && $r->isPage(), 'lib: 404 als Seite');

$r = $blog->handle('/blog', 'GET');
ok($r && has($r->html, '<div class="blog-intro">') && has($r->html, '<strong>this</strong>'), 'lib: Einleitung der Übersicht', $r?->html ?? '');

/* Seitentitel */

$pt = fn(string $t, string $s) => AimarageBlog\Pages::pageTitle($t, $s);
ok($pt('Hello', 'Kiln Studio') === 'Hello | Kiln Studio', 'titel: mit Website');
ok($pt('Clay and Glaze: New Spring Mugs by Kiln Studio | Ceramics', 'Kiln Studio') === 'Clay and Glaze: New Spring Mugs by Kiln Studio | Ceramics', 'titel: Website schon drin');
ok($pt('Clay and Glaze: A Spring Collection of Stoneware Mugs, Piece by Piece', 'Kiln Studio') === 'Clay and Glaze: A Spring Collection of Stoneware Mugs', 'titel: am Trenner gekürzt');
ok($pt(str_repeat('word ', 20), 'X') === trim(str_repeat('word ', 20)), 'titel: ohne Trenner unverändert');

exec('rm -rf ' . escapeshellarg($d1) . ' ' . escapeshellarg($d2));
echo ($failed ? "$failed FEHLER, " : 'Alles gut, ') . "$passed Prüfungen bestanden\n";
exit($failed ? 1 : 0);
