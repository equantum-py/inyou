import test from 'node:test';
import assert from 'node:assert/strict';
import {
  normalizeCatalog,
  canBuyVariant,
  cartLines,
  cartTotal,
  productPriceRange,
  updateQuantity,
} from '../store-core.mjs';

const payload = [{
  id: 'remera-floral',
  name: 'Remera Floral',
  description: 'Producto de prueba',
  collection: 'Floral & Arte',
  tipo: 'remera',
  para: 'Unisex',
  fit: 'Regular',
  priceGs: 150000,
  image: 'assets/products/remera-floral.webp',
  images: [
    {src: 'assets/products/remera-floral.webp', alt: 'Remera floral frente'},
    {src: 'assets/products/remera-floral-2.webp', alt: 'Remera floral detalle'},
  ],
  published: true,
  variants: [
    {sku:'INY-FLO-S-BEI',size:'S',color:'Beige',mode:'stock',stock:2,leadTime:'',priceGs:150000},
    {sku:'INY-FLO-M-BEI',size:'M',color:'Beige',mode:'bajo_pedido',stock:0,leadTime:'3 a 5 días',priceGs:165000},
  ],
}];

test('normaliza imágenes y precios por variante', () => {
  const [product] = normalizeCatalog(payload);
  assert.equal(product.image, 'assets/products/remera-floral.webp');
  assert.equal(product.images.length, 2);
  assert.equal(product.variants[1].priceGs, 165000);
  assert.deepEqual(productPriceRange(product), {min:150000,max:165000});
});

test('stock cero no se compra en modo stock', () => {
  assert.equal(canBuyVariant({mode:'stock',stock:0}), false);
});

test('bajo pedido permite compra aun con stock cero', () => {
  assert.equal(canBuyVariant({mode:'bajo_pedido',stock:0}), true);
});

test('respeta límite de stock físico', () => {
  const catalog = normalizeCatalog(payload);
  assert.throws(() => updateQuantity([], 'INY-FLO-S-BEI', 3, catalog), /disponibilidad/);
  assert.deepEqual(updateQuantity([], 'INY-FLO-S-BEI', 2, catalog), [{sku:'INY-FLO-S-BEI',qty:2}]);
});

test('carrito usa el precio real de la variante', () => {
  const catalog = normalizeCatalog(payload);
  const cart = [{sku:'INY-FLO-M-BEI',qty:2}];
  const [line] = cartLines(cart,catalog);
  assert.equal(line.unitPrice,165000);
  assert.equal(line.subtotal,330000);
  assert.equal(cartTotal(cart,catalog),330000);
});

test('rechaza rutas de imagen fuera de assets/products', () => {
  const bad = structuredClone(payload);
  bad[0].image = '../secreto.jpg';
  bad[0].images = [];
  assert.throws(() => normalizeCatalog(bad), /Imagen inválida/);
});
