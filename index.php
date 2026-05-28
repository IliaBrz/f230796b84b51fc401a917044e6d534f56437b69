<?php
declare(strict_types=1);

// Кулинарная книга "Кривые ручки"
// Весь проект — в одном файле по ТЗ.

mb_internal_encoding('UTF-8');
session_start();

// -------------------- CONFIG --------------------
$CFG = [
  'db' => [
    'host' => '127.0.0.1',
    'name' => 'labworks.teletr.ru',
    'user' => 'labworks.teletr.ru',
    'pass' => 'wSXpxX_DJLat3qL4',
    'charset' => 'utf8mb4',
  ],
  'site' => [
    'title' => 'Кулинарная книга "Кривые ручки"',
    'tagline' => 'Сварил яйца и не сжёг соседей? Да ты - кулинар!',
  ],
  'pagination' => [
    'per_page' => 10,
  ],
];

// -------------------- DB --------------------
function db(array $CFG): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $CFG['db']['host'],
    $CFG['db']['name'],
    $CFG['db']['charset']
  );
  $pdo = new PDO($dsn, $CFG['db']['user'], $CFG['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  return $pdo;
}

function q(PDO $db, string $sql, array $params = []): PDOStatement {
  $st = $db->prepare($sql);
  $st->execute($params);
  return $st;
}

// -------------------- HELPERS --------------------
function h(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nowIso(): string {
  return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function isPost(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }

function csrfToken(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['csrf'];
}

function csrfCheck(): void {
  if (!isPost()) return;
  $t = (string)($_POST['csrf'] ?? '');
  if (!$t || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
    http_response_code(400);
    echo 'CSRF token mismatch.';
    exit;
  }
}

function flash(string $type, string $msg): void {
  $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function takeFlashes(): array {
  $f = $_SESSION['flash'] ?? [];
  unset($_SESSION['flash']);
  return is_array($f) ? $f : [];
}

function redirect(string $to): void {
  header('Location: ' . $to);
  exit;
}

function currentUser(PDO $db): ?array {
  $id = (int)($_SESSION['uid'] ?? 0);
  if ($id <= 0) return null;
  $u = q($db, 'SELECT id,last_name,first_name,email,password,role,registered_at,last_login_at FROM users WHERE id=?', [$id])->fetch();
  return $u ?: null;
}

function requireLogin(PDO $db): array {
  $u = currentUser($db);
  if (!$u) {
    flash('warning', 'Нужно войти в систему.');
    redirect('/login');
  }
  return $u;
}

function requireAdmin(PDO $db): array {
  $u = requireLogin($db);
  if (($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }
  return $u;
}

function requireEditor(PDO $db): array {
  $u = requireLogin($db);
  $role = (string)($u['role'] ?? '');
  if (!in_array($role, ['user', 'admin'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }
  return $u;
}

function parseInt($v, int $default = 0): int {
  if ($v === null || $v === '') return $default;
  if (is_numeric($v)) return (int)$v;
  return $default;
}

function clamp(int $v, int $min, int $max): int {
  return max($min, min($max, $v));
}

function normalizeSort(string $sort): array {
  $sort = trim($sort);
  $allowed = [
    'created_desc' => 'p.created_at DESC, p.id DESC',
    'created_asc'  => 'p.created_at ASC, p.id ASC',
    'updated_desc' => 'COALESCE(p.updated_at, p.created_at) DESC, p.id DESC',
    'updated_asc'  => 'COALESCE(p.updated_at, p.created_at) ASC, p.id ASC',
    'author_asc'   => "CONCAT(u.last_name,' ',u.first_name) ASC, u.email ASC, p.id DESC",
    'author_desc'  => "CONCAT(u.last_name,' ',u.first_name) DESC, u.email DESC, p.id DESC",
    'title_asc'    => 'p.title ASC, p.id DESC',
    'title_desc'   => 'p.title DESC, p.id DESC',
  ];
  if (!isset($allowed[$sort])) $sort = 'created_desc';
  return [$sort, $allowed[$sort]];
}

function userLabel(array $u): string {
  $ln = trim((string)($u['last_name'] ?? ''));
  $fn = trim((string)($u['first_name'] ?? ''));
  $name = trim($ln . ' ' . $fn);
  $email = trim((string)($u['email'] ?? ''));
  return $name !== '' ? ($name . ' (' . $email . ')') : $email;
}

function bbcodeToHtml(string $text): string {
  $s = h($text);
  $s = str_replace(["\r\n", "\r"], "\n", $s);

  $s = preg_replace('~\[b\](.*?)\[/b\]~si', '<strong>$1</strong>', $s) ?? $s;
  $s = preg_replace('~\[i\](.*?)\[/i\]~si', '<em>$1</em>', $s) ?? $s;
  $s = preg_replace('~\[u\](.*?)\[/u\]~si', '<u>$1</u>', $s) ?? $s;

  $s = preg_replace_callback('~\[color=(.*?)\](.*?)\[/color\]~si', function ($m) {
    $color = trim($m[1]);
    if (!preg_match('~^#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$~', $color) && !preg_match('~^[a-zA-Z]{1,20}$~', $color)) {
      $color = '#000';
    }
    if ($color[0] !== '#'
      && preg_match('~^[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$~', $color)) {
      $color = '#' . $color;
    }
    return '<span style="color:' . h($color) . ';">' . $m[2] . '</span>';
  }, $s) ?? $s;

  $s = preg_replace_callback('~\[img\](.*?)\[/img\]~si', function ($m) {
    $url = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if (!preg_match('~^https?://~i', $url)) return '<span class="red-text">[Неверный URL изображения]</span>';
    $safe = h($url);
    return '<img src="' . $safe . '" alt="image" style="max-width:100%;height:auto;" referrerpolicy="no-referrer" loading="lazy">';
  }, $s) ?? $s;

  $s = nl2br($s, false);
  return $s;
}

function bbcodeToPlain(string $text): string {
  $s = str_replace(["\r\n", "\r"], "\n", $text);
  $s = preg_replace('~\[img\].*?\[/img\]~si', '', $s) ?? $s;
  $s = preg_replace('~\[(?:/)?(?:b|i|u)\]~i', '', $s) ?? $s;
  $s = preg_replace('~\[(?:/)?color(?:=[^\]]+)?\]~i', '', $s) ?? $s;
  $s = preg_replace('~\[[a-z]+\b[^\]]*\]~i', '', $s) ?? $s;
  $s = preg_replace("~\\s+~u", ' ', $s) ?? $s;
  $s = trim($s);
  return $s;
}

function highlight(string $plainText, string $needle): string {
  $hay = h($plainText);
  $needle = trim($needle);
  if ($needle === '') return nl2br($hay, false);
  $n = preg_quote($needle, '~');
  $out = preg_replace('~(' . $n . ')~iu', '<mark class="yellow">$1</mark>', $hay);
  return nl2br($out ?? $hay, false);
}

function route(): string {
  $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
  $path = parse_url($uri, PHP_URL_PATH);
  $path = is_string($path) ? $path : '/';
  $path = rtrim($path, '/');
  if ($path === '') $path = '/';
  return $path;
}

function baseUrl(string $path): string {
  if ($path === '/') return '/';
  return $path;
}

function renderLayout(array $CFG, ?array $me, string $title, string $contentHtml): void {
  $flashes = takeFlashes();
  $siteTitle = $CFG['site']['title'];
  $tagline = $CFG['site']['tagline'];
  $meEmail = $me ? (string)$me['email'] : null;
  $meRole = $me ? (string)$me['role'] : null;

  header('Content-Type: text/html; charset=UTF-8');
  ?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= h($title) ?> — <?= h($siteTitle) ?></title>
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
  <link rel="stylesheet" href="/styles/main.css">
</head>
<body>
  <nav class="app-nav">
    <div class="nav-wrapper container">
      <a href="/" class="brand-logo"><?= h($siteTitle) ?></a>
      <a href="#" data-target="mobile-menu" class="sidenav-trigger"><i class="material-icons">menu</i></a>
      <ul class="right hide-on-med-and-down">
        <li><a href="/contact">Контакты</a></li>
        <?php if ($me): ?>
          <li><a href="/post/new">Создать статью</a></li>
          <?php if ($meRole === 'admin'): ?>
            <li><a href="/admin">Админ</a></li>
          <?php endif; ?>
          <li><a href="/logout">Выход (<?= h($meEmail) ?>)</a></li>
        <?php else: ?>
          <li><a href="/register">Регистрация</a></li>
          <li><a href="/login">Вход</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </nav>

  <ul class="sidenav" id="mobile-menu">
    <li><a href="/">Главная</a></li>
    <li><a href="/contact">Контакты</a></li>
    <?php if ($me): ?>
      <li><a href="/post/new">Создать статью</a></li>
      <?php if ($meRole === 'admin'): ?>
        <li><a href="/admin">Админ</a></li>
      <?php endif; ?>
      <li><a href="/logout">Выход (<?= h($meEmail) ?>)</a></li>
    <?php else: ?>
      <li><a href="/register">Регистрация</a></li>
      <li><a href="/login">Вход</a></li>
    <?php endif; ?>
  </ul>

  <div class="container" style="margin-top: 18px;">
    <div class="card app-hero">
      <div class="card-content">
        <span class="card-title">Описание</span>
        <p><?= h($tagline) ?></p>
      </div>
    </div>

    <div class="card">
      <div class="card-content">
        <form method="get" action="/search">
          <div class="row" style="margin-bottom:0;">
            <div class="input-field col s12 m9">
              <i class="material-icons prefix">search</i>
              <input id="q" name="q" type="text" value="<?= h((string)($_GET['q'] ?? '')) ?>">
              <label for="q">Поиск по статьям</label>
            </div>
            <div class="input-field col s12 m3">
              <button class="btn app-btn waves-effect waves-light" type="submit" style="width:100%;">
                Искать <i class="material-icons right">arrow_forward</i>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <?php foreach ($flashes as $f): ?>
      <?php
        $cls = 'blue lighten-5';
        if (($f['type'] ?? '') === 'success') $cls = 'green lighten-5';
        if (($f['type'] ?? '') === 'warning') $cls = 'yellow lighten-5';
        if (($f['type'] ?? '') === 'error') $cls = 'red lighten-5';
      ?>
      <div class="card <?= h($cls) ?>">
        <div class="card-content"><?= h((string)($f['msg'] ?? '')) ?></div>
      </div>
    <?php endforeach; ?>

    <?= $contentHtml ?>

    <div class="section footer-note center-align">
      <p>Белоусов Илья Васильевич, группа 257-321. Москва, май 2026.</p>
    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      var elems = document.querySelectorAll('.sidenav');
      M.Sidenav.init(elems);
    });
  </script>
</body>
</html>
  <?php
}

function renderPagination(string $basePath, array $query, int $page, int $pages): string {
  if ($pages <= 1) return '';
  $page = clamp($page, 1, $pages);

  $mk = function(int $p) use ($basePath, $query): string {
    $q = $query;
    $q['page'] = $p;
    $qs = http_build_query($q);
    return $basePath . ($qs ? ('?' . $qs) : '');
  };

  $html = '<ul class="pagination center-align">';
  $prev = max(1, $page - 1);
  $next = min($pages, $page + 1);

  $html .= '<li class="' . ($page <= 1 ? 'disabled' : 'waves-effect') . '"><a href="' . h($mk($prev)) . '"><i class="material-icons">chevron_left</i></a></li>';

  $start = max(1, $page - 3);
  $end = min($pages, $page + 3);
  for ($p = $start; $p <= $end; $p++) {
    $active = $p === $page ? 'active' : 'waves-effect';
    $html .= '<li class="' . $active . '"><a href="' . h($mk($p)) . '">' . $p . '</a></li>';
  }

  $html .= '<li class="' . ($page >= $pages ? 'disabled' : 'waves-effect') . '"><a href="' . h($mk($next)) . '"><i class="material-icons">chevron_right</i></a></li>';
  $html .= '</ul>';
  return $html;
}

// -------------------- ROUTES --------------------
$db = db($CFG);
$me = currentUser($db);
$path = route();

// ----- auth -----
if ($path === '/register') {
  if (isPost()) {
    csrfCheck();
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = trim((string)($_POST['password'] ?? ''));
    if ($lastName === '' || $firstName === '' || $email === '' || $pass === '') {
      flash('warning', 'Заполните фамилию, имя, email и пароль.');
    } else {
      try {
        q($db, 'INSERT INTO users (last_name,first_name,email,password,role,registered_at) VALUES (?,?,?,?,?,NOW())', [$lastName, $firstName, $email, $pass, 'user']);
        flash('success', 'Регистрация выполнена. Теперь войдите.');
        redirect('/login');
      } catch (Throwable $e) {
        flash('error', 'Не удалось зарегистрироваться (возможно, email уже занят).');
      }
    }
  }

  ob_start(); ?>
  <div class="card">
    <div class="card-content">
      <span class="card-title">Регистрация</span>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <div class="row">
          <div class="input-field col s12 m3">
            <input id="last_name" name="last_name" type="text" value="<?= h((string)($_POST['last_name'] ?? '')) ?>">
            <label for="last_name">Фамилия</label>
          </div>
          <div class="input-field col s12 m3">
            <input id="first_name" name="first_name" type="text" value="<?= h((string)($_POST['first_name'] ?? '')) ?>">
            <label for="first_name">Имя</label>
          </div>
          <div class="input-field col s12 m6">
            <input id="email" name="email" type="text" value="<?= h((string)($_POST['email'] ?? '')) ?>">
            <label for="email">Email</label>
          </div>
          <div class="input-field col s12 m6">
            <input id="password" name="password" type="password">
            <label for="password">Пароль</label>
          </div>
        </div>
        <button class="btn app-btn waves-effect waves-light" type="submit">Создать аккаунт</button>
      </form>
    </div>
  </div>
  <?php
  $html = ob_get_clean();
  renderLayout($CFG, $me, 'Регистрация', $html);
  exit;
}

if ($path === '/login') {
  if (isPost()) {
    csrfCheck();
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = trim((string)($_POST['password'] ?? ''));
    $u = q($db, 'SELECT * FROM users WHERE email=? LIMIT 1', [$email])->fetch();
    if ($u && (string)$u['password'] === $pass) {
      $_SESSION['uid'] = (int)$u['id'];
      q($db, 'UPDATE users SET last_login_at=NOW() WHERE id=?', [(int)$u['id']]);
      $fn = trim((string)($u['first_name'] ?? ''));
      flash('success', 'Добро пожаловать' . ($fn !== '' ? (', ' . $fn) : '') . '!');
      redirect('/');
    } else {
      flash('error', 'Неверный логин или пароль.');
    }
  }

  ob_start(); ?>
  <div class="card">
    <div class="card-content">
      <span class="card-title">Вход</span>
      <p class="grey-text">Администратор: <span class="mono">admin / 20262026</span></p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <div class="row">
          <div class="input-field col s12 m6">
            <input id="email" name="email" type="text" value="<?= h((string)($_POST['email'] ?? '')) ?>">
            <label for="email">Email (или логин)</label>
          </div>
          <div class="input-field col s12 m6">
            <input id="password" name="password" type="password">
            <label for="password">Пароль</label>
          </div>
        </div>
        <button class="btn app-btn waves-effect waves-light" type="submit">Войти</button>
      </form>
    </div>
  </div>
  <?php
  $html = ob_get_clean();
  renderLayout($CFG, $me, 'Вход', $html);
  exit;
}

if ($path === '/logout') {
  $u = $me;
  session_destroy();
  session_start();
  $fn = trim((string)($u['first_name'] ?? ''));
  flash('success', 'Вы вышли из системы. До свидания' . ($fn !== '' ? (', ' . $fn) : '') . '!');
  redirect('/');
}

// ----- contact -----
if ($path === '/contact') {
  if (isPost()) {
    csrfCheck();
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $msg = trim((string)($_POST['message'] ?? ''));

    if ($name === '' || $email === '' || $msg === '') {
      flash('warning', 'Заполните все поля.');
    } else {
      q($db, 'INSERT INTO contact_messages (name,email,message,created_at) VALUES (?,?,?,NOW())', [$name, $email, $msg]);
      flash('success', 'Сообщение отправлено и сохранено.');
      redirect('/contact');
    }
  }

  ob_start(); ?>
  <div class="card">
    <div class="card-content">
      <span class="card-title">Контактная форма</span>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <div class="row">
          <div class="input-field col s12 m6">
            <input id="name" name="name" type="text" value="<?= h((string)($_POST['name'] ?? '')) ?>">
            <label for="name">Имя</label>
          </div>
          <div class="input-field col s12 m6">
            <input id="email" name="email" type="text" value="<?= h((string)($_POST['email'] ?? '')) ?>">
            <label for="email">Email</label>
          </div>
          <div class="input-field col s12">
            <textarea id="message" name="message" class="materialize-textarea"><?= h((string)($_POST['message'] ?? '')) ?></textarea>
            <label for="message">Сообщение</label>
          </div>
        </div>
        <button class="btn app-btn waves-effect waves-light" type="submit">Отправить</button>
      </form>
    </div>
  </div>
  <?php
  $html = ob_get_clean();
  renderLayout($CFG, $me, 'Контакты', $html);
  exit;
}

// ----- admin -----
if ($path === '/admin') {
  requireAdmin($db);
  ob_start(); ?>
  <div class="card">
    <div class="card-content">
      <span class="card-title">Административная панель</span>
      <div class="collection">
        <a class="collection-item" href="/admin/users"><i class="material-icons left">people</i>Пользователи</a>
        <a class="collection-item" href="/admin/messages"><i class="material-icons left">mail</i>Сообщения контактной формы</a>
      </div>
    </div>
  </div>
  <?php
  $html = ob_get_clean();
  renderLayout($CFG, $me, 'Админ', $html);
  exit;
}

if ($path === '/admin/users') {
  $admin = requireAdmin($db);

  if (isPost()) {
    csrfCheck();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
      $lastName = trim((string)($_POST['last_name'] ?? ''));
      $firstName = trim((string)($_POST['first_name'] ?? ''));
      $email = trim((string)($_POST['email'] ?? ''));
      $pass = trim((string)($_POST['password'] ?? ''));
      $role = (string)($_POST['role'] ?? 'user');
      if ($lastName === '' || $firstName === '' || $email === '' || $pass === '' || !in_array($role, ['user', 'admin'], true)) {
        flash('warning', 'Проверьте поля пользователя.');
      } else {
        try {
          q($db, 'INSERT INTO users (last_name,first_name,email,password,role,registered_at) VALUES (?,?,?,?,?,NOW())', [$lastName, $firstName, $email, $pass, $role]);
          flash('success', 'Пользователь создан.');
        } catch (Throwable $e) {
          flash('error', 'Не удалось создать пользователя (возможно, email уже занят).');
        }
      }
      redirect('/admin/users');
    }

    if ($action === 'update') {
      $id = parseInt($_POST['id'] ?? 0);
      $lastName = trim((string)($_POST['last_name'] ?? ''));
      $firstName = trim((string)($_POST['first_name'] ?? ''));
      $email = trim((string)($_POST['email'] ?? ''));
      $pass = trim((string)($_POST['password'] ?? ''));
      $role = (string)($_POST['role'] ?? 'user');
      if ($id <= 0 || $lastName === '' || $firstName === '' || $email === '' || !in_array($role, ['user', 'admin'], true)) {
        flash('warning', 'Проверьте данные.');
      } else {
        if ($pass !== '') {
          q($db, 'UPDATE users SET last_name=?, first_name=?, email=?, password=?, role=? WHERE id=?', [$lastName, $firstName, $email, $pass, $role, $id]);
        } else {
          q($db, 'UPDATE users SET last_name=?, first_name=?, email=?, role=? WHERE id=?', [$lastName, $firstName, $email, $role, $id]);
        }
        flash('success', 'Пользователь обновлён.');
      }
      redirect('/admin/users');
    }

    if ($action === 'delete') {
      $id = parseInt($_POST['id'] ?? 0);
      if ($id <= 0) {
        flash('warning', 'Некорректный id.');
      } elseif ($id === (int)$admin['id']) {
        flash('error', 'Нельзя удалить собственный аккаунт администратора.');
      } else {
        $countAdmins = (int)q($db, "SELECT COUNT(*) c FROM users WHERE role='admin'")->fetch()['c'];
        $target = q($db, 'SELECT * FROM users WHERE id=?', [$id])->fetch();
        if ($target && (string)$target['role'] === 'admin' && $countAdmins <= 1) {
          flash('error', 'Нельзя удалить последнего администратора.');
        } else {
          try {
            q($db, 'DELETE FROM users WHERE id=?', [$id]);
            flash('success', 'Пользователь удалён.');
          } catch (Throwable $e) {
            flash('error', 'Не удалось удалить пользователя (возможно, есть статьи/комментарии).');
          }
        }
      }
      redirect('/admin/users');
    }
  }

  $users = q($db, 'SELECT id,last_name,first_name,email,password,role,registered_at,last_login_at FROM users ORDER BY id DESC')->fetchAll();

  ob_start(); ?>
  <div class="card">
    <div class="card-content">
      <span class="card-title">Пользователи</span>

      <h6>Создать пользователя</h6>
      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="create">
        <div class="input-field col s12 m3">
          <input name="last_name" id="new_last_name" type="text">
          <label for="new_last_name">Фамилия</label>
        </div>
        <div class="input-field col s12 m3">
          <input name="first_name" id="new_first_name" type="text">
          <label for="new_first_name">Имя</label>
        </div>
        <div class="input-field col s12 m4">
          <input name="email" id="new_email" type="text">
          <label for="new_email">Email (или логин)</label>
        </div>
        <div class="input-field col s12 m3">
          <input name="password" id="new_pass" type="text">
          <label for="new_pass">Пароль</label>
        </div>
        <div class="input-field col s12 m3">
          <select name="role">
            <option value="user" selected>user</option>
            <option value="admin">admin</option>
          </select>
          <label>Роль</label>
        </div>
        <div class="input-field col s12 m2">
          <button class="btn app-btn" type="submit" style="width:100%;">Создать</button>
        </div>
      </form>

      <h6>Список</h6>
      <div class="responsive-table">
        <table class="striped">
          <thead>
            <tr>
              <th>ID</th>
              <th>Фамилия</th>
              <th>Имя</th>
              <th>Email</th>
              <th>Пароль</th>
              <th>Роль</th>
              <th>Регистрация</th>
              <th>Последний вход</th>
              <th>Действия</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= (int)$u['id'] ?></td>
              <td><?= h((string)$u['last_name']) ?></td>
              <td><?= h((string)$u['first_name']) ?></td>
              <td><?= h((string)$u['email']) ?></td>
              <td><span class="mono"><?= h((string)$u['password']) ?></span></td>
              <td><?= h((string)$u['role']) ?></td>
              <td><?= h((string)$u['registered_at']) ?></td>
              <td><?= h((string)($u['last_login_at'] ?? '—')) ?></td>
              <td>
                <a class="btn-small app-btn" href="/admin/user/edit?id=<?= (int)$u['id'] ?>">Редактировать</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <p class="grey-text">Удаление пользователя делается со страницы редактирования (чтобы не ошибиться).</p>
    </div>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      var elems = document.querySelectorAll('select');
      M.FormSelect.init(elems);
    });
  </script>
  <?php
  $html = ob_get_clean();
  renderLayout($CFG, $me, 'Админ — пользователи', $html);
  exit;
}

if ($path === '/admin/user/edit') {
  $admin = requireAdmin($db);
  $id = parseInt($_GET['id'] ?? 0);
  $u = $id > 0 ? q($db, 'SELECT * FROM users WHERE id=?', [$id])->fetch() : null;
  if (!$u) {
    flash('error', 'Пользователь не найден.');
    redirect('/admin/users');
  }

  if (isPost()) {
    csrfCheck();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'update') {
      $lastName = trim((string)($_POST['last_name'] ?? ''));
      $firstName = trim((string)($_POST['first_name'] ?? ''));
      $email = trim((string)($_POST['email'] ?? ''));
      $pass = trim((string)($_POST['password'] ?? ''));
      $role = (string)($_POST['role'] ?? 'user');
      if ($lastName === '' || $firstName === '' || $email === '' || !in_array($role, ['user','admin'], true)) {
        flash('warning', 'Проверьте поля.');
      } else {
        if ($pass !== '') q($db, 'UPDATE users SET last_name=?, first_name=?, email=?, password=?, role=? WHERE id=?', [$lastName, $firstName, $email, $pass, $role, $id]);
        else q($db, 'UPDATE users SET last_name=?, first_name=?, email=?, role=? WHERE id=?', [$lastName, $firstName, $email, $role, $id]);
        flash('success', 'Сохранено.');
      }
      redirect('/admin/user/edit?id=' . $id);
    }
    if ($action === 'delete') {
      if ($id === (int)$admin['id']) {
        flash('error', 'Нельзя удалить собственный аккаунт администратора.');
        redirect('/admin/user/edit?id=' . $id);
      }
      $countAdmins = (int)q($db, "SELECT COUNT(*) c FROM users WHERE role='admin'")->fetch()['c'];
      if ((string)$u['role'] === 'admin' && $countAdmins <= 1) {
        flash('error', 'Нельзя удалить последнего администратора.');
        redirect('/admin/user/edit?id=' . $id);
      }
      try {
        q($db, 'DELETE FROM users WHERE id=?', [$id]);
        flash('success', 'Пользователь удалён.');
        redirect('/admin/users');
      } catch (Throwable $e) {
        flash('error', 'Не удалось удалить пользователя (возможно, есть статьи/комментарии).');
        redirect('/admin/user/edit?id=' . $id);
      }
    }
  }

  ob_start(); ?>
  <div class="card">
    <div class="card-content">
      <span class="card-title">Редактирование пользователя #<?= (int)$u['id'] ?></span>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="update">
        <div class="row">
          <div class="input-field col s12 m3">
            <input id="last_name" name="last_name" type="text" value="<?= h((string)$u['last_name']) ?>">
            <label class="active" for="last_name">Фамилия</label>
          </div>
          <div class="input-field col s12 m3">
            <input id="first_name" name="first_name" type="text" value="<?= h((string)$u['first_name']) ?>">
            <label class="active" for="first_name">Имя</label>
          </div>
          <div class="input-field col s12 m6">
            <input id="email" name="email" type="text" value="<?= h((string)$u['email']) ?>">
            <label class="active" for="email">Email</label>
          </div>
          <div class="input-field col s12 m4">
            <input id="password" name="password" type="text" placeholder="Оставьте пустым, чтобы не менять">
            <label class="active" for="password">Новый пароль</label>
          </div>
          <div class="input-field col s12 m3">
            <select name="role">
              <option value="user" <?= (string)$u['role'] === 'user' ? 'selected' : '' ?>>user</option>
              <option value="admin" <?= (string)$u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
            </select>
            <label>Роль</label>
          </div>
        </div>
        <button class="btn app-btn" type="submit">Сохранить</button>
        <a class="btn grey" href="/admin/users">Назад</a>
      </form>

      <div class="divider" style="margin:18px 0;"></div>
      <form method="post" onsubmit="return confirm('Удалить пользователя?');">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="delete">
        <button class="btn red" type="submit">Удалить пользователя</button>
      </form>
    </div>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      var elems = document.querySelectorAll('select');
      M.FormSelect.init(elems);
    });
  </script>
  <?php
  $html = ob_get_clean();
  renderLayout($CFG, $me, 'Админ — редактирование пользователя', $html);
  exit;
}

if ($path === '/admin/messages') {
  requireAdmin($db);

  if (isPost()) {
    csrfCheck();
    $action = (string)($_POST['action'] ?? '');
    $id = parseInt($_POST['id'] ?? 0);
    if ($id > 0 && $action === 'read') {
      q($db, 'UPDATE contact_messages SET read_at=COALESCE(read_at, NOW()) WHERE id=?', [$id]);
      flash('success', 'Помечено прочитанным.');
      redirect('/admin/messages');
    }
    if ($id > 0 && $action === 'delete') {
      q($db, 'DELETE FROM contact_messages WHERE id=?', [$id]);
      flash('success', 'Сообщение удалено.');
      redirect('/admin/messages');
    }
  }

  $msgs = q($db, 'SELECT * FROM contact_messages ORDER BY created_at DESC, id DESC')->fetchAll();

  ob_start(); ?>
  <div class="card">
    <div class="card-content">
      <span class="card-title">Сообщения контактной формы</span>
      <div class="responsive-table">
        <table class="striped">
          <thead>
            <tr>
              <th>ID</th>
              <th>Имя</th>
              <th>Email</th>
              <th>Сообщение</th>
              <th>Отправлено</th>
              <th>Прочитано</th>
              <th>Действия</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($msgs as $m): ?>
            <tr class="<?= $m['read_at'] ? '' : 'app-soft' ?>">
              <td><?= (int)$m['id'] ?></td>
              <td><?= h((string)$m['name']) ?></td>
              <td><?= h((string)$m['email']) ?></td>
              <td><?= h((string)$m['message']) ?></td>
              <td><?= h((string)$m['created_at']) ?></td>
              <td><?= h((string)($m['read_at'] ?? '—')) ?></td>
              <td>
                <?php if (!$m['read_at']): ?>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <input type="hidden" name="action" value="read">
                    <button class="btn-small app-btn" type="submit">Прочитано</button>
                  </form>
                <?php endif; ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Удалить сообщение?');">
                  <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="btn-small red" type="submit">Удалить</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="grey-text">Непрочитанные подсвечены фоном.</p>
    </div>
  </div>
  <?php
  $html = ob_get_clean();
  renderLayout($CFG, $me, 'Админ — сообщения', $html);
  exit;
}

// ----- posts -----
if ($path === '/post/new') {
  requireEditor($db);

  if (isPost()) {
    csrfCheck();
    $title = trim((string)($_POST['title'] ?? ''));
    $body  = trim((string)($_POST['body'] ?? ''));
    if ($title === '' || $body === '') {
      flash('warning', 'Заполните заголовок и текст.');
    } else {
      q($db, 'INSERT INTO posts (author_id,title,body,created_at,updated_at) VALUES (?,?,?,NOW(),NOW())', [(int)$me['id'], $title, $body]);
      $id = (int)$db->lastInsertId();
      flash('success', 'Статья создана.');
      redirect('/post?id=' . $id);
    }
  }

  ob_start(); ?>
  <div class="card">
    <div class="card-content">
      <span class="card-title">Создать статью</span>
      <p class="grey-text">
        BBCode: <span class="mono">[b][/b]</span>, <span class="mono">[i][/i]</span>, <span class="mono">[u][/u]</span>,
        <span class="mono">[color=red][/color]</span>, <span class="mono">[img]https://...[/img]</span> (только URL, загрузки файлов нет).
      </p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <div class="row">
          <div class="input-field col s12">
            <input id="title" name="title" type="text" value="<?= h((string)($_POST['title'] ?? '')) ?>">
            <label for="title">Название статьи</label>
          </div>
          <div class="input-field col s12">
            <textarea id="body" name="body" class="materialize-textarea" style="min-height:180px;"><?= h((string)($_POST['body'] ?? '')) ?></textarea>
            <label for="body">Текст (BBCode)</label>
          </div>
        </div>
        <button class="btn app-btn" type="submit">Опубликовать</button>
      </form>
    </div>
  </div>
  <?php
  $html = ob_get_clean();
  renderLayout($CFG, $me, 'Создать статью', $html);
  exit;
}

if ($path === '/post/edit') {
  $u = requireEditor($db);
  $id = parseInt($_GET['id'] ?? 0);
  $post = $id > 0 ? q($db, 'SELECT * FROM posts WHERE id=?', [$id])->fetch() : null;
  if (!$post) {
    flash('error', 'Статья не найдена.');
    redirect('/');
  }
  if ((int)$post['author_id'] !== (int)$u['id'] && (string)$u['role'] !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }

  if (isPost()) {
    csrfCheck();
    $title = trim((string)($_POST['title'] ?? ''));
    $body  = trim((string)($_POST['body'] ?? ''));
    if ($title === '' || $body === '') {
      flash('warning', 'Заполните заголовок и текст.');
    } else {
      q($db, 'UPDATE posts SET title=?, body=?, updated_at=NOW() WHERE id=?', [$title, $body, $id]);
      flash('success', 'Статья обновлена.');
      redirect('/post?id=' . $id);
    }
  }

  ob_start(); ?>
  <div class="card">
    <div class="card-content">
      <span class="card-title">Редактировать статью #<?= (int)$post['id'] ?></span>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <div class="row">
          <div class="input-field col s12">
            <input id="title" name="title" type="text" value="<?= h((string)($_POST['title'] ?? $post['title'])) ?>">
            <label class="active" for="title">Название статьи</label>
          </div>
          <div class="input-field col s12">
            <textarea id="body" name="body" class="materialize-textarea" style="min-height:180px;"><?= h((string)($_POST['body'] ?? $post['body'])) ?></textarea>
            <label class="active" for="body">Текст (BBCode)</label>
          </div>
        </div>
        <button class="btn app-btn" type="submit">Сохранить</button>
        <a class="btn grey" href="/post?id=<?= (int)$post['id'] ?>">Назад</a>
      </form>
    </div>
  </div>
  <?php
  $html = ob_get_clean();
  renderLayout($CFG, $me, 'Редактировать статью', $html);
  exit;
}

if ($path === '/post/delete') {
  $u = requireEditor($db);
  $id = parseInt($_GET['id'] ?? 0);
  $post = $id > 0 ? q($db, 'SELECT * FROM posts WHERE id=?', [$id])->fetch() : null;
  if (!$post) {
    flash('error', 'Статья не найдена.');
    redirect('/');
  }
  if ((int)$post['author_id'] !== (int)$u['id'] && (string)$u['role'] !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }

  if (isPost()) {
    csrfCheck();
    q($db, 'DELETE FROM posts WHERE id=?', [$id]);
    flash('success', 'Статья удалена.');
    redirect('/');
  }

  ob_start(); ?>
  <div class="card red lighten-5">
    <div class="card-content">
      <span class="card-title">Удалить статью?</span>
      <p><b><?= h((string)$post['title']) ?></b></p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <button class="btn red" type="submit">Удалить</button>
        <a class="btn grey" href="/post?id=<?= (int)$id ?>">Отмена</a>
      </form>
    </div>
  </div>
  <?php
  $html = ob_get_clean();
  renderLayout($CFG, $me, 'Удалить статью', $html);
  exit;
}

if ($path === '/post') {
  $id = parseInt($_GET['id'] ?? 0);
  $post = $id > 0 ? q($db, '
    SELECT p.*,
           u.email author_email,
           CONCAT(u.last_name, " ", u.first_name) author_name
    FROM posts p
    JOIN users u ON u.id=p.author_id
    WHERE p.id=?
  ', [$id])->fetch() : null;

  if (!$post) {
    http_response_code(404);
    renderLayout($CFG, $me, 'Не найдено', '<div class="card"><div class="card-content">Статья не найдена.</div></div>');
    exit;
  }

  if (isPost() && (string)($_POST['action'] ?? '') === 'comment') {
    csrfCheck();
    $u = requireLogin($db);
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body === '') {
      flash('warning', 'Комментарий пустой.');
    } else {
      q($db, 'INSERT INTO comments (post_id,user_id,body,created_at) VALUES (?,?,?,NOW())', [(int)$post['id'], (int)$u['id'], $body]);
      flash('success', 'Комментарий добавлен.');
    }
    redirect('/post?id=' . (int)$post['id']);
  }

  $comments = q($db, '
    SELECT c.*,
           u.email user_email,
           CONCAT(u.last_name, " ", u.first_name) user_name
    FROM comments c
    JOIN users u ON u.id=c.user_id
    WHERE c.post_id=?
    ORDER BY c.created_at DESC, c.id DESC
  ', [(int)$post['id']])->fetchAll();

  $canEdit = $me && (((int)$me['id'] === (int)$post['author_id']) || ((string)$me['role'] === 'admin'));

  ob_start(); ?>
  <div class="card">
    <div class="card-content">
      <span class="card-title"><?= h((string)$post['title']) ?></span>
      <div class="post-meta grey-text">
        Автор: <b><?= h((string)$post['author_name']) ?></b> ·
        Создано: <?= h((string)$post['created_at']) ?> ·
        Обновлено: <?= h((string)($post['updated_at'] ?? '—')) ?>
      </div>
      <?php if ($canEdit): ?>
        <div class="section" style="padding-top:8px;">
          <a class="btn-small app-btn" href="/post/edit?id=<?= (int)$post['id'] ?>">Редактировать</a>
          <a class="btn-small red" href="/post/delete?id=<?= (int)$post['id'] ?>">Удалить</a>
        </div>
      <?php endif; ?>
      <div class="divider" style="margin:12px 0;"></div>
      <div class="post-body"><?= bbcodeToHtml((string)$post['body']) ?></div>
    </div>
  </div>

  <div class="card">
    <div class="card-content">
      <span class="card-title">Комментарии</span>
      <?php if ($me): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="action" value="comment">
          <div class="input-field">
            <textarea name="body" id="comment_body" class="materialize-textarea"></textarea>
            <label for="comment_body">Оставить комментарий</label>
          </div>
          <button class="btn app-btn" type="submit">Отправить</button>
        </form>
        <div class="divider" style="margin:18px 0;"></div>
      <?php else: ?>
        <p>Чтобы оставить комментарий, нужно <a href="/login">войти</a>.</p>
        <div class="divider" style="margin:18px 0;"></div>
      <?php endif; ?>

      <?php if (!$comments): ?>
        <p class="grey-text">Комментариев пока нет.</p>
      <?php endif; ?>

      <?php foreach ($comments as $c): ?>
        <div class="card app-soft">
          <div class="card-content">
            <div class="grey-text">
              <b><?= h((string)$c['user_name']) ?></b> · <?= h((string)$c['created_at']) ?>
            </div>
            <div><?= nl2br(h((string)$c['body']), false) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php
  $html = ob_get_clean();
  renderLayout($CFG, $me, (string)$post['title'], $html);
  exit;
}

// ----- search -----
if ($path === '/search') {
  $qstr = trim((string)($_GET['q'] ?? ''));
  $page = clamp(parseInt($_GET['page'] ?? 1, 1), 1, 1000000);
  $per = (int)$CFG['pagination']['per_page'];
  $off = ($page - 1) * $per;

  $where = '';
  $params = [];
  if ($qstr !== '') {
    $where = 'WHERE (p.title LIKE ? OR p.body LIKE ?)';
    $like = '%' . $qstr . '%';
    $params = [$like, $like];
  }

  $total = (int)q($db, "SELECT COUNT(*) c FROM posts p $where", $params)->fetch()['c'];
  $pages = max(1, (int)ceil($total / $per));
  $page = clamp($page, 1, $pages);
  $off = ($page - 1) * $per;

  $rows = q($db, "
    SELECT p.*,
           u.email author_email,
           CONCAT(u.last_name, ' ', u.first_name) author_name
    FROM posts p
    JOIN users u ON u.id=p.author_id
    $where
    ORDER BY p.created_at DESC, p.id DESC
    LIMIT $per OFFSET $off
  ", $params)->fetchAll();

  ob_start(); ?>
  <div class="card">
    <div class="card-content">
      <span class="card-title">Результаты поиска</span>
      <p>Запрос: <span class="mono"><?= h($qstr) ?></span>. Найдено: <b><?= $total ?></b></p>
      <?php if (!$rows): ?>
        <p class="grey-text">Ничего не найдено.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php foreach ($rows as $p): ?>
    <div class="card">
      <div class="card-content">
        <span class="card-title">
          <a href="/post?id=<?= (int)$p['id'] ?>"><?= highlight((string)$p['title'], $qstr) ?></a>
        </span>
        <div class="grey-text">
          Автор: <b><?= h((string)$p['author_name']) ?></b> ·
          Создано: <?= h((string)$p['created_at']) ?> ·
          Обновлено: <?= h((string)($p['updated_at'] ?? '—')) ?>
        </div>
        <div class="divider" style="margin:10px 0;"></div>
        <div>
          <?php
            $plain = bbcodeToPlain((string)$p['body']);
            $preview = mb_substr($plain, 0, 260);
            echo highlight($preview . (mb_strlen($plain) > 260 ? '…' : ''), $qstr);
          ?>
        </div>
      </div>
      <div class="card-action">
        <a href="/post?id=<?= (int)$p['id'] ?>">Открыть</a>
      </div>
    </div>
  <?php endforeach; ?>

  <?= renderPagination('/search', ['q' => $qstr], $page, $pages) ?>
  <?php
  $html = ob_get_clean();
  renderLayout($CFG, $me, 'Поиск', $html);
  exit;
}

// ----- home -----
if ($path === '/') {
  [$sortKey, $orderSql] = normalizeSort((string)($_GET['sort'] ?? 'created_desc'));
  $page = clamp(parseInt($_GET['page'] ?? 1, 1), 1, 1000000);
  $per = (int)$CFG['pagination']['per_page'];
  $off = ($page - 1) * $per;

  $total = (int)q($db, 'SELECT COUNT(*) c FROM posts')->fetch()['c'];
  $pages = max(1, (int)ceil($total / $per));
  $page = clamp($page, 1, $pages);
  $off = ($page - 1) * $per;

  $posts = q($db, "
    SELECT p.*,
           u.email author_email,
           CONCAT(u.last_name, ' ', u.first_name) author_name
    FROM posts p
    JOIN users u ON u.id=p.author_id
    ORDER BY $orderSql
    LIMIT $per OFFSET $off
  ")->fetchAll();

  ob_start(); ?>
  <div class="card">
    <div class="card-content">
      <span class="card-title">Последние статьи</span>
      <div class="row" style="margin-bottom:0;">
        <div class="input-field col s12 m6">
          <select id="sortSelect" onchange="location.href=this.value;">
            <?php
              $opts = [
                'created_desc' => 'Дата создания (сначала новые)',
                'created_asc'  => 'Дата создания (сначала старые)',
                'updated_desc' => 'Дата редактирования (сначала новые)',
                'updated_asc'  => 'Дата редактирования (сначала старые)',
                'author_asc'   => 'Автор (A→Я)',
                'author_desc'  => 'Автор (Я→A)',
                'title_asc'    => 'Название (A→Я)',
                'title_desc'   => 'Название (Я→A)',
              ];
              foreach ($opts as $k => $label) {
                $url = '/?'.http_build_query(['sort' => $k, 'page' => 1]);
                $sel = $k === $sortKey ? 'selected' : '';
                echo '<option value="'.h($url).'" '.$sel.'>'.h($label).'</option>';
              }
            ?>
          </select>
          <label>Сортировка</label>
        </div>
        <div class="col s12 m6 right-align" style="margin-top: 18px;">
          <span class="grey-text">Показано: <b><?= count($posts) ?></b> из <b><?= $total ?></b></span>
        </div>
      </div>
    </div>
  </div>

  <?php if (!$posts): ?>
    <div class="card">
      <div class="card-content">
        <p class="grey-text">Статей пока нет. <?php if ($me): ?>Создайте первую: <a href="/post/new">создать статью</a>.<?php endif; ?></p>
      </div>
    </div>
  <?php endif; ?>

  <?php foreach ($posts as $p): ?>
    <div class="card">
      <div class="card-content">
        <span class="card-title"><a href="/post?id=<?= (int)$p['id'] ?>"><?= h((string)$p['title']) ?></a></span>
        <div class="grey-text">
          Автор: <b><?= h((string)$p['author_name']) ?></b> ·
          Создано: <?= h((string)$p['created_at']) ?> ·
          Обновлено: <?= h((string)($p['updated_at'] ?? '—')) ?>
        </div>
        <div class="divider" style="margin:10px 0;"></div>
        <div>
          <?php
            $plain = bbcodeToPlain((string)$p['body']);
            $preview = mb_substr($plain, 0, 260);
            echo nl2br(h($preview . (mb_strlen($plain) > 260 ? '…' : '')), false);
          ?>
        </div>
      </div>
      <div class="card-action">
        <a href="/post?id=<?= (int)$p['id'] ?>">Читать</a>
      </div>
    </div>
  <?php endforeach; ?>

  <?= renderPagination('/', ['sort' => $sortKey], $page, $pages) ?>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      var elems = document.querySelectorAll('select');
      M.FormSelect.init(elems);
    });
  </script>
  <?php
  $html = ob_get_clean();
  renderLayout($CFG, $me, 'Главная', $html);
  exit;
}

http_response_code(404);
renderLayout($CFG, $me, 'Не найдено', '<div class="card"><div class="card-content">Страница не найдена.</div></div>');

