<?php
declare(strict_types=1);
require_once __DIR__.'/inyou-storage.php';

/** Localized homepage image slots. URLs and secrets never come from client input. */
function inyouSiteSlots():array {
 return [
  'hero'=>['Banner principal','1920 × 450','750 × 507','64:15 / 250:169'],
  'category_women'=>['Categoría: Mujer','800 × 800','600 × 600','1:1'],
  'category_men'=>['Categoría: Hombre','800 × 800','600 × 600','1:1'],
  'category_unisex'=>['Categoría: Unisex','800 × 800','600 × 600','1:1'],
  'category_caps'=>['Categoría: Kepis','800 × 800','600 × 600','1:1'],
  'collection_floral'=>['Colección: Floral & Arte','900 × 1200','600 × 800','3:4'],
  'collection_essentials'=>['Colección: Esenciales','900 × 1200','600 × 800','3:4'],
  'collection_faith'=>['Colección: Fe & Esperanza','900 × 1200','600 × 800','3:4'],
  'collection_moments'=>['Colección: Momentos Especiales','900 × 1200','600 × 800','3:4'],
  'season'=>['Banner temporada','1200 × 1000','750 × 900','6:5 / 5:6'],
  'look_one'=>['Look: Urbano esencial','900 × 1125','600 × 750','4:5'],
  'look_two'=>['Look: Oversize diario','900 × 1125','600 × 750','4:5'],
  'brand'=>['Banner: Sobre InYou','1200 × 900','750 × 900','4:3 / 5:6'],
 ];
}
function inyouSiteSettingKey(string $slot,string $device):string {
 if(!array_key_exists($slot,inyouSiteSlots())||!in_array($device,['desktop','mobile'],true))throw new RuntimeException('Sección de imagen no válida.');
 return 'site_img_'.$slot.'_'.$device;
}
function inyouSiteImagePath(string $slot,string $device):string {
 inyouSiteSettingKey($slot,$device);
 return 'supabase/site/'.$slot.'-'.$device.'-'.bin2hex(random_bytes(16));
}
function inyouSiteImages(PDO $pdo):array {
 $rows=$pdo->query("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'site_img_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
 $result=[];
 foreach(inyouSiteSlots() as $slot=>$labels){
  foreach(['desktop','mobile'] as $device){
   $path=(string)($rows[inyouSiteSettingKey($slot,$device)]??'');
   if($path!==''&&inyouStorageIsPath($path))$result[$slot][$device]=['path'=>$path,'url'=>inyouStoragePublicUrl($path)];
  }
 }
 return $result;
}

/** Orden y estado del carrusel principal. La imagen original es la diapositiva 0. */
function inyouHeroOrder(PDO $pdo):array {
 $stmt=$pdo->query("SELECT `value` FROM settings WHERE `key`='site_hero_slides' LIMIT 1");
 $raw=$stmt->fetchColumn();
 if($raw===false)return [['id'=>0,'active'=>true]];
 $data=json_decode((string)$raw,true);
 if(!is_array($data))return [['id'=>0,'active'=>true]];
 $slides=[];$used=[];
 foreach($data as $row){
  if(!is_array($row)||!isset($row['id']))continue;
  $id=filter_var($row['id'],FILTER_VALIDATE_INT);
  if($id===false||$id<0||$id>5||isset($used[$id]))continue;
  $used[$id]=true;
  $slides[]=['id'=>$id,'active'=>!empty($row['active'])];
 }
 return $slides?:[['id'=>0,'active'=>true]];
}
function inyouHeroSaveOrder(PDO $pdo,array $slides):void {
 if(count($slides)>6)throw new RuntimeException('Máximo 6 banners principales.');
 $json=json_encode(array_values($slides),JSON_THROW_ON_ERROR);
 $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('site_hero_slides',?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")->execute([$json]);
}
function inyouHeroImageKey(int $id,string $device):string {
 if($id<0||$id>5||!in_array($device,['desktop','mobile'],true))throw new RuntimeException('Banner no válido.');
 return $id===0?inyouSiteSettingKey('hero',$device):'site_img_hero_'.$id.'_'.$device;
}
function inyouHeroImages(PDO $pdo,bool $activeOnly=true):array {
 $slides=inyouHeroOrder($pdo);
 $stmt=$pdo->query("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'site_img_hero_%'");
 $paths=$stmt->fetchAll(PDO::FETCH_KEY_PAIR);
 $result=[];
 foreach($slides as $slide){
  if($activeOnly&&!$slide['active'])continue;
  $entry=['id'=>$slide['id'],'active'=>$slide['active'],'desktop'=>null,'mobile'=>null];
  foreach(['desktop','mobile'] as $device){
   $path=(string)($paths[inyouHeroImageKey($slide['id'],$device)]??'');
   if($path!==''&&inyouStorageIsPath($path))$entry[$device]=['path'=>$path,'url'=>inyouStoragePublicUrl($path)];
  }
  if(!$activeOnly||$entry['desktop']||$entry['mobile'])$result[]=$entry;
 }
 return $result;
}
