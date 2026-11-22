/* InYou: imágenes de portada editadas desde el administrador (sin deploy por cambio). */
(()=>{
 'use strict';
 const selectors={
  hero:'.hero-media',
  category_women:'.shortcut-women',
  category_men:'.shortcut-men',
  category_unisex:'.shortcut-unisex',
  category_caps:'.shortcut-cap',
  collection_floral:'.collection-floral',
  collection_essentials:'.collection-essential',
  collection_faith:'.collection-faith',
  collection_moments:'.collection-moments',
  season:'.season-photo',
  look_one:'.look-one',
  look_two:'.look-two',
  brand:'.brand-visual'
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
   const src=mobile.matches?(item.mobile||item.desktop):item.desktop;
   const bg=src?urlCss(src):'';
   if(!bg){
    if(slot==='hero'){
     element.querySelector('img[data-hero-banner]')?.remove();
     element.classList.remove('hero-has-upload');
    }
    element.style.removeProperty('background-image');
    continue;
   }
   if(slot==='hero'){
    // No mostrar la foto predeterminada mientras se obtiene el banner del admin.
    // El banner nuevo solo se presenta cuando el archivo está cargado y decodificado.
    let image=element.querySelector('img[data-hero-banner]');
    if(!image){
     image=document.createElement('img');
     image.dataset.heroBanner='true';
     image.alt='Banner principal de InYou';
     image.decoding='async';
     image.loading='eager';
     image.fetchPriority='high';
     element.append(image);
    }
    const source=new URL(src,location.href).href;
    const token=(Number(element.dataset.heroRequest||0)+1);
    element.dataset.heroRequest=String(token);
    image.style.visibility='hidden';
    element.classList.remove('hero-has-upload');
    const reveal=()=>{
     if(Number(element.dataset.heroRequest)!==token)return;
     image.style.visibility='visible';
     element.classList.add('hero-has-upload');
    };
    image.onload=()=>{
     if(typeof image.decode==='function'){
      image.decode().then(reveal).catch(reveal);
     }else reveal();
    };
    image.onerror=()=>{
     if(Number(element.dataset.heroRequest)===token)image.style.visibility='hidden';
    };
    if(image.src!==source)image.src=source;
    else if(image.complete&&image.naturalWidth>0)image.onload();
    element.style.setProperty('background-image','none','important');
   }else if(slot==='brand'){
    element.style.backgroundImage='linear-gradient(180deg,rgba(0,0,0,.02),rgba(0,0,0,.32)),'+bg;
   }else element.style.backgroundImage=bg;
  }
 }
 async function load(){
  try{
   const res=await fetch('/api/v1/site-images.php',{credentials:'omit',cache:'no-store'});
   if(!res.ok)return;
   const data=await res.json();
   if(!data||typeof data.images!=='object'||!data.images)return;
   for(const [key,item] of Object.entries(data.images)){
    if(Object.prototype.hasOwnProperty.call(selectors,key)&&item&&typeof item==='object')values[key]=item;
   }
   render();
  }catch{ /* Se conservan las imágenes de respaldo definidas en CSS. */ }
 }
 if(mobile.addEventListener)mobile.addEventListener('change',render);
 else if(mobile.addListener)mobile.addListener(render);
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',load,{once:true});
 else load();
})();
