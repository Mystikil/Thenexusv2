<?php

declare(strict_types=1);


require_once __DIR__ . '/partials/bootstrap.php';
require_once __DIR__ . '/../auth.php';
require_admin('admin');

$adminPageTitle = 'Shop';
$adminNavActive = 'shop';

require __DIR__ . '/partials/header.php';

$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="admin-section"><h2>Shop</h2><div class="admin-alert admin-alert--error">Database connection unavailable.</div></section>';
    require __DIR__ . '/partials/footer.php';

    return;
}
$currentAdmin = current_user();
$actorIsMaster = $currentAdmin !== null && is_master($currentAdmin);
$tab = $_GET['tab'] ?? 'products';
$tab = in_array($tab, ['products', 'orders'], true) ? $tab : 'products';
$csrfToken = csrf_token();
$successMessage = take_flash('success');
$errorMessage = take_flash('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? null;
    $redirectTab = $_POST['tab'] ?? $tab;
    $redirectTab = in_array($redirectTab, ['products', 'orders'], true) ? $redirectTab : 'products';

    if (!csrf_validate($token)) {
        flash('error', 'Invalid request. Please try again.');
        redirect('shop.php?tab=' . urlencode($redirectTab));
    }

    try {
        switch ($action) {
            case 'create_product':
                $name = trim((string) ($_POST['name'] ?? ''));
                $itemId = (int) ($_POST['item_id'] ?? 0);
                $itemIndexId = (int) ($_POST['item_index_id'] ?? 0);
                $priceCoins = (int) ($_POST['price_coins'] ?? 0);
                $count = max(1, (int) ($_POST['count'] ?? 1));
                $description = trim((string) ($_POST['description'] ?? ''));
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                $itemIndexRow = null;
                if ($itemIndexId > 0) {
                    $stmt = $pdo->prepare('SELECT id, name, description FROM item_index WHERE id = :id LIMIT 1');
                    $stmt->execute(['id' => $itemIndexId]);
                    $itemIndexRow = $stmt->fetch();

                    if ($itemIndexRow === false) {
                        throw new RuntimeException('The selected item from items.xml could not be found.');
                    }

                    if ($name === '') {
                        $name = (string) $itemIndexRow['name'];
                    }

                    if ($itemId <= 0) {
                        $itemId = (int) $itemIndexRow['id'];
                    }

                    if ($description === '' && isset($itemIndexRow['description']) && $itemIndexRow['description'] !== null) {
                        $description = (string) $itemIndexRow['description'];
                    }
                }

                if ($name === '') {
                    throw new RuntimeException('Product name is required.');
                }

                if ($itemId <= 0) {
                    throw new RuntimeException('Item ID must be greater than zero.');
                }

                if ($priceCoins < 0) {
                    throw new RuntimeException('Price cannot be negative.');
                }

                $meta = ['count' => $count];
                if ($description !== '') {
                    $meta['description'] = $description;
                }

                $stmt = $pdo->prepare('INSERT INTO shop_products (name, item_id, price_coins, is_active, item_index_id, meta)
                    VALUES (:name, :item_id, :price_coins, :is_active, :item_index_id, :meta)');
                $stmt->execute([
                    'name' => $name,
                    'item_id' => $itemId,
                    'price_coins' => $priceCoins,
                    'is_active' => $isActive,
                    'item_index_id' => $itemIndexId > 0 ? $itemIndexId : null,
                    'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                ]);

                $productId = (int) $pdo->lastInsertId();

                audit_log($currentAdmin['id'] ?? null, 'admin_shop_product_create', null, [
                    'product_id' => $productId,
                    'name' => $name,
                    'item_id' => $itemId,
                    'item_index_id' => $itemIndexId,
                    'price_coins' => $priceCoins,
                    'is_active' => $isActive,
                    'meta' => $meta,
                    'a_is_master' => $actorIsMaster ? 1 : 0,
                ]);

                flash('success', 'Product created successfully.');
                redirect('shop.php?tab=products');
                break;

            case 'update_product':
                $productId = (int) ($_POST['product_id'] ?? 0);
                $name = trim((string) ($_POST['name'] ?? ''));
                $itemId = (int) ($_POST['item_id'] ?? 0);
                $itemIndexId = (int) ($_POST['item_index_id'] ?? 0);
                $priceCoins = (int) ($_POST['price_coins'] ?? 0);
                $count = max(1, (int) ($_POST['count'] ?? 1));
                $description = trim((string) ($_POST['description'] ?? ''));
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                $stmt = $pdo->prepare('SELECT * FROM shop_products WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $productId]);
                $existing = $stmt->fetch();

                if ($existing === false) {
                    throw new RuntimeException('Product not found.');
                }

                $itemIndexRow = null;
                if ($itemIndexId > 0) {
                    $lookup = $pdo->prepare('SELECT id, name, description FROM item_index WHERE id = :id LIMIT 1');
                    $lookup->execute(['id' => $itemIndexId]);
                    $itemIndexRow = $lookup->fetch();

                    if ($itemIndexRow === false) {
                        throw new RuntimeException('The selected item from items.xml could not be found.');
                    }

                    if ($name === '') {
                        $name = (string) $itemIndexRow['name'];
                    }

                    if ($itemId <= 0) {
                        $itemId = (int) $itemIndexRow['id'];
                    }

                    if ($description === '' && isset($itemIndexRow['description']) && $itemIndexRow['description'] !== null) {
                        $description = (string) $itemIndexRow['description'];
                    }
                }

                if ($name === '') {
                    throw new RuntimeException('Product name is required.');
                }

                if ($itemId <= 0) {
                    throw new RuntimeException('Item ID must be greater than zero.');
                }

                if ($priceCoins < 0) {
                    throw new RuntimeException('Price cannot be negative.');
                }

                $meta = ['count' => $count];
                if ($description !== '') {
                    $meta['description'] = $description;
                }

                $updateStmt = $pdo->prepare('UPDATE shop_products
                    SET name = :name, item_id = :item_id, price_coins = :price_coins, is_active = :is_active,
                        item_index_id = :item_index_id, meta = :meta
                    WHERE id = :id');
                $updateStmt->execute([
                    'name' => $name,
                    'item_id' => $itemId,
                    'price_coins' => $priceCoins,
                    'is_active' => $isActive,
                    'item_index_id' => $itemIndexId > 0 ? $itemIndexId : null,
                    'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'id' => $productId,
                ]);

                audit_log($currentAdmin['id'] ?? null, 'admin_shop_product_update', $existing, [
                    'id' => $productId,
                    'name' => $name,
                    'item_id' => $itemId,
                    'item_index_id' => $itemIndexId,
                    'price_coins' => $priceCoins,
                    'is_active' => $isActive,
                    'meta' => $meta,
                    'a_is_master' => $actorIsMaster ? 1 : 0,
                ]);

                flash('success', 'Product updated successfully.');
                redirect('shop.php?tab=products');
                break;

            case 'delete_product':
                $productId = (int) ($_POST['product_id'] ?? 0);

                $stmt = $pdo->prepare('SELECT * FROM shop_products WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $productId]);
                $product = $stmt->fetch();

                if ($product === false) {
                    throw new RuntimeException('Product not found.');
                }

                $deleteStmt = $pdo->prepare('DELETE FROM shop_products WHERE id = :id');
                $deleteStmt->execute(['id' => $productId]);

                audit_log($currentAdmin['id'] ?? null, 'admin_shop_product_delete', $product, [
                    'a_is_master' => $actorIsMaster ? 1 : 0,
                ]);

                flash('success', 'Product deleted successfully.');
                redirect('shop.php?tab=products');
                break;

            case 'enqueue_order':
                $orderId = (int) ($_POST['order_id'] ?? 0);

                $orderStmt = $pdo->prepare('SELECT o.*, u.email, p.name AS product_name, p.item_id, p.item_index_id, p.price_coins, p.meta
                    FROM shop_orders o
                    INNER JOIN website_users u ON u.id = o.user_id
                    INNER JOIN shop_products p ON p.id = o.product_id
                    WHERE o.id = :id AND o.status = "pending"
                    LIMIT 1');
                $orderStmt->execute(['id' => $orderId]);
                $order = $orderStmt->fetch();

                if ($order === false) {
                    throw new RuntimeException('Order not found or already processed.');
                }

                if (trim((string) $order['player_name']) === '') {
                    throw new RuntimeException('Order is missing a character name.');
                }

                $existingJobStmt = $pdo->prepare("SELECT id FROM rcon_jobs WHERE type = 'deliver_shop_order' AND args_json LIKE :pattern AND status IN ('queued', 'in_progress') LIMIT 1");
                $existingJobStmt->execute(['pattern' => '%"order_id":' . $orderId . '%']);

                if ($existingJobStmt->fetch()) {
                    throw new RuntimeException('An active delivery job already exists for this order.');
                }

                $meta = [];
                if (isset($order['meta']) && $order['meta'] !== null) {
                    $decoded = json_decode((string) $order['meta'], true);
                    if (is_array($decoded)) {
                        $meta = $decoded;
                    }
                }

                $deliveryItemId = (int) ($order['item_index_id'] ?? 0);
                if ($deliveryItemId <= 0) {
                    $deliveryItemId = (int) $order['item_id'];
                }

                $deliveryCount = max(1, (int) ($meta['count'] ?? 1));

                $args = [
                    'order_id' => (int) $order['id'],
                    'player' => $order['player_name'],
                    'item_id' => $deliveryItemId,
                    'count' => $deliveryCount,
                    'inbox' => true,
                ];

                $argsJson = json_encode($args, JSON_UNESCAPED_UNICODE);

                $jobStmt = $pdo->prepare('INSERT INTO rcon_jobs (type, args_json) VALUES (:type, :args_json)');
                $jobStmt->execute([
                    'type' => 'deliver_shop_order',
                    'args_json' => $argsJson,
                ]);

                $jobId = (int) $pdo->lastInsertId();

                $updateOrderStmt = $pdo->prepare('UPDATE shop_orders SET result_text = :result_text WHERE id = :id');
                $updateOrderStmt->execute([
                    'result_text' => 'Queued in job #' . $jobId,
                    'id' => $orderId,
                ]);

                audit_log($currentAdmin['id'] ?? null, 'admin_shop_order_enqueue', $order, [
                    'order_id' => $orderId,
                    'job_id' => $jobId,
                    'args' => $args,
                    'a_is_master' => $actorIsMaster ? 1 : 0,
                ]);

                flash('success', 'Delivery job queued successfully.');
                redirect('shop.php?tab=orders');
                break;

            default:
                throw new RuntimeException('Unsupported action.');
        }
    } catch (RuntimeException $exception) {
        flash('error', $exception->getMessage());
        redirect('shop.php?tab=' . urlencode($redirectTab));
    }
}

$itemSearchQuery = '';
$itemSearchResults = [];
$prefillProduct = [
    'name' => '',
    'item_id' => '',
    'price_coins' => '',
    'is_active' => 1,
    'item_index_id' => '',
    'count' => 1,
    'description' => '',
];

if ($tab === 'products') {
    $itemSearchQuery = trim((string) ($_GET['item_search'] ?? ''));
    if ($itemSearchQuery !== '') {
        $conditions = [];
        $params = [];

        if (ctype_digit($itemSearchQuery)) {
            $conditions[] = 'id = :exact_id';
            $params['exact_id'] = (int) $itemSearchQuery;
        }

        $conditions[] = 'name LIKE :name_like';
        $params['name_like'] = '%' . $itemSearchQuery . '%';

        $query = 'SELECT id, name, plural, description, weight, stackable, attributes FROM item_index WHERE ' . implode(' OR ', $conditions) . ' ORDER BY name ASC LIMIT 25';
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $itemSearchResults = $stmt->fetchAll();
    }

    $useItemId = isset($_GET['use_item']) ? (int) $_GET['use_item'] : 0;
    if ($useItemId > 0) {
        $stmt = $pdo->prepare('SELECT id, name, description, stackable FROM item_index WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $useItemId]);
        $useItem = $stmt->fetch();

        if ($useItem !== false) {
            $prefillProduct['name'] = (string) $useItem['name'];
            $prefillProduct['item_id'] = (int) $useItem['id'];
            $prefillProduct['item_index_id'] = (int) $useItem['id'];
            $prefillProduct['description'] = (string) ($useItem['description'] ?? '');
            $prefillProduct['count'] = ((int) ($useItem['stackable'] ?? 0)) === 1 ? 100 : 1;
        }
    }
}

$products = $pdo->query('SELECT p.*, i.name AS index_name, i.description AS index_description, i.stackable AS index_stackable, i.weight AS index_weight
    FROM shop_products p
    LEFT JOIN item_index i ON i.id = p.item_index_id
    ORDER BY p.name ASC')->fetchAll();

foreach ($products as &$productRow) {
    $productRow['meta_decoded'] = [];
    if (isset($productRow['meta']) && $productRow['meta'] !== null) {
        $decoded = json_decode((string) $productRow['meta'], true);
        if (is_array($decoded)) {
            $productRow['meta_decoded'] = $decoded;
        }
    }
}
unset($productRow);

$editProduct = null;
if ($tab === 'products' && isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    foreach ($products as $product) {
        if ((int) $product['id'] === $editId) {
            $editProduct = $product;
            break;
        }
    }
}

$ordersStmt = $pdo->query('SELECT o.id, o.player_name, o.created_at, u.email, p.name AS product_name, p.item_id, p.item_index_id, p.price_coins, p.meta
    FROM shop_orders o
    INNER JOIN website_users u ON u.id = o.user_id
    INNER JOIN shop_products p ON p.id = o.product_id
    WHERE o.status = "pending"
    ORDER BY o.created_at ASC');
$pendingOrders = $ordersStmt->fetchAll();
?>
<section class="admin-section admin-section--shop">
    <h2>Shop Management</h2>

    <div class="admin-tabs">
        <a class="admin-tabs__link <?php echo $tab === 'products' ? 'is-active' : ''; ?>" href="?tab=products">Products</a>
        <a class="admin-tabs__link <?php echo $tab === 'orders' ? 'is-active' : ''; ?>" href="?tab=orders">Orders</a>
    </div>

    <div class="admin-actions admin-actions--compact">
        <form method="post" action="indexers.php?action=reindex_items">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">
            <input type="hidden" name="return_to" value="shop.php?tab=<?php echo sanitize($tab); ?>">
            <button type="submit" class="admin-button">Re-index Items</button>
        </form>
    </div>

    <?php if ($errorMessage): ?>
        <div class="alert alert--error"><?php echo sanitize($errorMessage); ?></div>
    <?php endif; ?>

    <?php if ($successMessage): ?>
        <div class="alert alert--success"><?php echo sanitize($successMessage); ?></div>
    <?php endif; ?>

    <?php if ($tab === 'products'): ?>
        <section class="admin-card">
            <h3>Create Product</h3>
            <form method="post" class="admin-form">
                <input type="hidden" name="action" value="create_product">
                <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">
                <input type="hidden" name="tab" value="products">

                <div class="form-group">
                    <label for="product-name">Name</label>
                    <input type="text" id="product-name" name="name" value="<?php echo sanitize($prefillProduct['name']); ?>">
                </div>

                <div class="form-group">
                    <label for="product-item-id">Item ID</label>
                    <input type="number" id="product-item-id" name="item_id" min="1" value="<?php echo sanitize($prefillProduct['item_id']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="product-item-index">Item Index ID (optional)</label>
                    <input type="number" id="product-item-index" name="item_index_id" min="1" value="<?php echo sanitize($prefillProduct['item_index_id']); ?>">
                    <p class="admin-form__hint">Link this product to the parsed items.xml entry for automatic metadata.</p>
                </div>

                <div class="form-group">
                    <label for="product-count">Delivery Count</label>
                    <input type="number" id="product-count" name="count" min="1" value="<?php echo sanitize($prefillProduct['count']); ?>">
                </div>

                <div class="form-group">
                    <label for="product-description">Description</label>
                    <textarea id="product-description" name="description" rows="3"><?php echo sanitize($prefillProduct['description']); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="product-price">Price (coins)</label>
                    <input type="number" id="product-price" name="price_coins" min="0" required>
                </div>

                <div class="form-group form-group--checkbox">
                    <label>
                        <input type="checkbox" name="is_active" value="1" checked>
                        Active
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="admin-button">Create</button>
                </div>
            </form>
        </section>

        <section class="admin-card">
            <h3>Add from items.xml</h3>
            <form method="get" class="admin-form admin-form--inline">
                <input type="hidden" name="tab" value="products">
                <div class="form-group">
                    <label for="item-search" class="visually-hidden">Search items</label>
                    <input type="text" id="item-search" name="item_search" value="<?php echo sanitize($itemSearchQuery); ?>" placeholder="Search by name or ID">
                </div>
                <div class="form-actions">
                    <button type="submit" class="admin-button">Search</button>
                </div>
            </form>

            <?php if ($itemSearchQuery !== '' && $itemSearchResults === []): ?>
                <p>No items matched your search.</p>
            <?php elseif ($itemSearchResults !== []): ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itemSearchResults as $item): ?>
                            <tr>
                                <td><?php echo (int) $item['id']; ?></td>
                                <td><?php echo sanitize($item['name']); ?></td>
                                <td><?php echo sanitize((string) ($item['description'] ?? '')); ?></td>
                                <td>
                                    <a class="admin-button admin-button--secondary" href="?tab=products&amp;use_item=<?php echo (int) $item['id']; ?>">Use Item</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="admin-card">
            <h3>Existing Products</h3>

            <?php if ($products === []): ?>
                <p>No products found.</p>
            <?php else: ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Item</th>
                            <th>Price</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?php echo (int) $product['id']; ?></td>
                                <td><?php echo sanitize($product['name']); ?></td>
                                <td>
                                    <div><?php echo sanitize($product['item_id']); ?><?php if ($product['item_index_id']): ?> (index #<?php echo (int) $product['item_index_id']; ?>)<?php endif; ?></div>
                                    <?php $meta = $product['meta_decoded']; ?>
                                    <?php if (isset($meta['count'])): ?>
                                        <div class="admin-table__meta">Count: <?php echo (int) $meta['count']; ?></div>
                                    <?php endif; ?>
                                    <?php if (isset($meta['description'])): ?>
                                        <div class="admin-table__meta">Desc: <?php echo sanitize($meta['description']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo sanitize(number_format((int) $product['price_coins'])); ?></td>
                                <td><?php echo (int) $product['is_active'] === 1 ? 'Active' : 'Inactive'; ?></td>
                                <td class="admin-table__actions">
                                    <a class="admin-button admin-button--secondary" href="?tab=products&amp;edit=<?php echo (int) $product['id']; ?>">Edit</a>
                                    <form method="post" onsubmit="return confirm('Delete this product?');">
                                        <input type="hidden" name="action" value="delete_product">
                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">
                                        <input type="hidden" name="tab" value="products">
                                        <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                                        <button type="submit" class="admin-button admin-button--danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <?php if ($editProduct !== null): ?>
            <?php $editMeta = $editProduct['meta_decoded']; ?>
            <section class="admin-card">
                <h3>Edit Product #<?php echo (int) $editProduct['id']; ?></h3>
                <form method="post" class="admin-form">
                    <input type="hidden" name="action" value="update_product">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">
                    <input type="hidden" name="tab" value="products">
                    <input type="hidden" name="product_id" value="<?php echo (int) $editProduct['id']; ?>">

                    <div class="form-group">
                        <label for="edit-name">Name</label>
                        <input type="text" id="edit-name" name="name" value="<?php echo sanitize($editProduct['name']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="edit-item-id">Item ID</label>
                        <input type="number" id="edit-item-id" name="item_id" value="<?php echo (int) $editProduct['item_id']; ?>" min="1" required>
                    </div>

                    <div class="form-group">
                        <label for="edit-item-index">Item Index ID</label>
                        <input type="number" id="edit-item-index" name="item_index_id" value="<?php echo sanitize($editProduct['item_index_id']); ?>" min="1">
                    </div>

                    <div class="form-group">
                        <label for="edit-count">Delivery Count</label>
                        <input type="number" id="edit-count" name="count" value="<?php echo sanitize((int) ($editMeta['count'] ?? 1)); ?>" min="1">
                    </div>

                    <div class="form-group">
                        <label for="edit-description">Description</label>
                        <textarea id="edit-description" name="description" rows="3"><?php echo sanitize((string) ($editMeta['description'] ?? '')); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="edit-price">Price (coins)</label>
                        <input type="number" id="edit-price" name="price_coins" value="<?php echo (int) $editProduct['price_coins']; ?>" min="0" required>
                    </div>

                    <div class="form-group form-group--checkbox">
                        <label>
                            <input type="checkbox" name="is_active" value="1" <?php echo (int) $editProduct['is_active'] === 1 ? 'checked' : ''; ?>>
                            Active
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="admin-button">Save Changes</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <section class="admin-card">
            <h3>Pending Orders</h3>

            <?php if ($pendingOrders === []): ?>
                <p>No pending orders found.</p>
            <?php else: ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Character</th>
                            <th>Product</th>
                            <th>Price</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingOrders as $order): ?>
                            <?php
                                $orderMeta = [];
                                if ($order['meta'] !== null) {
                                    $decoded = json_decode((string) $order['meta'], true);
                                    if (is_array($decoded)) {
                                        $orderMeta = $decoded;
                                    }
                                }
                                $orderCount = (int) ($orderMeta['count'] ?? 1);
                            ?>
                            <tr>
                                <td><?php echo (int) $order['id']; ?></td>
                                <td><?php echo sanitize($order['email']); ?></td>
                                <td><?php echo sanitize($order['player_name']); ?></td>
                                <td>
                                    <?php echo sanitize($order['product_name']); ?>
                                    <div class="admin-table__meta">Item ID: <?php echo (int) ($order['item_index_id'] ?? $order['item_id']); ?></div>
                                    <div class="admin-table__meta">Count: <?php echo $orderCount; ?></div>
                                </td>
                                <td><?php echo sanitize(number_format((int) $order['price_coins'])); ?></td>
                                <td><?php echo sanitize($order['created_at']); ?></td>
                                <td class="admin-table__actions">
                                    <form method="post">
                                        <input type="hidden" name="action" value="enqueue_order">
                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">
                                        <input type="hidden" name="tab" value="orders">
                                        <input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
                                        <button type="submit" class="admin-button">Enqueue Delivery</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</section>
<?php
require __DIR__ . '/partials/footer.php';
