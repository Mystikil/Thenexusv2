<?php

declare(strict_types=1);

/**
 * Normalize filesystem paths to forward slashes and remove trailing separators.
 */
function nx_normalize_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);
    return rtrim($path, '/');
}

/**
 * Determine the server root and commonly used resource folders.
 */
function nx_server_paths(): array
{
    static $cache;

    if (is_array($cache)) {
        return $cache;
    }

    $configured = trim((string) (get_setting('server_path') ?? ''));

    if ($configured !== '') {
        $candidate = $configured;
        if (is_dir($candidate)) {
            $real = realpath($candidate);
            if ($real !== false) {
                $configured = $real;
            }
        }
    }

    if ($configured === '' || !is_dir($configured)) {
        $configured = SERVER_PATH;
    }

    $realRoot = is_dir($configured) ? realpath($configured) : false;
    $root = nx_normalize_path($realRoot !== false ? $realRoot : $configured);

    $dataPath = nx_normalize_path($root . '/data');
    $configLua = nx_normalize_path($root . '/config.lua');

    $overrideMonsters = nx_normalize_path($root . '/monsters');
    if (!is_dir($overrideMonsters)) {
        $overrideMonsters = '';
    }

    $overrideItems = nx_normalize_path($root . '/items/items.xml');
    if (!is_file($overrideItems)) {
        $overrideItems = '';
    }

    $monstersPath = $overrideMonsters !== ''
        ? $overrideMonsters
        : nx_normalize_path($dataPath . '/monster');

    $itemsPath = $overrideItems !== ''
        ? $overrideItems
        : nx_normalize_path($dataPath . '/items/items.xml');

    $spellsPath = nx_normalize_path($dataPath . '/spells');
    $questsPath = nx_normalize_path($dataPath . '/quests');

    $paths = [
        'server_root' => $root,
        'config_lua' => $configLua,
        'data' => $dataPath,
        'monsters' => $monstersPath,
        'items_xml' => $itemsPath,
        'spells' => $spellsPath,
    ];

    if (is_dir($questsPath)) {
        $paths['quests'] = $questsPath;
    }

    $cache = $paths;

    return $paths;
}

/**
 * Parse simple key=value pairs from config.lua.
 */
function nx_parse_config_lua(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        return [];
    }

    $result = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return $result;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || strpos($line, '--') === 0) {
            continue;
        }

        if (($commentPos = strpos($line, '--')) !== false) {
            $line = trim(substr($line, 0, $commentPos));
        }

        if ($line === '' || strpos($line, '=') === false) {
            continue;
        }

        [$rawKey, $rawValue] = array_map('trim', explode('=', $line, 2));

        if ($rawKey === '') {
            continue;
        }

        $value = rtrim($rawValue, ',');

        if ($value === '') {
            $result[$rawKey] = null;
            continue;
        }

        $firstChar = $value[0];
        $lastChar = substr($value, -1);

        if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
            $inner = substr($value, 1, -1);
            $result[$rawKey] = stripcslashes($inner);
            continue;
        }

        $lower = strtolower($value);
        if (in_array($lower, ['true', 'yes', 'on'], true)) {
            $result[$rawKey] = true;
            continue;
        }

        if (in_array($lower, ['false', 'no', 'off'], true)) {
            $result[$rawKey] = false;
            continue;
        }

        if (is_numeric($value)) {
            $result[$rawKey] = strpos($value, '.') !== false ? (float) $value : (int) $value;
            continue;
        }

        $result[$rawKey] = $value;
    }

    return $result;
}
