<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

function valid_token(mixed $v): string {
    if(!is_string($v)||!preg_match('/^[a-f0-9]{32}$/D',$v)) throw new DomainException('Formulario de operación no válido. Abra un formulario nuevo.');
    return $v;
}
function normalized_lines(string $idField='product_id'): array {
    $ids=post_array($idField); $qty=post_array('quantity');
    if(count($ids)===0 || count($ids)>40 || count($ids)!==count($qty)) throw new DomainException('Agregue entre 1 y 40 líneas válidas.');
    $items=[];
    foreach($ids as $i=>$id) {
        if(!isset($qty[$i])) throw new DomainException('Faltan cantidades en el formulario.');
        $p=positive_int($id,'Producto'); $q=positive_int($qty[$i]);
        $items[$p]=($items[$p]??0)+$q;
        if($items[$p]>100000000) throw new DomainException('Cantidad excesiva.');
    }
    ksort($items,SORT_NUMERIC); return $items;
}
function create_sale(array $form,int $userId): int {
    $items=normalized_lines();
    $received=cents((string)($form['cash_received']??''));
    $token=valid_token($form['request_token']??null);
    $client=isset($form['client_id'])&&$form['client_id']!==''?positive_int($form['client_id'],'Cliente'):null;
    if($client!==null && !can_clients()) throw new DomainException('No tiene permiso para asociar clientes a ventas.');
    $pdo=db();$pdo->beginTransaction();
    try {
        if($client && !fetch('SELECT id FROM clients WHERE id=? AND active=1',[$client])) throw new DomainException('Cliente no disponible.');
        $amount=0; $details=[];
        foreach($items as $productId=>$quantity) {
            $p=fetch('SELECT id,name,sale_price,active,requires_prescription FROM products WHERE id=? FOR UPDATE',[$productId]);
            if(!$p || !(int)$p['active']) throw new DomainException('Uno de los productos ya no está disponible.');
            $unit=cents((string)$p['sale_price']);
            $available=0;$allocation=[];
            $lots=rows('SELECT id,available_qty,expiry_date FROM lots WHERE product_id=? AND available_qty>0 AND (expiry_date IS NULL OR expiry_date>=CURDATE()) ORDER BY expiry_date IS NULL,expiry_date,id FOR UPDATE',[$productId]);
            foreach($lots as $lot) {
                if($available >= $quantity) break;
                $use=min((int)$lot['available_qty'],$quantity-$available);
                if($use>0) { $allocation[]=['lot'=>$lot['id'],'qty'=>$use];$available+=$use; }
            }
            if($available<$quantity) throw new DomainException('Existencias insuficientes o vencidas para: '.$p['name'].'. Disponibles para venta: '.$available.'.');
            $amount+=$unit*$quantity;
            foreach($allocation as $piece) $details[]=['product'=>$productId,'name'=>$p['name'],'unit'=>$unit,'lot'=>$piece['lot'],'qty'=>$piece['qty']];
        }
        if($amount>999999999999) throw new DomainException('El total excede el límite permitido.');
        if($received<$amount) throw new DomainException('El efectivo recibido no cubre el total de la venta ('.money(decimal($amount)).').');
        exec_sql('INSERT INTO sales(client_id,user_id,subtotal,total,payment_method,cash_received,cash_change,request_token) VALUES(?,?,?, ?,\'efectivo\',?,?,?)',
            [$client,$userId,decimal($amount),decimal($amount),decimal($received),decimal($received-$amount),$token]);
        $saleId=(int)$pdo->lastInsertId();
        exec_sql('UPDATE sales SET receipt_no=? WHERE id=?',[sprintf('FLB-%08d',$saleId),$saleId]);
        foreach($details as $line) {
            $subtotal=$line['unit']*$line['qty'];
            exec_sql('INSERT INTO sale_lines(sale_id,product_id,lot_id,product_name,quantity,unit_price,line_total) VALUES(?,?,?,?,?,?,?)',
                [$saleId,$line['product'],$line['lot'],$line['name'],$line['qty'],decimal($line['unit']),decimal($subtotal)]);
            if(exec_sql('UPDATE lots SET available_qty=available_qty-? WHERE id=? AND available_qty>=?',[$line['qty'],$line['lot'],$line['qty']])!==1) throw new RuntimeException('Cambio simultáneo de inventario. Intente nuevamente.');
            exec_sql('INSERT INTO movements(product_id,lot_id,type,quantity,ref_type,ref_id,note,user_id) VALUES(?,?,\'venta\',?,\'venta\',?,?,?)',
                [$line['product'],$line['lot'],-$line['qty'],$saleId,'Venta confirmada',$userId]);
        }
        audit('confirmar_venta','venta',$saleId,'Total '.decimal($amount));
        $pdo->commit(); return $saleId;
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function purchase_form_lines(): array {
    $ids=post_array('product_id'); $qty=post_array('quantity');$cost=post_array('unit_cost');
    $code=post_array('lot_code');$exp=post_array('expiry_date');
    if(count($ids)<1||count($ids)>40||count($ids)!==count($qty)) throw new DomainException('La compra necesita entre 1 y 40 líneas completas.');
    $items=[];
    foreach($ids as $i=>$v) {
        if(!isset($qty[$i],$cost[$i])) throw new DomainException('Faltan cantidades o costos de una línea.');
        $id=positive_int($v,'Producto');$q=positive_int($qty[$i]);$unit=cents((string)$cost[$i]);
        $lot=trim((string)($code[$i]??''));
        if(mb_len($lot)>90) throw new DomainException('Código de lote demasiado largo.');
        $items[]=['product'=>$id,'qty'=>$q,'unit'=>$unit,'lot'=>$lot,'expiry'=>nullable_date($exp[$i]??'')];
    }
    return $items;
}
function create_purchase(array $form,int $userId): int {
    $providerId=positive_int($form['provider_id']??null,'Proveedor');
    $invoice=text_required($form['invoice_no']??null,'Referencia de factura',80);
    $token=valid_token($form['request_token']??null);
    $received=($form['status']??'pendiente')==='recibida';
    if(!in_array($form['status']??'', ['recibida','pendiente'],true)) throw new DomainException('Estado de compra incorrecto.');
    $lines=purchase_form_lines();$total=0;
    foreach($lines as $line){ $total+=$line['unit']*$line['qty']; }
    if($total>999999999999) throw new DomainException('El importe supera el máximo permitido.');
    $pdo=db(); $pdo->beginTransaction();
    try {
        if(!fetch('SELECT id FROM providers WHERE id=? AND active=1',[$providerId])) throw new DomainException('Seleccione un proveedor activo.');
        $ids=array_unique(array_column($lines,'product'));sort($ids,SORT_NUMERIC);
        foreach($ids as $id){$p=fetch('SELECT id,active FROM products WHERE id=? FOR UPDATE',[$id]);if(!$p||(int)$p['active']!==1)throw new DomainException('La compra contiene un producto inactivo.');}
        exec_sql('INSERT INTO purchases(provider_id,user_id,invoice_no,status,total,request_token) VALUES(?,?,?,\'pendiente\',?,?)',[$providerId,$userId,$invoice,decimal($total),$token]);
        $id=(int)$pdo->lastInsertId();
        foreach($lines as $line){
            exec_sql('INSERT INTO purchase_lines(purchase_id,product_id,lot_code,expiry_date,quantity,unit_cost,line_total) VALUES(?,?,?,?,?,?,?)',
                [$id,$line['product'],$line['lot'],$line['expiry'],$line['qty'],decimal($line['unit']),decimal($line['unit']*$line['qty'])]);
        }
        if($received) receive_purchase_locked($id,$userId);
        audit('registrar_compra','compra',$id,$received?'Recibida':'Pendiente');
        $pdo->commit();return $id;
    } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack();throw $e; }
}
function receive_purchase_locked(int $purchaseId,int $userId): void {
    $p=fetch('SELECT id,status FROM purchases WHERE id=? FOR UPDATE',[$purchaseId]);
    if(!$p || $p['status']!=='pendiente') throw new DomainException('La compra ya está recibida o no existe.');
    $lines=rows('SELECT * FROM purchase_lines WHERE purchase_id=? ORDER BY product_id,id FOR UPDATE',[$purchaseId]);
    foreach($lines as $line){
        $product=fetch('SELECT id,active FROM products WHERE id=? FOR UPDATE',[$line['product_id']]);
        if(!$product) throw new RuntimeException('Producto de compra inexistente.');
        exec_sql('INSERT INTO lots(product_id,lot_code,expiry_date,available_qty) VALUES(?,?,?,?)',[$line['product_id'],$line['lot_code'],$line['expiry_date'],$line['quantity']]);
        $lotId=(int)db()->lastInsertId();
        exec_sql('UPDATE purchase_lines SET lot_id=? WHERE id=?',[$lotId,$line['id']]);
        exec_sql('INSERT INTO movements(product_id,lot_id,type,quantity,ref_type,ref_id,note,user_id) VALUES(?,?,\'compra\',?,\'compra\',?,?,?)',[$line['product_id'],$lotId,$line['quantity'],$purchaseId,'Compra recibida',$userId]);
    }
    exec_sql('UPDATE purchases SET status=\'recibida\',received_at=NOW() WHERE id=? AND status=\'pendiente\' ',[$purchaseId]);
}
function receive_purchase(int $id,int $userId): void {
    $pdo=db();$pdo->beginTransaction();
    try { receive_purchase_locked($id,$userId);audit('recibir_compra','compra',$id);$pdo->commit(); }
    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function adjust_stock(array $form,int $userId): void {
    $productId=positive_int($form['product_id']??null,'Producto');
    $action=(string)($form['movement_type']??'');
    if(!in_array($action,['entrada','salida'],true))throw new DomainException('Tipo de ajuste inválido.');
    $qty=positive_int($form['quantity']??null);
    $reason=text_required($form['reason']??null,'Motivo del ajuste',255);
    $pdo=db();$pdo->beginTransaction();
    try{
        $p=fetch('SELECT id FROM products WHERE id=? FOR UPDATE',[$productId]);
        if(!$p)throw new DomainException('Producto inexistente.');
        exec_sql('INSERT INTO stock_adjustments(product_id,user_id,reason) VALUES(?,?,?)',[$productId,$userId,$reason]);
        $adjustId=(int)$pdo->lastInsertId();
        if($action==='entrada'){
            $expiry=nullable_date($form['expiry_date']??'');
            $code=trim((string)($form['lot_code']??''));if(mb_len($code)>90)throw new DomainException('Código de lote demasiado largo.');
            exec_sql('INSERT INTO lots(product_id,lot_code,expiry_date,available_qty) VALUES(?,?,?,?)',[$productId,$code,$expiry,$qty]);
            $lotId=(int)$pdo->lastInsertId();
            exec_sql('INSERT INTO movements(product_id,lot_id,type,quantity,ref_type,ref_id,note,user_id) VALUES(?,?,\'ajuste_entrada\',?,\'ajuste\',?,?,?)',[$productId,$lotId,$qty,$adjustId,$reason,$userId]);
        }else{
            $lotId=positive_int($form['lot_id']??null,'Lote');
            $lot=fetch('SELECT id,available_qty FROM lots WHERE id=? AND product_id=? FOR UPDATE',[$lotId,$productId]);
            if(!$lot||(int)$lot['available_qty']<$qty)throw new DomainException('No hay suficientes unidades en el lote indicado.');
            exec_sql('UPDATE lots SET available_qty=available_qty-? WHERE id=?',[$qty,$lotId]);
            exec_sql('INSERT INTO movements(product_id,lot_id,type,quantity,ref_type,ref_id,note,user_id) VALUES(?,?,\'ajuste_salida\',?,\'ajuste\',?,?,?)',[$productId,$lotId,-$qty,$adjustId,$reason,$userId]);
        }
        audit('ajustar_inventario','ajuste',$adjustId,$reason);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
