/* InYou · carrusel de banners administrables, sin imágenes predeterminadas intermedias. */
(()=>{
 'use strict';
 const hero=document.querySelector('.hero-media');
 // Los scripts defer pueden ejecutarse cuando el DOM ya existe, pero no asumirlo.
 const mq=window.matchMedia('(max-width: 767px)');
 const reduce=window.matchMedia('(prefers-reduced-motion: reduce)');
 let slides=[];
 let current=0;
 let timer=null;
 let generation=0;
 let paused=false;
 let startX=null;
 const DELAY=5000;
 function root(){return document.querySelector('.hero-media');}
 function stop(){if(timer){clearInterval(timer);timer=null;}}
 function schedule(){
  stop();
  if(slides.length<2||paused||reduce.matches||document.hidden)return;
  timer=setInterval(()=>go(current+1),DELAY);
 }
 function go(index){
  const el=root();
  if(!el||slides.length<2)return;
  current=(index+slides.length)%slides.length;
  el.querySelectorAll('[data-carousel-slide]').forEach((node,i)=>{
   const active=i===current;
   node.classList.toggle('is-active',active);
   node.setAttribute('aria-hidden',active?'false':'true');
  });
  el.querySelectorAll('[data-carousel-dot]').forEach((node,i)=>{
   node.classList.toggle('is-active',i===current);
   node.setAttribute('aria-label','Ver banner '+(i+1)+' de '+slides.length+(i===current?', actual':''));
   node.setAttribute('aria-pressed',i===current?'true':'false');
  });
 }
 function source(slide){return mq.matches?(slide.mobile||slide.desktop):(slide.desktop||slide.mobile);}
 function isSafe(url){
  try{return new URL(url,location.href).protocol==='https:';}catch{return false;}
 }
 async function prepared(url){
  if(!isSafe(url))return null;
  const src=new URL(url,location.href).href;
  const img=new Image();
  img.alt='Campaña InYou';
  img.decoding='async';
  img.src=src;
  try{
   if(typeof img.decode==='function')await img.decode();
   else if(!img.complete)await new Promise((resolve,reject)=>{img.onload=resolve;img.onerror=reject;});
  }catch{return null;}
  return img;
 }
 async function render(){
  const container=root();
  if(!container)return;
  const seq=++generation;
  stop();
  const sources=slides.map(source).filter(isSafe);
  if(!sources.length){
   container.replaceChildren();
   container.classList.remove('hero-has-carousel','hero-has-upload');
   return;
  }
  // Hasta que esté decodificada la primera diapositiva, mantener el fondo neutro.
  let initial=null;
  let startIndex=-1;
  for(let i=0;i<sources.length;i++){
   initial=await prepared(sources[i]);
   if(seq!==generation)return;
   if(initial){startIndex=i;break;}
  }
  if(!initial)return;
  const nodes=[];
  for(let i=0;i<sources.length;i++){
   const node=document.createElement('div');
   node.className='inyou-carousel-slide'+(i===startIndex?' is-active':'');
   node.dataset.carouselSlide=String(i);
   node.setAttribute('aria-hidden',i===startIndex?'false':'true');
   const image=i===startIndex?initial:new Image();
   image.alt='Banner '+(i+1)+' de InYou';
   image.decoding='async';
   if(i!==startIndex){image.loading='lazy';image.src=new URL(sources[i],location.href).href;}
   node.append(image);
   nodes.push(node);
  }
  const fragments=[...nodes];
  if(nodes.length>1){
   const left=document.createElement('button');
   left.type='button';left.className='inyou-carousel-arrow prev';
   left.setAttribute('aria-label','Banner anterior');left.textContent='‹';
   left.addEventListener('click',()=>{go(current-1);schedule();});
   const right=document.createElement('button');
   right.type='button';right.className='inyou-carousel-arrow next';
   right.setAttribute('aria-label','Siguiente banner');right.textContent='›';
   right.addEventListener('click',()=>{go(current+1);schedule();});
   const dots=document.createElement('div');
   dots.className='inyou-carousel-dots';dots.setAttribute('aria-label','Elegir banner');
   sources.forEach((_,i)=>{
    const button=document.createElement('button');
    button.type='button';button.dataset.carouselDot=String(i);
    button.addEventListener('click',()=>{go(i);schedule();});
    dots.append(button);
   });
   fragments.push(left,right,dots);
  }
  if(seq!==generation)return;
  container.replaceChildren(...fragments);
  container.style.setProperty('background-image','none','important');
  container.classList.remove('hero-has-upload');
  container.classList.add('hero-has-carousel');
  container.setAttribute('aria-label','Carrusel principal de InYou');
  current=startIndex;
  go(current);
  if(nodes.length>1){
   container.setAttribute('tabindex','0');
   container.onkeydown=e=>{
    if(e.key==='ArrowLeft'){e.preventDefault();go(current-1);schedule();}
    if(e.key==='ArrowRight'){e.preventDefault();go(current+1);schedule();}
   };
   container.onmouseenter=()=>{paused=true;stop();};
   container.onmouseleave=()=>{paused=false;schedule();};
   container.onfocusin=()=>{paused=true;stop();};
   container.onfocusout=e=>{if(!container.contains(e.relatedTarget)){paused=false;schedule();}};
   container.onpointerdown=e=>{if(e.pointerType==='touch')startX=e.clientX;};
   container.onpointerup=e=>{
    if(startX===null)return;
    const distance=e.clientX-startX;startX=null;
    if(Math.abs(distance)>45){go(current+(distance<0?1:-1));schedule();}
   };
  }else{
   container.removeAttribute('tabindex');
   container.onkeydown=null;container.onmouseenter=null;container.onmouseleave=null;
   container.onfocusin=null;container.onfocusout=null;container.onpointerdown=null;container.onpointerup=null;
  }
  schedule();
 }
 function setSlides(data){
  if(!Array.isArray(data))return;
  const seen=new Set();
  slides=data.filter(item=>{
   if(!item||typeof item!=='object')return false;
   const key=String(item.id);
   if(seen.has(key))return false;
   seen.add(key);
   return isSafe(item.desktop)||isSafe(item.mobile);
  }).slice(0,6);
  render();
 }
 document.addEventListener('visibilitychange',()=>{if(document.hidden)stop();else schedule();});
 if(mq.addEventListener)mq.addEventListener('change',render);
 else if(mq.addListener)mq.addListener(render);
 if(reduce.addEventListener)reduce.addEventListener('change',schedule);
 window.InYouHeroCarousel={setSlides};
})();
