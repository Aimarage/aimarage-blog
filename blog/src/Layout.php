<?php
namespace AimarageBlog;

/*
 * Rahmen für den eigenständigen Betrieb: eigenes schlichtes Layout oder
 * Kopf und Fuß der Website. Im zweiten Fall werden Titel, Beschreibung,
 * canonical, hreflang und Open Graph der Website-Vorlage durch die des
 * Artikels ersetzt. Gibt die Vorlage selbst $blogHead aus, bleibt sie unberührt.
 */
class Layout {

	private Blog $b;

	public function __construct(Blog $blog) {
		$this->b = $blog;
	}

	private static function e(string $s): string {
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	public function wrap(Response $r): string {
		$l = $this->b->c['layout'];
		if (is_array($l) && !empty($l['header'])) return $this->site($r, $l);
		return $this->builtin($r);
	}

	public function headTags(Response $r, bool $title): string {
		$css = $this->b->c['stylesheet'] ?? '';
		return ($title ? '<title>' . self::e($r->title) . "</title>\n" : '')
			. ($r->description !== '' ? '<meta name="description" content="' . self::e($r->description) . "\">\n" : '')
			. $r->head . "\n"
			. ($css ? '<link rel="stylesheet" href="' . self::e($css) . "\">\n" : '');
	}

	private function builtin(Response $r): string {
		$b = $this->b;
		$lang = $r->lang ?: $b->defaultLang();
		$feed = rtrim($b->c['languages'][$lang]['path'] ?? '', '/') . '/feed.xml';
		return "<!doctype html>\n<html lang=\"" . self::e($lang) . "\">\n<head>\n"
			. "<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
			. $this->headTags($r, true)
			. "</head>\n<body class=\"aimarage-blog-page\">\n"
			. '<header class="site-head"><a href="' . self::e($b->c['base_url'] . '/') . '">' . self::e($b->c['site_name']) . '</a>'
			. '<a href="' . self::e($b->listUrl($lang)) . '">' . self::e($b->c['languages'][$lang]['title'] ?? 'Blog') . "</a></header>\n"
			. "<main>\n" . $r->html . "</main>\n"
			. '<footer class="site-foot"><a href="' . self::e($feed) . '">Feed</a></footer>' . "\n"
			. "</body>\n</html>\n";
	}

	private function site(Response $r, array $l): string {
		$blog = $r;
		$blogTitle = $r->title;
		$blogDescription = $r->description;
		$blogHead = $this->headTags($r, false);
		$title = $r->title;
		$description = $r->description;
		$lang = $r->lang;

		ob_start();
		if (!empty($l['bootstrap'])) include $l['bootstrap'];
		ob_end_clean();

		ob_start();
		include $l['header'];
		$head = (string) ob_get_clean();
		ob_start();
		if (!empty($l['footer'])) include $l['footer'];
		$foot = (string) ob_get_clean();

		if (stripos($head, '</head>') !== false && !str_contains($head, $r->head)) {
			$head = $this->inject($head, $r);
		}
		return $head . $r->html . $foot;
	}

	private function inject(string $html, Response $r): string {
		$pos = stripos($html, '</head>');
		$top = substr($html, 0, $pos);
		$rest = substr($html, $pos);
		$top = preg_replace([
			'#<title\b[^>]*>.*?</title>\s*#is',
			'#<meta\s[^>]*name=["\']?(description|robots|twitter:[a-z:]+)["\'\s][^>]*>\s*#i',
			'#<meta\s[^>]*property=["\']?(og|article):[a-z_:]+["\'\s][^>]*>\s*#i',
			'#<link\s[^>]*rel=["\']?canonical["\'\s][^>]*>\s*#i',
			'#<link\s[^>]*hreflang=[^>]*>\s*#i',
		], '', $top);
		return $top . $this->headTags($r, true) . $rest;
	}
}
