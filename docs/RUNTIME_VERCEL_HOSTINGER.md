# Runtime dual — Vercel de prueba + Hostinger producción

El mismo código de InYou puede ejecutarse en dos entornos sin duplicar el proyecto.

## 1. Vercel de prueba

Vercel usa `Dockerfile.vercel` para levantar PHP 8.3 + Apache + PDO MySQL.

La conexión MySQL se define únicamente mediante variables de entorno:

- `INY_DB_HOST`
- `INY_DB_PORT` (opcional, por defecto `3306`)
- `INY_DB_NAME`
- `INY_DB_USER`
- `INY_DB_PASSWORD`
- `INY_APP_ENV=vercel-test` (opcional)

No subir credenciales al repositorio.

El endpoint `/api/v1/health.php` debe responder `runtime: vercel` cuando el despliegue está ejecutándose en Vercel.

### Importante sobre imágenes

El panel puede ejecutar el flujo de upload dentro del contenedor para pruebas funcionales, pero no se debe considerar el filesystem del deployment como almacenamiento permanente del catálogo.

Para pruebas reales de persistencia de imágenes en Vercel se debe conectar almacenamiento externo. En Hostinger las imágenes se guardarán normalmente bajo `assets/products/`.

## 2. Hostinger producción

Si las variables `INY_DB_*` no existen, el mismo código utiliza automáticamente:

```text
inyou-private/db.php
```

ubicado fuera de `public_html`.

Estructura esperada:

```text
dominio/
├── inyou-private/
│   └── db.php
└── public_html/
    ├── admin/
    ├── api/
    ├── assets/
    ├── lib/
    └── ...
```

Ejemplo de `inyou-private/db.php` (no versionar):

```php
<?php
return [
    'host' => 'localhost',
    'database' => 'BASE',
    'username' => 'USUARIO',
    'password' => 'CONTRASEÑA',
    'port' => '3306',
];
```

## 3. Prioridad de configuración

1. Si existen todas las variables `INY_DB_*`, se usan esas variables.
2. Si no existe ninguna, se usa `inyou-private/db.php`.
3. Si existe solo una parte de las variables, la app falla de forma segura por configuración incompleta.

Esto evita conectar accidentalmente un preview de Vercel a la base equivocada.

## 4. Flujo recomendado

### Vercel

1. usar una base MySQL exclusiva de pruebas;
2. cargar las variables de entorno;
3. desplegar con preset `Container`;
4. validar `/api/v1/health.php`;
5. validar `/admin/login.php`;
6. ejecutar E03/E04 sobre esa base de pruebas;
7. probar catálogo completo.

### Hostinger

1. terminar E05/E06;
2. comparar migraciones con el esquema real;
3. preparar `inyou-private/db.php`;
4. copiar la versión cerrada;
5. ejecutar migraciones aprobadas;
6. validar health, admin, catálogo y flujo comercial.

## Regla

Vercel es ambiente de prueba. Hostinger seguirá siendo el ambiente final previsto para esta versión del ecommerce.
