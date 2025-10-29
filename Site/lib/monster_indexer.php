<?php

declare(strict_types=1);

require_once __DIR__ . '/server_paths.php';

function nx_index_monsters(PDO $pdo): array
{
    $paths = nx_server_paths();
    $monstersRoot = $paths['monsters'] ?? '';

    $logStmt = $pdo->prepare('INSERT INTO index_scan_log (kind, status, message) VALUES (:kind, :status, :message)');

    if ($monstersRoot === '' || !is_dir($monstersRoot)) {
        $message = sprintf('Monsters directory not found at %s', $monstersRoot !== '' ? $monstersRoot : 'n/a');
        $logStmt->execute(['kind' => 'monsters', 'status' => 'error', 'message' => $message]);
        throw new RuntimeException($message);
    }

    $monstersProcessed = 0;
    $lootProcessed = 0;
    $errors = [];

    $insertMonster = $pdo->prepare(
        'INSERT INTO monster_index (
            file_path, name, race, experience, health, speed,
            summonable, convinceable, illusionable, elemental, immunities, flags, outfit
        ) VALUES (
            :file_path, :name, :race, :experience, :health, :speed,
            :summonable, :convinceable, :illusionable, :elemental, :immunities, :flags, :outfit
        )
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            race = VALUES(race),
            experience = VALUES(experience),
            health = VALUES(health),
            speed = VALUES(speed),
            summonable = VALUES(summonable),
            convinceable = VALUES(convinceable),
            illusionable = VALUES(illusionable),
            elemental = VALUES(elemental),
            immunities = VALUES(immunities),
            flags = VALUES(flags),
            outfit = VALUES(outfit)'
    );

    $selectId = $pdo->prepare('SELECT id FROM monster_index WHERE file_path = :file_path LIMIT 1');
    $deleteLoot = $pdo->prepare('DELETE FROM monster_loot WHERE monster_id = :monster_id');
    $insertLoot = $pdo->prepare(
        'INSERT INTO monster_loot (monster_id, item_id, item_name, chance, count_min, count_max)
         VALUES (:monster_id, :item_id, :item_name, :chance, :count_min, :count_max)
         ON DUPLICATE KEY UPDATE chance = VALUES(chance), count_min = VALUES(count_min), count_max = VALUES(count_max)'
    );

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($monstersRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }

        if (strtolower($fileInfo->getExtension()) !== 'xml') {
            continue;
        }

        $fullPath = $fileInfo->getPathname();
        $relativePath = nx_normalize_path(str_replace('\\', '/', substr($fullPath, strlen($monstersRoot))));
        $relativePath = ltrim($relativePath, '/');

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($fullPath);
        $loadErrors = libxml_get_errors();
        libxml_clear_errors();

        if ($xml === false) {
            $errors[] = sprintf('Failed to parse %s (%d errors)', $fullPath, count($loadErrors));
            continue;
        }

        $name = trim((string) ($xml['name'] ?? ''));
        if ($name === '') {
            $errors[] = sprintf('Missing monster name in %s', $fullPath);
            continue;
        }

        $race = trim((string) ($xml['race'] ?? '')) ?: null;
        $experience = (int) ($xml['experience'] ?? 0);
        $speed = (int) ($xml['speed'] ?? 0);

        $health = null;
        if (isset($xml->health)) {
            $now = (string) ($xml->health['now'] ?? '');
            $max = (string) ($xml->health['max'] ?? '');
            if ($now !== '') {
                $health = (int) $now;
            } elseif ($max !== '') {
                $health = (int) $max;
            }
        }

        $summonable = false;
        $convinceable = false;
        $illusionable = false;
        $flagMap = [];

        if (isset($xml->flags)) {
            foreach ($xml->flags->flag as $flag) {
                foreach ($flag->attributes() as $key => $value) {
                    $stringValue = trim((string) $value);
                    $lower = strtolower($stringValue);
                    $isTrue = in_array($lower, ['1', 'true', 'yes', 'on'], true);
                    $numeric = is_numeric($stringValue) ? (float) $stringValue : null;

                    switch ($key) {
                        case 'summonable':
                            $summonable = $isTrue;
                            break;
                        case 'convinceable':
                            $convinceable = $isTrue;
                            break;
                        case 'illusionable':
                            $illusionable = $isTrue;
                            break;
                    }

                    if ($numeric !== null && strpos($stringValue, '.') !== false) {
                        $flagMap[$key] = (float) $stringValue;
                    } elseif ($numeric !== null) {
                        $flagMap[$key] = (int) round($numeric);
                    } else {
                        $flagMap[$key] = $isTrue;
                    }
                }
            }
        }

        $elemental = [];
        if (isset($xml->elements)) {
            foreach ($xml->elements->element as $element) {
                foreach ($element->attributes() as $key => $value) {
                    $keyString = (string) $key;
                    $valueString = trim((string) $value);

                    if ($keyString === '') {
                        continue;
                    }

                    if (substr($keyString, -7) === 'Percent') {
                        $elementName = strtolower(substr($keyString, 0, -7));
                        $elemental[$elementName] = (int) round((float) $valueString);
                    }
                }
            }
        }

        $immunities = [];
        if (isset($xml->immunities)) {
            foreach ($xml->immunities->immunity as $immunity) {
                foreach ($immunity->attributes() as $key => $value) {
                    $valueString = trim((string) $value);
                    if ($valueString === '') {
                        continue;
                    }
                    $lower = strtolower($valueString);
                    if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
                        $immunities[$key] = true;
                    } elseif (in_array($lower, ['0', 'false', 'no', 'off'], true)) {
                        $immunities[$key] = false;
                    } else {
                        $immunities[$key] = $valueString;
                    }
                }
            }
        }

        $outfit = null;
        if (isset($xml->look)) {
            $outfitAttributes = [];
            foreach ($xml->look->attributes() as $key => $value) {
                $outfitAttributes[$key] = (string) $value;
            }
            if ($outfitAttributes !== []) {
                $outfit = json_encode($outfitAttributes, JSON_UNESCAPED_UNICODE);
            }
        }

        $elementJson = $elemental === [] ? null : json_encode($elemental, JSON_UNESCAPED_UNICODE);
        $immunityJson = $immunities === [] ? null : json_encode($immunities, JSON_UNESCAPED_UNICODE);
        $flagJson = $flagMap === [] ? null : json_encode($flagMap, JSON_UNESCAPED_UNICODE);

        $insertMonster->execute([
            'file_path' => $relativePath,
            'name' => $name,
            'race' => $race,
            'experience' => $experience,
            'health' => $health,
            'speed' => $speed,
            'summonable' => $summonable ? 1 : 0,
            'convinceable' => $convinceable ? 1 : 0,
            'illusionable' => $illusionable ? 1 : 0,
            'elemental' => $elementJson,
            'immunities' => $immunityJson,
            'flags' => $flagJson,
            'outfit' => $outfit,
        ]);

        $monsterId = (int) $pdo->lastInsertId();
        if ($monsterId === 0) {
            $selectId->execute(['file_path' => $relativePath]);
            $monsterId = (int) $selectId->fetchColumn();
        }

        if ($monsterId === 0) {
            $errors[] = sprintf('Unable to resolve monster ID for %s', $fullPath);
            continue;
        }

        $deleteLoot->execute(['monster_id' => $monsterId]);

        if (isset($xml->loot)) {
            foreach ($xml->loot->item as $lootItem) {
                $attributes = $lootItem->attributes();
                if ($attributes === null) {
                    continue;
                }

                $lootId = null;
                $lootName = null;

                if (isset($attributes['id'])) {
                    $lootId = (int) $attributes['id'];
                }

                if (isset($attributes['name'])) {
                    $lootName = trim((string) $attributes['name']);
                }

                if ($lootId === null && ($lootName === null || $lootName === '')) {
                    continue;
                }

                $chanceValue = null;
                if (isset($attributes['chance'])) {
                    $chanceValue = (string) $attributes['chance'];
                } elseif (isset($attributes['chancepercent'])) {
                    $chanceValue = (string) $attributes['chancepercent'];
                }

                $chance = nx_normalize_monster_chance($chanceValue);

                $countMin = 1;
                $countMax = 1;

                if (isset($attributes['countmin'])) {
                    $countMin = (int) $attributes['countmin'];
                }

                if (isset($attributes['countmax'])) {
                    $countMax = (int) $attributes['countmax'];
                }

                if ($countMin > $countMax) {
                    $countMin = $countMax;
                }

                $insertLoot->execute([
                    'monster_id' => $monsterId,
                    'item_id' => $lootId,
                    'item_name' => $lootName,
                    'chance' => $chance,
                    'count_min' => $countMin > 0 ? $countMin : 1,
                    'count_max' => $countMax > 0 ? $countMax : 1,
                ]);
                $lootProcessed++;
            }
        }

        $monstersProcessed++;
    }

    $status = $errors === [] ? 'ok' : 'error';
    $messageParts = [
        sprintf('Indexed %d monsters (%d loot) from %s', $monstersProcessed, $lootProcessed, $monstersRoot),
    ];

    if ($errors !== []) {
        $messageParts[] = 'Errors: ' . implode('; ', array_slice($errors, 0, 5));
        if (count($errors) > 5) {
            $messageParts[] = sprintf('(+%d more)', count($errors) - 5);
        }
    }

    $logStmt->execute([
        'kind' => 'monsters',
        'status' => $status,
        'message' => implode(' ', $messageParts),
    ]);

    if ($errors !== []) {
        throw new RuntimeException($messageParts[0]);
    }

    return [
        'monsters' => $monstersProcessed,
        'loot' => $lootProcessed,
        'source' => $monstersRoot,
    ];
}

function nx_normalize_monster_chance(?string $value): ?int
{
    if ($value === null) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $trimmed = rtrim($trimmed, '%');

    if (!is_numeric($trimmed)) {
        return null;
    }

    $number = (float) $trimmed;

    if ($number <= 100) {
        return (int) round($number * 1000);
    }

    return (int) round($number);
}
