<?php
namespace AimarageBlog;

/*
 * Ergebnis von Blog::handle(). Bei einer Seite (isPage) bettet die Website
 * $html in ihr Layout und schreibt $head in den <head>. Alles andere
 * (Schnittstelle, Feed, Sitemap, Bilder, Weiterleitungen) mit send() ausgeben.
 */
class Response {

	public int $status = 200;
	public array $headers = [];
	public string $body = '';

	public bool $page = false;
	public string $lang = '';
	public string $title = '';
	public string $description = '';
	public string $head = '';
	public string $html = '';
	public ?array $article = null;

	public ?string $file = null;

	public static function raw(int $status, string $type, string $body, array $headers = []): self {
		$r = new self();
		$r->status = $status;
		$r->headers = ['Content-Type' => $type] + $headers;
		$r->body = $body;
		return $r;
	}

	public static function json(int $status, array $data): self {
		return self::raw($status, 'application/json; charset=utf-8',
			json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['Cache-Control' => 'no-store']);
	}

	public static function redirect(string $to, int $status = 301): self {
		return self::raw($status, 'text/plain; charset=utf-8', '', ['Location' => $to, 'Cache-Control' => 'public, max-age=3600']);
	}

	public function isPage(): bool {
		return $this->page;
	}

	public function sendHeaders(): void {
		if (headers_sent()) return;
		http_response_code($this->status);
		foreach ($this->headers as $k => $v) header($k . ': ' . $v);
	}

	public function send(): void {
		$this->sendHeaders();
		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return;
		if ($this->file !== null) {
			readfile($this->file);
			return;
		}
		echo $this->page ? $this->html : $this->body;
	}
}
