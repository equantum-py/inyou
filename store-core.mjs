export const COLLECTIONS = Object.freeze(["Floral & Arte", "Esenciales", "Fe & Esperanza", "Momentos Especiales"]);
export const TYPES = Object.freeze(["remera", "kepi"]);
export const AUDIENCES = Object.freeze(["Mujer", "Hombre", "Unisex"]);
export const FITS = Object.freeze(["", "Regular", "Oversize", "Cropped", "Único"]);
export const MAX_QTY = 20;
export const money = (guaranies) => `G. ${Number(guaranies).toLocaleString('es-PY')}`;

export function normalizeCatalog(payload) {
  if (!Array.isArray(payload)) throw new Error('El catálogo debe ser una lista.');
  const seenIds = new Set();
  const seenSkus = new Set();
  return payload.map((p) => {
    if (!p || typeof p !== 'object' || Array.isArray(p)) throw new Error('Producto no válido.');
    if (typeof p.id !== 'string' || !/^[a-z0-9-]{3,60}$/.test(p.id) || seenIds.has(p.id)) throw new Error('ID de producto inválido o duplicado.');
    seenIds.add(p.id);
    if (typeof p.name !== 'string' || !p.name.trim() || p.name.length > 100) throw new Error('Nombre de producto inválido.');
    if (!COLLECTIONS.includes(p.collection)) throw new Error(`Colección inválida: ${p.id}`);
    if (!Number.isInteger(p.priceGs) || p.priceGs <= 0) throw new Error(`Precio inválido: ${p.id}`);
    if (typeof p.published !== 'boolean') throw new Error(`Estado de publicación inválido: ${p.id}`);
    if (typeof p.description !== 'string' || p.description.length > 1000) throw new Error(`Descripción inválida: ${p.id}`);
    const tipo = p.tipo ?? 'remera';
    const para = p.para ?? 'Unisex';
    const fit = p.fit ?? '';
    if (!TYPES.includes(tipo)) throw new Error(`Tipo de producto inválido: ${p.id}`);
    if (!AUDIENCES.includes(para)) throw new Error(`Público inválido: ${p.id}`);
    if (!FITS.includes(fit)) throw new Error(`Fit inválido: ${p.id}`);
    if (p.image && (typeof p.image !== 'string' || !/^assets\/[a-zA-Z0-9._/-]+\.(jpg|jpeg|png|webp)$/i.test(p.image) || p.image.includes('..'))) throw new Error(`Imagen inválida: ${p.id}`);
    if (!Array.isArray(p.variants)) throw new Error(`Variantes inválidas: ${p.id}`);
    const variants = p.variants.map(v => {
      if (!v || typeof v.sku !== 'string' || !/^[A-Z0-9-]{4,55}$/.test(v.sku) || seenSkus.has(v.sku)) throw new Error(`SKU inválido o duplicado: ${p.id}`);
      seenSkus.add(v.sku);
      if (typeof v.size !== 'string' || !v.size.trim() || v.size.length > 20 || typeof v.color !== 'string' || !v.color.trim() || v.color.length > 45) throw new Error('Talle o color inválido.');
      if (!['stock','bajo_pedido'].includes(v.mode) || !Number.isInteger(v.stock) || v.stock < 0) throw new Error('Disponibilidad inválida.');
      if (typeof v.leadTime !== 'string' || v.leadTime.length > 120) throw new Error('Plazo inválido.');
      return {sku:v.sku,size:v.size,color:v.color,mode:v.mode,stock:v.stock,leadTime:v.leadTime};
    });
    return {id:p.id,name:p.name,description:p.description,collection:p.collection,tipo,para,fit,priceGs:p.priceGs,image:p.image||'',published:p.published,variants};
  }).filter(p=>p.published);
}

export function variantOf(catalog, sku) {
  for (const p of catalog) { const v = p.variants.find(x=>x.sku === sku); if(v) return {product:p,variant:v}; }
  return null;
}
export function canBuyVariant(v) { return v.mode === 'bajo_pedido' || v.stock > 0; }
export function cleanCart(cart, catalog) {
  if (!Array.isArray(cart)) return [];
  const out = [];
  const seen = new Set();
  for (const line of cart) {
    if (!line || typeof line.sku !== 'string' || seen.has(line.sku)) continue;
    seen.add(line.sku);
    const match = variantOf(catalog,line.sku);
    if(!match || !canBuyVariant(match.variant)) continue;
    const limit = match.variant.mode === 'stock' ? Math.min(MAX_QTY,match.variant.stock) : MAX_QTY;
    const qty = Math.min(limit, Math.max(0, Math.trunc(Number(line.qty)||0)));
    if(qty) out.push({sku:line.sku,qty});
  }
  return out;
}
export function updateQuantity(cart, sku, nextQty, catalog) {
  const target = variantOf(catalog,sku);
  if(!target || !canBuyVariant(target.variant)) throw new Error('Variante no disponible.');
  const qty = Number(nextQty);
  if(!Number.isInteger(qty) || qty < 0) throw new Error('Cantidad inválida.');
  const limit = target.variant.mode === 'stock' ? Math.min(MAX_QTY,target.variant.stock) : MAX_QTY;
  if(qty > limit) throw new Error('La cantidad supera la disponibilidad declarada.');
  const current=cleanCart(cart,catalog).filter(x=>x.sku!==sku);
  return qty ? [...current,{sku,qty}] : current;
}
export function cartLines(cart,catalog) {
  return cleanCart(cart,catalog).map(l=>{
    const {product,variant}=variantOf(catalog,l.sku);
    return {...l,product,variant,subtotal:product.priceGs*l.qty};
  });
}
export function cartTotal(cart,catalog) {return cartLines(cart,catalog).reduce((a,l)=>a+l.subtotal,0);}
export function whatsappMessage(cart,catalog) {
  const lines=cartLines(cart,catalog);
  if(!lines.length) throw new Error('Carrito vacío.');
  const detail=lines.map((l,i)=>`${i+1}. ${l.product.name} | ${l.variant.size} | ${l.variant.color} | SKU ${l.sku} | ${l.qty} u. × ${money(l.product.priceGs)} = ${money(l.subtotal)}${l.variant.mode==='bajo_pedido'?' | BAJO PEDIDO':''}`).join('\n');
  return `Hola, IN YOU. Quiero consultar por estos productos:\n\n${detail}\n\nSubtotal estimado: ${money(cartTotal(cart,catalog))}\nEnvío: a cotizar según destino.\n\nPor favor confirmen stock, costo de envío, plazo y total final. Entiendo que este mensaje es una CONSULTA: el pedido solo se confirma tras aceptación y acreditación de transferencia bancaria. Gracias.`;
}
export function whatsappUrl(phone,message) {
  if(typeof phone!=='string' || !/^\d{10,15}$/.test(phone)) throw new Error('WhatsApp de negocio no configurado correctamente.');
  return `https://wa.me/${phone}?text=${encodeURIComponent(message)}`;
}
