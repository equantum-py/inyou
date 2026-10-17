import {normalizeCatalog,variantOf,cleanCart,updateQuantity,cartLines,cartTotal,money,whatsappMessage,whatsappUrl,canBuyVariant,unitPrice,productPriceRange} from './store-core.mjs';

const qs=(sel)=>document.querySelector(sel);
const node=(tag,cls,text)=>{const el=document.createElement(tag);if(cls)el.className=cls;if(text!==undefined)el.textContent=text;return el;};
const demo = new URLSearchParams(location.search).get('demo') === '1';
let catalog=[];
let cart=[];
let config={whatsappPhone:'',whatsappEnabled:false,storeReady:false};
let activeFilter='Todas';
let activeType='Todos';
let activeAudience='Todos';
const TYPE_LABEL={remera:'Remera',kepi:'Kepi'};
let toastTimer;
let lastFocus;

function toast(message){const el=qs('#toast');el.textContent=message;el.classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(()=>el.classList.remove('show'),3500);}
function readCart(){try{return JSON.parse(localStorage.getItem('inyou-v1-cart')||'[]')}catch{return []}}
function writeCart(){try{localStorage.setItem('inyou-v1-cart',JSON.stringify(cart))}catch{/* Private browsing may restrict storage. */}}
function setFilter(filter){activeFilter=filter;for(const btn of document.querySelectorAll('.filter')){const active=btn.dataset.filter===filter;btn.classList.toggle('active',active);btn.setAttribute('aria-pressed',String(active));}renderCatalog();}

function productCard(product){
  const card=node('article','product-card');
  const art=node('div','product-image');
  if(product.image){
    const image=node('img');
    image.src=product.image;
    image.alt=product.images.find(img=>img.src===product.image)?.alt || `Fotografía del producto: ${product.name}`;
    image.loading='lazy';
    image.decoding='async';
    image.addEventListener('error',()=>{image.remove();art.append(illustration());});
    art.append(image);
  }else art.append(illustration());
  art.append(node('span','product-tag',product.collection));

  const facts=[TYPE_LABEL[product.tipo]||'Remera',product.para,product.fit].filter(Boolean).join(' · ');
  const price=node('div','product-price');
  const range=productPriceRange(product);
  price.textContent=range.min===range.max?money(range.min):`Desde ${money(range.min)}`;
  card.append(art,node('h3','product-title',product.name),node('div','product-meta',product.description),node('div','product-facts',facts),price);

  const ctr=node('div','product-controls');
  const selector=node('select','variant-select');
  selector.setAttribute('aria-label',`Seleccionar talla y color de ${product.name}`);
  const available=product.variants.filter(canBuyVariant);

  if(!available.length){
    const opt=node('option','','No disponible');opt.value='';selector.append(opt);selector.disabled=true;
  }else{
    for(const v of available){
      const availability=v.mode==='stock'?`${v.stock} en stock`:(v.leadTime?`Bajo pedido · ${v.leadTime}`:'Bajo pedido');
      const variantPrice=unitPrice(product,v);
      const opt=node('option',null,`${v.size} · ${v.color} · ${availability} · ${money(variantPrice)}`);
      opt.value=v.sku;
      selector.append(opt);
    }
    const syncPrice=()=>{const selected=available.find(v=>v.sku===selector.value);if(selected)price.textContent=money(unitPrice(product,selected));};
    selector.addEventListener('change',syncPrice);
    syncPrice();
  }

  const add=node('button','product-add','Agregar al carrito +');add.type='button';add.disabled=!available.length;
  add.addEventListener('click',()=>{const sku=selector.value;const prev=cart.find(x=>x.sku===sku)?.qty||0;try{cart=updateQuantity(cart,sku,prev+1,catalog);writeCart();renderCart();toast('Producto agregado al carrito');}catch(e){toast(e.message)}});
  ctr.append(selector,add);card.append(ctr);return card;
}

function illustration(){const shirt=node('div','t-shirt');shirt.setAttribute('role','img');shirt.setAttribute('aria-label','Ilustración temporal, no fotografía de una prenda real');const print=node('div','tee-print');print.append(node('span','flower-icon','✳'),node('span','tee-print-label','in You.'));shirt.append(print);return shirt;}
function setChip(groupSelector,attr,value){for(const btn of document.querySelectorAll(`${groupSelector} .chip`)){const active=btn.dataset[attr]===value;btn.classList.toggle('active',active);btn.setAttribute('aria-pressed',String(active));}}
function setType(value){activeType=value;setChip('#typeFilters','type',value);renderCatalog();}
function setAudience(value){activeAudience=value;setChip('#audienceFilters','audience',value);renderCatalog();}
function matchesAudience(product){if(activeAudience==='Todos')return true;if(activeAudience==='Unisex')return product.para==='Unisex';return product.para===activeAudience||product.para==='Unisex';}
function matchesType(product){return activeType==='Todos'||product.tipo===activeType;}
function matchesCollection(product){return activeFilter==='Todas'||product.collection===activeFilter;}
function renderCatalog(){const grid=qs('#productGrid');grid.replaceChildren();const items=catalog.filter(p=>matchesCollection(p)&&matchesType(p)&&matchesAudience(p));for(const p of items)grid.append(productCard(p));qs('#catalogEmpty').hidden=items.length>0;}
function closeCart(){qs('#cartDrawer').classList.remove('open');qs('#cartDrawer').inert=true;qs('#cartDrawer').setAttribute('aria-hidden','true');qs('#backdrop').hidden=true;document.body.style.overflow='';if(lastFocus?.isConnected)lastFocus.focus();}
function openCart(){lastFocus=document.activeElement;renderCart();qs('#cartDrawer').inert=false;qs('#cartDrawer').classList.add('open');qs('#cartDrawer').setAttribute('aria-hidden','false');qs('#backdrop').hidden=false;document.body.style.overflow='hidden';qs('#closeCart').focus();}
function renderCart(){const items=qs('#cartItems');items.replaceChildren();const lines=cartLines(cart,catalog);const count=lines.reduce((n,l)=>n+l.qty,0);qs('#cartCount').textContent=count;qs('#cartSubtotal').textContent=money(cartTotal(cart,catalog));
  if(!lines.length)items.append(node('p','cart-empty','Tu carrito está vacío. Explorá las colecciones y agregá tus favoritos.'));
  for(const l of lines){const line=node('div','cart-line');const detail=node('div');const availability=l.variant.mode==='stock'?'Stock sujeto a confirmación':`Bajo pedido${l.variant.leadTime?` · ${l.variant.leadTime}`:''}`;detail.append(node('h4','',l.product.name),node('p','',`${l.variant.size} · ${l.variant.color} · ${availability}`),node('strong','',`${money(l.unitPrice)} c/u · ${money(l.subtotal)}`));
    const qty=node('div','qty-controls');const minus=node('button','','−');minus.type='button';minus.setAttribute('aria-label',`Quitar una unidad de ${l.product.name}`);const plus=node('button','','+');plus.type='button';plus.setAttribute('aria-label',`Agregar una unidad de ${l.product.name}`);
    const value=node('span','',String(l.qty));minus.addEventListener('click',()=>changeQty(l.sku,l.qty-1));plus.addEventListener('click',()=>changeQty(l.sku,l.qty+1));qty.append(minus,value,plus);line.append(detail,qty);items.append(line);
  }
  const enabled=!demo && Boolean(config.storeReady && config.whatsappEnabled && /^\d{10,15}$/.test(config.whatsappPhone)) && lines.length>0;
  qs('#checkout').disabled=!enabled;
  qs('#checkoutReason').textContent=demo?'Modo demostración: no se envían pedidos ni se habilitan ventas.':!config.storeReady?'El sitio aún no está habilitado para ventas.':!config.whatsappEnabled || !config.whatsappPhone?'WhatsApp Business pendiente de configuración.':!lines.length?'Agregá un producto para comenzar.':'';
}
function changeQty(sku,n){try{cart=updateQuantity(cart,sku,n,catalog);writeCart();renderCart();}catch(e){toast(e.message)}}
function handleCheckout(){if(demo || !config.storeReady || !config.whatsappEnabled){toast('El sitio todavía no está habilitado para ventas.');return;}try{const url=whatsappUrl(config.whatsappPhone,whatsappMessage(cart,catalog));window.open(url,'_blank','noopener,noreferrer');}catch(e){toast(e.message)}}
async function load(){
  qs('#year').textContent=new Date().getFullYear();
  if(demo){qs('#demoBanner').hidden=false;qs('#notice').textContent='VISTA DEMO · No es un catálogo real · Sin ventas habilitadas';}
  try{
    const catalogUrl=demo?'./data/catalog.demo.json':'./api/v1/products.php';
    const configUrl=demo?'./data/config.json':'./api/v1/config.php';
    const [configRes,catalogRes]=await Promise.all([
      fetch(configUrl,{cache:'no-store',headers:{'Accept':'application/json'}}),
      fetch(catalogUrl,{cache:'no-store',headers:{'Accept':'application/json'}})
    ]);
    if(!configRes.ok)throw new Error('No fue posible leer la configuración de la tienda.');
    if(!catalogRes.ok)throw new Error(`No fue posible cargar el catálogo (${catalogRes.status}).`);
    config=await configRes.json();
    catalog=normalizeCatalog(await catalogRes.json());
    cart=cleanCart(readCart(),catalog);
    writeCart();
  }catch(e){console.error('Error de catálogo:',e);toast('No se pudo cargar el catálogo en este momento.');catalog=[];cart=[];}
  renderCatalog();renderCart();
}
qs('#openCart').addEventListener('click',openCart);qs('#closeCart').addEventListener('click',closeCart);qs('#backdrop').addEventListener('click',closeCart);qs('#checkout').addEventListener('click',handleCheckout);
qs('#filters').addEventListener('click',(e)=>{const button=e.target.closest('.filter');if(button)setFilter(button.dataset.filter)});
qs('#typeFilters').addEventListener('click',(e)=>{const button=e.target.closest('.chip');if(button)setType(button.dataset.type)});
qs('#audienceFilters').addEventListener('click',(e)=>{const button=e.target.closest('.chip');if(button)setAudience(button.dataset.audience)});
for(const card of document.querySelectorAll('[data-filter].collection-card'))card.addEventListener('click',()=>setFilter(card.dataset.filter));
document.addEventListener('keydown',(e)=>{if(e.key==='Escape' && qs('#cartDrawer').classList.contains('open'))closeCart();if(e.key==='Tab' && qs('#cartDrawer').classList.contains('open')){const focusable=Array.from(qs('#cartDrawer').querySelectorAll('button:not(:disabled),a[href],input:not(:disabled),select:not(:disabled)'));if(!focusable.length)return;const first=focusable[0],last=focusable.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus()}}});
load();
