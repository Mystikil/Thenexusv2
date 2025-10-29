<?php

declare(strict_types=1);

/**
 * Return the absolute path to the themes directory.
 */
function nx_themes_directory(): string
{
    return __DIR__ . '/../themes';
}

/**
 * Normalize a raw theme slug into a filesystem-safe value.
 */
function nx_theme_normalize_slug(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9_\-]+/', '-', $slug);
    $slug = trim($slug, '-_');

    return $slug;
}

/**
 * Discover all themes within the themes directory.
 *
 * @return array<string, array<string, mixed>>
 */
function nx_themes_list(bool $forceRefresh = false): array
{
    static $cache;

    if (!$forceRefresh && is_array($cache)) {
        return $cache;
    }

    $themes = [];
    $baseDir = nx_themes_directory();

    if (!is_dir($baseDir)) {
        $cache = [];

        return $cache;
    }

    $entries = scandir($baseDir) ?: [];

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $directory = $baseDir . '/' . $entry;

        if (!is_dir($directory)) {
            continue;
        }

        $manifest = [];
        $manifestFile = $directory . '/manifest.json';

        if (is_file($manifestFile)) {
            $manifestJson = @file_get_contents($manifestFile);

            if ($manifestJson !== false) {
                $decoded = json_decode($manifestJson, true);

                if (is_array($decoded)) {
                    $manifest = $decoded;
                }
            }
        }

        $slug = '';

        if (isset($manifest['slug']) && is_string($manifest['slug'])) {
            $slug = nx_theme_normalize_slug($manifest['slug']);
        }

        if ($slug === '') {
            $slug = nx_theme_normalize_slug($entry);
        }

        if ($slug === '') {
            continue;
        }

        if (isset($themes[$slug])) {
            // Skip duplicate slugs; the first discovered theme wins.
            continue;
        }

        $name = isset($manifest['name']) && is_string($manifest['name'])
            ? trim($manifest['name'])
            : '';
        $type = isset($manifest['type']) && is_string($manifest['type'])
            ? trim($manifest['type'])
            : '';
        $version = isset($manifest['version']) && is_string($manifest['version'])
            ? trim($manifest['version'])
            : '';
        $author = isset($manifest['author']) && is_string($manifest['author'])
            ? trim($manifest['author'])
            : '';
        $description = isset($manifest['description']) && is_string($manifest['description'])
            ? trim($manifest['description'])
            : '';

        $assets = ['css' => [], 'js' => []];

        if (isset($manifest['assets']) && is_array($manifest['assets'])) {
            foreach (['css', 'js'] as $kind) {
                if (!isset($manifest['assets'][$kind]) || !is_array($manifest['assets'][$kind])) {
                    continue;
                }

                foreach ($manifest['assets'][$kind] as $assetPath) {
                    if (!is_string($assetPath)) {
                        continue;
                    }

                    $assetPath = trim($assetPath);

                    if ($assetPath === '') {
                        continue;
                    }

                    $assets[$kind][] = ltrim($assetPath, '/');
                }
            }
        }

        $assets['css'] = array_values(array_unique($assets['css']));
        $assets['js'] = array_values(array_unique($assets['js']));

        $screenshot = null;

        foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
            $candidate = $directory . '/screenshot.' . $extension;
            if (is_file($candidate)) {
                $screenshot = $candidate;
                break;
            }
        }

        $themes[$slug] = [
            'slug' => $slug,
            'name' => $name !== '' ? $name : ucfirst($slug),
            'type' => $type,
            'version' => $version,
            'author' => $author,
            'description' => $description,
            'path' => $directory,
            'manifest' => $manifest,
            'assets' => $assets,
            'screenshot' => $screenshot,
        ];
    }

    ksort($themes);

    $cache = $themes;

    return $themes;
}

/**
 * Backwards-compatible wrapper for older code paths.
 *
 * @return array<string, array<string, mixed>>
 */
function nx_all_themes(): array
{
    return nx_themes_list();
}

function nx_theme_path(string $slug, string $subPath = ''): string
{
    $slug = nx_theme_normalize_slug($slug);

    if ($slug === '') {
        $slug = 'default';
    }

    $base = nx_themes_directory() . '/' . $slug;

    if ($subPath === '') {
        return $base;
    }

    return rtrim($base, '/') . '/' . ltrim($subPath, '/');
}

function nx_theme_setting(string $key): ?string
{
    static $cache = [];

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $pdo = db();

        if (!$pdo instanceof PDO) {
            $cache[$key] = null;

            return null;
        }

        $stmt = $pdo->prepare('SELECT value FROM settings WHERE `key` = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();
    } catch (Throwable $exception) {
        $value = false;
    }

    if (!is_string($value) || trim($value) === '') {
        $cache[$key] = null;
    } else {
        $cache[$key] = trim((string) $value);
    }

    return $cache[$key];
}

function nx_theme_active_slug(): string
{
    $themes = nx_themes_list();

    if (isset($_SESSION['preview_theme'])) {
        $previewSlug = nx_theme_normalize_slug((string) $_SESSION['preview_theme']);

        if ($previewSlug !== '' && isset($themes[$previewSlug])) {
            return $previewSlug;
        }

        unset($_SESSION['preview_theme']);
    }

    $selected = nx_theme_setting('active_theme');

    if ($selected !== null && isset($themes[$selected])) {
        $activeSlug = $selected;
    } else {
        $legacy = nx_theme_setting('default_theme');
        $activeSlug = $legacy !== null && isset($themes[$legacy]) ? $legacy : null;
    }

    if (function_exists('current_user')) {
        $user = current_user();
        if (is_array($user)) {
            $preference = nx_theme_normalize_slug((string) ($user['theme_preference'] ?? ''));
            if ($preference !== '' && isset($themes[$preference])) {
                return $preference;
            }
        }
    }

    if (isset($activeSlug) && $activeSlug !== null && isset($themes[$activeSlug])) {
        return $activeSlug;
    }

    if (isset($themes['default'])) {
        return 'default';
    }

    $slugs = array_keys($themes);

    if ($slugs !== []) {
        return (string) reset($slugs);
    }

    return 'default';
}

/**
 * Backwards-compatible wrapper retaining the previous function signature.
 */
function nx_current_theme_slug(?PDO $unused = null): string
{
    return nx_theme_active_slug();
}

function current_theme(): string
{
    return nx_theme_active_slug();
}

function theme_path(string $path = ''): string
{
    return nx_theme_path(nx_theme_active_slug(), $path);
}

function nx_theme_assets(string $slug): array
{
    $slug = nx_theme_normalize_slug($slug);
    $themes = nx_themes_list();

    if (!isset($themes[$slug])) {
        return ['css' => [], 'js' => []];
    }

    $assets = $themes[$slug]['assets'] ?? ['css' => [], 'js' => []];

    if (!is_array($assets)) {
        return ['css' => [], 'js' => []];
    }

    foreach (['css', 'js'] as $kind) {
        if (!isset($assets[$kind]) || !is_array($assets[$kind])) {
            $assets[$kind] = [];
        }
        $assets[$kind] = array_values(array_unique(array_filter(array_map(static function ($value) {
            return is_string($value) ? trim($value) : '';
        }, $assets[$kind]))));
    }

    return $assets;
}

function nx_theme_locate(string $slug, string $template): ?string
{
    $slug = nx_theme_normalize_slug($slug);
    $template = trim($template);

    if ($template === '') {
        return null;
    }

    $template = preg_replace('/[^a-zA-Z0-9_\-]/', '', $template);

    if ($template === '') {
        return null;
    }

    $candidate = nx_theme_path($slug, 'templates/' . $template . '.php');

    if (is_file($candidate)) {
        return $candidate;
    }

    if ($slug !== 'default') {
        $fallback = nx_theme_path('default', 'templates/' . $template . '.php');

        if (is_file($fallback)) {
            return $fallback;
        }
    }

    return null;
}

/**
 * Backwards-compatible alias for legacy code.
 */
function nx_locate_template(?PDO $pdo, string $template): ?string
{
    unset($pdo);

    return nx_theme_locate(nx_theme_active_slug(), $template);
}

function nx_theme_get_options(string $slug): array
{
    $slug = nx_theme_normalize_slug($slug);

    if ($slug === '') {
        return [];
    }

    if (!isset($GLOBALS['nx_theme_options_cache']) || !is_array($GLOBALS['nx_theme_options_cache'])) {
        $GLOBALS['nx_theme_options_cache'] = [];
    }

    if (isset($GLOBALS['nx_theme_options_cache'][$slug])) {
        return $GLOBALS['nx_theme_options_cache'][$slug];
    }

    try {
        $pdo = db();

        if (!$pdo instanceof PDO) {
            $GLOBALS['nx_theme_options_cache'][$slug] = [];

            return $GLOBALS['nx_theme_options_cache'][$slug];
        }

        $stmt = $pdo->prepare('SELECT opt_key, opt_value FROM theme_options WHERE theme_slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $options = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = (string) ($row['opt_key'] ?? '');
            if ($key === '') {
                continue;
            }

            $value = $row['opt_value'];
            $options[$key] = $value === null ? null : (string) $value;
        }

        $GLOBALS['nx_theme_options_cache'][$slug] = $options;
    } catch (Throwable $exception) {
        $GLOBALS['nx_theme_options_cache'][$slug] = [];
    }

    return $GLOBALS['nx_theme_options_cache'][$slug];
}

function nx_theme_get_option(string $slug, string $key, $default = null)
{
    $slug = nx_theme_normalize_slug($slug);
    $key = trim($key);

    if ($slug === '' || $key === '') {
        return $default;
    }

    $options = nx_theme_get_options($slug);

    if (!array_key_exists($key, $options)) {
        return $default;
    }

    $value = $options[$key];

    return $value === null ? $default : $value;
}

function nx_theme_set_option(string $slug, string $key, ?string $value): void
{
    $slug = nx_theme_normalize_slug($slug);
    $key = trim($key);

    if ($slug === '' || $key === '') {
        return;
    }

    if (isset($GLOBALS['nx_theme_options_cache'][$slug])) {
        unset($GLOBALS['nx_theme_options_cache'][$slug]);
    }

    $pdo = db();

    if (!$pdo instanceof PDO) {
        return;
    }

    if ($value === null) {
        $stmt = $pdo->prepare('DELETE FROM theme_options WHERE theme_slug = :slug AND opt_key = :key');
        $stmt->execute([
            'slug' => $slug,
            'key' => $key,
        ]);

        return;
    }

    $value = (string) $value;

    $stmt = $pdo->prepare('INSERT INTO theme_options (theme_slug, opt_key, opt_value) VALUES (:slug, :key, :value)
        ON DUPLICATE KEY UPDATE opt_value = VALUES(opt_value)');
    $stmt->execute([
        'slug' => $slug,
        'key' => $key,
        'value' => $value,
    ]);
}

function nx_theme_options_schema(string $slug): array
{
    $slug = nx_theme_normalize_slug($slug);

    if ($slug === '') {
        return [];
    }

    $optionsFile = nx_theme_path($slug, 'options.json');

    if (!is_file($optionsFile)) {
        return [];
    }

    $json = @file_get_contents($optionsFile);

    if ($json === false) {
        return [];
    }

    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        return [];
    }

    $schema = [];

    foreach ($decoded as $key => $definition) {
        if (!is_string($key) || trim($key) === '') {
            continue;
        }

        $optionKey = trim($key);
        $label = ucwords(str_replace(['_', '-'], ' ', $optionKey));
        $type = 'text';
        $choices = [];
        $defaultValue = null;

        if (is_string($definition)) {
            $parts = explode('|', $definition, 2);
            $type = trim($parts[0]) !== '' ? trim($parts[0]) : 'text';

            if (isset($parts[1])) {
                $choices = nx_theme_parse_choices($parts[1]);
            }
        } elseif (is_array($definition)) {
            if (isset($definition['label']) && is_string($definition['label'])) {
                $label = trim($definition['label']) !== '' ? trim($definition['label']) : $label;
            }

            if (isset($definition['type']) && is_string($definition['type'])) {
                $type = trim($definition['type']) !== '' ? trim($definition['type']) : 'text';
            }

            if (isset($definition['choices'])) {
                $choices = nx_theme_parse_choices($definition['choices']);
            }

            if (array_key_exists('default', $definition)) {
                $defaultValue = is_scalar($definition['default']) ? (string) $definition['default'] : null;
            }
        }

        $schema[$optionKey] = [
            'key' => $optionKey,
            'label' => $label,
            'type' => $type,
            'choices' => $choices,
            'default' => $defaultValue,
        ];
    }

    return $schema;
}

function nx_theme_parse_choices($raw): array
{
    if (is_string($raw)) {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        // Attempt to decode JSON first.
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            $raw = $decoded;
        } else {
            // Fall back to comma-separated values, handling legacy single quotes.
            $raw = trim($raw, "[](){}");
            if ($raw === '') {
                return [];
            }

            $parts = array_map(static function ($value) {
                $value = trim($value);
                $value = trim($value, "'\"");

                return $value;
            }, explode(',', $raw));

            $raw = $parts;
        }
    }

    if (!is_array($raw)) {
        return [];
    }

    $choices = [];

    foreach ($raw as $value) {
        if (!is_string($value) && !is_numeric($value)) {
            continue;
        }

        $value = (string) $value;
        $value = trim($value);

        if ($value === '') {
            continue;
        }

        $choices[] = $value;
    }

    return array_values(array_unique($choices));
}
