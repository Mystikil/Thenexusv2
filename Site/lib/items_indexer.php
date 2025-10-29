<?php

declare(strict_types=1);

require_once __DIR__ . '/server_paths.php';

function nx_index_items(PDO $pdo): array
{
    $paths = nx_server_paths();
    $itemsFile = $paths['items_xml'] ?? '';

    $logStmt = $pdo->prepare('INSERT INTO index_scan_log (kind, status, message) VALUES (:kind, :status, :message)');

    if ($itemsFile === '' || !is_file($itemsFile)) {
        $message = sprintf('Items XML not found at %s', $itemsFile !== '' ? $itemsFile : 'n/a');
        $logStmt->execute(['kind' => 'items', 'status' => 'error', 'message' => $message]);
        throw new RuntimeException($message);
    }

    libxml_use_internal_errors(true);
    $document = new DOMDocument();

    if (!$document->load($itemsFile)) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        $message = sprintf('Failed to parse %s (%d errors)', $itemsFile, count($errors));
        $logStmt->execute(['kind' => 'items', 'status' => 'error', 'message' => $message]);
        throw new RuntimeException($message);
    }

    libxml_clear_errors();

    $itemNodes = $document->getElementsByTagName('item');
    $upsert = $pdo->prepare(
        'INSERT INTO item_index (id, name, article, plural, description, weight, stackable, type, attributes)
         VALUES (:id, :name, :article, :plural, :description, :weight, :stackable, :type, :attributes)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            article = VALUES(article),
            plural = VALUES(plural),
            description = VALUES(description),
            weight = VALUES(weight),
            stackable = VALUES(stackable),
            type = VALUES(type),
            attributes = VALUES(attributes)'
    );

    $count = 0;

    foreach ($itemNodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        $ids = [];

        if ($node->hasAttribute('id')) {
            $ids[] = (int) $node->getAttribute('id');
        } elseif ($node->hasAttribute('fromid') && $node->hasAttribute('toid')) {
            $start = (int) $node->getAttribute('fromid');
            $end = (int) $node->getAttribute('toid');

            if ($start > 0 && $end >= $start) {
                for ($i = $start; $i <= $end; $i++) {
                    $ids[] = $i;
                }
            }
        }

        if ($ids === []) {
            continue;
        }

        $name = trim((string) $node->getAttribute('name'));
        $article = trim((string) $node->getAttribute('article')) ?: null;
        $plural = trim((string) $node->getAttribute('plural')) ?: null;
        $type = null;

        if ($node->hasAttribute('class')) {
            $type = trim((string) $node->getAttribute('class')) ?: null;
        }

        if ($type === null && $node->hasAttribute('type')) {
            $type = trim((string) $node->getAttribute('type')) ?: null;
        }

        $extraAttributes = [];

        foreach ($node->attributes as $attribute) {
            if (!$attribute instanceof DOMAttr) {
                continue;
            }

            $attrName = $attribute->name;

            if (in_array($attrName, ['id', 'fromid', 'toid', 'name', 'article', 'plural', 'class', 'type'], true)) {
                continue;
            }

            $extraAttributes[$attrName] = (string) $attribute->value;
        }

        $description = null;
        $weight = null;
        $stackable = false;

        foreach ($node->getElementsByTagName('attribute') as $attributeNode) {
            if (!$attributeNode instanceof DOMElement) {
                continue;
            }

            $key = trim((string) $attributeNode->getAttribute('key'));
            $value = (string) $attributeNode->getAttribute('value');

            if ($key === '') {
                continue;
            }

            switch ($key) {
                case 'description':
                    $description = $value;
                    break;
                case 'weight':
                    if (is_numeric($value)) {
                        $weight = (int) round((float) $value);
                    }
                    break;
                case 'stackable':
                    $stackable = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
                    break;
                case 'type':
                    if ($type === null) {
                        $type = $value;
                    }
                    $extraAttributes[$key] = $value;
                    break;
                default:
                    $extraAttributes[$key] = $value;
                    break;
            }
        }

        $encodedAttributes = $extraAttributes === [] ? null : json_encode($extraAttributes, JSON_UNESCAPED_UNICODE);

        foreach ($ids as $id) {
            $upsert->execute([
                'id' => $id,
                'name' => $name,
                'article' => $article,
                'plural' => $plural,
                'description' => $description,
                'weight' => $weight,
                'stackable' => $stackable ? 1 : 0,
                'type' => $type,
                'attributes' => $encodedAttributes,
            ]);
            $count++;
        }
    }

    $message = sprintf('Indexed %d items from %s', $count, $itemsFile);
    $logStmt->execute(['kind' => 'items', 'status' => 'ok', 'message' => $message]);

    return [
        'count' => $count,
        'source' => $itemsFile,
    ];
}
