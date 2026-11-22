/* InYou Studio - selector, vista previa y carga segura de imágenes de tienda. */
(()=>{
 'use strict';
 const maxBytes=10*1024*1024;
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
   say(file.name+' · '+(file.size/1024/1024).toFixed(2)+' MB'+(file.size>maxBytes?' · se optimizará antes de guardar':' · lista para guardar'));
  });
  async function optimize(file){
   if(file.size<=maxBytes)return file;
   const img=await createImageBitmap(file);
   if(img.width*img.height>25000000){img.close();throw new Error('La fotografía supera los 25 megapíxeles permitidos.');}
   const canvas=document.createElement('canvas');
   canvas.width=img.width;canvas.height=img.height;
   const context=canvas.getContext('2d');
   if(!context){img.close();throw new Error('El navegador no puede procesar esta imagen.');}
   context.drawImage(img,0,0);img.close();
   let blob=null;
   for(const quality of [.93,.86,.76,.65]){
    blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/webp',quality));
    if(blob&&blob.size<=maxBytes)break;
   }
   canvas.width=0;canvas.height=0;
   if(!blob||blob.size>maxBytes)throw new Error('No fue posible optimizar la imagen a menos de 10 MB. Reducí su peso.');
   const name=file.name.replace(/\.[^.]+$/,'')+'.webp';
   return new File([blob],name,{type:'image/webp',lastModified:Date.now()});
  }
  form.addEventListener('submit',async event=>{
   if(busy){event.preventDefault();return;}
   const file=input.files&&input.files[0];
   if(!file){event.preventDefault();say('Elegí una imagen antes de guardar.',true);input.focus();return;}
   if(!allowed.includes(file.type)){event.preventDefault();say('Formato no permitido.',true);return;}
   if(file.size>maxBytes){
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