import {normalizeCatalog,cleanCart,updateQuantity,cartLines,cartTotal,money,whatsappMessage,whatsappUrl,canBuyVariant,unitPrice,productPriceRange} from './store-core.mjs';

const qs=(sel)=>document.querySelector(sel);
const qsa=(sel)=>Array.from(document.querySelectorAll(sel));
const node=(tag,cls,text)=>{const el=document.createElement(tag);if(cls)el.className=cls;if(text!==undefined)el.textContent=text;return el;};
const demo=new URLSearchParams(location.search).get('demo')==='1';
let catalog=[];
let cart=[];
let favorites=[];
let config={whatsappPhone:'',whatsappEnabled:false,storeReady:false};
let activeFilter='Todas';
let activeType='Todos';
let activeAudience='Todos';
const TYPE_LABEL={remera:'Remera',kepi:'Kepi'};
let toastTimer;
let lastFocus;

function toast(message){const el=qs('#toast');if(!el)return;el.textContent=message;el.classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(()=>el.classList.remove('show'),2600);}
function readJson(key,fallback=[]){try{return JSON.parse(localStorage.getItem(key)||JSON.stringify(fallback))}catch{return fallback}}
function writeJson(key,value){try{localStorage.setItem(key,JSON.stringify(value))}catch{}}
function readCart(){return readJson('inyou-v1-cart',[])}
function writeCart(){writeJson('inyou-v1-cart',cart)}
function readFavorites(){const list=readJson('inyou-v1-favorites',[]);return Array.isArray(list)?list:[]}
function writeFavorites(){writeJson('inyou-v1-favorites',favorites);renderFavoriteCount();}
function productKey(product){return String(product.id??product.slug??product.sku??product.name)}
function isFavorite(product){return favorites.includes(productKey(product))}
function toggleFavorite(product){const key=productKey(product);if(isFavorite(product)){favorites=favorites.filter(x=>x!==key);toast('Quitado de favoritos');}else{favorites=[...favorites,key];toast('Guardado en favoritos');}writeFavorites();renderCatalog();renderFavorites();}
function renderFavoriteCount(){const el=qs('#favoriteCount');if(el)el.textContent=String(favorites.length)}

function illustration(){const shirt=node('div','fashion-silhouette');shirt.style.position='relative';shirt.style.width='58%';shirt.style.height='72%';shirt.style.background='#f4efe5';return shirt;}

function productCard(product,{favoriteOnly=false}={}){
  const card=node('article',favoriteOnly?'favorite-card product-card':'product-card');
  const art=node('div','product-image');
  if(product.image){
    const image=node('img');image.src=product.image;image.alt=product.images?.find(img=>img.src===product.image)?.alt||`Fotografía de ${product.name}`;image.loading='lazy';image.decoding='async';image.addEventListener('error',()=>{image.remove();art.append(illustration())});art.append(image);
  }else art.append(illustration());
  if(product.collection)art.append(node('span','product-tag',product.collection));
  const fav=node('button',`favorite-btn${isFavorite(product)?' active':''}`,isFavorite(product)?'♥':'♡');fav.type='button';fav.setAttribute('aria-label',isFavorite(product)?`Quitar ${product.name} de favoritos`:`Guardar ${product.name} en favoritos`);fav.addEventListener('click',(e)=>{e.stopPropagation();toggleFavorite(product)});art.append(fav);

  const facts=[TYPE_LABEL[product.tipo]||'Remera',product.para,product.fit].filter(Boolean).join(' · ');
  const range=productPriceRange(product);const price=node('div','product-price',range.min===range.max?money(range.min):`Desde ${money(range.min)}`);
  card.append(art,node('h3','product-title',product.name),node('div','product-meta',product.description||''),node('div','product-facts',facts),price);

  if(!favoriteOnly){
    const ctr=node('div','product-controls');const selector=node('select','variant-select');const available=product.variants.filter(canBuyVariant);
    if(!available.length){const opt=node('option','','No disponible');selector.append(opt);selector.disabled=true;}else{for(const v of available){const opt=node('option','',`${v.size} · ${v.color} · ${money(unitPrice(product,v))}`);opt.value=v.sku;selector.append(opt)}}
    const add=node('button','product-add','Agregar al carrito');add.type='button';add.disabled=!available.length;add.addEventListener('click',()=>{const sku=selector.value;const prev=cart.find(x=>x.sku===sku)?.qty||0;try{cart=updateQuantity(cart,sku,prev+1,catalog);writeCart();renderCart();toast('Producto agregado al carrito')}catch(e){toast(e.message)}});ctr.append(selector,add);card.append(ctr);
  }
  return card;
}

function matchesAudience(product){if(activeAudience==='Todos')return true;if(activeAudience==='Unisex')return product.para==='Unisex';return product.para===activeAudience||product.para==='Unisex'}
function matchesType(product){return activeType==='Todos'||product.tipo===activeType}
function matchesCollection(product){return activeFilter==='Todas'||product.collection===activeFilter}
function filteredCatalog(){return catalog.filter(p=>matchesCollection(p)&&matchesType(p)&&matchesAudience(p))}
function renderCatalog(){const grid=qs('#productGrid');if(!grid)return;grid.replaceChildren();const items=filteredCatalog();for(const p of items)grid.append(productCard(p));const empty=qs('#catalogEmpty');if(empty)empty.hidden=items.length>0;syncFilterStates();}
function syncFilterStates(){qsa('.filter').forEach(b=>b.classList.toggle('active',b.dataset.filter===activeFilter));qsa('#typeFilters .chip').forEach(b=>b.classList.toggle('active',b.dataset.type===activeType));qsa('#audienceFilters .chip').forEach(b=>b.classList.toggle('active',b.dataset.audience===activeAudience));}
function setFilter(value){activeFilter=value;renderCatalog();scrollToProducts()}
function setType(value){activeType=value;renderCatalog();scrollToProducts()}
function setAudience(value){activeAudience=value;renderCatalog();scrollToProducts()}
function scrollToProducts(){qs('#novedades')?.scrollIntoView({behavior:'smooth',block:'start'})}

function closeCart(){const drawer=qs('#cartDrawer');if(!drawer)return;drawer.classList.remove('open');drawer.inert=true;drawer.setAttribute('aria-hidden','true');qs('#backdrop').hidden=true;document.body.style.overflow='';if(lastFocus?.isConnected)lastFocus.focus()}
function openCart(){lastFocus=document.activeElement;renderCart();const drawer=qs('#cartDrawer');drawer.inert=false;drawer.classList.add('open');drawer.setAttribute('aria-hidden','false');qs('#backdrop').hidden=false;document.body.style.overflow='hidden';qs('#closeCart')?.focus()}
function renderCart(){const items=qs('#cartItems');if(!items)return;items.replaceChildren();const lines=cartLines(cart,catalog);const count=lines.reduce((n,l)=>n+l.qty,0);qs('#cartCount').textContent=count;qs('#cartSubtotal').textContent=money(cartTotal(cart,catalog));if(!lines.length)items.append(node('p','cart-empty','Tu carrito está vacío.'));
  for(const l of lines){const line=node('div','cart-line');const detail=node('div');detail.append(node('h4','',l.product.name),node('p','',`${l.variant.size} · ${l.variant.color}`),node('strong','',money(l.subtotal)));const qty=node('div','qty-controls');const minus=node('button','','−');const plus=node('button','','+');minus.addEventListener('click',()=>changeQty(l.sku,l.qty-1));plus.addEventListener('click',()=>changeQty(l.sku,l.qty+1));qty.append(minus,node('span','',String(l.qty)),plus);line.append(detail,qty);items.append(line)}
  const enabled=!demo&&Boolean(config.storeReady&&config.whatsappEnabled&&/^\d{10,15}$/.test(config.whatsappPhone))&&lines.length>0;qs('#checkout').disabled=!enabled;qs('#checkoutReason').textContent=demo?'Modo demostración.':!config.storeReady?'La tienda todavía no está habilitada para ventas.':!config.whatsappEnabled?'WhatsApp pendiente de configuración.':!lines.length?'Agregá un producto para comenzar.':'';
}
function changeQty(sku,n){try{cart=updateQuantity(cart,sku,n,catalog);writeCart();renderCart()}catch(e){toast(e.message)}}
function handleCheckout(){if(demo||!config.storeReady||!config.whatsappEnabled){toast('La tienda todavía no está habilitada para ventas.');return}try{window.open(whatsappUrl(config.whatsappPhone,whatsappMessage(cart,catalog)),'_blank','noopener,noreferrer')}catch(e){toast(e.message)}}

function openMenu(){qs('#mobileMenu').hidden=false;document.body.style.overflow='hidden'}
function closeMenu(){qs('#mobileMenu').hidden=true;document.body.style.overflow=''}
function openSearch(){qs('#searchPanel').hidden=false;document.body.style.overflow='hidden';setTimeout(()=>qs('#smartSearch')?.focus(),30)}
function closeSearch(){qs('#searchPanel').hidden=true;document.body.style.overflow=''}
function openFavorites(){renderFavorites();qs('#favoritesPanel').hidden=false;document.body.style.overflow='hidden'}
function closeFavorites(){qs('#favoritesPanel').hidden=true;document.body.style.overflow=''}
function renderFavorites(){const box=qs('#favoriteItems');if(!box)return;box.replaceChildren();const items=catalog.filter(isFavorite);if(!items.length){const empty=node('p','cart-empty','Todavía no guardaste ninguna prenda. Tocá ♡ en un producto para guardarlo.');empty.style.gridColumn='1/-1';box.append(empty);return}for(const p of items)box.append(productCard(p,{favoriteOnly:true}))}

function normalizeText(value){return String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase()}
function searchProducts(term){const q=normalizeText(term).trim();if(!q)return[];return catalog.map(p=>{const hay=normalizeText([p.name,p.description,p.collection,p.tipo,p.para,p.fit,...(p.variants||[]).map(v=>`${v.color} ${v.size}`)].join(' '));let score=0;if(normalizeText(p.name).startsWith(q))score+=6;if(normalizeText(p.name).includes(q))score+=4;if(normalizeText(p.collection).includes(q))score+=3;if(hay.includes(q))score+=1;return{p,score}}).filter(x=>x.score>0).sort((a,b)=>b.score-a.score).slice(0,6).map(x=>x.p)}
function renderSearch(term){const box=qs('#searchSuggestions');if(!box)return;const items=searchProducts(term);box.replaceChildren();if(!term.trim()){box.append(node('p','','Probá buscar “remera”, “floral”, “mujer” o “kepi”.'));return}if(!items.length){box.append(node('p','','No encontramos productos con esa búsqueda.'));return}for(const p of items){const row=node('button','search-result');row.type='button';let thumb;if(p.image){thumb=node('img');thumb.src=p.image;thumb.alt=''}else thumb=node('div');const text=node('div');text.append(node('strong','',p.name),node('div','product-facts',[p.collection,p.para].filter(Boolean).join(' · ')));const range=productPriceRange(p);row.append(thumb,text,node('span','',range.min===range.max?money(range.min):`Desde ${money(range.min)}`));row.addEventListener('click',()=>{activeFilter=p.collection||'Todas';activeType=p.tipo||'Todos';activeAudience=p.para||'Todos';closeSearch();renderCatalog();scrollToProducts()});box.append(row)}}

async function load(){
  qs('#year').textContent=new Date().getFullYear();favorites=readFavorites();renderFavoriteCount();if(demo){qs('#demoBanner').hidden=false;qs('#notice').textContent='VISTA DEMO · No es un catálogo real · Sin ventas habilitadas'}
  try{const catalogUrl=demo?'./data/catalog.demo.json':'./api/v1/products.php';const configUrl=demo?'./data/config.json':'./api/v1/config.php';const [configRes,catalogRes]=await Promise.all([fetch(configUrl,{cache:'no-store',headers:{Accept:'application/json'}}),fetch(catalogUrl,{cache:'no-store',headers:{Accept:'application/json'}})]);if(!configRes.ok)throw new Error('No fue posible leer la configuración.');if(!catalogRes.ok)throw new Error('No fue posible cargar el catálogo.');config=await configRes.json();catalog=normalizeCatalog(await catalogRes.json());cart=cleanCart(readCart(),catalog);writeCart()}catch(e){console.error(e);toast('No se pudo cargar el catálogo.');catalog=[];cart=[]}
  renderCatalog();renderCart();renderFavorites();
}

qs('#openCart')?.addEventListener('click',openCart);qs('#closeCart')?.addEventListener('click',closeCart);qs('#backdrop')?.addEventListener('click',closeCart);qs('#checkout')?.addEventListener('click',handleCheckout);
qs('#openMenu')?.addEventListener('click',openMenu);qs('#closeMenu')?.addEventListener('click',closeMenu);qsa('#mobileMenu a').forEach(a=>a.addEventListener('click',closeMenu));
qs('#openSearch')?.addEventListener('click',openSearch);qs('#closeSearch')?.addEventListener('click',closeSearch);qs('#smartSearch')?.addEventListener('input',e=>renderSearch(e.target.value));
qs('#openFavorites')?.addEventListener('click',openFavorites);qs('#closeFavorites')?.addEventListener('click',closeFavorites);
qs('#filters')?.addEventListener('click',e=>{const b=e.target.closest('.filter');if(b)setFilter(b.dataset.filter)});qs('#typeFilters')?.addEventListener('click',e=>{const b=e.target.closest('.chip');if(b)setType(b.dataset.type)});qs('#audienceFilters')?.addEventListener('click',e=>{const b=e.target.closest('.chip');if(b)setAudience(b.dataset.audience)});
qsa('[data-filter].collection-card').forEach(b=>b.addEventListener('click',()=>setFilter(b.dataset.filter)));qsa('[data-audience-quick]').forEach(b=>b.addEventListener('click',()=>setAudience(b.dataset.audienceQuick)));qsa('[data-type-quick]').forEach(b=>b.addEventListener('click',()=>setType(b.dataset.typeQuick)));qsa('[data-quick="Todos"]').forEach(b=>b.addEventListener('click',()=>{activeFilter='Todas';activeType='Todos';activeAudience='Todos';renderCatalog();scrollToProducts()}));qsa('[data-audience-link]').forEach(a=>a.addEventListener('click',()=>setAudience(a.dataset.audienceLink)));
document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(!qs('#mobileMenu').hidden)closeMenu();if(!qs('#searchPanel').hidden)closeSearch();if(!qs('#favoritesPanel').hidden)closeFavorites();if(qs('#cartDrawer')?.classList.contains('open'))closeCart()}});
load();