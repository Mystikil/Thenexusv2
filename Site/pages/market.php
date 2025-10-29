<?php
$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="page page--market"><h2>Market</h2><p class="text-muted mb-0">Unavailable.</p></section>';

    return;
}

function get_item_names(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    $file = dirname(__DIR__, 2) . '/data/items/items.xml';

    if (!is_file($file)) {
        return $cache;
    }

    $xml = @simplexml_load_file($file);
    if ($xml === false) {
        return $cache;
    }

    foreach ($xml->item as $item) {
        if (!isset($item['id']) || !isset($item['name'])) {
            continue;
        }

        $id = (int) $item['id'];
        $name = (string) $item['name'];
        if ($id > 0 && $name !== '') {
            $cache[$id] = $name;
        }
    }

    return $cache;
}

$itemNames = get_item_names();
$itemQuery = trim((string) ($_GET['item'] ?? ''));
$sellerQuery = trim((string) ($_GET['seller'] ?? ''));

$conditions = ['1 = 1'];
$params = [];
$itemIds = [];

if ($itemQuery !== '') {
    foreach ($itemNames as $id => $name) {
        if (stripos($name, $itemQuery) !== false) {
            $itemIds[] = $id;
        }
    }

    if ($itemIds === []) {
        $offers = [];
    } else {
        $placeholders = implode(', ', array_fill(0, count($itemIds), '?'));
        $conditions[] = 'mo.itemtype IN (' . $placeholders . ')';
        foreach ($itemIds as $id) {
            $params[] = $id;
        }
    }
}

if (!isset($offers)) {
    if ($sellerQuery !== '') {
        $conditions[] = 'p.name LIKE ?';
        $params[] = '%' . $sellerQuery . '%';
    }

    $sql = 'SELECT mo.id, mo.sale, mo.itemtype, mo.amount, mo.price, mo.created, mo.anonymous, p.name AS seller_name
        FROM market_offers mo
        LEFT JOIN players p ON p.id = mo.player_id
        WHERE ' . implode(' AND ', $conditions) . '
        ORDER BY mo.created DESC
        LIMIT 50';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $offers = $stmt->fetchAll();
}

function format_timestamp(int $timestamp): string
{
    if ($timestamp <= 0) {
        return 'Unknown';
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function market_offer_type(int $sale): string
{
    return $sale === 1 ? 'Sell' : 'Buy';
}
?>
<section class="page page--market">
    <h2>Market</h2>
    <form method="get" class="market__filters">
        <input type="hidden" name="p" value="market">
        <label>
            Item name:
            <input type="text" name="item" value="<?php echo sanitize($itemQuery); ?>" placeholder="e.g. sword">
        </label>
        <label>
            Seller:
            <input type="text" name="seller" value="<?php echo sanitize($sellerQuery); ?>" placeholder="e.g. Alice">
        </label>
        <button type="submit">Search</button>
    </form>

    <?php if ($offers === []): ?>
        <p>No offers found.</p>
    <?php else: ?>
        <table class="table table--market">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Item</th>
                    <th>Amount</th>
                    <th>Price</th>
                    <th>Seller</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($offers as $offer): ?>
                    <?php
                        $itemId = (int) $offer['itemtype'];
                        $itemName = $itemNames[$itemId] ?? ('Item #' . $itemId);
                    ?>
                    <tr>
                        <td><?php echo sanitize(market_offer_type((int) $offer['sale'])); ?></td>
                        <td><?php echo sanitize($itemName); ?></td>
                        <td><?php echo (int) $offer['amount']; ?></td>
                        <td><?php echo sanitize(number_format((int) $offer['price'])); ?></td>
                        <td>
                            <?php if ((int) $offer['anonymous'] === 1): ?>
                                <?php echo sanitize('Anonymous'); ?>
                            <?php else: ?>
                                <?php echo sanitize($offer['seller_name'] ?? 'Unknown'); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo sanitize(format_timestamp((int) $offer['created'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
