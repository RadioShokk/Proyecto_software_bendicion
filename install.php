<?php
declare(strict_types=1);
require_once __DIR__.'/app/core.php';
$c=cfg();
ini_set('session.use_strict_mode','1');
session_name('FLBINSTALL');session_set_cookie_params(['httponly'=>true,'secure'=>(bool)($c['https']??false),'samesite'=>'Lax']);session_start();
header('X-Frame-Options: DENY');header('Cache-Control: no-store');
try {$n=(int)(fetch('SELECT COUNT(*) AS n FROM users')['n']??0);}catch(Throwable $e){ http_response_code(503);exit('<h1>No se pudo acceder a la base de datos</h1><p>Configure config.php, cree la base e importe database/schema.sql.</p>'); }
if($n>0){header('Location: index.php?route=login');exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        check_csrf();$name=text_required($_POST['name']??null,'Nombre',120);$uname=text_required($_POST['username']??null,'Usuario',50);
        if(!preg_match('/^[a-zA-Z0-9._-]{3,50}$/D',$uname))throw new DomainException('Usuario inválido: mínimo 3 caracteres; letras, números, punto, guion.');
        $hash=password_hash_secure((string)($_POST['password']??''));
        // Evita instalar por segunda vez mediante la unicidad del usuario inicial.
        db()->beginTransaction();
        fetch('SELECT id FROM settings WHERE id=1 FOR UPDATE');
        $n=(int)(fetch('SELECT COUNT(*) AS n FROM users')['n']??0);
        if($n!==0)throw new DomainException('El sistema ya fue instalado.');
        exec_sql("INSERT INTO users(name,username,password_hash,role) VALUES(?,?,?,'propietaria')",[$name,$uname,$hash]);
        db()->commit();header('Location: index.php?route=login');exit;
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$error=$e instanceof DomainException?$e->getMessage():'No se pudo crear la cuenta inicial.';}
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Configuración inicial | La Bendición</title><link rel="stylesheet" href="assets/style.css"></head><body class="auth-body"><main class="auth-wrap"><form method="post" class="auth-card"><span class="eyebrow">Primera instalación</span><h1>Farmacia La Bendición</h1><p>Cree la cuenta de la propietaria. Este asistente se bloquea después de crear el primer usuario.</p><?php if($error):?><div class="alert error" role="alert"><?=e($error)?></div><?php endif;?><?=csrf_field()?><label>Nombre completo<input name="name" required maxlength="120" autocomplete="name"></label><label>Usuario<input name="username" required minlength="3" maxlength="50" autocomplete="username"></label><label>Contraseña de al menos 10 caracteres<input name="password" type="password" required minlength="10" autocomplete="new-password"></label><button class="btn primary" type="submit">Crear cuenta propietaria</button></form></main></body></html>
