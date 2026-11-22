/* InYou: imágenes editadas desde el administrador, incluidos banners del carrusel. */
(()=>{
 'use strict';
 const selectors={
  category_women:'.shortcut-women',category_men:'.shortcut-men',
  category_unisex:'.shortcut-unisex',category_caps:'.shortcut-cap',
  collection_floral:'.collection-floral',collection_essentials:'.collection-essential',
  collection_faith:'.collection-faith',collection_moments:'.collection-moments',
  season:'.season-photo',look_one:'.look-one',look_two:'.look-two',brand:'.brand-visual'
 };
 const mobile=window.matchMedia('(max-width: 767px)');
 const values={};
 function urlCss(url){
  try{
   const parsed=new URL(url,location.href);
   if(parsed.protocol!=='https:')return '';
   return 'url("'+parsed.href.replace(/["\\\n\r]/g,'')+'")';
  }catch{return '';}
 }
 function render(){
  for(const [slot,selector] of Object.entries(selectors)){
   const element=document.querySelector(selector);
   if(!element)continue;
   const item=values[slot]||{};
   const src=mobile.matches?(item.mobile||item.desktop):(item.desktop||item.mobile);
   const bg=src?urlCss(src):'';
   if(!bg){element.style.removeProperty('background-image');continue;}
   if(slot==='brand')element.style.backgroundImage='linear-gradient(180deg,rgba(0,0,0,.02),rgba(0,0,0,.32)),'+bg;
   else element.style.backgroundImage=bg;
  }
 }
 async function load(){
  try{
   const response=await fetch('/api/v1/site-images.php',{credentials:'omit',cache:'no-store'});
   if(!response.ok)return;
   const data=await response.json();
   if(!data||typeof data.images!=='object'||!data.images)return;
   for(const [key,item] of Object.entries(data.images)){
    if(Object.prototype.hasOwnProperty.call(selectors,key)&&item&&typeof item==='object')values[key]=item;
   }
   render();
   const fallback=data.images.hero||{};
   const slides=Array.isArray(data.heroSlides)?data.heroSlides:
    (fallback.desktop||fallback.mobile?[{id:0,desktop:fallback.desktop||null,mobile:fallback.mobile||null}]:[]);
   window.InYouHeroCarousel?.setSlides(slides);
  }catch{ /* Fondo neutro sin imagen ajena mientras falla la API. */ }
 }
 if(mobile.addEventListener)mobile.addEventListener('change',render);
 else if(mobile.addListener)mobile.addListener(render);
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',load,{once:true});
 else load();
})();
