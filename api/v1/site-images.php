<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/lib/database.php';
require_once dirname(__DIR__,2).'/lib/inyou-site-visuals.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
try{
 $pdo=inyouDb();
 $items=inyouSiteImages($pdo);
 $heroSlides=inyouHeroImages($pdo,true);
 $images=[];
 foreach($items as $slot=>$devices){
  foreach($devices as $device=>$entry)$images[$slot][$device]=$entry['url'];
 }
 $carousel=[];
 foreach($heroSlides as $slide)$carousel[]=['id'=>$slide['id'],'desktop'=>$slide['desktop']['url']??null,'mobile'=>$slide['mobile']['url']??null];
 echo json_encode(['images'=>(object)$images,'heroSlides'=>$carousel],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
 error_log('InYou site images public endpoint: '.$e->getMessage());
 http_response_code(503);
 echo '{"images":{}}';
}
