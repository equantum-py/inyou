/* InYou Studio - selector, vista previa y carga segura de imágenes de tienda. */
(()=>{
 'use strict';
 // Mantener el archivo por debajo del límite de PHP en producción (2 MB).
 const maxBytes=10*1024*1024;
 const safeUploadBytes=1_700_000;
 document.querySelectorAll('.site-upload-form').forEach(form=>{
  const input=form.querySelector('input[type="file"]');
  const button=form.querySelector('button[type="submit"]');
  const preview=form.closest('.site-image-device').querySelector('.site-image-preview');
  const feedback=form.querySelector('.site-file-feedback');
  const notice=form.querySelector('.site-upload-notice');
  const original=preview.innerHTML;
  let url=null;
  let busy=false;
  button.disabled=true;
  function say(message,isError=false){
   feedback.textContent=message;
   feedback.classList.toggle('error',isError);
  }
  function clearPreview(){
   if(url){URL.revokeObjectURL(url);url=null;}
   preview.innerHTML=original;
  }
  function render(file){
   clearPreview();
   if(!file)return;
   url=URL.createObjectURL(file);
   const img=document.createElement('img');
   img.src=url;img.alt='Vista previa de '+file.name;
   preview.replaceChildren(img);
  }
  const allowed=['image/jpeg','image/png','image/webp'];
  input.addEventListener('change',()=>{
   const file=input.files&&input.files[0];
   notice.textContent='';
   if(!file){button.disabled=true;clearPreview();say('Seleccioná una imagen antes de guardar.');return;}
   if(!allowed.includes(file.type)){input.value='';button.disabled=true;clearPreview();say('Formato no permitido: usá JPG, PNG o WEBP.',true);return;}
   button.disabled=false;
   render(file);
   say(file.name+' · '+(file.size/1024/1024).toFixed(2)+' MB'+(file.size>safeUploadBytes?' · se optimizará antes de guardar':' · lista para guardar'));
  });
  async function optimize(file){
   if(file.size<=safeUploadBytes)return file;
   if(!('createImageBitmap' in window))throw new Error('Este navegador no permite optimizar la imagen. Usá un archivo menor de 1,7 MB.');
   const bitmap=await createImageBitmap(file);
   try{
    if(bitmap.width*bitmap.height>25000000)throw new Error('La imagen supera 25 megapíxeles. Reducí su resolución.');
    const canvas=document.createElement('canvas');
    // Mantener las dimensiones originales para conservar el texto del banner.
    canvas.width=bitmap.width;canvas.height=bitmap.height;
    const context=canvas.getContext('2d');
    if(!context)throw new Error('No se pudo procesar la imagen en este navegador.');
    context.drawImage(bitmap,0,0);
    let blob=null;
    for(const quality of [.92,.85,.75,.65,.52,.42]){
     blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/webp',quality));
     if(blob&&blob.size<=safeUploadBytes)break;
    }
    // Solo si no alcanza la compresión, reducir dimensiones proporcionalmente.
    let width=bitmap.width,height=bitmap.height;
    for(let pass=0;blob&&blob.size>safeUploadBytes&&pass<4;pass++){
     width=Math.max(1,Math.round(width*.85));
     height=Math.max(1,Math.round(height*.85));
     canvas.width=width;canvas.height=height;
     canvas.getContext('2d').drawImage(bitmap,0,0,width,height);
     blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/webp',.8));
    }
    canvas.width=0;canvas.height=0;
    if(!blob||blob.size>safeUploadBytes)throw new Error('La imagen sigue pesando demasiado. Reducí el archivo a menos de 1,7 MB.');
    const filename=file.name.replace(/\.[^.]+$/,'')+'.webp';
    return new File([blob],filename,{type:'image/webp',lastModified:Date.now()});
   }finally{bitmap.close();}
  }
  form.addEventListener('submit',async event=>{
   if(busy){event.preventDefault();return;}
   const file=input.files&&input.files[0];
   if(!file){event.preventDefault();say('Elegí una imagen antes de guardar.',true);input.focus();return;}
   if(!allowed.includes(file.type)){event.preventDefault();say('Formato no permitido.',true);return;}
   if(file.size>safeUploadBytes){
    event.preventDefault();
    busy=true;button.disabled=true;
    const previous=button.textContent;
    button.textContent='Optimizando imagen…';
    try{
     const optimized=await optimize(file);
     const dt=new DataTransfer();dt.items.add(optimized);
     input.files=dt.files;
     say('Imagen optimizada a '+(optimized.size/1024/1024).toFixed(2)+' MB. Guardando…');
     button.textContent='Guardando…';
     form.submit();
    }catch(e){
     say(e.message||'No se pudo optimizar la imagen.',true);
     busy=false;button.disabled=false;button.textContent=previous;
    }
   }else{
    busy=true;button.disabled=true;button.textContent='Guardando…';
    say('Subiendo la imagen. No cierres esta página.');
   }
  });
  window.addEventListener('pagehide',()=>{if(url)URL.revokeObjectURL(url);});
 });
})();