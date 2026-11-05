<?php
declare(strict_types=1);
const PASSWORD_OPTIONS = ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1];

function cfg(): array {
    static $cfg;
    if ($cfg === null) {
        $file = dirname(__DIR__) . '/config.php';
        if (!is_file($file)) {
            http_response_code(503);
            exit('<h2>Configuración pendiente</h2><p>Copie config.example.php a config.php, configure MySQL y siga README.md.</p>');
        }
        $cfg = require $file;
        date_default_timezone_set($cfg['timezone'] ?? 'America/Managua');
    }
    return $cfg;
}
function db(): PDO {
    static $db;
    if (!isset($db)) {
        $c = cfg();
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['db_host'], $c['db_port'], $c['db_name']);
        $db = new PDO($dsn, $c['db_user'], $c['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->exec("SET time_zone = '-06:00'"); // Nicaragua no usa horario de verano.
    }
    return $db;
}
function fetch(string $sql, array $params = []): ?array {
    $st = db()->prepare($sql); $st->execute($params); $row = $st->fetch();
    return $row ?: null;
}
function rows(string $sql, array $params = []): array {
    $st = db()->prepare($sql); $st->execute($params); return $st->fetchAll();
}
function exec_sql(string $sql, array $params = []): int {
    $st = db()->prepare($sql); $st->execute($params); return $st->rowCount();
}
function e(mixed $text): string { return htmlspecialchars((string)($text ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money(mixed $num): string { return 'C$ ' . number_format((float)$num, 2, '.', ','); }
function cents(string|float|int $amount): int {
    $s = trim((string)$amount);
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $s)) throw new DomainException('Introduzca un importe válido (hasta dos decimales).');
    [$a,$b] = array_pad(explode('.', $s, 2), 2, '');
    return ((int)$a)*100 + (int)str_pad($b, 2, '0');
}
function decimal(int $c): string { return sprintf('%d.%02d', intdiv($c,100), $c%100); }
function positive_int(mixed $v, string $field = 'Cantidad'): int {
    if (!is_scalar($v) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$v)) throw new DomainException("$field debe ser un entero mayor que cero.");
    return (int)$v;
}
function integer_zero(mixed $v, string $field): int {
    if (!is_scalar($v) || !preg_match('/^(0|[1-9][0-9]{0,8})$/D', (string)$v)) throw new DomainException("$field debe ser un entero no negativo.");
    return (int)$v;
}
function text_required(mixed $v,string $field,int $max=180): string {
    $s = trim((string)($v ?? ''));
    if ($s === '' || mb_len($s)>$max) throw new DomainException("$field es obligatorio (máximo $max caracteres).");
    return $s;
}
function mb_len(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s); }
function nullable_date(mixed $v): ?string {
    if (!is_string($v) || trim($v)==='') return null;
    $t=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
    if (!$t || $t->format('Y-m-d')!==$v) throw new DomainException('La fecha de vencimiento no es válida.');
    return $v;
}
function post_array(string $name): array { $v=$_POST[$name]??[]; if(!is_array($v)) throw new DomainException('Formato de formulario incorrecto.'); return $v; }
function user(): ?array { return $_SESSION['user'] ?? null; }
function owner(): bool { return (user()['role'] ?? '') === 'propietaria'; }
function can_clients(): bool { return owner() || (bool)(user()['can_manage_clients'] ?? false); }
function require_login(): void {
    if (!user()) { header('Location: index.php?route=login'); exit; }
    // Consultar el estado en cada solicitud, evitando sesiones de usuarios desactivados.
    $u=fetch('SELECT id,name,username,role,can_manage_clients,active FROM users WHERE id=?', [user()['id']]);
    if (!$u || !(int)$u['active']) { unset($_SESSION['user']); header('Location: index.php?route=login'); exit; }
    $_SESSION['user']=$u;
}
function require_owner(): void { require_login(); if(!owner()) { http_response_code(403); render_error('No tiene autorización para esta sección.',403); } }
function require_clients(): void { require_login(); if(!can_clients()) { http_response_code(403); render_error('No tiene autorización para gestionar clientes.',403); } }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function check_csrf(): void { if (!hash_equals(csrf_token(),(string)($_POST['csrf']??''))) throw new DomainException('La sesión del formulario expiró. Actualice la página y vuelva a intentarlo.'); }
function flash(string $msg, string $type='success'): void { $_SESSION['flash'] = ['message'=>$msg,'type'=>$type]; }
function redirect(string $route, array $query=[]): never { header('Location: index.php?'.http_build_query(['route'=>$route]+$query)); exit; }
function audit(string $action,string $type,?int $id=null,string $context=''): void {
    exec_sql('INSERT INTO audit_log(user_id,action,entity_type,entity_id,context) VALUES(?,?,?,?,?)', [user()['id']??null,$action,$type,$id,substr($context,0,500)]);
}
function setting(): array { return fetch('SELECT * FROM settings WHERE id=1') ?? []; }
function stock_expr(): string { return '(SELECT COALESCE(SUM(l.available_qty),0) FROM lots l WHERE l.product_id=p.id)'; }
function products_with_stock(bool $activeOnly=false): array {
    $sql='SELECT p.*, ' . stock_expr() . ' AS stock, (SELECT COALESCE(SUM(l.available_qty),0) FROM lots l WHERE l.product_id=p.id AND (l.expiry_date IS NULL OR l.expiry_date >= CURDATE())) AS sellable_stock FROM products p';
    if($activeOnly) $sql.=' WHERE p.active=1';
    return rows($sql.' ORDER BY p.name,p.id');
}
function current_path(string $route): string { return 'index.php?route='.rawurlencode($route); }
function password_hash_secure(string $password): string {
    if (strlen($password)<10 || strlen($password)>200) throw new DomainException('La contraseña debe tener entre 10 y 200 caracteres.');
    if (!defined('PASSWORD_ARGON2ID')) throw new RuntimeException('El servidor no soporta Argon2id. Active una versión compatible de PHP.');
    return password_hash($password, PASSWORD_ARGON2ID, PASSWORD_OPTIONS);
}
function render_error(string $message,int $code=400): never {
    http_response_code($code); include __DIR__.'/error.php'; exit;
}
