<?php
declare(strict_types=1);
// Copia consistente en SQL. Programar cada 24 horas desde el Programador de tareas.
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI.');}
require_once dirname(__DIR__).'/app/core.php';
try {
    $pdo=db();$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $dir=dirname(__DIR__).'/storage/backups';
    if(!is_dir($dir))mkdir($dir,0700,true);
    $output=$dir.'/respaldo-'.date('Y-m-d_H-i-s').'-'.bin2hex(random_bytes(3)).'.sql';
    $fp=fopen($output,'xb');if(!$fp)throw new RuntimeException('No se pudo abrir el archivo de respaldo.');
    fwrite($fp,"-- Respaldo generado por Farmacia La Bendición: ".date('c')."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    foreach($tables as $table){
        // El nombre proviene de SHOW TABLES, no de entrada del usuario.
        $safe='`'.str_replace('`','``',$table).'`';
        $schema=$pdo->query('SHOW CREATE TABLE '.$safe)->fetch(PDO::FETCH_NUM);
        fwrite($fp,"\nDROP TABLE IF EXISTS {$safe};\n".$schema[1].";\n");
        $st=$pdo->query('SELECT * FROM '.$safe);
        while($record=$st->fetch(PDO::FETCH_ASSOC)){
            $fields=[];$values=[];
            foreach($record as $field=>$value){
                $fields[]='`'.str_replace('`','``',$field).'`';
                $values[]=$value===null?'NULL':$pdo->quote((string)$value);
            }
            fwrite($fp,'INSERT INTO '.$safe.' ('.implode(',',$fields).') VALUES ('.implode(',',$values).");\n");
        }
    }
    fwrite($fp,"SET FOREIGN_KEY_CHECKS=1;\n");fclose($fp);
    $pdo->commit();@chmod($output,0600);
    echo "RESPALDO CREADO: $output\n";
    echo "ATENCIÓN: contiene datos personales y hashes de contraseñas. Protéjalo y guarde otra copia fuera del equipo.\n";
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    if(isset($fp)&&is_resource($fp))fclose($fp);
    if(isset($output)&&is_file($output))unlink($output);
    fwrite(STDERR,'ERROR DE RESPALDO: '.$e->getMessage()."\n");exit(1);
}
