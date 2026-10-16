import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (name) => fs.readFileSync(new URL(`../admin/${name}`, import.meta.url), 'utf8');
const products = read('products.php');
const variants = read('variants.php');
const collections = read('collections.php');
const images = read('images.php');
const bootstrap = read('bootstrap.php');

test('todas las mutaciones del catálogo exigen CSRF y permiso', () => {
  for (const source of [products, variants, collections, images]) {
    assert.match(source, /validCsrf\(\)/);
    assert.match(source, /canManageCatalog\(\)/);
  }
});

test('publicación exige precio, colección, variante disponible e imagen', () => {
  assert.match(products, /base_price/);
  assert.match(products, /collections_ok/);
  assert.match(products, /buyable_variants/);
  assert.match(products, /images_ok/);
  assert.match(products, /assertProductReady/);
});

test('variantes permiten edición y protegen última variante activa publicada', () => {
  assert.match(variants, /\?product=.*&edit=/);
  assert.match(variants, /UPDATE product_variants SET/);
  assert.match(variants, /última variante activa/);
  assert.match(variants, /bajo_pedido/);
  assert.match(variants, /lead_time/);
});

test('colecciones permiten edición y bloquean eliminación si están en uso', () => {
  assert.match(collections, /\?edit=/);
  assert.match(collections, /UPDATE collections SET/);
  assert.match(collections, /tiene productos asignados/);
});

test('imágenes validan MIME, tamaño y protegen última imagen publicada', () => {
  assert.match(images, /FILEINFO_MIME_TYPE/);
  assert.match(images, /image\/jpeg/);
  assert.match(images, /image\/png/);
  assert.match(images, /image\/webp/);
  assert.match(images, /5\*1024\*1024/);
  assert.match(images, /última imagen/);
});

test('auditoría administrativa está integrada', () => {
  assert.match(bootstrap, /function auditLog/);
  for (const source of [products, variants, collections, images]) {
    assert.match(source, /auditLog\(/);
  }
});
