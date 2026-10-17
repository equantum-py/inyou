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
let activeSize='Todos';
let activeColor='Todos';
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
function illustration(){return node('div','product-placeholder','Foto pendiente')}
function unique(values){return [...new Set(values.filter(Boolean))]}
function normalizeText(value){return String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase()}

function variantLabels(product){
  const available=(product.variants||[]).filter(canBuyVariant);
  return {
    sizes:unique(available.map(v=>v.size)),
    colors:unique(available.map(v=>v.color)),
    available
  };
}

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
  const info=node('div','product-info');
  info.append(node('h3','product-title',product.name),node('div','product-facts',facts),price);

  const labels=variantLabels(product);
  const variantInfo=node('div','variant-info');
  const sizeRow=node('div','variant-row');sizeRow.append(node('span','variant-label','Talles'));
  if(labels.sizes.length){for(const s of labels.sizes)sizeRow.append(node('span','variant-chip',s));}else sizeRow.append(node('span','variant-muted','Sin talles disponibles'));
  const colorRow=node('div','variant-row');colorRow.append(node('span','variant-label','Colores'));
  if(labels.colors.length){for(const c of labels.colors)colorRow.append(node('span','color-chip',c));}else colorRow.append(node('span','variant-muted','Sin colores disponibles'));
  variantInfo.append(sizeRow,colorRow);
  info.append(variantInfo);

  if(!favoriteOnly){
    const ctr=node('div','product-controls');
    const selector=node('select','variant-select');selector.setAttribute('aria-label',`Elegir variante de ${product.name}`);
    if(!labels.available.length){const opt=node('option','','No disponible');selector.append(opt);selector.disabled=true;}else{
      for(const v of labels.available){const opt=node('option','',`${v.size} · ${v.color}`);opt.value=v.sku;selector.append(opt)}
    }
    const add=node('button','product-add');add.type='button';add.disabled=!labels.available.length;add.innerHTML='<span aria-hidden="true">🛍</span><span>Agregar</span>';
    add.addEventListener('click',()=>{const sku=selector.value;const prev=cart.find(x=>x.sku===sku)?.qty||0;try{cart=updateQuantity(cart,sku,prev+1,catalog);writeCart();renderCart();toast('Producto agregado al carrito')}catch(e){toast(e.message)}});
    ctr.append(selector,add);info.append(ctr);
  }
  card.append(art,info);return card;
}

function matchesAudience(product){if(activeAudience==='Todos')return true;if(activeAudience==='Unisex')return product.para==='Unisex';return product.para===activeAudience||product.para==='Unisex'}
function matchesType(product){return activeType==='Todos'||product.tipo===activeType}
function matchesCollection(product){return activeFilter==='Todas'||product.collection===activeFilter}
function matchesSize(product){return activeSize==='Todos'||(product.variants||[]).some(v=>canBuyVariant(v)&&v.size===activeSize)}
function matchesColor(product){return activeColor==='Todos'||(product.variants||[]).some(v=>canBuyVariant(v)&&normalizeText(v.color)===normalizeText(activeColor))}
function filteredCatalog(){return catalog.filter(p=>matchesCollection(p)&&matchesType(p)&&matchesAudience(p)&&matchesSize(p)&&matchesColor(p))}
function renderCatalog(){const grid=qs('#productGrid');if(!grid)return;grid.replaceChildren();const items=filteredCatalog();for(const p of items)grid.append(productCard(p));const empty=qs('#catalogEmpty');if(empty)empty.hidden=items.length>0;syncCatalogFilters();renderFilterSummary(items.length);}
function renderFilterSummary(count){const el=qs('#activeFilterSummary');if(!el)return;const parts=[];if(activeFilter!=='Todas')parts.push(activeFilter);if(activeType!=='Todos')parts.push(TYPE_LABEL[activeType]||activeType);if(activeAudience!=='Todos')parts.push(activeAudience);if(activeSize!=='Todos')parts.push(`Talle ${activeSize}`);if(activeColor!=='Todos')parts.push(activeColor);el.textContent=parts.length?`${count} producto${count===1?'':'s'} · ${parts.join(' · ')}`:`${count} producto${count===1?'':'s'} destacado${count===1?'':'s'}.`;}
function syncCatalogFilters(){qsa('[data-type-filter]').forEach(b=>b.classList.toggle('active',b.dataset.typeFilter===activeType));const s=qs('#sizeFilter');if(s)s.value=activeSize;const c=qs('#colorFilter');if(c)c.value=activeColor;}
function setFilter(value){activeFilter=value;renderCatalog();scrollToProducts()}
function setType(value){activeType=value;renderCatalog();scrollToProducts()}
function setAudience(value){activeAudience=value;renderCatalog();scrollToProducts()}
function resetFilters(){activeFilter='Todas';activeType='Todos';activeAudience='Todos';activeSize='Todos';activeColor='Todos';renderCatalog();scrollToProducts()}
function scrollToProducts(){qs('#destacados')?.scrollIntoView({behavior:'smooth',block:'start'})}
function populateVariantFilters(){
  const size=qs('#sizeFilter'),color=qs('#colorFilter');if(!size||!color)return;
  const sizes=unique(catalog.flatMap(p=>(p.variants||[]).filter(canBuyVariant).map(v=>v.size))).sort();
  const colors=unique(catalog.flatMap(p=>(p.variants||[]).filter(canBuyVariant).map(v=>v.color))).sort((a,b)=>a.localeCompare(b,'es'));
  size.replaceChildren(new Option('Todos','Todos'));for(const value of sizes)size.add(new Option(value,value));
  color.replaceChildren(new Option('Todos','Todos'));for(const value of colors)color.add(new Option(value,value));
}

function closeCart(){const drawer=qs('#cartDrawer');if(!drawer)return;drawer.classList.remove('open');drawer.inert=true;drawer.setAttribute('aria-hidden','true');qs('#backdrop').hidden=true;document.body.style.overflow='';if(lastFocus?.isConnected)lastFocus.focus()}
function openCart(){lastFocus=document.activeElement;renderCart();const drawer=qs('#cartDrawer');drawer.inert=false;drawer.classList.add('open');drawer.setAttribute('aria-hidden','false');qs('#backdrop').hidden=false;document.body.style.overflow='hidden';qs('#closeCart')?.focus()}
function renderCart(){const items=qs('#cartItems');if(!items)return;items.replaceChildren();const lines=cartLines(cart,catalog);const count=lines.reduce((n,l)=>n+l.qty,0);qs('#cartCount').textContent=count;qs('#cartSubtotal').textContent=money(cartTotal(cart,catalog));if(!lines.length)items.append(node('p','cart-empty','Tu carrito está vacío.'));
  for(const l of lines){const line=node('div','cart-line');const detail=node('div');detail.append(node('h4','',l.product.name),node('p','',`${l.variant.size} · ${l.variant.color}`),node('strong','',money(l.subtotal)));const qty=node('div','qty-controls');const minus=node('button','','−');const plus=node('button','','+');minus.addEventListener('click',()=>changeQty(l.sku,l.qty-1));plus.addEventListener('click',()=>changeQty(l.sku,l.qty+1));qty.append(minus,node('span','',String(l.qty)),plus);line.append(detail,qty);items.append(line)}
  const enabled=!demo&&Boolean(config.storeReady&&config.whatsappEnabled&&/^\d{10,15}$/.test(config.whatsappPhone))&&lines.length>0;qs('#checkout').disabled=!enabled;qs('#checkoutReason').textContent=demo?'Modo demostración.':!config.storeReady?'La tienda todavía no está habilitada para ventas.':!config.whatsappEnabled?'WhatsApp pendiente de configuración.':!lines.length?'Agregá un producto para comenzar.':'';
}
function changeQty(sku,n){try{cart=updateQuantity(cart,sku,n,catalog);writeCart();renderCart()}catch(e){toast(e.message)}}
function handleCheckout(){if(demo||!config.storeReady||!config.whatsappEnabled){toast('La tienda todavía no está habilitada para ventas.');return}try{window.open(whatsappUrl(config.whatsappPhone,whatsappMessage(cart,catalog)),'_blank','noopener,noreferrer')}catch(e){toast(e.message)}}
function openWhatsAppGeneral(){if(demo||!config.whatsappEnabled||!/^\d{10,15}$/.test(config.whatsappPhone)){toast('WhatsApp todavía no está habilitado.');return}window.open(`https://wa.me/${config.whatsappPhone}?text=${encodeURIComponent('Hola InYou, quisiera consultar sobre sus prendas.')}`,'_blank','noopener,noreferrer')}

function openMenu(){qs('#mobileMenu').hidden=false;document.body.style.overflow='hidden'}
function closeMenu(){qs('#mobileMenu').hidden=true;document.body.style.overflow=''}
function openSearch(){qs('#searchPanel').hidden=false;document.body.style.overflow='hidden';setTimeout(()=>qs('#smartSearch')?.focus(),30)}
function closeSearch(){qs('#searchPanel').hidden=true;document.body.style.overflow=''}
function openFavorites(){renderFavorites();qs('#favoritesPanel').hidden=false;document.body.style.overflow='hidden'}
function closeFavorites(){qs('#favoritesPanel').hidden=true;document.body.style.overflow=''}
function renderFavorites(){const box=qs('#favoriteItems');if(!box)return;box.replaceChildren();const items=catalog.filter(isFavorite);if(!items.length){const empty=node('p','cart-empty','Todavía no guardaste ninguna prenda. Tocá ♡ en un producto para guardarlo.');empty.style.gridColumn='1/-1';box.append(empty);return}for(const p of items)box.append(productCard(p,{favoriteOnly:true}))}

function searchProducts(term){const q=normalizeText(term).trim();if(!q)return[];return catalog.map(p=>{const hay=normalizeText([p.name,p.description,p.collection,p.tipo,p.para,p.fit,...(p.variants||[]).map(v=>`${v.color} ${v.size}`)].join(' '));let score=0;if(normalizeText(p.name).startsWith(q))score+=6;if(normalizeText(p.name).includes(q))score+=4;if(normalizeText(p.collection).includes(q))score+=3;if(hay.includes(q))score+=1;return{p,score}}).filter(x=>x.score>0).sort((a,b)=>b.score-a.score).slice(0,6).map(x=>x.p)}
function renderSearch(term){const box=qs('#searchSuggestions');if(!box)return;const items=searchProducts(term);box.replaceChildren();if(!term.trim()){box.append(node('p','','Probá buscar “remera”, “floral”, “mujer” o “kepi”.'));return}if(!items.length){box.append(node('p','','No encontramos productos con esa búsqueda.'));return}for(const p of items){const row=node('button','search-result');row.type='button';let thumb;if(p.image){thumb=node('img');thumb.src=p.image;thumb.alt=''}else thumb=node('div','product-placeholder','');const text=node('div');text.append(node('strong','',p.name),node('div','product-facts',[p.collection,p.para].filter(Boolean).join(' · ')));const range=productPriceRange(p);row.append(thumb,text,node('span','',range.min===range.max?money(range.min):`Desde ${money(range.min)}`));row.addEventListener('click',()=>{activeFilter=p.collection||'Todas';activeType=p.tipo||'Todos';activeAudience=p.para||'Todos';closeSearch();renderCatalog();scrollToProducts()});box.append(row)}}

async function load(){
  qs('#year').textContent=new Date().getFullYear();favorites=readFavorites();renderFavoriteCount();if(demo){qs('#demoBanner').hidden=false;qs('#notice').textContent='VISTA DEMO · No es un catálogo real · Sin ventas habilitadas'}
  try{const catalogUrl=demo?'./data/catalog.demo.json':'./api/v1/products.php';const configUrl=demo?'./data/config.json':'./api/v1/config.php';const [configRes,catalogRes]=await Promise.all([fetch(configUrl,{cache:'no-store',headers:{Accept:'application/json'}}),fetch(catalogUrl,{cache:'no-store',headers:{Accept:'application/json'}})]);if(!configRes.ok)throw new Error('No fue posible leer la configuración.');if(!catalogRes.ok)throw new Error('No fue posible cargar el catálogo.');config=await configRes.json();catalog=normalizeCatalog(await catalogRes.json());cart=cleanCart(readCart(),catalog);writeCart()}catch(e){console.error(e);toast('No se pudo cargar el catálogo.');catalog=[];cart=[]}
  populateVariantFilters();renderCatalog();renderCart();renderFavorites();
}

qs('#openCart')?.addEventListener('click',openCart);qs('#openCartBottom')?.addEventListener('click',openCart);qs('#closeCart')?.addEventListener('click',closeCart);qs('#backdrop')?.addEventListener('click',closeCart);qs('#checkout')?.addEventListener('click',handleCheckout);
qs('#openMenu')?.addEventListener('click',openMenu);qs('#closeMenu')?.addEventListener('click',closeMenu);qsa('#mobileMenu a').forEach(a=>a.addEventListener('click',closeMenu));
qs('#openSearch')?.addEventListener('click',openSearch);qs('#openSearchMobile')?.addEventListener('click',openSearch);qs('#closeSearch')?.addEventListener('click',closeSearch);qs('#smartSearch')?.addEventListener('input',e=>renderSearch(e.target.value));
qs('#openFavorites')?.addEventListener('click',openFavorites);qs('#openFavoritesBottom')?.addEventListener('click',openFavorites);qs('#closeFavorites')?.addEventListener('click',closeFavorites);
qs('#benefitWhatsApp')?.addEventListener('click',openWhatsAppGeneral);qs('#whatsappFloat')?.addEventListener('click',openWhatsAppGeneral);
qs('#sizeFilter')?.addEventListener('change',e=>{activeSize=e.target.value;renderCatalog()});qs('#colorFilter')?.addEventListener('change',e=>{activeColor=e.target.value;renderCatalog()});qs('#clearCatalogFilters')?.addEventListener('click',resetFilters);qsa('[data-type-filter]').forEach(b=>b.addEventListener('click',()=>setType(b.dataset.typeFilter)));
qsa('[data-filter].collection-card').forEach(b=>b.addEventListener('click',()=>setFilter(b.dataset.filter)));qsa('[data-audience-quick]').forEach(b=>b.addEventListener('click',()=>setAudience(b.dataset.audienceQuick)));qsa('[data-type-quick]').forEach(b=>b.addEventListener('click',()=>setType(b.dataset.typeQuick)));qsa('[data-quick="Todos"]').forEach(b=>b.addEventListener('click',resetFilters));qsa('[data-audience-link]').forEach(a=>a.addEventListener('click',()=>setAudience(a.dataset.audienceLink)));
document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(!qs('#mobileMenu').hidden)closeMenu();if(!qs('#searchPanel').hidden)closeSearch();if(!qs('#favoritesPanel').hidden)closeFavorites();if(qs('#cartDrawer')?.classList.contains('open'))closeCart()}});
load();