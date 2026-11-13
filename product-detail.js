import {normalizeCatalog,canBuyVariant,updateQuantity,cleanCart,money,unitPrice} from './store-core.mjs';
const $=id=>document.getElementById(id);
const params=new URLSearchParams(location.search);
let product=null,photos=[],photoIndex=0,currentVariant=null,cart=[];
function selectOptions(el,values,selected){el.replaceChildren();for(const x of values)el.add(new Option(x,x));if(selected&&values.includes(selected))el.value=selected;}
function uniq(values){return [...new Set(values)];}
function showPhoto(index){
 if(!photos.length)return;
 photoIndex=(index+photos.length)%photos.length;
 const photo=photos[photoIndex];$('mainImage').src=photo.src;$('mainImage').alt=photo.alt||product.name+': fotografía '+(photoIndex+1);
 $('imageCounter').textContent=(photoIndex+1)+' / '+photos.length;
 $('photoThumbnails').querySelectorAll('button').forEach((b,i)=>{b.classList.toggle('selected',i===photoIndex);b.setAttribute('aria-pressed',String(i===photoIndex));});
}
function buildGallery(){
 photos=product.images||[];
 const container=$('photoThumbnails');container.replaceChildren();
 if(!photos.length){$('mainImage').hidden=true;$('emptyImage').hidden=false;$('previousPhoto').hidden=$('nextPhoto').hidden=true;return;}
 photos.forEach((photo,i)=>{const button=document.createElement('button');button.type='button';button.className='pdp-thumb';button.setAttribute('aria-label','Mostrar fotografía '+(i+1)+' de '+photos.length);const image=new Image();image.src=photo.src;image.alt='';image.loading='lazy';button.append(image);button.addEventListener('click',()=>showPhoto(i));container.append(button);});
 $('previousPhoto').hidden=$('nextPhoto').hidden=photos.length<2;
 showPhoto(0);
}
function updateVariant(){
 const color=$('productColor').value,size=$('productSize').value;
 const available=(product.variants||[]).filter(canBuyVariant);
 currentVariant=available.find(v=>v.color===color&&v.size===size)||null;
 $('addToCart').disabled=!currentVariant;
 $('productPrice').textContent=money(currentVariant?unitPrice(product,currentVariant):product.priceGs);
 $('availability').textContent=currentVariant
  ?currentVariant.mode==='bajo_pedido'?'Disponible bajo pedido'+(currentVariant.leadTime?' · '+currentVariant.leadTime:''):'Disponible · '+currentVariant.stock+' unidad'+(currentVariant.stock===1?'':'es')
  :'Esta combinación no está disponible.';
}
function changeColor(){
 const available=product.variants.filter(canBuyVariant);const color=$('productColor').value;
 const sizes=uniq(available.filter(v=>v.color===color).map(v=>v.size));
 selectOptions($('productSize'),sizes,$('productSize').value);updateVariant();
}
function buildVariants(){
 const available=product.variants.filter(canBuyVariant);
 if(!available.length){$('productColor').disabled=$('productSize').disabled=true;$('availability').textContent='Sin stock disponible por el momento.';$('productPrice').textContent=money(product.priceGs);return;}
 selectOptions($('productColor'),uniq(available.map(v=>v.color)));
 changeColor();$('productColor').addEventListener('change',changeColor);$('productSize').addEventListener('change',updateVariant);
}
function readCart(){try{const r=JSON.parse(localStorage.getItem('inyou-v1-cart')||'[]');return Array.isArray(r)?r:[]}catch{return [];}}
function countCart(){let total=0;for(const line of cart)total+=Math.max(0,Number(line.qty)||0);$('cartQuantity').textContent=String(total);}
function addToCart(){
 if(!currentVariant)return;
 try{const old=cart.find(x=>x.sku===currentVariant.sku)?.qty||0;cart=updateQuantity(cart,currentVariant.sku,old+1,[product]);localStorage.setItem('inyou-v1-cart',JSON.stringify(cart));countCart();$('actionMessage').textContent='✓ Agregada a tu bolsa. Podés seguir mirando las fotos o ver tu bolsa.';}catch(e){$('actionMessage').textContent=e.message||'No se pudo agregar.';}
}
async function load(){
 const slug=params.get('id')||'';
 if(!/^[a-z0-9-]{3,60}$/.test(slug)){$('productStatus').textContent='Producto no encontrado. Volvé a la tienda.';return;}
 try{
 const res=await fetch('/api/v1/products.php',{cache:'no-store',headers:{Accept:'application/json'}});
 if(!res.ok)throw new Error('No pudimos cargar el catálogo.');
 const all=normalizeCatalog(await res.json());product=all.find(p=>p.id===slug);
 if(!product){$('productStatus').textContent='Este producto no está publicado o ya no está disponible.';return;}
 cart=cleanCart(readCart(),all);countCart();
 document.title=product.name+' · InYou';$('breadcrumbProduct').textContent=product.name;$('productName').textContent=product.name;
 $('productCollection').textContent=product.collection;
 $('productFacts').textContent=[product.tipo==='kepi'?'Kepi':'Remera',product.para,product.fit].filter(Boolean).join(' · ');
 $('productDescription').textContent=product.description||'Conocé los colores y talles de esta prenda.';
 $('productMeta').textContent=[product.collection,product.para,product.fit].filter(Boolean).join(' · ');
 buildGallery();buildVariants();
 $('productStatus').hidden=true;$('productDetail').hidden=false;
 }catch(e){$('productStatus').textContent='No fue posible cargar el producto. Actualizá la página o volvé a la tienda.';console.error(e);}
}
$('previousPhoto').addEventListener('click',()=>showPhoto(photoIndex-1));
$('nextPhoto').addEventListener('click',()=>showPhoto(photoIndex+1));
$('addToCart').addEventListener('click',addToCart);
document.addEventListener('keydown',e=>{if(e.key==='ArrowLeft'&&!e.target.matches('input,select,textarea'))showPhoto(photoIndex-1);if(e.key==='ArrowRight'&&!e.target.matches('input,select,textarea'))showPhoto(photoIndex+1);});
load();