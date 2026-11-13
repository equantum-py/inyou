<?php
declare(strict_types=1);

/**
 * Almacenamiento público de imágenes InYou en Supabase Storage.
 * Clave secreta exclusivamente en el backend: nunca devolverla a la tienda.
 */
const INYOU_STORAGE_BUCKET = 'inyou-products';
const INYOU_STORAGE_MAX_BYTES = 10 * 1024 * 1024;

function inyouStorageUrl(): string {
    $url = rtrim(trim((string)(getenv('INY_SUPABASE_URL') ?: '')), '/');
    if (!preg_match('~^https://[a-z0-9-]+\\.supabase\\.co$~D', $url)) {
        throw new RuntimeException('Falta configurar una URL válida de Supabase.');
    }
    return $url;
}

function inyouStorageSecret(): string {
    $key = trim((string)(getenv('INY_SUPABASE_SECRET_KEY') ?: ''));
    if (!str_starts_with($key, 'sb_secret_') || strlen($key) < 20) {
        throw new RuntimeException('La clave privada de Supabase no está configurada.');
    }
    return $key;
}

function inyouStorageIsPath(string $path): bool {
    return (bool) preg_match('~^supabase/products/p[0-9]+-[a-f0-9]{32}\\.(jpg|png|webp)$~D', $path);
}

function inyouStoragePublicUrl(string $path): string {
    if (!inyouStorageIsPath($path)) throw new RuntimeException('Ruta de fotografía inválida.');
    return inyouStorageUrl() . '/storage/v1/object/public/' . INYOU_STORAGE_BUCKET . '/' . substr($path, strlen('supabase/'));
}

/** Returns response code and body, throwing on transport errors; no secrets in logs. */
function inyouStorageRequest(string $method, string $path, ?string $payload = null, ?string $mime = null): array {
    if (!inyouStorageIsPath($path)) throw new RuntimeException('Ruta de fotografía inválida.');
    $secret = inyouStorageSecret();
    $url = inyouStorageUrl().'/storage/v1/object/'.INYOU_STORAGE_BUCKET.'/'.substr($path, strlen('supabase/'));
    $headers = [
        'apikey: '.$secret,
        'Accept: application/json',
        'x-upsert: false'
    ];
    if ($payload !== null) {
        $headers[]='Content-Type: '.($mime ?: 'application/octet-stream');
        $headers[]='Content-Length: '.strlen($payload);
    }
    $context = stream_context_create(['http'=>[
        'method'=>$method,
        'header'=>implode("\r\n", $headers),
        'content'=>$payload ?? '',
        'timeout'=>45,
        'ignore_errors'=>true,
        'follow_location'=>0,
        'protocol_version'=>1.1
    ], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $response=@file_get_contents($url, false, $context);
    $statusLine=$http_response_header[0]??'';
    $code=preg_match('~^HTTP/\\S+\\s+(\\d{3})~', $statusLine,$match)?(int)$match[1]:0;
    if ($code===0 || $response===false) throw new RuntimeException('No se pudo comunicar con Supabase Storage.');
    return [$code,(string)$response];
}

function inyouStorageUpload(string $path,string $bytes,string $mime): void {
    if (strlen($bytes)<1 || strlen($bytes)>INYOU_STORAGE_MAX_BYTES) {
        throw new RuntimeException('La fotografía debe pesar como máximo 10 MB.');
    }
    if (!in_array($mime,['image/jpeg','image/png','image/webp'],true)) {
        throw new RuntimeException('Formato de fotografía no permitido.');
    }
    [$status,$body]=inyouStorageRequest('POST',$path,$bytes,$mime);
    if ($status!==200 && $status!==201) {
        error_log('InYou Supabase upload HTTP '.$status);
        throw new RuntimeException('Supabase no aceptó la fotografía (HTTP '.$status.'). Revisá Storage y las variables de Vercel.');
    }
}

function inyouStorageDelete(string $path): void {
    [$status,$body]=inyouStorageRequest('DELETE',$path);
    if ($status!==200 && $status!==204 && $status!==404) {
        error_log('InYou Supabase delete HTTP '.$status);
        throw new RuntimeException('No se pudo eliminar la fotografía de Supabase.');
    }
}
