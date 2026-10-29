<?php
declare(strict_types=1);
require_once __DIR__.'/app/services.php';
$c=cfg();
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_name('FLBSESSION');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>(bool)($c['https']??false),'httponly'=>true,'samesite'=>'Lax']);
session_start();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; base-uri \'self\'; form-action \'self\'; frame-ancestors \'none\'');
$route=(string)($_GET['route']??'dashboard');
$public=['login'];
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        check_csrf();
        if($route==='login'){
            $fails=(int)($_SESSION['fails']??0);
            if($fails>=6 && (time()-(int)($_SESSION['lock_time']??0))<900) throw new DomainException('Demasiados intentos. Vuelva a probar dentro de 15 minutos.');
            if($fails>=6){$_SESSION['fails']=0;}
            $uname=trim((string)($_POST['username']??''));
            $pw=(string)($_POST['password']??'');
            $u=fetch('SELECT * FROM users WHERE username=? AND active=1',[$uname]);
            if(!$u||!password_verify($pw,$u['password_hash'])){
                $_SESSION['fails']=((int)($_SESSION['fails']??0))+1;
                if($_SESSION['fails']>=6)$_SESSION['lock_time']=time();
                throw new DomainException('Usuario o contraseña incorrectos.');
            }
            session_regenerate_id(true);$_SESSION['fails']=0;
            $_SESSION['user']=array_intersect_key($u,array_flip(['id','name','username','role','can_manage_clients','active']));
            if(password_needs_rehash($u['password_hash'],PASSWORD_ARGON2ID,PASSWORD_OPTIONS)){
                exec_sql('UPDATE users SET password_hash=? WHERE id=?',[password_hash_secure($pw),$u['id']]);
            }
            exec_sql('UPDATE users SET last_login=NOW() WHERE id=?',[$u['id']]);
            audit('iniciar_sesion','usuario',(int)$u['id']);redirect('dashboard');
        }
        require_login();
        if($route==='logout'){
            audit('cerrar_sesion','usuario',(int)user()['id']);
            $_SESSION=[];session_destroy();redirect('login');
        }
        switch($route) {
        case 'save-product':
            require_owner();
            $id=($_POST['id']??'')!==''?positive_int($_POST['id'],'Producto'):null;
            $code=text_required($_POST['code']??null,'Código',60);
            $name=text_required($_POST['name']??null,'Nombre');
            $description=trim((string)($_POST['description']??''));if(mb_len($description)>500)throw new DomainException('Descripción demasiado larga.');
            $category=trim((string)($_POST['category']??''));if(mb_len($category)>100)throw new DomainException('Categoría demasiado larga.');
            $price=decimal(cents((string)($_POST['sale_price']??'')));
            $min=($_POST['min_stock']??'')===''?null:integer_zero($_POST['min_stock'],'Umbral de stock');
            $rx=(int)(($_POST['requires_prescription']??'0')==='1');
            if($id){
                if(!fetch('SELECT id FROM products WHERE id=?',[$id]))throw new DomainException('Producto no encontrado.');
                exec_sql('UPDATE products SET code=?,name=?,description=?,category=?,sale_price=?,min_stock=?,requires_prescription=? WHERE id=?',[$code,$name,$description,$category,$price,$min,$rx,$id]);
            }else{
                exec_sql('INSERT INTO products(code,name,description,category,sale_price,min_stock,requires_prescription) VALUES(?,?,?,?,?,?,?)',[$code,$name,$description,$category,$price,$min,$rx]);
                $id=(int)db()->lastInsertId();
            }
            audit('guardar_producto','producto',$id);flash('Producto guardado correctamente.');redirect('products');
        case 'toggle-product':
            require_owner();$id=positive_int($_POST['id']??null,'Producto');
            if(exec_sql('UPDATE products SET active=1-active WHERE id=?',[$id])!==1)throw new DomainException('Producto no encontrado.');
            audit('cambiar_estado','producto',$id);flash('Estado del producto actualizado.');redirect('products');
        case 'save-provider':
            require_owner();$id=($_POST['id']??'')!==''?positive_int($_POST['id'],'Proveedor'):null;
            $name=text_required($_POST['name']??null,'Nombre');$phone=trim((string)($_POST['phone']??''));$address=trim((string)($_POST['address']??''));
            if(mb_len($phone)>40||mb_len($address)>255)throw new DomainException('Teléfono o dirección demasiado largos.');
            if($id){if(exec_sql('UPDATE providers SET name=?,phone=?,address=? WHERE id=?',[$name,$phone,$address,$id])!==1 && !fetch('SELECT id FROM providers WHERE id=?',[$id]))throw new DomainException('Proveedor no encontrado.');}
            else {exec_sql('INSERT INTO providers(name,phone,address) VALUES(?,?,?)',[$name,$phone,$address]);$id=(int)db()->lastInsertId();}
            audit('guardar_proveedor','proveedor',$id);flash('Proveedor guardado.');redirect('providers');
        case 'toggle-provider':
            require_owner();$id=positive_int($_POST['id']??null,'Proveedor');
            if(exec_sql('UPDATE providers SET active=1-active WHERE id=?',[$id])!==1)throw new DomainException('Proveedor no encontrado.');
            audit('cambiar_estado','proveedor',$id);flash('Estado actualizado.');redirect('providers');
        case 'save-client':
            require_clients();$id=($_POST['id']??'')!==''?positive_int($_POST['id'],'Cliente'):null;
            $name=text_required($_POST['name']??null,'Nombre');$phone=trim((string)($_POST['phone']??''));
            if(mb_len($phone)>40)throw new DomainException('Teléfono demasiado largo.');
            if($id){exec_sql('UPDATE clients SET name=?,phone=? WHERE id=?',[$name,$phone,$id]);if(!fetch('SELECT id FROM clients WHERE id=?',[$id]))throw new DomainException('Cliente no encontrado.');}
            else {exec_sql('INSERT INTO clients(name,phone) VALUES(?,?)',[$name,$phone]);$id=(int)db()->lastInsertId();}
            audit('guardar_cliente','cliente',$id);flash('Cliente guardado.');redirect('clients');
        case 'toggle-client':
            require_clients();$id=positive_int($_POST['id']??null,'Cliente');
            if(exec_sql('UPDATE clients SET active=1-active WHERE id=?',[$id])!==1)throw new DomainException('Cliente no encontrado.');
            audit('cambiar_estado','cliente',$id);flash('Estado actualizado.');redirect('clients');
        case 'save-user':
            require_owner();$id=($_POST['id']??'')!==''?positive_int($_POST['id'],'Usuario'):null;
            $name=text_required($_POST['name']??null,'Nombre',120);
            $username=text_required($_POST['username']??null,'Usuario',50);
            if(!preg_match('/^[a-zA-Z0-9._-]{3,50}$/D',$username))throw new DomainException('El usuario debe tener 3 a 50 caracteres: letras, números, punto, guion o guion bajo.');
            $role=(string)($_POST['role']??'');if(!in_array($role,['propietaria','apoyo'],true))throw new DomainException('Rol inválido.');
            $perms=(int)($role==='apoyo'&&($_POST['can_manage_clients']??'0')==='1');
            $pw=(string)($_POST['password']??'');
            if($id){
                if($id===(int)user()['id'] && $role!=='propietaria')throw new DomainException('No puede eliminar su propio rol de propietaria.');
                if(!fetch('SELECT id FROM users WHERE id=?',[$id]))throw new DomainException('Usuario inexistente.');
                if($pw!=='')exec_sql('UPDATE users SET password_hash=? WHERE id=?',[password_hash_secure($pw),$id]);
                exec_sql('UPDATE users SET name=?,username=?,role=?,can_manage_clients=? WHERE id=?',[$name,$username,$role,$perms,$id]);
            }else{
                exec_sql('INSERT INTO users(name,username,password_hash,role,can_manage_clients) VALUES(?,?,?,?,?)',[$name,$username,password_hash_secure($pw),$role,$perms]);
                $id=(int)db()->lastInsertId();
            }
            audit('guardar_usuario','usuario',$id);flash('Usuario guardado.');redirect('users');
        case 'toggle-user':
            require_owner();$id=positive_int($_POST['id']??null,'Usuario');
            if($id===(int)user()['id'])throw new DomainException('No puede desactivar su propia cuenta.');
            $u=fetch('SELECT id,role,active FROM users WHERE id=?',[$id]);if(!$u)throw new DomainException('Usuario inexistente.');
            if((int)$u['active']===1 && $u['role']==='propietaria'){
                $n=fetch("SELECT COUNT(*) n FROM users WHERE active=1 AND role='propietaria'");
                if((int)$n['n']<=1)throw new DomainException('Debe permanecer al menos una propietaria activa.');
            }
            exec_sql('UPDATE users SET active=1-active WHERE id=?',[$id]);
            audit('cambiar_estado','usuario',$id);flash('Estado del usuario actualizado.');redirect('users');
        case 'sale-create':
            $id=create_sale($_POST,(int)user()['id']);flash('Venta confirmada. Puede imprimir el comprobante.');redirect('receipt',['id'=>$id]);
        case 'purchase-create':
            require_owner();$id=create_purchase($_POST,(int)user()['id']);flash('Compra registrada.');redirect('purchase-detail',['id'=>$id]);
        case 'purchase-receive':
            require_owner();$id=positive_int($_POST['id']??null,'Compra');receive_purchase($id,(int)user()['id']);flash('Recepción confirmada. El inventario fue actualizado.');redirect('purchase-detail',['id'=>$id]);
        case 'adjust-stock':
            require_owner();adjust_stock($_POST,(int)user()['id']);flash('Ajuste de inventario registrado y auditado.');redirect('inventory');
        case 'save-settings':
            require_owner();
            $name=text_required($_POST['business_name']??null,'Nombre del establecimiento',140);
            $address=trim((string)($_POST['business_address']??''));$phone=trim((string)($_POST['business_phone']??''));
            if(mb_len($address)>250||mb_len($phone)>40)throw new DomainException('Datos del establecimiento demasiado largos.');
            $days=($_POST['expiry_alert_days']??'')===''?null:integer_zero($_POST['expiry_alert_days'],'Días de alerta');
            if($days!==null&&$days>3650)throw new DomainException('El período debe ser de 0 a 3650 días.');
            $retention=trim((string)($_POST['retention_policy']??''));if(mb_len($retention)>250)throw new DomainException('Texto de conservación demasiado largo.');
            exec_sql('UPDATE settings SET business_name=?,business_address=?,business_phone=?,expiry_alert_days=?,retention_policy=? WHERE id=1',[$name,$address,$phone,$days,$retention?:'Pendiente de validación']);
            audit('actualizar_parametros','configuracion',1);flash('Configuración actualizada.');redirect('settings');
        default: render_error('Acción no encontrada.',404);
        }
    } catch(PDOException $e){
        error_log('[Farmacia] '.$e->getMessage());
        $err=($e->getCode()==='23000')?'Ya existe un registro con estos datos o la operación fue procesada anteriormente.':'No se pudo guardar la operación. Compruebe la conexión y los datos.';
        flash($err,'error');redirect(match($route){'login'=>'login','sale-create'=>'sale-new','purchase-create'=>'purchase-new','purchase-receive'=>'purchases','save-product','toggle-product'=>'products','save-provider','toggle-provider'=>'providers','save-client','toggle-client'=>'clients','save-user','toggle-user'=>'users','save-settings'=>'settings','adjust-stock'=>'inventory',default=>'dashboard'});
    }catch(DomainException $e){
        flash($e->getMessage(),'error');redirect(match($route){'login'=>'login','sale-create'=>'sale-new','purchase-create'=>'purchase-new','purchase-receive'=>'purchases','save-product','toggle-product'=>'products','save-provider','toggle-provider'=>'providers','save-client','toggle-client'=>'clients','save-user','toggle-user'=>'users','save-settings'=>'settings','adjust-stock'=>'inventory',default=>'dashboard'});
    }
}
if(!in_array($route,$public,true)) require_login();
if($route==='export-sales') {
    require_owner();$date=(string)($_GET['date']??date('Y-m-d'));
    if(!nullable_date($date))render_error('Fecha inválida.');
    $report=rows('SELECT s.receipt_no,s.created_at,u.name responsible,s.payment_method,s.subtotal,s.total,s.cash_received,s.cash_change FROM sales s JOIN users u ON u.id=s.user_id WHERE s.created_at>=? AND s.created_at<? ORDER BY s.id',[$date, date('Y-m-d',strtotime($date.' +1 day'))]);
    header_remove('Content-Security-Policy');header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="ventas-'.$date.'.csv"');
    echo "\xEF\xBB\xBF";$fp=fopen('php://output','w'); fputcsv($fp,['Comprobante','Fecha','Responsable','Método','Subtotal C$','Total C$','Recibido C$','Cambio C$']);
    foreach($report as $line) fputcsv($fp,array_values($line));fclose($fp);exit;
}
require_once __DIR__.'/app/views.php';
try { display_page($route); }
catch(DomainException $e){ render_error($e->getMessage(),400); }
catch(PDOException $e){ error_log('[Farmacia] '.$e->getMessage()); render_error('No se pudo consultar la información solicitada.',503); }
