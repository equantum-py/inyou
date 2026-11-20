/* InYou Studio · Navegación adaptable y tablas operativas.
   Solo modifica presentación; no interfiere con formularios ni sesiones. */
(function(){
  'use strict';
  function init(){
    const body=document.body;
    if(!body.classList.contains('dashboard'))return;
    body.classList.add('admin-mobile-ready');
    const sidebar=document.querySelector('.sidebar');
    if(!sidebar)return;
    sidebar.id='admin-navigation';
    const nav=sidebar.querySelector('nav');
    if(nav){
      nav.setAttribute('aria-label','Secciones del administrador');
      const selected=nav.querySelector('.selected');
      if(selected)selected.setAttribute('aria-current','page');
    }
    const top=document.createElement('div');
    top.className='admin-mobile-top';
    const open=document.createElement('button');
    open.type='button';
    open.className='admin-menu-toggle';
    open.setAttribute('aria-label','Abrir menú del administrador');
    open.setAttribute('aria-controls','admin-navigation');
    open.setAttribute('aria-expanded','false');
    open.innerHTML='<span aria-hidden="true">☰</span><span>Menú</span>';
    const title=document.createElement('a');
    title.href='/admin/';
    title.className='admin-mobile-title';
    title.textContent='inYou.';
    const quick=document.createElement('a');
    quick.className='admin-mobile-quick';
    quick.href='/admin/product-workspace.php?new=1';
    quick.textContent='+ Prenda';
    top.append(open,title,quick);
    const overlay=document.createElement('button');
    overlay.className='admin-nav-overlay';
    overlay.type='button';
    overlay.setAttribute('aria-label','Cerrar menú');
    overlay.hidden=true;
    sidebar.prepend((function(){
      const close=document.createElement('button');
      close.type='button';close.className='admin-menu-close';
      close.setAttribute('aria-label','Cerrar menú');
      close.textContent='✕';
      return close;
    })());
    body.prepend(top);
    body.append(overlay);
    const closeButton=sidebar.querySelector('.admin-menu-close');
    const mq=window.matchMedia('(max-width: 1023px)');
    let returnFocus=null;
    function closeMenu(restore){
      body.classList.remove('admin-nav-open');
      open.setAttribute('aria-expanded','false');
      overlay.hidden=true;
      if(restore&&returnFocus) returnFocus.focus();
      returnFocus=null;
    }
    function openMenu(){
      if(!mq.matches)return;
      returnFocus=document.activeElement;
      body.classList.add('admin-nav-open');
      open.setAttribute('aria-expanded','true');
      overlay.hidden=false;
      const first=nav&&nav.querySelector('a');
      (first||closeButton).focus();
    }
    open.addEventListener('click',()=>{
      body.classList.contains('admin-nav-open')?closeMenu(true):openMenu();
    });
    closeButton.addEventListener('click',()=>closeMenu(true));
    overlay.addEventListener('click',()=>closeMenu(true));
    document.addEventListener('keydown',e=>{
      if(!body.classList.contains('admin-nav-open'))return;
      if(e.key==='Escape'){e.preventDefault();closeMenu(true);return;}
      if(e.key==='Tab'){
        const els=Array.from(sidebar.querySelectorAll('a,button:not([disabled])')).filter(el=>el.getClientRects().length);
        if(!els.length)return;
        const first=els[0],last=els[els.length-1];
        if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
        if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
      }
    });
    if(nav)nav.addEventListener('click',e=>{if(e.target.closest('a'))closeMenu(false);});
    if(mq.addEventListener)mq.addEventListener('change',e=>{if(!e.matches)closeMenu(false);});
    else if(mq.addListener)mq.addListener(e=>{if(!e.matches)closeMenu(false);});

    document.querySelectorAll('table').forEach(table=>{
      const headings=Array.from(table.querySelectorAll('thead th')).map(x=>x.textContent.trim());
      if(!headings.length)return;
      table.classList.add('admin-card-table');
      table.querySelectorAll('tbody tr').forEach(row=>{
        row.querySelectorAll('td').forEach((cell,index)=>{
          cell.setAttribute('data-label',headings[index]||'Detalle');
          if(index===0)cell.classList.add('admin-card-title');
          if(/acciones?|acción/i.test(headings[index]||''))cell.classList.add('admin-card-actions');
        });
        if(row.children.length===1||row.querySelector('td[colspan]'))row.classList.add('admin-card-empty');
      });
    });
    document.querySelectorAll('input:not([type="hidden"]),select,textarea').forEach(el=>el.classList.add('admin-touch-field'));
    document.querySelectorAll('.panel img,.card img,.fashion-product-card img,.photo img').forEach(img=>{img.loading='lazy';});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
