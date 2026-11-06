<?php
namespace AimarageBlog;

/*
 * Übersicht, Artikel, 404, Feeds und Sitemap.
 */
class Pages {

	private Blog $b;

	public function __construct(Blog $blog) {
		$this->b = $blog;
	}

	private static function e(string $s): string {
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/* Seitentitel: Name der Website nur, wenn er fehlt und alles in max Zeichen passt; zu lange Titel am letzten Trenner kürzen */
	public static function pageTitle(string $title, string $site, int $max = 60): string {
		$t = trim($title);
		if ($site !== '' && mb_stripos($t, $site) === false && mb_strlen($t) + mb_strlen($site) + 3 <= $max) return $t . ' | ' . $site;
		if (mb_strlen($t) <= $max) return $t;
		$cut = 0;
		foreach ([' | ', ' - ', ', ', ': '] as $sep) {
			$pos = mb_strrpos(mb_substr($t, 0, $max), $sep);
			if ($pos !== false && $pos >= 20) $cut = max($cut, $pos);
		}
		return $cut ? mb_substr($t, 0, $cut) : $t;
	}

	private static function x(string $s): string {
		return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private static function ld(array $data): string {
		return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
	}

	/* Übersicht */

	public function listing(string $lang, int $page): Response {
		$b = $this->b;
		$all = $b->list($lang);
		$per = max(1, (int) $b->c['per_page']);
		$pages = max(1, (int) ceil(count($all) / $per));
		if ($page < 1 || $page > $pages) return $this->notFound($lang);
		$items = array_slice($all, ($page - 1) * $per, $per);
		$set = $b->c['languages'][$lang];

		$html = '<div class="aimarage-blog blog-list">' . "\n"
			. '<header class="blog-head"><h1>' . self::e($set['title'] ?? 'Blog') . '</h1>'
			. (!empty($set['description']) ? '<p class="blog-lead">' . self::e($set['description']) . '</p>' : '')
			. "</header>\n"
			. (!empty($set['intro']) && $page === 1 ? '<div class="blog-intro">' . "\n" . (new Markdown($b->c['base_url']))->render($set['intro']) . "</div>\n" : '');
		if (!$items) {
			$html .= '<p class="blog-empty">' . self::e($b->text('empty', $lang)) . "</p>\n";
		} else {
			$html .= "<ul class=\"blog-items\">\n";
			foreach ($items as $a) {
				$html .= '<li class="blog-item"><a href="' . self::e($b->url($a)) . '">'
					. ($a['image'] ? '<img src="' . self::e($a['image']) . '" alt="" loading="lazy" decoding="async">' : '')
					. '<h2>' . self::e($a['title']) . '</h2>'
					. ($a['description'] ? '<p>' . self::e($a['description']) . '</p>' : '')
					. '<time datetime="' . self::e($a['date']) . '">' . self::e($b->date($a['date'], $lang)) . '</time>'
					. "</a></li>\n";
			}
			$html .= "</ul>\n";
		}
		if ($pages > 1) {
			$html .= '<nav class="blog-pager">';
			if ($page > 1) $html .= '<a rel="prev" href="' . self::e($b->listUrl($lang, $page - 1)) . '">' . self::e($b->text('newer', $lang)) . '</a>';
			$html .= '<span>' . self::e($b->text('page', $lang)) . ' ' . $page . ' / ' . $pages . '</span>';
			if ($page < $pages) $html .= '<a rel="next" href="' . self::e($b->listUrl($lang, $page + 1)) . '">' . self::e($b->text('older', $lang)) . '</a>';
			$html .= "</nav>\n";
		}
		$html .= "</div>\n";

		$r = new Response();
		$r->page = true;
		$r->lang = $lang;
		$r->title = self::pageTitle(($set['title'] ?? 'Blog') . ($page > 1 ? ' - ' . $b->text('page', $lang) . ' ' . $page : ''), $b->c['site_name']);
		$r->description = $set['description'] ?? '';
		$r->html = $html;
		$canonical = $b->abs($b->listUrl($lang, $page));
		$alt = [];
		if ($page === 1) foreach ($b->langs() as $l) $alt[$l] = $b->abs($b->listUrl($l));
		$extra = [];
		if ($page > 1) $extra[] = '<link rel="prev" href="' . self::e($b->abs($b->listUrl($lang, $page - 1))) . '">';
		if ($page < $pages) $extra[] = '<link rel="next" href="' . self::e($b->abs($b->listUrl($lang, $page + 1))) . '">';
		$extra[] = self::ld([
			'@context' => 'https://schema.org',
			'@graph' => [
				['@type' => 'Blog', '@id' => $b->abs($b->listUrl($lang)) . '#blog', 'name' => $set['title'] ?? 'Blog', 'description' => $set['description'] ?? '',
					'url' => $b->abs($b->listUrl($lang)), 'inLanguage' => $lang,
					'blogPost' => array_map(fn($a) => ['@type' => 'BlogPosting', 'headline' => $a['title'], 'url' => $b->abs($b->url($a)), 'datePublished' => $a['date']], $items)],
				$this->breadcrumb($lang, null),
			],
		]);
		$r->head = $this->head($r, $canonical, $alt, 'website', null, $extra);
		$r->headers = ['Content-Type' => 'text/html; charset=utf-8'];
		return $r;
	}

	/* Artikel */

	public function article(array $a): Response {
		$b = $this->b;
		$lang = $a['lang'];
		$md = new Markdown($b->c['base_url']);
		$html = '<article class="aimarage-blog blog-article">' . "\n"
			. '<header class="blog-head">'
			. '<p class="blog-back"><a href="' . self::e($b->listUrl($lang)) . '">' . self::e($b->c['languages'][$lang]['title'] ?? 'Blog') . '</a></p>'
			. '<h1>' . self::e($a['title']) . '</h1>'
			. ($a['description'] ? '<p class="blog-lead">' . self::e($a['description']) . '</p>' : '')
			. '<p class="blog-meta">' . self::e($b->text('published', $lang)) . ' <time datetime="' . self::e($a['date']) . '">' . self::e($b->date($a['date'], $lang)) . '</time>'
			. ($a['updated'] && $a['updated'] !== $a['date'] ? ' &middot; ' . self::e($b->text('updated', $lang)) . ' <time datetime="' . self::e($a['updated']) . '">' . self::e($b->date($a['updated'], $lang)) . '</time>' : '')
			. "</p>\n"
			. ($a['image'] ? '<img class="blog-cover" src="' . self::e($a['image']) . '" alt="" decoding="async">' : '')
			. "</header>\n"
			. '<div class="blog-body">' . "\n" . $md->render($a['body']) . "</div>\n"
			. "</article>\n";

		$r = new Response();
		$r->page = true;
		$r->lang = $lang;
		$r->article = $a;
		$r->title = self::pageTitle($a['title'], $b->c['site_name']);
		$r->description = $a['description'];
		$r->html = $html;
		$canonical = $b->abs($b->url($a));
		$alt = [];
		$tr = $b->translations($a);
		if (count($tr) > 1) foreach ($tr as $l => $t) $alt[$l] = $b->abs($b->url($t));
		$publisher = array_filter([
			'@type' => 'Organization',
			'name' => $b->c['publisher']['name'] ?? $b->c['site_name'],
			'url' => $b->c['base_url'] . '/',
			'logo' => !empty($b->c['publisher']['logo']) ? ['@type' => 'ImageObject', 'url' => $b->abs($b->c['publisher']['logo'])] : null,
		]);
		$author = !empty($b->c['author']['name'])
			? ['@type' => $b->c['author']['type'] ?? 'Person', 'name' => $b->c['author']['name']] + (!empty($b->c['author']['url']) ? ['url' => $b->c['author']['url']] : [])
			: $publisher;
		$image = $b->image($a);
		$extra = [
			'<meta property="article:published_time" content="' . self::e($a['date']) . '">',
			'<meta property="article:modified_time" content="' . self::e($a['updated']) . '">',
			...($a['ai_generated'] ? ['<meta name="ai-generated" content="true">'] : []),
			self::ld([
				'@context' => 'https://schema.org',
				'@graph' => [
					array_filter([
						'@type' => 'BlogPosting',
						'headline' => $a['title'],
						'description' => $a['description'],
						'inLanguage' => $lang,
						'datePublished' => $a['date'],
						'dateModified' => $a['updated'],
						'image' => $image ?: null,
						'mainEntityOfPage' => $canonical,
						'url' => $canonical,
						'author' => $author,
						'publisher' => $publisher,
						'isPartOf' => ['@id' => $b->abs($b->listUrl($lang)) . '#blog'],
						'digitalSourceType' => $a['ai_generated'] ? 'https://schema.org/TrainedAlgorithmicMediaDigitalSource' : null,
					]),
					$this->breadcrumb($lang, $a),
				],
			]),
		];
		$r->head = $this->head($r, $canonical, $alt, 'article', $image, $extra);
		$mod = strtotime($a['updated'] ?: $a['date']);
		$r->headers = ['Content-Type' => 'text/html; charset=utf-8'] + ($mod ? ['Last-Modified' => gmdate('D, d M Y H:i:s', $mod) . ' GMT'] : []) + ($a['ai_generated'] ? ['AI-Disclosure' => 'mode=ai-originated'] : []);
		return $r;
	}

	public function notFound(string $lang): Response {
		$b = $this->b;
		$r = new Response();
		$r->page = true;
		$r->status = 404;
		$r->lang = $lang;
		$r->title = $b->text('notfound', $lang) . ' | ' . $b->c['site_name'];
		$r->html = '<div class="aimarage-blog blog-404"><h1>' . self::e($b->text('notfound', $lang)) . '</h1>'
			. '<p>' . self::e($b->text('notfound_text', $lang)) . '</p>'
			. '<p><a href="' . self::e($b->listUrl($lang)) . '">' . self::e($b->c['languages'][$lang]['title'] ?? 'Blog') . "</a></p></div>\n";
		$r->head = '<meta name="robots" content="noindex">';
		$r->headers = ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache'];
		return $r;
	}

	private function breadcrumb(string $lang, ?array $a): array {
		$b = $this->b;
		$items = [
			['@type' => 'ListItem', 'position' => 1, 'name' => $b->text('home', $lang), 'item' => $b->c['base_url'] . '/'],
			['@type' => 'ListItem', 'position' => 2, 'name' => $b->c['languages'][$lang]['title'] ?? 'Blog', 'item' => $b->abs($b->listUrl($lang))],
		];
		if ($a) $items[] = ['@type' => 'ListItem', 'position' => 3, 'name' => $a['title'], 'item' => $b->abs($b->url($a))];
		return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
	}

	private function head(Response $r, string $canonical, array $alt, string $type, ?string $image, array $extra): string {
		$b = $this->b;
		$image = $image ?? ($b->c['default_image'] ? $b->abs($b->c['default_image']) : '');
		$locale = $b->c['languages'][$r->lang]['locale'] ?? '';
		$t = [
			'<meta name="robots" content="index, follow, max-image-preview:large">',
			'<link rel="canonical" href="' . self::e($canonical) . '">',
		];
		if (count($alt) > 1) {
			foreach ($alt as $l => $u) $t[] = '<link rel="alternate" hreflang="' . self::e($l) . '" href="' . self::e($u) . '">';
			$t[] = '<link rel="alternate" hreflang="x-default" href="' . self::e($alt[$b->defaultLang()] ?? reset($alt)) . '">';
		}
		$feed = rtrim($b->c['languages'][$r->lang]['path'], '/');
		$t[] = '<link rel="alternate" type="application/atom+xml" title="' . self::e($b->c['site_name']) . '" href="' . self::e($b->abs($feed . '/feed.xml')) . '">';
		$t[] = '<link rel="alternate" type="application/rss+xml" title="' . self::e($b->c['site_name']) . '" href="' . self::e($b->abs($feed . '/rss.xml')) . '">';
		$t[] = '<meta property="og:type" content="' . $type . '">';
		$t[] = '<meta property="og:site_name" content="' . self::e($b->c['site_name']) . '">';
		if ($locale) $t[] = '<meta property="og:locale" content="' . self::e($locale) . '">';
		$t[] = '<meta property="og:url" content="' . self::e($canonical) . '">';
		$t[] = '<meta property="og:title" content="' . self::e($r->article['title'] ?? $r->title) . '">';
		if ($r->description !== '') $t[] = '<meta property="og:description" content="' . self::e($r->description) . '">';
		if ($image) {
			$t[] = '<meta property="og:image" content="' . self::e($image) . '">';
			$t[] = '<meta name="twitter:card" content="summary_large_image">';
		} else {
			$t[] = '<meta name="twitter:card" content="summary">';
		}
		return implode("\n", array_merge($t, $extra));
	}

	/* Feeds */

	public function atom(string $lang): Response {
		$b = $this->b;
		$items = array_slice($b->list($lang), 0, 20);
		$self = $b->abs(rtrim($b->c['languages'][$lang]['path'], '/') . '/feed.xml');
		$upd = $items ? max(array_map(fn($a) => $a['updated'] ?: $a['date'], $items)) : date('Y-m-d');
		$o = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="' . self::x($lang) . '">' . "\n"
			. "\t<title>" . self::x(($b->c['languages'][$lang]['title'] ?? 'Blog') . ' | ' . $b->c['site_name']) . "</title>\n"
			. "\t<id>" . self::x($self) . "</id>\n"
			. "\t" . '<link rel="self" href="' . self::x($self) . '"/>' . "\n"
			. "\t" . '<link rel="alternate" type="text/html" href="' . self::x($b->abs($b->listUrl($lang))) . '"/>' . "\n"
			. "\t<updated>" . self::x($this->iso($upd)) . "</updated>\n";
		$md = new Markdown($b->c['base_url']);
		foreach ($items as $a) {
			$u = $b->abs($b->url($a));
			$o .= "\t<entry>\n"
				. "\t\t<title>" . self::x($a['title']) . "</title>\n"
				. "\t\t" . '<link rel="alternate" type="text/html" href="' . self::x($u) . '"/>' . "\n"
				. "\t\t<id>" . self::x($u) . "</id>\n"
				. "\t\t<published>" . self::x($this->iso($a['date'])) . "</published>\n"
				. "\t\t<updated>" . self::x($this->iso($a['updated'] ?: $a['date'])) . "</updated>\n"
				. "\t\t<author><name>" . self::x($b->c['author']['name'] ?? $b->c['site_name']) . "</name></author>\n"
				. "\t\t<summary>" . self::x($a['description']) . "</summary>\n"
				. "\t\t" . '<content type="html">' . self::x($this->absLinks($md->render($a['body']))) . "</content>\n"
				. "\t</entry>\n";
		}
		$o .= "</feed>\n";
		return Response::raw(200, 'application/atom+xml; charset=utf-8', $o, ['Cache-Control' => 'public, max-age=600']);
	}

	public function rss(string $lang): Response {
		$b = $this->b;
		$items = array_slice($b->list($lang), 0, 20);
		$self = $b->abs(rtrim($b->c['languages'][$lang]['path'], '/') . '/rss.xml');
		$o = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>' . "\n"
			. "\t<title>" . self::x(($b->c['languages'][$lang]['title'] ?? 'Blog') . ' | ' . $b->c['site_name']) . "</title>\n"
			. "\t<link>" . self::x($b->abs($b->listUrl($lang))) . "</link>\n"
			. "\t<description>" . self::x($b->c['languages'][$lang]['description'] ?? '') . "</description>\n"
			. "\t<language>" . self::x($lang) . "</language>\n"
			. "\t" . '<atom:link href="' . self::x($self) . '" rel="self" type="application/rss+xml"/>' . "\n";
		foreach ($items as $a) {
			$u = $b->abs($b->url($a));
			$o .= "\t<item>\n"
				. "\t\t<title>" . self::x($a['title']) . "</title>\n"
				. "\t\t<link>" . self::x($u) . "</link>\n"
				. "\t\t" . '<guid isPermaLink="true">' . self::x($u) . "</guid>\n"
				. "\t\t<pubDate>" . self::x(date(DATE_RSS, strtotime($a['date']) ?: time())) . "</pubDate>\n"
				. "\t\t<description>" . self::x($a['description']) . "</description>\n"
				. "\t</item>\n";
		}
		$o .= "</channel></rss>\n";
		return Response::raw(200, 'application/rss+xml; charset=utf-8', $o, ['Cache-Control' => 'public, max-age=600']);
	}

	private function iso(string $d): string {
		$t = strtotime($d);
		return gmdate('Y-m-d\TH:i:s\Z', $t ?: time());
	}

	private function absLinks(string $html): string {
		$base = $this->b->c['base_url'];
		return preg_replace('#(href|src)="/(?!/)#', '$1="' . $base . '/', $html);
	}

	/* Sitemap mit Übersichten und Artikeln, hreflang bei Übersetzungen */

	public function sitemap(): Response {
		$b = $this->b;
		$o = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
		$langs = $b->langs();
		$alts = function (array $map) use ($b) {
			if (count($map) < 2) return '';
			$s = '';
			foreach ($map as $l => $u) $s .= "\t\t" . '<xhtml:link rel="alternate" hreflang="' . self::x($l) . '" href="' . self::x($u) . '"/>' . "\n";
			return $s . "\t\t" . '<xhtml:link rel="alternate" hreflang="x-default" href="' . self::x($map[$b->defaultLang()] ?? reset($map)) . '"/>' . "\n";
		};
		$lists = [];
		foreach ($langs as $l) $lists[$l] = $b->abs($b->listUrl($l));
		foreach ($langs as $l) {
			$newest = $b->list($l)[0] ?? null;
			$o .= "\t<url>\n\t\t<loc>" . self::x($lists[$l]) . "</loc>\n"
				. ($newest ? "\t\t<lastmod>" . self::x($newest['updated'] ?: $newest['date']) . "</lastmod>\n" : '')
				. $alts($lists) . "\t</url>\n";
		}
		foreach ($b->all() as $a) {
			$map = [];
			foreach ($b->translations($a) as $l => $t) $map[$l] = $b->abs($b->url($t));
			$o .= "\t<url>\n\t\t<loc>" . self::x($b->abs($b->url($a))) . "</loc>\n"
				. (($a['updated'] ?: $a['date']) ? "\t\t<lastmod>" . self::x($a['updated'] ?: $a['date']) . "</lastmod>\n" : '')
				. $alts($map) . "\t</url>\n";
		}
		$o .= "</urlset>\n";
		return Response::raw(200, 'application/xml; charset=utf-8', $o, ['Cache-Control' => 'public, max-age=600']);
	}
}
