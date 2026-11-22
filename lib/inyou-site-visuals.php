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
