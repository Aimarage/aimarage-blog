<?php
namespace AimarageBlog;

/*
 * Schnittstelle für Aimarage (Aimarage-Blog-API):
 *
 *   POST   <api>          Artikel anlegen oder ersetzen (JSON key, lang, slug, title,
 *                         description, date, updated, image, ai_generated, body), 201 neu, 200 ersetzt
 *   GET    <api>          Liste (?lang=de), mit ?key=...&lang=... ein Artikel samt body
 *   DELETE <api>          Zurückziehen, JSON oder Query: key, lang (ohne lang alle Sprachen)
 *   POST   <api>/images   Bild hochladen (multipart "file", rohe Bilddaten oder JSON name/data)
 *
 * Bearer-Token, nur der SHA-256-Hash steht in der Einstellung. Ohne Hash: 404 disabled.
 */
class Api {

	const SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
	const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];

	private Blog $b;

	public function __construct(Blog $blog) {
		$this->b = $blog;
	}

	public function handle(string $sub, string $method): Response {
		$b = $this->b;
		if ($b->tokenHash() === '') return Response::json(404, ['error' => 'disabled']);

		$allowed = $sub === '' ? ['POST', 'GET', 'DELETE'] : ($sub === 'images' ? ['POST'] : []);
		if (!$allowed) return Response::json(404, ['error' => 'not found']);
		if (!in_array($method, $allowed, true)) {
			$r = Response::json(405, ['error' => 'method not allowed']);
			$r->headers['Allow'] = implode(', ', $allowed);
			return $r;
		}

		$limit = $this->limit();
		if ($limit) return $limit;

		if (!$this->authorized()) {
			$this->fail();
			return Response::json(401, ['error' => 'unauthorized']);
		}

		try {
			if ($sub === 'images') return $this->upload();
			if ($method === 'GET') return $this->list();
			if ($method === 'DELETE') return $this->delete();
			return $this->save();
		} catch (\RuntimeException $e) {
			return Response::json(500, ['error' => $e->getMessage()]);
		}
	}

	/* Zugang */

	private function token(): string {
		$h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		if ($h === '' && function_exists('getallheaders')) {
			foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $h = $v;
		}
		return preg_match('/^Bearer\s+(\S+)$/', trim($h), $m) ? $m[1] : '';
	}

	private function authorized(): bool {
		$t = $this->token();
		return $t !== '' && hash_equals($this->b->tokenHash(), hash('sha256', $t));
	}

	/* Einfache Bremse je Adresse: Anfragen je Minute und Fehlversuche je 10 Minuten */

	private function rateFile(): string {
		$dir = $this->b->c['cache_dir'] . '/rate';
		if (!is_dir($dir)) @mkdir($dir, 0775, true);
		return $dir . '/' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '-') . '.json';
	}

	private function limit(): ?Response {
		$f = $this->rateFile();
		$now = time();
		$s = is_file($f) ? (json_decode((string) @file_get_contents($f), true) ?: []) : [];
		$fails = array_values(array_filter($s['fails'] ?? [], fn($t) => $t > $now - 600));
		if ((int) $this->b->c['auth_fail_limit'] > 0 && count($fails) >= (int) $this->b->c['auth_fail_limit']) {
			return $this->tooMany(max(1, min($fails) + 600 - $now));
		}
		$win = (int) ($s['win'] ?? 0);
		$n = $win === intdiv($now, 60) ? (int) ($s['n'] ?? 0) + 1 : 1;
		@file_put_contents($f, json_encode(['win' => intdiv($now, 60), 'n' => $n, 'fails' => $fails]), LOCK_EX);
		if ((int) $this->b->c['rate_limit'] > 0 && $n > (int) $this->b->c['rate_limit']) return $this->tooMany(60 - $now % 60);
		return null;
	}

	private function fail(): void {
		$f = $this->rateFile();
		$s = is_file($f) ? (json_decode((string) @file_get_contents($f), true) ?: []) : [];
		$s['fails'][] = time();
		@file_put_contents($f, json_encode($s), LOCK_EX);
	}

	private function tooMany(int $wait): Response {
		$r = Response::json(429, ['error' => 'too many requests']);
		$r->headers['Retry-After'] = (string) $wait;
		return $r;
	}

	/* Eingaben */

	private function input(int $max = 2097152): array|Response {
		$raw = (string) file_get_contents('php://input', false, null, 0, $max + 1);
		if (strlen($raw) > $max) return Response::json(413, ['error' => 'too large']);
		$d = json_decode($raw, true);
		if (!is_array($d)) return Response::json(400, ['error' => 'invalid json']);
		return $d;
	}

	private function save(): Response {
		$b = $this->b;
		$d = $this->input();
		if ($d instanceof Response) return $d;

		$bad = [];
		foreach (['key', 'slug'] as $f) {
			if (!is_string($d[$f] ?? null) || !preg_match(self::SLUG, $d[$f]) || strlen($d[$f]) > 120) $bad[] = $f;
		}
		if (!is_string($d['lang'] ?? null) || !in_array($d['lang'], $b->langs(), true)) $bad[] = 'lang';
		foreach (['title', 'description', 'body'] as $f) {
			if (!is_string($d[$f] ?? null) || trim($d[$f]) === '') $bad[] = $f;
		}
		if (is_string($d['title'] ?? null) && mb_strlen($d['title']) > 300) $bad[] = 'title';
		if (is_string($d['description'] ?? null) && mb_strlen($d['description']) > 1000) $bad[] = 'description';
		foreach (['date', 'updated'] as $f) {
			if (isset($d[$f]) && (!is_string($d[$f]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d[$f]) || !checkdate((int) substr($d[$f], 5, 2), (int) substr($d[$f], 8, 2), (int) substr($d[$f], 0, 4)))) $bad[] = $f;
		}
		if (isset($d['ai_generated']) && !is_bool($d['ai_generated'])) $bad[] = 'ai_generated';
		if (isset($d['image']) && $d['image'] !== '' && (!is_string($d['image']) || !preg_match('#^(/[A-Za-z0-9._/-]+|https://[^\s"<>]+)$#', $d['image']) || str_contains($d['image'], '..'))) $bad[] = 'image';
		if ($bad) return Response::json(422, ['error' => 'invalid fields', 'fields' => array_values(array_unique($bad))]);

		$other = $b->bySlug($d['slug'], $d['lang']);
		if ($other && $other['key'] !== $d['key']) return Response::json(409, ['error' => 'slug already used', 'key' => $other['key']]);

		$res = $b->save($d);
		$a = $res['article'];
		return Response::json($res['new'] ? 201 : 200, ['ok' => true, 'url' => $b->abs($b->url($a)), 'key' => $a['key'], 'lang' => $a['lang']]);
	}

	private function list(): Response {
		$b = $this->b;
		$key = $_GET['key'] ?? null;
		$lang = $_GET['lang'] ?? null;
		if ($lang !== null && !in_array($lang, $b->langs(), true)) return Response::json(422, ['error' => 'invalid fields', 'fields' => ['lang']]);
		if ($key !== null) {
			if (!is_string($key) || !preg_match(self::SLUG, $key)) return Response::json(422, ['error' => 'invalid fields', 'fields' => ['key']]);
			$out = [];
			foreach ($lang ? [$lang] : $b->langs() as $l) {
				if ($a = $b->load($key, $l)) $out[] = $this->row($a, true);
			}
			if (!$out) return Response::json(404, ['error' => 'not found']);
			return Response::json(200, ['ok' => true, 'articles' => $out]);
		}
		$rows = array_map(fn($a) => $this->row($a, false), $lang ? $b->list($lang) : $b->all());
		return Response::json(200, ['ok' => true, 'articles' => $rows]);
	}

	private function row(array $a, bool $body): array {
		$r = ['key' => $a['key'], 'lang' => $a['lang'], 'slug' => $a['slug'], 'title' => $a['title'], 'description' => $a['description'],
			'date' => $a['date'], 'updated' => $a['updated'], 'image' => $a['image'], 'ai_generated' => $a['ai_generated'], 'url' => $this->b->abs($this->b->url($a))];
		if ($body) $r['body'] = $a['body'];
		return $r;
	}

	private function delete(): Response {
		$b = $this->b;
		$d = $_GET;
		if (!isset($d['key'])) {
			$in = $this->input();
			if ($in instanceof Response) return $in;
			$d = $in;
		}
		$bad = [];
		if (!is_string($d['key'] ?? null) || !preg_match(self::SLUG, $d['key'])) $bad[] = 'key';
		$lang = $d['lang'] ?? null;
		if ($lang !== null && $lang !== '' && (!is_string($lang) || !in_array($lang, $b->langs(), true))) $bad[] = 'lang';
		if ($bad) return Response::json(422, ['error' => 'invalid fields', 'fields' => $bad]);
		$done = $b->remove($d['key'], $lang ?: null);
		if (!$done) return Response::json(404, ['error' => 'not found']);
		return Response::json(200, ['ok' => true, 'key' => $d['key'], 'deleted' => $done]);
	}

	/* Bilder */

	private function upload(): Response {
		$b = $this->b;
		$max = (int) $b->c['image_max_bytes'];
		$name = $_POST['name'] ?? $_GET['name'] ?? '';
		$data = null;

		if (!empty($_FILES['file'])) {
			$f = $_FILES['file'];
			if (($f['error'] ?? 1) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 1) === UPLOAD_ERR_FORM_SIZE) return Response::json(413, ['error' => 'too large']);
			if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return Response::json(400, ['error' => 'upload failed']);
			if ($f['size'] > $max) return Response::json(413, ['error' => 'too large']);
			$data = (string) file_get_contents($f['tmp_name']);
			if ($name === '') $name = pathinfo((string) $f['name'], PATHINFO_FILENAME);
		} else {
			$type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
			if (str_starts_with($type, 'application/json')) {
				$d = $this->input((int) ($max * 1.4) + 4096);
				if ($d instanceof Response) return $d;
				$data = is_string($d['data'] ?? null) ? base64_decode(preg_replace('#^data:[^,]*,#', '', $d['data']), true) : false;
				if ($data === false) return Response::json(422, ['error' => 'invalid fields', 'fields' => ['data']]);
				if (is_string($d['name'] ?? null)) $name = $d['name'];
			} else {
				$data = (string) file_get_contents('php://input', false, null, 0, $max + 1);
			}
		}
		if (strlen($data) > $max) return Response::json(413, ['error' => 'too large']);
		if ($data === '') return Response::json(422, ['error' => 'invalid fields', 'fields' => ['file']]);

		$info = @getimagesizefromstring($data);
		if (!$info || !isset(self::TYPES[$info[2]]) || $info[0] < 1 || $info[1] < 1 || $info[0] > 12000 || $info[1] > 12000) {
			return Response::json(415, ['error' => 'unsupported image type', 'allowed' => ['jpeg', 'png', 'webp', 'gif']]);
		}
		$ext = self::TYPES[$info[2]];

		$base = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $name) ?: ''));
		$base = substr(trim($base, '-'), 0, 60) ?: 'image';
		$file = $base . '-' . substr(hash('sha256', $data), 0, 10) . '.' . $ext;

		$dir = $b->c['media_dir'];
		if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new \RuntimeException('cannot create folder');
		$path = $dir . '/' . $file;
		$new = !is_file($path);
		if ($new && @file_put_contents($path, $data, LOCK_EX) === false) throw new \RuntimeException('cannot write file');

		$rel = $b->c['media_url'] . '/' . $file;
		return Response::json($new ? 201 : 200, ['ok' => true, 'url' => $b->abs($rel), 'path' => $rel, 'width' => $info[0], 'height' => $info[1], 'type' => $info['mime']]);
	}
}
