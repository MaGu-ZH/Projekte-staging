<?php
declare(strict_types=1);

ini_set('display_errors', '0'); // Hosting zeigt es eh nicht
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
session_start();

function out(array $arr, int $code = 200): void {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function json_in(): array {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw ?: "{}", true);
  return is_array($data) ? $data : [];
}

function must_login(): int {
  if (!isset($_SESSION['uid'])) out(["ok"=>false, "error"=>"not_logged_in"], 401);
  return (int)$_SESSION['uid'];
}

// ---- load config safely ----
$configFile = __DIR__ . '/../private/s.php';
if (!file_exists($configFile)) {
  out(["ok"=>false, "error"=>"config_missing", "detail"=>"s.php not found"], 500);
}

$config = require $configFile;
if (!is_array($config)) {
  out(["ok"=>false, "error"=>"config_invalid", "detail"=>"s.php must return an array"], 500);
}
foreach (["h","n","u","p"] as $k) {
  if (!isset($config[$k]) || $config[$k] === "") {
    out(["ok"=>false, "error"=>"config_invalid", "detail"=>"Missing config key: ".$k], 500);
  }
}


$config['h'] = trim((string)$config['h']);
$config['n'] = trim((string)$config['n']);
$config['u'] = trim((string)$config['u']);
$config['p'] = (string)$config['p']; // Passwort nicht trimmen, falls es absichtlich Leerzeichen enthält


// ---- connect DB with clear error ----


try {
$dsn = "mysql:host={$config['h']};dbname={$config['n']};charset=utf8mb4";

$pdo = new PDO($dsn, $config['u'], $config['p'], [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

} catch (Throwable $e) {
  out(["ok"=>false, "error"=>"db_connect_failed", "detail"=>$e->getMessage()], 500);
}

// ---- routing ----
$action = $_GET['action'] ?? '';

if ($action === '') {
  out(["ok"=>false, "error"=>"no_action", "php"=>PHP_VERSION]);
}

if ($action === 'ping') {
  out(["ok"=>true, "pong"=>true, "php"=>PHP_VERSION]);
}

if ($action === 'register') {
  $in = json_in();
  $email = strtolower(trim((string)($in['email'] ?? '')));
  $pw = (string)($in['password'] ?? '');

  if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pw) < 8) {
    out(["ok"=>false, "error"=>"invalid_input"], 400);
  }

  $hash = password_hash($pw, PASSWORD_DEFAULT);

  try {
    $stmt = $pdo->prepare("INSERT INTO users(email,password_hash) VALUES(?,?)");
    $stmt->execute([$email, $hash]);
    out(["ok"=>true]);
  } catch (Throwable $e) {
    out(["ok"=>false, "error"=>"email_exists", "detail"=>$e->getMessage()], 409);
  }
}

if ($action === 'login') {
  $in = json_in();
  $email = strtolower(trim((string)($in['email'] ?? '')));
  $pw = (string)($in['password'] ?? '');

  $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE email=?");
  $stmt->execute([$email]);
  $u = $stmt->fetch();

  if (!$u || !password_verify($pw, $u['password_hash'])) {
    out(["ok"=>false, "error"=>"bad_login"], 401);
  }

  session_regenerate_id(true);
  $_SESSION['uid'] = (int)$u['id'];
  out(["ok"=>true]);
}

if ($action === 'logout') {
  session_destroy();
  out(["ok"=>true]);
}

if ($action === 'get_day') {
  $uid = must_login();
  $date = (string)($_GET['date'] ?? '');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) out(["ok"=>false,"error"=>"bad_date"], 400);

  $stmt = $pdo->prepare("SELECT data_json, updated_at FROM entries WHERE user_id=? AND entry_date=?");
  $stmt->execute([$uid, $date]);
  $row = $stmt->fetch();

  if (!$row) out(["ok"=>true, "data"=>null]);

  $decoded = json_decode((string)$row['data_json'], true);
  out(["ok"=>true, "data"=>$decoded, "updated_at"=>$row['updated_at']]);
}

if ($action === 'save_day') {
  $uid = must_login();
  $in = json_in();
  $date = (string)($in['date'] ?? '');
  $data = $in['data'] ?? null;

  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_array($data)) {
    out(["ok"=>false,"error"=>"bad_input"], 400);
  }

  $json = json_encode($data, JSON_UNESCAPED_UNICODE);

  $stmt = $pdo->prepare("
    INSERT INTO entries(user_id, entry_date, data_json)
    VALUES(?, ?, ?)
    ON DUPLICATE KEY UPDATE data_json=VALUES(data_json), updated_at=CURRENT_TIMESTAMP
  ");
  $stmt->execute([$uid, $date, $json]);

  out(["ok"=>true]);
}

out(["ok"=>false, "error"=>"unknown_action"], 404);
