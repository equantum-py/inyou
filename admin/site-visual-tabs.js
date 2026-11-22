/* InYou Studio · editor de contenido visual por área, sin perder el estado del servidor. */
(()=>{
 'use strict';
 const tabs=[...document.querySelectorAll('[data-visual-tab]')];
 const carousel=document.querySelector('[data-visual-panel="banners"]');
 const grid=document.getElementById('site-visual-grid');
 const cards=[...document.querySelectorAll('.site-image-card[data-visual-group]')];
 if(!tabs.length||!carousel||!grid)return;
 const groups=new Set(['banners','categories','collections','editorial']);
 function show(group,focus=false){
  if(!groups.has(group))group='banners';
  tabs.forEach(tab=>{
   const selected=tab.dataset.visualTab===group;
   tab.classList.toggle('is-current',selected);
   if(selected)tab.setAttribute('aria-current','page');
   else tab.removeAttribute('aria-current');
  });
  carousel.hidden=group!=='banners';
  grid.hidden=group==='banners';
  cards.forEach(card=>{card.hidden=card.dataset.visualGroup!==group;});
  if(focus)document.querySelector('.site-visual-tabs')?.scrollIntoView({block:'start',behavior:'smooth'});
  try{sessionStorage.setItem('inyou-visual-tab',group);}catch{}
 }
 tabs.forEach(tab=>tab.addEventListener('click',()=>show(tab.dataset.visualTab)));
 const hash=decodeURIComponent(location.hash.replace(/^#/,''));
 const match=cards.find(card=>card.id===hash);
 let initial='banners';
 if(match)initial=match.dataset.visualGroup;
 else if(hash==='carousel'||hash.startsWith('banner-'))initial='banners';
 else {try{initial=sessionStorage.getItem('inyou-visual-tab')||'banners';}catch{}}
 show(initial);
 if(hash.startsWith('banner-')){
  const row=document.getElementById(hash);
  row?.querySelector('details.site-carousel-edit')?.setAttribute('open','');
  requestAnimationFrame(()=>row?.scrollIntoView({block:'center'}));
 }else if(match){requestAnimationFrame(()=>match.scrollIntoView({block:'start'}));}
})();
