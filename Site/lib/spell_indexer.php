<?php

declare(strict_types=1);

require_once __DIR__ . '/server_paths.php';

function nx_index_spells(PDO $pdo): array
{
    $paths = nx_server_paths();
    $spellsRoot = $paths['spells'] ?? '';

    $logStmt = $pdo->prepare('INSERT INTO index_scan_log (kind, status, message) VALUES (:kind, :status, :message)');

    if ($spellsRoot === '' || !is_dir($spellsRoot)) {
        $message = sprintf('Spells directory not found at %s', $spellsRoot !== '' ? $spellsRoot : 'n/a');
        $logStmt->execute(['kind' => 'spells', 'status' => 'error', 'message' => $message]);
        throw new RuntimeException($message);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($spellsRoot, FilesystemIterator::SKIP_DOTS)
    );

    $insertStmt = $pdo->prepare(
        'INSERT INTO spells_index (
            file_path, name, words, level, mana, cooldown, vocations, type, attributes
        ) VALUES (
            :file_path, :name, :words, :level, :mana, :cooldown, :vocations, :type, :attributes
        )
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            words = VALUES(words),
            level = VALUES(level),
            mana = VALUES(mana),
            cooldown = VALUES(cooldown),
            vocations = VALUES(vocations),
            type = VALUES(type),
            attributes = VALUES(attributes)'
    );

    $processed = 0;
    $errors = [];

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }

        if (strtolower($fileInfo->getExtension()) !== 'xml') {
            continue;
        }

        $fullPath = $fileInfo->getPathname();
        $relativePath = nx_normalize_path(str_replace('\\', '/', substr($fullPath, strlen($spellsRoot))));
        $relativePath = ltrim($relativePath, '/');

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($fullPath);
        $loadErrors = libxml_get_errors();
        libxml_clear_errors();

        if ($xml === false) {
            $errors[] = sprintf('Failed to parse %s (%d errors)', $fullPath, count($loadErrors));
            continue;
        }

        $spellNodes = [];
        if ($xml->getName() === 'spells') {
            foreach ($xml->children() as $child) {
                $spellNodes[] = $child;
            }
        } else {
            $spellNodes[] = $xml;
        }

        if ($spellNodes === []) {
            continue;
        }

        foreach ($spellNodes as $spellNode) {
            if (!$spellNode instanceof SimpleXMLElement) {
                continue;
            }

            $spellName = trim((string) ($spellNode['name'] ?? ''));
            if ($spellName === '') {
                $errors[] = sprintf('Missing spell name in %s', $fullPath);
                continue;
            }

            $words = trim((string) ($spellNode['words'] ?? '')) ?: null;
            $type = strtolower($spellNode->getName());

            $level = null;
            foreach (['level', 'spellLevel', 'runeLevel'] as $levelKey) {
                if (isset($spellNode[$levelKey]) && $spellNode[$levelKey] !== '') {
                    $level = (int) $spellNode[$levelKey];
                    break;
                }
            }

            $mana = null;
            foreach (['mana', 'manacost', 'manaCost'] as $manaKey) {
                if (isset($spellNode[$manaKey]) && $spellNode[$manaKey] !== '') {
                    $mana = (int) $spellNode[$manaKey];
                    break;
                }
            }

            $cooldown = null;
            if (isset($spellNode['cooldown']) && $spellNode['cooldown'] !== '') {
                $cooldown = (int) $spellNode['cooldown'];
            } elseif (isset($spellNode->cooldown)) {
                foreach ($spellNode->cooldown as $cooldownNode) {
                    if (isset($cooldownNode['value']) && $cooldownNode['value'] !== '') {
                        $cooldown = (int) $cooldownNode['value'];
                        break;
                    }
                }
            }

            $vocationNames = [];
            $childExtras = [];

            foreach ($spellNode->children() as $child) {
                $childName = $child->getName();
                if ($childName === 'vocation') {
                    $nameAttr = trim((string) ($child['name'] ?? ''));
                    $vocationId = trim((string) ($child['id'] ?? ''));
                    if ($nameAttr !== '') {
                        $vocationNames[] = ucwords($nameAttr);
                    } elseif ($vocationId !== '') {
                        $vocationNames[] = $vocationId;
                    }
                    continue;
                }

                $attributeMap = [];
                foreach ($child->attributes() as $key => $value) {
                    $attributeMap[$key] = (string) $value;
                }
                $valueText = trim((string) $child);
                if ($valueText !== '') {
                    $attributeMap['value'] = $valueText;
                }
                $childExtras[$childName][] = $attributeMap;
            }

            $vocationNames = array_values(array_unique($vocationNames));
            $vocations = $vocationNames === [] ? null : implode(',', $vocationNames);

            $extraAttributes = [];
            foreach ($spellNode->attributes() as $key => $value) {
                $keyString = (string) $key;
                if (in_array($keyString, ['name', 'words'], true)) {
                    continue;
                }
                $extraAttributes[$keyString] = (string) $value;
            }

            if ($childExtras !== []) {
                $extraAttributes['children'] = $childExtras;
            }

            $attributesJson = $extraAttributes === [] ? null : json_encode($extraAttributes, JSON_UNESCAPED_UNICODE);

            $entryPath = $relativePath;
            if (count($spellNodes) > 1) {
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $spellName));
                $slug = trim($slug, '-');
                if ($slug === '') {
                    $slug = substr(md5($spellName), 0, 8);
                }
                $entryPath .= '#' . $slug;
            }

            try {
                $insertStmt->execute([
                    'file_path' => $entryPath,
                    'name' => $spellName,
                    'words' => $words,
                    'level' => $level,
                    'mana' => $mana,
                    'cooldown' => $cooldown,
                    'vocations' => $vocations,
                    'type' => $type !== '' ? $type : null,
                    'attributes' => $attributesJson,
                ]);
                $processed++;
            } catch (Throwable $exception) {
                $errors[] = sprintf('Failed to store spell %s from %s: %s', $spellName, $fullPath, $exception->getMessage());
            }
        }
    }

    $status = $errors === [] ? 'ok' : 'error';
    $messageParts = [
        sprintf('Indexed %d spells from %s', $processed, $spellsRoot),
    ];

    if ($errors !== []) {
        $messageParts[] = 'Errors: ' . implode('; ', array_slice($errors, 0, 5));
        if (count($errors) > 5) {
            $messageParts[] = sprintf('(+%d more)', count($errors) - 5);
        }
    }

    $logStmt->execute([
        'kind' => 'spells',
        'status' => $status,
        'message' => implode(' ', $messageParts),
    ]);

    if ($errors !== []) {
        throw new RuntimeException($messageParts[0]);
    }

    return [
        'count' => $processed,
        'source' => $spellsRoot,
    ];
}
