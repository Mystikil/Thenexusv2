<?php

declare(strict_types=1);

const WIDGET_CACHE_DIR = __DIR__ . '/../assets/cache';

function cache_key(string $name, array $paramsArray = []): string
{
    ksort($paramsArray);
    $encoded = json_encode($paramsArray);
    if ($encoded === false) {
        $encoded = '';
    }

    $payload = $name . '|' . $encoded;

    return hash('sha256', $payload);
}

function cache_path(string $key): string
{
    return WIDGET_CACHE_DIR . '/' . $key . '.html';
}

function cache_last_modified(string $key): ?int
{
    $path = cache_path($key);

    if (!is_file($path)) {
        return null;
    }

    $mtime = filemtime($path);

    return $mtime === false ? null : (int) $mtime;
}

function cache_get(string $key, int $ttlSeconds): ?string
{
    if ($ttlSeconds <= 0) {
        return null;
    }

    $path = cache_path($key);

    if (!is_file($path)) {
        return null;
    }

    $mtime = filemtime($path);
    if ($mtime === false) {
        return null;
    }

    if ((time() - $mtime) > $ttlSeconds) {
        return null;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return null;
    }

    return $contents;
}

function cache_set(string $key, string $html): void
{
    if (!is_dir(WIDGET_CACHE_DIR) && !mkdir(WIDGET_CACHE_DIR, 0775, true) && !is_dir(WIDGET_CACHE_DIR)) {
        return;
    }

    $path = cache_path($key);
    file_put_contents($path, $html, LOCK_EX);
}
