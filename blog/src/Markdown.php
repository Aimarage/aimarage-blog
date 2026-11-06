<?php
namespace AimarageBlog;

/*
 * Kleiner Markdown-Umsetzer: Überschriften, Absätze, Listen, Zitate, Code,
 * Tabellen, Trennlinien, Bilder, Links, fett, kursiv. Aller Text wird
 * maskiert, rohes HTML gibt es nicht, Links nur http(s), mailto, / und #.
 */
class Markdown {

	private string $site;

	public function __construct(string $siteUrl = '') {
		$this->site = $siteUrl;
	}

	public function render(string $md): string {
		$lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $md));
		$html = '';
		$block = [];
		$n = count($lines);
		for ($i = 0; $i < $n; $i++) {
			$line = $lines[$i];
			if (preg_match('/^\s*(```|~~~)/', $line, $f)) {
				$html .= $this->block($block);
				$block = [];
				$code = [];
				for ($i++; $i < $n && !preg_match('/^\s*' . preg_quote($f[1], '/') . '\s*$/', $lines[$i]); $i++) $code[] = $lines[$i];
				$html .= '<pre><code>' . $this->esc(implode("\n", $code)) . "</code></pre>\n";
				continue;
			}
			if (trim($line) === '') {
				$html .= $this->block($block);
				$block = [];
				continue;
			}
			if (preg_match('/^\s{0,3}#{1,6}\s+/', $line) || preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line)) {
				$html .= $this->block($block) . $this->block([$line]);
				$block = [];
				continue;
			}
			$block[] = $line;
		}
		return $html . $this->block($block);
	}

	private function block(array $lines): string {
		if (!$lines) return '';
		$first = trim($lines[0]);

		if (preg_match('/^(#{1,6})\s+(.*?)\s*#*$/', $first, $m)) {
			$lv = max(2, min(6, strlen($m[1]) + (strlen($m[1]) === 1 ? 1 : 0)));
			return "<h$lv>" . $this->inline($m[2]) . "</h$lv>\n";
		}
		if (preg_match('/^([-*_])(\s*\1){2,}$/', $first)) return "<hr>\n";

		if (preg_match('/^([-*+]|\d+[.)])\s+/', $first)) {
			$tag = preg_match('/^\d/', $first) ? 'ol' : 'ul';
			$items = [];
			foreach ($lines as $l) {
				if (preg_match('/^\s*(?:[-*+]|\d+[.)])\s+(.*)$/', $l, $m)) $items[] = $m[1];
				elseif ($items) $items[count($items) - 1] .= ' ' . trim($l);
			}
			return "<$tag>" . implode('', array_map(fn($p) => '<li>' . $this->inline($p) . '</li>', $items)) . "</$tag>\n";
		}

		if (str_starts_with($first, '>')) {
			$t = implode(' ', array_map(fn($l) => trim(preg_replace('/^\s*>\s?/', '', $l)), $lines));
			return '<blockquote><p>' . $this->inline($t) . "</p></blockquote>\n";
		}

		if (count($lines) >= 2 && str_starts_with($first, '|') && preg_match('/^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?$/', trim($lines[1]))) {
			$cells = fn($l) => array_map('trim', explode('|', trim(trim($l), '|')));
			$out = '<div class="table"><table><thead><tr>';
			foreach ($cells($lines[0]) as $c) $out .= '<th>' . $this->inline($c) . '</th>';
			$out .= '</tr></thead><tbody>';
			foreach (array_slice($lines, 2) as $l) {
				$out .= '<tr>';
				foreach ($cells($l) as $c) $out .= '<td>' . $this->inline($c) . '</td>';
				$out .= '</tr>';
			}
			return $out . "</tbody></table></div>\n";
		}

		if (count($lines) === 1 && preg_match('/^!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)$/', $first, $m)) {
			$img = $this->img($m[1], $m[2]);
			if ($img === '') return '';
			$cap = ($m[3] ?? '') !== '' ? '<figcaption>' . $this->inline($m[3]) . '</figcaption>' : '';
			return '<figure>' . $img . $cap . "</figure>\n";
		}

		return '<p>' . $this->inline(implode(' ', array_map('trim', $lines))) . "</p>\n";
	}

	public function inline(string $text): string {
		$keep = [];
		$text = str_replace(["\x01", "\x02"], '', $text);
		$text = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$keep) {
			$keep[] = '<code>' . $this->esc($m[1]) . '</code>';
			return "\x01" . (count($keep) - 1) . "\x02";
		}, $text);
		$text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) use (&$keep) {
			$keep[] = $this->img($m[1], $m[2]);
			return "\x01" . (count($keep) - 1) . "\x02";
		}, $text);

		$text = $this->esc($text);
		$text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
		$text = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/', '<em>$1</em>', $text);
		$text = preg_replace('/(?<![_\w])_(?!\s)(.+?)(?<!\s)_(?![_\w])/', '<em>$1</em>', $text);
		$text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
			$url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
			if (!$this->safeUrl($url, true)) return $m[1];
			$ext = preg_match('#^https?://#i', $url) && ($this->site === '' || stripos($url, $this->site . '/') !== 0 && strcasecmp($url, $this->site) !== 0);
			return '<a href="' . $this->esc($url) . '"' . ($ext ? ' rel="noopener"' : '') . '>' . $m[1] . '</a>';
		}, $text);

		return preg_replace_callback('/\x01(\d+)\x02/', fn($m) => $keep[(int) $m[1]] ?? '', $text);
	}

	private function img(string $alt, string $url): string {
		if (!$this->safeUrl($url, false)) return '';
		return '<img src="' . $this->esc($url) . '" alt="' . $this->esc($alt) . '" loading="lazy" decoding="async">';
	}

	private function safeUrl(string $url, bool $link): bool {
		if (preg_match('/[\x00-\x20"<>\\\\]/', $url)) return false;
		if (preg_match('#^https?://[^/]#i', $url)) return true;
		if (preg_match('#^/(?!/)#', $url)) return true;
		return $link && preg_match('#^(\#|mailto:)#i', $url) === 1;
	}

	private function esc(string $s): string {
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
