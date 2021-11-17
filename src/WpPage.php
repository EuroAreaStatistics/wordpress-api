<?php
namespace EuroAreaStatistics\WordPressApi;
use Nette\Caching\Cache;
use Nette\Caching\Storages\FileStorage;
use Nette\Caching\Storages\MemoryStorage;

class WpPage {
  const DOWNLOADS = '/wp-content/uploads/';
  const IMAGES = '/wp-content/themes/ezbdataviz/assets/images/';
  const STYLES = '/wp-content/themes/ezbdataviz/assets/build/css/';

  private $cache;
  private $ttl;
  private $api;
  private $prefix;
  private $status;
  private $postType;
  private $wpUrl;
  private $route;
  private $scripts = [];

  function __construct($config, $postType, $route = NULL) {
    $this->prefix = $config['prefix'];
    $this->wpUrl = $config['api']['url'];
    $this->status = $config['status'];
    $this->postType = $postType;
    $this->route = $route ?? $postType;
    $key = $config['api']['key'] ?? $config['api']['password'];
    $this->api = new WpApi($this->wpUrl, $config['api']['user'], [$config['api']['password'], $key], in_array('draft', $this->status));
    if (isset($config['cache']['directory'])) {
      if (!file_exists($config['cache']['directory'].'/')) {
        mkdir($config['cache']['directory'], 0777, true);
      }
      $storage = new FileStorage($config['cache']['directory']);
      $this->ttl = $config['cache']['ttl'];
    } else {
      $storage = new MemoryStorage;
    }
    $this->cache = new Cache($storage, $this->postType);
  }

  function getRoute() {
    return $this->route;
  }

  function cleanCache() {
    $this->cache->clean([Cache::ALL=>true]);
  }

  function recache($url, $lang = NULL) {
    $parts = explode('/', trim($url, '/'), 3);
    $html = FALSE;
    if ($parts[0] !== $this->route) {
      return;
    }
    if (count($parts) === 1) {
      $url = 'index.html';
    } else if (count($parts) === 2) {
      $url = $parts[1];
    } else {
      return;
    }
    $this->sitemap = $this->cacheLoad('sitemap', function () { return $this->getSitemap(); });
    if (!isset($this->sitemap['translation'][$url])) {
      return;
    }
    foreach ($this->sitemap['translation'][$url] as $lg => $page) {
      if (!isset($lang) || $lg === $lang) {
        echo "$this->route/$url $lg\n";
        flush();
        $this->cache->remove("page-$page");
        $this->lang = $lg;
        $this->cacheLoad("page-$page", function () use($page) { return $this->getTranslatedPage($page); });
      }
    }
  }

  function warmCache() {
    echo "Pages:\n";
    $this->assetLog = [];
    $this->sitemap = $this->cacheLoad('sitemap', function () { return $this->getSitemap(); });
    foreach ($this->sitemap['translation'] as $url => $translations) {
      foreach ($translations as $lang => $page) {
        echo "$this->route/$url $lang\n";
        flush();
        $this->lang = $lang;
        $this->cacheLoad("page-$page", function () use($page) { return $this->getTranslatedPage($page); });
      }
    }
    echo "Assets:\n";
    $this->assetLog[$this->route . '/styles/main.css'] = true;
    foreach (array_keys($this->assetLog) as $url) {
        echo "$url\n";
        flush();
        $this->cacheAsset($url);
    }
  }

  private function cacheLoad($key, $callback) {
    return $this->cache->load($key, function (&$dependencies) use ($callback) {
      if (isset($this->ttl)) {
        $dependencies[Cache::EXPIRE] = $this->ttl;
      }
      return call_user_func($callback);
    });
  }

  private $sitemap;
  private $lang;

  function render($url, $lang) {
    $parts = explode('/', trim($url, '/'), 3);
    $html = FALSE;
    if ($parts[0] === $this->route) {
      if (count($parts) === 1) {
        $html = $this->renderPage('index.html', $lang);
      } else if (count($parts) === 2) {
        $html = $this->renderPage($parts[1], $lang);
      } else if (count($parts) === 3) {
        // returns asset directly to browser and exits
        $this->renderAsset($parts[1], $parts[2]);
      }
    }
    if ($html !== FALSE) {
      return $html;
    }
    header('content-type: text/plain', true, 404);
    exit;
  }

  private function cacheAsset($url) {
    $parts = explode('/', trim($url, '/'), 3);
    if ($parts[0] === $this->route) {
      if (count($parts) === 3) {
        $this->renderAsset($parts[1], $parts[2], false);
      }
    }
  }

  private function renderAsset($dir, $path, $toBrowser = true) {
    if (preg_match('@[^a-z0-9./_-]@i', $path)) {
      return;
    }
    if (preg_match('@(^|/)[.]@', $path)) {
      return;
    }
    $mime = [
      'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'csv' => 'text/csv',
      'svg' => 'image/svg+xml',
      'png' => 'image/png',
      'css' => 'text/css',
      'jpg' => 'image/jpeg',
      'pdf' => 'applicaiton/pdf',
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!isset($mime[$ext])) {
      return;
    }
    if ($dir === 'downloads') {
      $url = self::DOWNLOADS . $path;
    } else if ($dir === 'images') {
      $url = self::IMAGES . $path;
    } else if ($dir === 'styles') {
      $url = self::STYLES . $path;
    } else {
      return;
    }
    if ($toBrowser) {
      header('content-type: '.$mime[$ext]);
      try {
        echo $this->cacheLoad("content-$url", function () use($url) { return $this->api->getContent($url); });
      }  catch (Exception $e) {
        return;
      }
      exit;
    } else {
      $this->cacheLoad("content-$url", function () use($url) { return $this->api->getContent($url); });
    }
  }

  private function renderPage($url, $lang) {
    $page = $this->getPageByUrl($url, $lang);
    if ($page === FALSE) {
      return FALSE;
    }
    $html = $this->cacheLoad("page-$page", function () use($page) { return $this->getTranslatedPage($page); });
    if (is_array($html)) {
      list($html, $this->scripts) = $html;
    } else {
      $this->scripts = [];
    }
    return $html;
  }

  function getLanguage() {
    return $this->lang;
  }

  function getPages() {
    $this->sitemap = $this->cacheLoad('sitemap', function () { return $this->getSitemap(); });
    $pages = [];
    foreach ($this->sitemap['translation'] as $url => $translations) {
      $pages[$url] = [
        'title' => $this->sitemap['title'][$url],
      ];
    }
    return $pages;
  }

  function getPageByUrl($url, $lang = NULL) {
    $this->sitemap = $this->cacheLoad('sitemap', function () { return $this->getSitemap(); });
    if (!isset($this->sitemap['translation'][$url])) {
      return FALSE;
    }
    $translations = $this->sitemap['translation'][$url];
    $this->lang = isset($translations[$lang]) ? $lang : 'en';
    return $translations[$this->lang];
  }

  private function getSitemap() {
    $sitemap = [];
// optional about page
    $pages = $this->api->getJson('/wp-json/wp/v2/pages', [
      'slug' => $this->postType,
      'parent' => 0,
      'lang' => 'en',
      'status' => implode(',', $this->status),
      '_fields' => 'id,translations,title',
    ]);
    foreach ($pages as $page) {
      $slug = 'index.html';
      $sitemap['translation'][$slug] = $page['translations'];
      $sitemap['title'][$slug] = $page['title']['rendered'];
    }
// single pages, English slug is the external URL
    $slugs = [];
    $pages = $this->api->getJson('/wp-json/wp/v2/'.$this->postType, [
      'lang' => 'en',
      'status' => implode(',', $this->status),
      '_fields' => 'slug,translations,title',
    ]);
    foreach ($pages as $page) {
      $slug = $page['slug'];
      if (substr($slug, 0, 3) === 'en-') {
        $slug = substr($slug, 3);
      }
      if ($slug === '') {
        $slug = strtolower(strip_tags($page['title']['rendered']));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        if ($slug === '') {
          $slug = 'unnamed';
        }
      }
      $sitemap['translation'][$slug] = $page['translations'];
      $sitemap['title'][$slug] = $page['title']['rendered'];
      foreach ($page['translations'] as $id) {
        $slugs[$id] = $slug;
      }
    }
// WP link for internal links
// ignore language, use language of page containing link
    $pages = $this->api->getJson('/wp-json/wp/v2/'.$this->postType, [
      'status' => implode(',', $this->status),
      '_fields' => 'id,link',
    ]);
    foreach ($pages as $page) {
      if (isset($slugs[$page['id']])) {
        $parts = parse_url($page['link']);
        $url = $parts['path'];
        if (isset($parts['query'])) {
          $url .= '?' . $parts['query'];
        }
        if (isset($sitemap['link'][$url])) {
           die('duplicate link: '.$page['id']);
        }
        $sitemap['link'][$url] = $slugs[$page['id']];
      }
    }
    return $sitemap;
  }

  function getScripts() {
    return $this->scripts;
  }

  private function getTranslatedPage($page) {
    $html = $this->api->getContent('/index.php', ['page_id' => $page]);
    $doc = new DOMDocument;
    $doc->preserveWhiteSpace = TRUE;
    $doc->formatOutput = FALSE;
    if (!@$doc->loadHTML($html)) {
      throw new \Exception(sprintf("%s: could not parse HTML", __METHOD__));
    }
    $xpath = new DOMXPath($doc);
    foreach ($xpath->query('//comment()|//span[@data-mce-type="bookmark"]') as $node) {
      $node->parentNode->removeChild($node);
    }
    $scripts = [];
    foreach ($xpath->query('//script[@src]') as $node) {
      $src = $node->getAttribute('src');
      $scripts[] = $src;
      $node->parentNode->removeChild($node);
    }
    foreach ($xpath->query('//li/a') as $node) {
      $class = $node->getAttribute('class');
      if (!preg_match('/\bnav-link\b/', $class)) continue;
      $count = 0;
      $class = preg_replace('/\bactive\b/', '', $class, -1, $count);
      if (!$count) continue;
      $node->setAttribute('class', $class);
      $pclass = $node->parentNode->getAttribute('class');
      $pclass = "$pclass active";
      $node->parentNode->setAttribute('class', $pclass);
    }
    foreach ($xpath->query('//a') as $node) {
      $href = $node->getAttribute('href');
      if (strpos($href, $this->wpUrl) === 0) {
        $href = substr($href, strlen($this->wpUrl));
      }
      if (substr($href, 0, 1) !== '/') {
        continue;
      }
      $href = $this->convertHref($href);
      $node->setAttribute('href', $href);
    }
    foreach ($xpath->query('//img') as $node) {
      $src = $node->getAttribute('src');
      $node->setAttribute('src', $this->convertSrc($src));
      if ($node->hasAttribute('srcset')) {
        $srcset = $node->getAttribute('srcset');
        $srcset = explode(',' ,$srcset);
        foreach ($srcset as &$s) {
          $s = explode(' ', $s);
          foreach ($s as &$t) {
            if ($t === '') continue;
            $t = $this->convertSrc($t);
            break;
          }
          $s = implode(' ', $s);
        }
        $srcset = implode(',', $srcset);
        $node->setAttribute('srcset', $srcset);
      }
    }
    foreach ($xpath->query('//p|//li|//table') as $node) {
      $class = $node->getAttribute('class');
      $count = 0;
      $class = preg_replace('/\bMso\w*/', '', $class, -1, $count);
      if (!$count) continue;
      $class = trim($class);
      if ($class === '') {
        $node->removeAttribute('class');
      } else {
        $node->setAttribute('class', $class);
      }
    }
    foreach ($xpath->query('//table|//td') as $node) {
      $node->removeAttribute('width');
    }
    return [$doc->saveHTML($xpath->query('/html/body/div[@role="document"]')[0]), $scripts];
  }

  private function convertHref($href) {
    if (isset($this->sitemap['link'][$href])) {
      return $this->prefix.'/'.$this->route.'/'.$this->sitemap['link'][$href].'?lg='.$this->lang;
    } else if (strpos($href, self::DOWNLOADS) === 0) {
      return $this->prefix.'/'.$this->logAsset($this->route.'/downloads/' . substr($href, strlen(self::DOWNLOADS)));
    } else {
      return '';
    }
  }

  private function convertSrc($src) {
    if (strpos($src, $this->wpUrl) === 0) {
      $src = substr($src, strlen($this->wpUrl));
    }
    if (strpos($src, self::IMAGES) === 0) {
      return $this->prefix.'/'.$this->logAsset($this->route.'/images/' . substr($src, strlen(self::IMAGES)));
    } else if (strpos($src, self::STYLES) === 0) {
      return $this->prefix.'/'.$this->logAsset($this->route.'/styles/' . substr($src, strlen(self::STYLES)));
    } else if (strpos($src, self::DOWNLOADS) === 0) {
      return $this->prefix.'/'.$this->logAsset($this->route.'/downloads/' . substr($src, strlen(self::DOWNLOADS)));
    } else {
      throw new \Exception(sprintf("%s: could not find url %s", __METHOD__, $src));
    }
  }

  private $assetLog = [];
  private function logAsset($asset) {
    $this->assetLog[$asset] = true;
    return $asset;
  }

  function getPageMetadata($page) {
    $postType = $this->postType;
    if (isset($this->sitemap['translation']['index.html']) && in_array($page, $this->sitemap['translation']['index.html'])) {
      $postType = 'pages';
    }
    return $this->api->getJson('/wp-json/wp/v2/'.$postType.'/'.rawurlencode($page), ['context' => 'edit']);
  }

  function updateMetadata($page, $data) {
    $postType = $this->postType;
    if (isset($this->sitemap['translation']['index.html']) && in_array($page, $this->sitemap['translation']['index.html'])) {
      $postType = 'pages';
    }
    return $this->api->postJson('/wp-json/wp/v2/'.$postType.'/'.rawurlencode($page), [], $data);
  }

  function updateFields($page, $fields) {
    // user needs edit_posts capability and/or filter acf/rest_api/item_permissions/update
    return $this->api->postJson('/wp-json/acf/v3/'.$this->postType.'/'.rawurlencode($page), [], ['fields' => $fields]);
  }
}
