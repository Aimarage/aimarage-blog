<?php
namespace AimarageBlog;

/*
 * Einstellungen und Artikel. Artikel liegen als Markdown unter
 * content/<key>/<lang>.md, der Ordnername verbindet die Sprachfassungen.
 */
class Blog {

	const NAME = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

	public array $c;
	private ?array $all = null;

	public function __construct(array $config) {
		$c = $config + [
			'site_name' => '', 'base_url' => '', 'languages' => [], 'api_path' => '/blog/api',
			'sitemap_path' => '/blog/sitemap.xml', 'token_hash' => '',
			'content_dir' => __DIR__ . '/../content', 'media_dir' => __DIR__ . '/../media',
			'media_url' => '/blog/media', 'cache_dir' => __DIR__ . '/../cache',
			'layout' => 'builtin', 'stylesheet' => '/blog/assets/blog.css', 'default_image' => '',
			'author' => [], 'publisher' => [], 'per_page' => 10, 'html_cache' => 600,
			'image_max_bytes' => 5 * 1024 * 1024, 'rate_limit' => 60, 'auth_fail_limit' => 10, 'texts' => [],
		];
		$c['base_url'] = rtrim($c['base_url'], '/');
		foreach ($c['languages'] as $l => $s) {
			$c['languages'][$l]['path'] = '/' . trim($s['path'] ?? '', '/');
		}
		foreach (['content_dir', 'media_dir', 'cache_dir'] as $k) $c[$k] = rtrim($c[$k], '/');
		$c['media_url'] = rtrim($c['media_url'], '/');
		$this->c = $c;
	}

	public function langs(): array {
		return array_keys($this->c['languages']);
	}

	public function defaultLang(): string {
		return $this->langs()[0] ?? 'de';
	}

	public function tokenHash(): string {
		$h = (string) ($this->c['token_hash'] ?: getenv('AIMARAGE_BLOG_TOKEN_HASH') ?: '');
		return strtolower(trim($h));
	}

	public function abs(string $path): string {
		if (preg_match('#^https?://#', $path)) return $path;
		return $this->c['base_url'] . $path;
	}

	public function listUrl(string $lang, int $page = 1): string {
		$p = $this->c['languages'][$lang]['path'];
		return $page > 1 ? rtrim($p, '/') . '/page/' . $page : $p;
	}

	public function url(array $a): string {
		return rtrim($this->c['languages'][$a['lang']]['path'], '/') . '/' . $a['slug'];
	}

	public function text(string $key, string $lang): string {
		static $t = [
			'de' => ['published' => 'Veröffentlicht', 'updated' => 'Aktualisiert', 'newer' => 'Neuere Artikel', 'older' => 'Ältere Artikel',
				'page' => 'Seite', 'empty' => 'Noch keine Artikel.', 'notfound' => 'Seite nicht gefunden',
				'notfound_text' => 'Diesen Artikel gibt es nicht oder nicht mehr.', 'home' => 'Startseite', 'read' => 'Weiterlesen',
				'months' => 'Januar,Februar,März,April,Mai,Juni,Juli,August,September,Oktober,November,Dezember'],
			'en' => ['published' => 'Published', 'updated' => 'Updated', 'newer' => 'Newer articles', 'older' => 'Older articles',
				'page' => 'Page', 'empty' => 'No articles yet.', 'notfound' => 'Page not found',
				'notfound_text' => 'This article does not exist or was removed.', 'home' => 'Home', 'read' => 'Read more',
				'months' => 'January,February,March,April,May,June,July,August,September,October,November,December'],
		];
		return $this->c['texts'][$lang][$key] ?? $t[$lang][$key] ?? $t['en'][$key] ?? $key;
	}

	public function date(string $d, string $lang): string {
		$t = strtotime($d);
		if (!$t) return '';
		$m = explode(',', $this->text('months', $lang));
		return date('j', $t) . ($lang === 'de' ? '. ' : ' ') . ($m[date('n', $t) - 1] ?? '') . ' ' . date('Y', $t);
	}

	/* Anfrage beantworten. null = Adresse gehört nicht zum Blog. */
	public function handle(?string $path = null, ?string $method = null): ?Response {
		$path = $path ?? (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
		$path = '/' . ltrim($path, '/');
		$method = strtoupper($method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		$c = $this->c;

		$api = rtrim($c['api_path'], '/');
		if ($path === $api || str_starts_with($path, $api . '/')) {
			return (new Api($this))->handle(trim(substr($path, strlen($api)), '/'), $method);
		}

		$read = in_array($method, ['GET', 'HEAD'], true);
		if ($path === $c['sitemap_path']) return $read ? (new Pages($this))->sitemap() : self::notAllowed();
		if (str_starts_with($path, $c['media_url'] . '/')) return $read ? $this->media(substr($path, strlen($c['media_url']) + 1)) : self::notAllowed();

		$langs = $c['languages'];
		uasort($langs, fn($x, $y) => strlen($y['path']) <=> strlen($x['path']));
		foreach ($langs as $lang => $set) {
			$base = $set['path'];
			$root = rtrim($base, '/');
			if ($path !== $base && $path !== $root . '/' && !str_starts_with($path, $root . '/')) continue;
			if (!$read) return self::notAllowed();
			return $this->cached($path, fn() => $this->route($lang, $root, $path));
		}
		return null;
	}

	private function route(string $lang, string $root, string $path): Response {
		$pages = new Pages($this);
		$rest = $path === $this->c['languages'][$lang]['path'] ? '' : substr($path, strlen($root) + 1);
		if ($rest === '') return $pages->listing($lang, 1);
		if (str_ends_with($rest, '/')) return Response::redirect($root . '/' . rtrim($rest, '/'));
		if ($rest === 'feed.xml') return $pages->atom($lang);
		if ($rest === 'rss.xml') return $pages->rss($lang);
		if (preg_match('#^page/(\d{1,5})$#', $rest, $m)) {
			if ((int) $m[1] === 1) return Response::redirect($this->listUrl($lang));
			return $pages->listing($lang, (int) $m[1]);
		}
		if (!preg_match('#^[A-Za-z0-9-]{1,120}$#', $rest)) return $pages->notFound($lang);
		$slug = strtolower($rest);
		$a = $this->bySlug($slug, $lang);
		if ($a && $slug === $rest) return $pages->article($a);
		$a = $a ?: $this->byAlias($slug, $lang);
		if ($a) return Response::redirect($this->url($a));
		return $pages->notFound($lang);
	}

	private static function notAllowed(): Response {
		$r = Response::raw(405, 'text/plain; charset=utf-8', "Method not allowed\n");
		$r->headers['Allow'] = 'GET, HEAD';
		return $r;
	}

	/* Bilder aus media_dir, falls der Ordner nicht direkt vom Webserver ausgeliefert wird */
	private function media(string $name): Response {
		$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$file = $this->c['media_dir'] . '/' . $name;
		if (!preg_match('#^[a-z0-9-]+\.(jpg|png|gif|webp)$#', $name) || !is_file($file)) {
			return Response::raw(404, 'text/plain; charset=utf-8', "Not found\n", ['Cache-Control' => 'no-cache']);
		}
		$etag = '"' . md5($name . filesize($file) . filemtime($file)) . '"';
		if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) return Response::raw(304, $types[$ext], '', ['ETag' => $etag]);
		$r = Response::raw(200, $types[$ext], '', [
			'Content-Length' => (string) filesize($file),
			'Cache-Control' => 'public, max-age=31536000, immutable',
			'ETag' => $etag,
			'X-Content-Type-Options' => 'nosniff',
		]);
		$r->file = $file;
		return $r;
	}

	/* Fertige Antworten zwischenspeichern, Schlüssel enthält den Stand des Inhalts */
	private function cached(string $path, callable $make): Response {
		$ttl = (int) $this->c['html_cache'];
		$file = null;
		if ($ttl > 0) {
			$file = $this->c['cache_dir'] . '/html/' . hash('sha256', $path . '|' . $this->version() . '|' . json_encode($this->c['languages'])) . '.html';
			if (is_file($file) && filemtime($file) > time() - $ttl) {
				$d = @unserialize((string) file_get_contents($file), ['allowed_classes' => [Response::class]]);
				if ($d instanceof Response) return $this->conditional($d);
			}
		}
		$r = $make();
		if ($r->page) {
			$r->headers['X-Content-Type-Options'] = 'nosniff';
			if ($r->status === 200) {
				$r->headers['Cache-Control'] = 'public, max-age=300';
				$r->headers['ETag'] = '"' . md5($r->html . $r->head) . '"';
			}
		}
		if ($file && in_array($r->status, [200, 301, 404], true)) {
			$dir = dirname($file);
			if (is_dir($dir) || @mkdir($dir, 0775, true)) {
				$tmp = $file . '.' . bin2hex(random_bytes(4));
				if (@file_put_contents($tmp, serialize($r)) !== false) @rename($tmp, $file);
			}
		}
		return $this->conditional($r);
	}

	private function conditional(Response $r): Response {
		$etag = $r->headers['ETag'] ?? null;
		if ($etag && $r->status === 200 && trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
			$n = clone $r;
			$n->status = 304;
			$n->html = '';
			$n->body = '';
			return $n;
		}
		return $r;
	}

	/* Eigenständiger Betrieb: Anfrage beantworten und mit Layout ausgeben */
	public function run(): void {
		$r = $this->handle();
		if (!$r) {
			$lang = $this->defaultLang();
			$r = (new Pages($this))->notFound($lang);
		}
		if ($r->page && $r->status !== 304) $r->html = (new Layout($this))->wrap($r);
		$r->send();
	}

	/* Artikel lesen */

	public function load(string $key, string $lang): ?array {
		if (!preg_match(self::NAME, $key) || !isset($this->c['languages'][$lang])) return null;
		$file = $this->c['content_dir'] . '/' . $key . '/' . $lang . '.md';
		if (!is_file($file)) return null;
		return self::parse(file_get_contents($file), $key, $lang);
	}

	public static function parse(string $text, string $key, string $lang): ?array {
		$text = str_replace("\r\n", "\n", $text);
		$head = [];
		if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $text, $m)) {
			foreach (explode("\n", $m[1]) as $line) {
				if (preg_match('/^([a-z_]+):\s*(.*)$/', trim($line), $z)) $head[$z[1]] = trim($z[2], " \"'");
			}
			$text = $m[2];
		}
		if (empty($head['slug']) || empty($head['title'])) return null;
		return [
			'key' => $key,
			'lang' => $lang,
			'slug' => $head['slug'],
			'title' => $head['title'],
			'description' => $head['description'] ?? '',
			'date' => $head['date'] ?? '',
			'updated' => $head['updated'] ?? ($head['date'] ?? ''),
			'image' => $head['image'] ?? '',
			'ai_generated' => ($head['ai_generated'] ?? '') === 'true',
			'aliases' => array_values(array_filter(explode(' ', $head['aliases'] ?? ''))),
			'body' => ltrim($text, "\n"),
		];
	}

	public function all(): array {
		if ($this->all !== null) return $this->all;
		$this->all = [];
		foreach ($this->langs() as $lang) {
			foreach (glob($this->c['content_dir'] . '/*/' . $lang . '.md') ?: [] as $f) {
				$a = $this->load(basename(dirname($f)), $lang);
				if ($a) $this->all[] = $a;
			}
		}
		usort($this->all, fn($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp($b['updated'], $a['updated']) ?: strcmp($a['key'], $b['key']));
		return $this->all;
	}

	public function list(string $lang): array {
		return array_values(array_filter($this->all(), fn($a) => $a['lang'] === $lang));
	}

	public function bySlug(string $slug, string $lang): ?array {
		foreach ($this->list($lang) as $a) if ($a['slug'] === $slug) return $a;
		return null;
	}

	public function byAlias(string $slug, string $lang): ?array {
		foreach ($this->list($lang) as $a) if (in_array($slug, $a['aliases'], true)) return $a;
		return null;
	}

	public function translations(array $a): array {
		$out = [];
		foreach ($this->langs() as $l) {
			$t = $l === $a['lang'] ? $a : $this->load($a['key'], $l);
			if ($t) $out[$l] = $t;
		}
		return $out;
	}

	public function image(array $a): string {
		$img = $a['image'] ?: $this->c['default_image'];
		return $img ? $this->abs($img) : '';
	}

	/* Stand des Inhalts, damit der Seiten-Zwischenspeicher bei Änderungen per FTP veraltet */
	public function version(): string {
		$files = glob($this->c['content_dir'] . '/*/*.md') ?: [];
		$max = 0;
		foreach ($files as $f) $max = max($max, (int) @filemtime($f));
		return count($files) . '-' . $max;
	}

	/* Artikel schreiben */

	public function save(array $d): array {
		$one = fn($s) => trim(preg_replace('/\s+/u', ' ', (string) $s));
		$old = $this->load($d['key'], $d['lang']);
		$aliases = $old ? $old['aliases'] : [];
		if ($old && $old['slug'] !== $d['slug']) $aliases[] = $old['slug'];
		$aliases = array_values(array_diff(array_unique($aliases), [$d['slug']]));

		$date = $d['date'] ?? date('Y-m-d');
		$text = "---\n"
			. 'slug: ' . $d['slug'] . "\n"
			. 'title: ' . $one($d['title']) . "\n"
			. 'description: ' . $one($d['description']) . "\n"
			. 'date: ' . $date . "\n"
			. 'updated: ' . ($d['updated'] ?? $date) . "\n"
			. (!empty($d['image']) ? 'image: ' . $d['image'] . "\n" : '')
			. (($d['ai_generated'] ?? false) === true ? "ai_generated: true\n" : '')
			. ($aliases ? 'aliases: ' . implode(' ', $aliases) . "\n" : '')
			. "---\n\n"
			. trim(str_replace("\r\n", "\n", $d['body'])) . "\n";

		$dir = $this->c['content_dir'] . '/' . $d['key'];
		if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new \RuntimeException('cannot create folder');
		$file = $dir . '/' . $d['lang'] . '.md';
		$new = !is_file($file);
		$tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
		if (@file_put_contents($tmp, $text, LOCK_EX) === false || !@rename($tmp, $file)) {
			@unlink($tmp);
			throw new \RuntimeException('cannot write file');
		}
		$this->changed();
		return ['new' => $new, 'article' => $this->load($d['key'], $d['lang'])];
	}

	/* Zurückziehen: Datei wandert nach content/.trash, nichts geht verloren */
	public function remove(string $key, ?string $lang): array {
		$done = [];
		$trash = $this->c['content_dir'] . '/.trash';
		foreach ($lang ? [$lang] : $this->langs() as $l) {
			$file = $this->c['content_dir'] . '/' . $key . '/' . $l . '.md';
			if (!is_file($file)) continue;
			if (!is_dir($trash)) @mkdir($trash, 0775, true);
			if (@rename($file, $trash . '/' . $key . '.' . $l . '.' . date('Ymd-His') . '.md')) $done[] = $l;
		}
		$dir = $this->c['content_dir'] . '/' . $key;
		if (is_dir($dir) && !(glob($dir . '/*') ?: [])) @rmdir($dir);
		if ($done) $this->changed();
		return $done;
	}

	public function changed(): void {
		$this->all = null;
		foreach (glob($this->c['cache_dir'] . '/html/*.html') ?: [] as $f) @unlink($f);
	}
}
