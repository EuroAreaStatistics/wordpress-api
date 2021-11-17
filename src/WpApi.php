<?php
namespace EuroAreaStatistics\WordPressApi;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

class WpApi {
  protected $client;
  private $user;
  private $password;
  private $key;
  private $cookies;
  private $useLogin;

  function __construct($baseUrl, $user, $password, $useLogin = false) {
    $this->client = new Client([
      'headers' => [ 'User-Agent' => 'wpapi/1.0' ],
      'base_uri' => $baseUrl,
    ]);
    $this->user = $user;
    if (is_array($password)) {
      $this->password = $password[0];
      $this->key = $password[1];
    } else {
      $this->password = $password;
      $this->key = $password;
    }
    $this->useLogin = $useLogin;
  }

  function getJson($url, $query = []) {
    $page = 1;
    $result = [];
    $query['per_page'] = 100;
    $query['order'] = 'asc';
    $query['orderBy'] = 'id'; # always valid and unique ?
    while (TRUE) {
      if ($page === 1) {
        unset($query['page']);
      } else {
        $query['page'] = $page;
      }
      $r = $this->getSingleJsonPage($url, $query);
      if (empty($r['pages'])) {
        return $r['json'];
      }
      $result = array_merge($result, $r['json']);
      $page++;
      if ($r['pages'][0] < $page) break;
    }
    return $result;
  }

  function getSingleJsonPage($url, $query) {
    $response = $this->client->request('GET', $url, [
      'auth' => [$this->user, $this->key],
      'query' => $query,
      'headers' => ['Accept' => 'application/json'],
    ]);
    return [
      'pages' => $response->getHeader('X-WP-TotalPages'),
      'json' => json_decode($response->getBody()->getContents(), TRUE),
    ];
  }

  function postJson($url, $query = [], $body = null) {
    $response = $this->client->request('POST', $url, [
      'auth' => [$this->user, $this->key],
      'query' => $query,
      'body' => json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ]);
    return json_decode($response->getBody()->getContents(), TRUE);
  }

  function getContent($url, $query = []) {
    if (!isset($this->cookies) && $this->useLogin && strpos($url, '/wp-content/') !== 0) {
      $this->cookies = new CookieJar;
      $this->client->request('POST', '/wp-login.php', [
        'cookies' => $this->cookies,
        'form_params' => [
          'log' => $this->user,
          'pwd' => $this->password,
          'wp-submit' => 'Log In',
        ],
      ]);
      if (!count(preg_grep('/^wp-settings-/', array_column($this->cookies->toArray(), 'Name')))) {
        throw new \Exception(sprintf("%s: login failed", __METHOD__));
      }
    }
    $response = $this->client->request('GET', $url, [
      'cookies' => $this->cookies,
      'query' => $query,
    ]);
    return $response->getBody()->getContents();
  }
}
