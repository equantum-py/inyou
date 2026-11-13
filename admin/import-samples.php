<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
requireAuth();
if(role()!=='owner'){http_response_code(403);exit('No autorizado.');}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');http_response_code(405);exit;}
if(!validCsrf()){http_response_code(403);exit('Sesión vencida. Volvé al listado de productos.');}

$templates=[
 ['slug'=>'muestra-aura-borgona','name'=>'Remera Aura Bordó','sku'=>'INY-AURA','collection'=>'Floral & Arte','price'=>129000,'audience'=>'Mujer','fit'=>'Regular','sizes'=>['S','M','L'],'colors'=>['Bordó','Blanco']],
 ['slug'=>'muestra-urbana-grafito','name'=>'Remera Urbana Grafito','sku'=>'INY-URBANA','collection'=>'Esenciales','price'=>149000,'audience'=>'Unisex','fit'=>'Oversize','sizes'=>['M','L','XL'],'colors'=>['Gris','Negro']],
 ['slug'=>'muestra-esencial-cielo','name'=>'Remera Esencial Cielo','sku'=>'INY-CIELO','collection'=>'Esenciales','price'=>119000,'audience'=>'Hombre','fit'=>'Regular','sizes'=>['S','M','L'],'colors'=>['Azul','Blanco']],
 ['slug'=>'muestra-libertad-crema','name'=>'Remera Libertad Crema','sku'=>'INY-LIBERTAD','collection'=>'Momentos Especiales','price'=>139000,'audience'=>'Mujer','fit'=>'Oversize','sizes'=>['S','M','L'],'colors'=>['Crema','Negro']]
];
$colors=['Bordó'=>'BOR','Blanco'=>'BLA','Gris'=>'GRI','Negro'=>'NEG','Azul'=>'AZU','Crema'=>'CRE'];
try{
 $pdo=db();
 $sizeIds=[];foreach($pdo->query('SELECT id,label FROM sizes WHERE is_active=1')->fetchAll() as $row){$sizeIds[(string)$row['label']]=(int)$row['id'];}
 $collections=[];foreach($pdo->query('SELECT id,name FROM collections WHERE is_active=1')->fetchAll() as $row){$collections[(string)$row['name']]=(int)$row['id'];}
 foreach($templates as $t){if(!isset($collections[$t['collection']]))throw new RuntimeException('Falta la colección '.$t['collection'].'.');foreach($t['sizes'] as $size)if(!isset($sizeIds[$size]))throw new RuntimeException('Falta el talle '.$size.'.');}
 $pdo->beginTransaction();
 $check=$pdo->prepare('SELECT id FROM products WHERE slug=? LIMIT 1');
 $insProduct=$pdo->prepare("INSERT INTO products (sku,slug,name,description,base_price,currency,status,featured,product_type,audience,fit) VALUES (?,?,?, ?,?,'PYG','draft',0,'remera',?,?)");
 $insCollection=$pdo->prepare('INSERT INTO product_collections (product_id,collection_id,sort_order) VALUES (?,?,0)');
 $insVariant=$pdo->prepare("INSERT INTO product_variants (product_id,size_id,sku,color_name,price_override,stock_qty,availability_mode,lead_time,is_active,sort_order) VALUES (?,?,?,?,NULL,0,'stock','',0,?)");
 $inserted=[];$existing=0;
 foreach($templates as $t){
  $check->execute([$t['slug']]);if($check->fetchColumn()){$existing++;continue;}
  $insProduct->execute([$t['sku'],$t['slug'],$t['name'],'Prenda de muestra para revisión interna. Fotografía, stock y precio sujetos a confirmación.',$t['price'],$t['audience'],$t['fit']]);
  $productId=(int)$pdo->lastInsertId();
  $insCollection->execute([$productId,$collections[$t['collection']]]);
  $sort=0;
  foreach($t['colors'] as $color){foreach($t['sizes'] as $size){
   $sku=$t['sku'].'-'.$size.'-'.$colors[$color];
   $insVariant->execute([$productId,$sizeIds[$size],$sku,$color,++$sort]);
  }}
  $inserted[]=$productId;
 }
 $pdo->commit();
 foreach($inserted as $id){auditLog($pdo,'product.create','product',$id,['source'=>'sample_import','status'=>'draft']);}
 header('Location: /admin/products.php?imported='.count($inserted).'&existing='.$existing,true,303);exit;
}catch(Throwable $e){
 if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
 error_log('InYou importar muestras: '.$e->getMessage());
 http_response_code(500);
 echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Importación · InYou</title><body style="font-family:Arial;padding:28px"><h1>No se pudieron cargar las prendas</h1><p>'.h($e instanceof PDOException?'Verificá las tablas y permisos de MySQL.':$e->getMessage()).'</p><p>No se guardó una importación parcial.</p><a href="/admin/products.php">Volver a Productos</a></body></html>';
}
