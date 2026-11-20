
/* InYou · creador de variantes para ropa: talles × colores; sin llamadas remotas. */
(function(){
 'use strict';
 const form=document.getElementById('studio-create-form');
 if(!form)return;
 const sizes=document.getElementById('studio-size-options');
 const colorBox=document.getElementById('studio-color-options');
 const customColor=document.getElementById('studio-custom-color');
 const addColor=document.getElementById('studio-add-color');
 const list=document.getElementById('studio-new-variants');
 const template=document.getElementById('studio-variant-template');
 const counter=document.getElementById('studio-variant-count');
 const empty=document.getElementById('studio-variants-empty');
 const bulk=document.getElementById('studio-bulk-qty');
 const apply=document.getElementById('studio-apply-stock');
 const photos=document.getElementById('new-images');
 const preview=document.getElementById('studio-image-preview');
 const status=document.getElementById('new-status');
 const help=document.getElementById('studio-publish-help');
 const colors=['Negro','Blanco','Rosa','Azul','Rojo','Verde','Gris','Beige','Marrón'];
 const selectedColors=new Set();
 const remembered=new Map();
 let blobUrls=[];
 function key(size,color){return String(size)+'|'+color.toLocaleLowerCase('es');}
 function snapshot(){
  for(const row of list.querySelectorAll('.studio-generated-line')){
   remembered.set(key(row.dataset.size,row.dataset.color),{
    stock:row.querySelector('[data-field="stock"]').value,
    price:row.querySelector('[data-field="price"]').value,
    active:row.querySelector('[data-field="active"]').checked
   });
  }
 }
 function chosenSizes(){return [...sizes.querySelectorAll('input:checked')].map(input=>({id:input.value,label:input.dataset.label}));}
 function combinations(){
  const options=chosenSizes(),result=[];
  for(const color of selectedColors)for(const size of options)result.push({size,color});
  return result;
 }
 function renderChoices(){
  colorBox.replaceChildren();
  for(const color of colors){
   const button=document.createElement('button');
   button.type='button';
   button.className='studio-color-chip'+(selectedColors.has(color)?' selected':'');
   button.setAttribute('aria-pressed',selectedColors.has(color)?'true':'false');
   button.textContent=color;
   button.addEventListener('click',()=>{
    snapshot();
    const on=selectedColors.has(color);
    if(on){selectedColors.delete(color);}else{selectedColors.add(color);}
    if(combinations().length>50){
     selectedColors.delete(color);
     alert('Máximo 50 combinaciones por prenda. Elegí menos talles o colores.');
    }
    renderChoices();renderVariants();
   });
   colorBox.append(button);
  }
 }
 function renderVariants(){
  const combinationsList=combinations();
  list.replaceChildren();
  empty.hidden=combinationsList.length!==0;
  counter.textContent=combinationsList.length+' combinaciones generadas'+(combinationsList.length?' · editá el stock de cada una':' · seleccioná talles y colores');
  combinationsList.forEach(({size,color},index)=>{
   const fragment=template.content.cloneNode(true);
   const row=fragment.querySelector('.studio-generated-line');
   const data=remembered.get(key(size.id,color))||{stock:'0',price:'',active:true};
   row.dataset.size=size.id;row.dataset.color=color;
   row.querySelector('[data-variant-label]').textContent=color+' · '+size.label;
   row.querySelector('[data-variant-description]').textContent='Usa el precio base, salvo que ingreses uno especial';
   for(const field of row.querySelectorAll('[data-field]')){
    const f=field.dataset.field;
    field.name='new_variants['+index+']['+f+']';
    if(f==='size_id')field.value=size.id;
    else if(f==='color')field.value=color;
    else if(f==='stock')field.value=data.stock;
    else if(f==='price')field.value=data.price;
    else if(f==='active')field.checked=data.active;
   }
   list.append(row);
  });
 }
 sizes.addEventListener('change',e=>{
  if(!e.target.matches('input[type="checkbox"]'))return;
  snapshot();
  if(combinations().length>50){
   e.target.checked=false;
   alert('Máximo 50 combinaciones. Elegí menos talles o colores.');
  }
  renderVariants();
 });
 function addCustom(){
  const color=customColor.value.trim().replace(/\s+/g,' ');
  if(!color||color.length>45){customColor.focus();return;}
  const duplicate=colors.find(v=>v.toLocaleLowerCase('es')===color.toLocaleLowerCase('es'));
  if(duplicate){snapshot();const already=selectedColors.has(duplicate);selectedColors.add(duplicate);if(!already&&combinations().length>50){selectedColors.delete(duplicate);alert('Máximo 50 combinaciones por prenda.');}else{customColor.value='';}renderChoices();renderVariants();return;}
  if(colors.length>=30){alert('Máximo 30 colores disponibles en el selector.');return;}
  snapshot();
  colors.push(color);selectedColors.add(color);
  if(combinations().length>50){
   selectedColors.delete(color);colors.pop();
   alert('Máximo 50 combinaciones. Seleccioná menos talles o colores.');
  }else{customColor.value='';}
  renderChoices();renderVariants();
 }
 addColor.addEventListener('click',addCustom);
 customColor.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();addCustom();}});
 apply.addEventListener('click',()=>{
  const n=bulk.value.trim();
  if(!/^\d{1,8}$/.test(n)){alert('Ingresá un stock válido.');bulk.focus();return;}
  list.querySelectorAll('[data-field="stock"]').forEach(input=>{input.value=String(Number(n));});
  snapshot();
 });
 renderChoices();renderVariants();

 function clearPreview(){blobUrls.forEach(url=>URL.revokeObjectURL(url));blobUrls=[];preview.replaceChildren();}
 photos.addEventListener('change',()=>{
  clearPreview();
  const files=Array.from(photos.files||[]);
  if(files.length>8){photos.value='';alert('Seleccioná como máximo 8 fotografías.');return;}
  for(const file of files){
   if(!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>10*1024*1024){
    photos.value='';clearPreview();alert('Solo JPG, PNG o WEBP, con máximo 10 MB por foto.');return;
   }
  }
  files.forEach((file,index)=>{
   const figure=document.createElement('figure');
   const image=document.createElement('img');
   const url=URL.createObjectURL(file);
   blobUrls.push(url);image.src=url;image.alt=file.name;
   const caption=document.createElement('figcaption');caption.textContent=index===0?'Principal':'Foto '+(index+1);
   figure.append(image,caption);preview.append(figure);
  });
 });
 status.addEventListener('change',()=>{
  help.textContent=status.value==='active'
   ?'Para publicar: al menos una fotografía y una combinación disponible con stock.'
   :'El borrador puede guardarse aunque todavía falten fotografías o stock.';
 });
 form.addEventListener('submit',event=>{
  if(status.value!=='active')return;
  const hasPhoto=photos.files&&photos.files.length>0;
  const sellable=[...list.querySelectorAll('.studio-generated-line')].some(row=>
   Number(row.querySelector('[data-field="stock"]').value)>0&&
   row.querySelector('[data-field="active"]').checked
  );
  if(!hasPhoto||!sellable){
   event.preventDefault();
   alert('Para publicar agregá una foto y al menos una combinación de talle y color con stock. O guardá como borrador.');
  }
 });
})();
