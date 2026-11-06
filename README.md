# aimarage-blog

[English](#english) | [Deutsch](#deutsch)

## English

A small PHP blog for existing websites. Articles are Markdown files, there is no database and no third-party service (no CDNs, no trackers, no external fonts). [Aimarage](https://aimarage.com), the AI marketing agent, publishes approved articles through the **Aimarage Blog API** on its own. Aimarage itself: https://github.com/Aimarage/aimarage (self-hosted) or https://aimarage.com (hosted).

Runs on Apache with PHP 8.3 or newer, also on shared hosting without SSH. No Composer needed, the Markdown renderer is built in.

### What it does

- Overview (newest first, with pages), article page, 404 page, clean URLs
- One or more languages, each with its own path (e.g. `/blog`, `/de/ratgeber`, `/guides`)
- SEO: title, description, canonical, hreflang between language versions, Open Graph and Twitter card with image, schema.org `BlogPosting` and `BreadcrumbList`, its own sitemap, Atom and RSS feed
- Fast: cache headers, ETag, finished pages are cached
- API: create and replace articles, list, withdraw, upload images
- AI label: articles sent with `ai_generated` get a meta tag, schema.org `digitalSourceType` and the header `AI-Disclosure` (machine-readable label under Art. 50(2) EU AI Act)
- Security: token stored only as a hash, brake against guessing, strict checks of all input, no HTML from Markdown, write access only in the folders for articles, images and cache
- Three ways to embed: its own plain layout, header and footer of your website, or as a library in a website with its own router

### Layout

```
blog/                    This is the folder that goes on the server
  index.php              Entry point when running on its own
  .htaccess              Clean URLs, access protection
  config.sample.php      Template for config.php
  assets/blog.css        Plain design with CSS variables
  src/                   Code (Blog, Api, Pages, Markdown, Layout, Response)
  content/               Articles: content/<key>/<lang>.md
  media/                 Uploaded images
  cache/                 Cache
tests/run.php            Tests (php tests/run.php)
tests/router.php         Router for php -S (replaces the .htaccess locally)
```

An article is a file `content/<key>/<lang>.md`. The `key` links the language versions:

```
---
slug: first-article
title: My first article
description: One line for Google and the overview.
date: 2026-10-09
updated: 2026-10-09
image: /blog/media/cover-1a2b3c4d5e.jpg
---

The text in Markdown.

## Subheading
```

You can also add or change articles by hand via FTP, the cache notices it on its own.

### Install in 5 steps (shared hosting, FTP only)

1. **Upload:** upload the folder `blog/` via FTP into the root folder of your website (where its `index.php` is). The blog is then at `https://www.example.com/blog`.
2. **Settings:** copy `config.sample.php` to `config.php` and fill in `site_name`, `base_url` (with `https://` and exactly as the site is called, e.g. with `www`), languages, author.
3. **Write access:** the folders `blog/content`, `blog/media` and `blog/cache` need write access for the web server. In the FTP program: right click, file permissions, `775` (if that is not enough: `777`).
4. **Token:** create a token (see below) and put its hash into `config.php` at `token_hash`.
5. **Aimarage:** enter address and token in Aimarage and click "Check" (see below). Then send a test article with curl or approve an article in Aimarage.

If "Check" says the token was rejected although it is right, the server does not pass the `Authorization` header to PHP. The included `.htaccess` already fetches it via `RewriteRule`. If that does not help, add `CGIPassAuth On` to the `.htaccess` in `blog/` (only works if the host allows it; otherwise you get an error 500, then remove the line again).

### Try it locally

```bash
cp blog/config.sample.php blog/config.php
# in config.php: 'base_url' => 'http://127.0.0.1:8080' and token_hash (see below)
php -S 127.0.0.1:8080 tests/router.php
```

Then open http://127.0.0.1:8080/blog. `php -S` ignores `.htaccess`, so `tests/router.php` sends all requests to `blog/index.php`. Use it only for testing, on the server Apache with the `.htaccess` is needed.

### Create a token

The token is a long random value. `config.php` only holds its SHA-256 hash, so whoever reads the file cannot publish anything with it.

```bash
php -r '$t = bin2hex(random_bytes(32)); echo "Token: $t\nHash:  ", hash("sha256", $t), "\n";'
```

Without PHP:

```bash
TOKEN=$(openssl rand -hex 32); echo "Token: $TOKEN"; printf %s "$TOKEN" | shasum -a 256
```

- The **token** goes into Aimarage (and nowhere else).
- The **hash** goes into `config.php` at `'token_hash' => '...'`.

Instead of `config.php` the hash can also be set as environment variable `AIMARAGE_BLOG_TOKEN_HASH` (e.g. `SetEnv AIMARAGE_BLOG_TOKEN_HASH ...` in the `.htaccess`). Without a hash the API is switched off and answers 404.

### Connect in Aimarage

Open the project, Settings, "Publish articles", type "Aimarage Blog API":

- **Address:** the full API address, e.g. `https://www.example.com/blog/api` (the value of `api_path` in `config.php`). It must be the address without redirect, so with `https` and with `www` if the site is called that way. Aimarage does not follow redirects.
- **Token:** the token (not the hash).
- "Check" tests the access: it lists the articles or sends an empty request, the right answer to that is 422 (fields missing).

Aimarage sends one call per language with the same `key`. Languages not set up in `config.php` are rejected with 422.

### Test with curl

```bash
curl -i -X POST https://www.example.com/blog/api \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"key":"test-article","lang":"de","slug":"test-article","title":"Test article","description":"Just a test.","body":"Hello **world**.\n\n## Section\n\n- one\n- two"}'
```

Answer `201` with `{"ok":true,"url":"https://www.example.com/blog/test-article",...}`. Sending it again gives `200` (replaced). Remove it again:

```bash
curl -i -X DELETE "https://www.example.com/blog/api?key=test-article" -H "Authorization: Bearer YOUR_TOKEN"
```

### Embed in your website

#### Use the design of your website

In `config.php`, instead of `'layout' => 'builtin'`, give header and footer of your website (file paths on the server):

```php
'layout' => [
	'bootstrap' => '/var/www/site/inc/init.php',
	'header'    => '/var/www/site/inc/header.php',
	'footer'    => '/var/www/site/inc/footer.php',
],
'stylesheet' => '/blog/assets/blog.css',
```

`bootstrap` is optional (e.g. constants and functions), `stylesheet` can be `''` to use only the CSS of your website. The blog replaces title, description, canonical, hreflang and Open Graph of the template with those of the article. The templates get the variables `$title`, `$description`, `$blogHead` (all head tags) and `$lang`. If the template prints `$blogHead` in its `<head>` itself, the blog replaces nothing.

Colors and width can be changed with the CSS variables in `blog.css` (`--blog-accent`, `--blog-width` etc.), easiest in the CSS of your website:

```css
:root {
	--blog-accent: #c0392b;
	--blog-font: inherit;
}
```

#### Other paths, e.g. /de/ratgeber and /guides

Enter the paths in `config.php` and, in the `.htaccess` in the **root folder** of the website, hand these paths to the blog, before the other rules:

```
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteRule ^(de/ratgeber|guides|api/guides|sitemap-blog\.xml)(/.*)?$ blog/index.php [L]
```

With `'api_path' => '/api/guides'` the API address is then `https://www.example.com/api/guides`.

#### Menu link and sitemap

Put a link to the overview into the menu, e.g. `<a href="/blog">Blog</a>`. The blog has its own sitemap (`sitemap_path`, e.g. `/blog/sitemap.xml`). Add it to the `robots.txt` of the website (`Sitemap: https://www.example.com/blog/sitemap.xml`) or to a sitemap index, and submit it once in Google Search Console.

### As a library in a site with its own router

If your `index.php` builds every page itself, the blog runs as a library: `handle()` returns `null` if the address does not belong to the blog, otherwise a response that your site prints or embeds in its layout. Code, settings and data can live apart, e.g. the code (`blog/src/`) in the repo of the website and settings, articles, images and cache outside the web root, so a deployment does not touch them (images are then served through PHP, with cache headers for one year).

```php
require __DIR__ . '/lib/aimarage-blog/src/autoload.php';
$blogPage = (new AimarageBlog(require '/var/www/blogdata/config.php'))->handle($path);
if ($blogPage && (!$blogPage->isPage() || $blogPage->status === 304)) {
	$blogPage->send();
	exit;
}
if ($blogPage) {
	$blogPage->sendHeaders();
	$title       = $blogPage->title;
	$description = $blogPage->description;
}
```

`send()` covers API, feeds, sitemap, images and redirects. For pages, print `$blogPage->head` in your `<head>` (instead of your own canonical, Open Graph and JSON-LD) and `$blogPage->html` in the content area. Pass the `Authorization` header to PHP in the `.htaccess` (`RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`).

What `handle()` returns (class `AimarageBlog\Response`):

| Field | Content |
|---|---|
| `isPage()` | `true` for overview, article, 404 - the website embeds `html` in its layout |
| `status` | HTTP status (200, 301, 304, 404, ...) |
| `title`, `description` | for `<title>` and meta description. The title gets `\| site_name` only if the name is missing and everything fits in 60 characters; longer titles are cut at the last separator (`\|`, `-`, `,`, `:`) |
| `head` | canonical, hreflang, Open Graph, Twitter, feeds, JSON-LD (without `<title>` and description) |
| `html` | the content as an HTML fragment |
| `lang`, `article` | language, for articles the article data |
| `send()`, `sendHeaders()` | print the response, or only status and headers |

### API

All calls with `Authorization: Bearer <TOKEN>`. Answers are JSON.

| Call | Purpose |
|---|---|
| `POST <api>` | Create or replace an article, see below |
| `GET <api>` | List of all articles (`?lang=de` for one language). With `?key=...` one article including `body` |
| `DELETE <api>` | Withdraw: `?key=...&lang=de` or JSON `{"key":"...","lang":"de"}`. Without `lang` all languages. The file moves to `content/.trash/` |
| `POST <api>/images` | Upload an image: form field `file` (multipart), raw image data with `Content-Type: image/...` and `?name=`, or JSON `{"name":"...","data":"<base64>"}`. JPEG, PNG, WebP, GIF up to `image_max_bytes`. Answer `{"ok":true,"url":"...","path":"/blog/media/name-1a2b3c4d5e.png","width":..,"height":..}`. `path` can be used as `image` of an article |

Create or replace an article (`POST <api>`), fields:

| Field | Required | Format |
|---|---|---|
| `key` | yes | `a-z`, `0-9`, hyphens, max. 120 characters. Links the language versions |
| `lang` | yes | one of the languages in `config.php` |
| `slug` | yes | like `key`, address of the article |
| `title` | yes | text, max. 300 characters |
| `description` | yes | text, max. 1000 characters, about 160 recommended |
| `body` | yes | Markdown without title |
| `date`, `updated` | no | `YYYY-MM-DD`, default today or `date` |
| `image` | no | `/path/image.jpg` or `https://...` |
| `ai_generated` | no | `true` if the text was written by an AI (Aimarage sends it). The article page then has `<meta name="ai-generated" content="true">`, `digitalSourceType` = `TrainedAlgorithmicMediaDigitalSource` in the schema.org `BlogPosting` and the HTTP header `AI-Disclosure: mode=ai-originated` |

Answers: `201` new, `200` replaced, each `{"ok":true,"url":"<public address>","key":"...","lang":"..."}`. Errors: `400` no JSON, `401` wrong token, `404` switched off, `405` wrong method, `409` slug belongs to another article, `413` too large, `415` not an allowed image, `422` invalid fields (`fields` names them), `429` too many requests (`Retry-After`), `500` no write access.

If the `slug` of an article changes, the old address redirects to the new one with 301.

### Markdown

`##` to `######` (a `#` becomes `##`, because the title already is the main heading), paragraphs, lists (`-`, `*`, `1.`), quotes (`>`), `**bold**`, `*italic*`, `` `code` ``, code blocks with ```` ``` ````, tables (`| a | b |`), rules (`---`), images `![Text](/path.jpg "Caption")`, links `[Text](https://...)`. Links and images only with `https://`, `http://`, `/` (links also `#` and `mailto:`). HTML in the text is not run but shown as text.

### Settings (config.php)

| Key | Meaning |
|---|---|
| `site_name`, `base_url` | name and address of the website |
| `languages` | languages: `path`, `title`, `description`, `locale`, optional `intro` (Markdown, shown on the first page of the overview below the description). The first one is the default language |
| `api_path`, `sitemap_path` | addresses of the API and the sitemap |
| `token_hash` | SHA-256 of the token |
| `content_dir`, `media_dir`, `cache_dir` | folders, may be outside the web root |
| `media_url` | address of the images |
| `layout` | `'builtin'` or `['bootstrap' => .., 'header' => .., 'footer' => ..]` |
| `stylesheet` | CSS file for the articles, `''` for none |
| `default_image` | preview image if an article has none (1200 x 630) |
| `author`, `publisher` | for schema.org and feeds |
| `per_page` | articles per overview page |
| `html_cache` | seconds for the page cache, `0` = off |
| `image_max_bytes` | largest allowed image |
| `rate_limit`, `auth_fail_limit` | requests per minute, failed attempts per 10 minutes (per IP address) |
| `texts` | own texts, e.g. `['en' => ['empty' => 'News coming soon.']]` |

### Tests

```bash
php tests/run.php
```

Starts two local servers with `php -S` (ports 18431 and 18432) and checks API, pages, feeds, sitemap, images, embedding with header and footer, library mode and security (path tricks, HTML and scripts in the text, dangerous links, fake images, brake). Needs the PHP extensions curl and gd.

### License

aimarage-blog is fair code, not open source, under the [Sustainable Use License](LICENSE). You may use and modify it for your own internal business purposes or privately. You may not host it for others or offer it as a service, sell or resell it, or distribute it under another name (white-label).

## Deutsch

Ein kleines PHP-Blog für bestehende Websites. Artikel sind Markdown-Dateien, es gibt keine Datenbank und keine fremden Dienste (keine CDNs, keine Tracker, keine Schriften von außen). [Aimarage](https://aimarage.com), der KI-Marketing-Agent, veröffentlicht freigegebene Artikel über die **Aimarage-Blog-API** selbst. Aimarage selbst: https://github.com/Aimarage/aimarage (zum Selbst-Installieren) oder https://aimarage.com (gehostet).

Läuft auf Apache mit PHP 8.3 oder neuer, auch auf Shared Hosting ohne SSH. Composer ist nicht nötig.

Was es kann: Übersicht, Artikelseite und 404-Seite mit sauberen Adressen, eine oder mehrere Sprachen, SEO (Titel, Beschreibung, canonical, hreflang, Open Graph, schema.org, Sitemap, Atom und RSS), Zwischenspeicher, KI-Kennzeichnung nach Art. 50 Abs. 2 KI-Verordnung, Schnittstelle zum Anlegen, Ersetzen, Zurückziehen und für Bilder. Einbindung mit eigenem Layout, mit Kopf und Fuß der Website oder als Bibliothek.

Installation (Shared Hosting, nur FTP):

1. Den Ordner `blog/` per FTP ins Hauptverzeichnis der Website laden. Das Blog ist dann unter `https://www.example.com/blog` erreichbar.
2. `config.sample.php` als `config.php` kopieren und `site_name`, `base_url` (genau so, wie die Seite aufgerufen wird, mit `https://` und ggf. `www`), Sprachen und Autor eintragen.
3. Die Ordner `blog/content`, `blog/media` und `blog/cache` brauchen Schreibrechte (`775`, sonst `777`).
4. Token erzeugen: `php -r '$t = bin2hex(random_bytes(32)); echo "Token: $t\nHash:  ", hash("sha256", $t), "\n";'`. Der Hash kommt in `config.php` bei `token_hash`, das Token nur in Aimarage.
5. In Aimarage unter Projekt > Einstellungen > "Artikel veröffentlichen", Art "Aimarage-Blog-API", die Adresse der Schnittstelle (z.B. `https://www.example.com/blog/api`, ohne Weiterleitung) und das Token eintragen und "Prüfen" klicken.

Lokal ausprobieren: `config.php` mit `'base_url' => 'http://127.0.0.1:8080'` anlegen, dann `php -S 127.0.0.1:8080 tests/router.php` und http://127.0.0.1:8080/blog öffnen.

Schnittstelle, Einstellungen, Markdown und Einbindung als Bibliothek stehen oben im englischen Teil. Tests: `php tests/run.php`.

Lizenz: Fair Code (kein Open Source) unter der [Sustainable Use License](LICENSE). Erlaubt ist die Nutzung und Anpassung für eigene interne Zwecke oder privat. Nicht erlaubt: für andere hosten oder als Dienst anbieten, verkaufen oder weiterverkaufen, unter anderem Namen weitergeben (White-Label).
