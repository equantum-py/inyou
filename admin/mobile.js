/* InYou · adaptación progresiva del panel administrativo para móvil.
   No cambia datos, permisos ni solicitudes al servidor. */
(function(){
  'use strict';
  function setup(){
    const body=document.body;
    if(!body.classList.contains('dashboard'))return;
    body.classList.add('admin-mobile-ready');

    // La navegación se puede recorrer sin que el menú corte secciones.
    const nav=document.querySelector('.sidebar nav');
    if(nav){
      nav.setAttribute('aria-label','Secciones del panel administrativo');
      const active=nav.querySelector('.selected');
      if(active){active.setAttribute('aria-current','page');}
    }

    // Tablas desktop -> tarjetas legibles en móvil sin duplicar formularios ni enlaces.
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

    // Previene zoom innecesario de Safari en inputs cuando reciben foco.
    document.querySelectorAll('input:not([type="hidden"]),select,textarea').forEach(el=>{
      el.classList.add('admin-touch-field');
    });

    // Imágenes de catálogo: no desbordan los paneles.
    document.querySelectorAll('.panel img,.card img').forEach(img=>img.loading='lazy');
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',setup,{once:true});else setup();
})();
