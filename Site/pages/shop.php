<?php
declare(strict_types=1);

$user = current_user();
$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="page page--shop"><h2>Shop</h2><p class="text-muted mb-0">Unavailable.</p></section>';

    return;
}
$purchaseErrors = [];
$successMessage = take_flash('success');
$errorMessage = take_flash('error');
$csrfToken = csrf_token();
$selectedProductId = null;
$characterNameInput = '';

if ($user !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;

    if (!csrf_validate($token)) {
        $purchaseErrors[] = 'Invalid request. Please try again.';
    } else {
        $selectedProductId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : null;
        $characterNameInput = trim((string) ($_POST['character_name'] ?? ''));

        if ($selectedProductId === null || $selectedProductId <= 0) {
            $purchaseErrors[] = 'Please choose a product to buy.';
        }

        if ($characterNameInput === '') {
            $purchaseErrors[] = 'Please enter the name of the character that should receive the delivery.';
        }

        if ($characterNameInput !== '') {
            $characterLength = mb_strlen($characterNameInput, 'UTF-8');

            if ($characterLength > 255) {
                $purchaseErrors[] = 'Character names must be 255 characters or fewer.';
            } else {
                $playerCheck = $pdo->prepare('SELECT id FROM players WHERE name = :name LIMIT 1');
                $playerCheck->execute(['name' => $characterNameInput]);

                if ($playerCheck->fetch() === false) {
                    $purchaseErrors[] = 'That character could not be found.';
                }
            }
        }

        $product = null;

        if ($purchaseErrors === [] && $selectedProductId !== null) {
            $stmt = $pdo->prepare('SELECT p.*, i.name AS index_name, i.description AS index_description, i.stackable AS index_stackable, i.weight AS index_weight
                FROM shop_products p
                LEFT JOIN item_index i ON i.id = p.item_index_id
                WHERE p.id = :id AND p.is_active = 1
                LIMIT 1');
            $stmt->execute(['id' => $selectedProductId]);
            $product = $stmt->fetch();

            if ($product === false) {
                $purchaseErrors[] = 'The selected product is not available.';
            }
        }

        if ($purchaseErrors === [] && $product !== null) {
            $productId = (int) $product['id'];
            $priceCoins = (int) $product['price_coins'];
            $meta = [];

            if (isset($product['meta']) && $product['meta'] !== null) {
                $decoded = json_decode((string) $product['meta'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }

            $deliveryCount = max(1, (int) ($meta['count'] ?? 1));
            $deliveryItemId = (int) ($product['item_index_id'] ?? 0);
            if ($deliveryItemId <= 0) {
                $deliveryItemId = (int) $product['item_id'];
            }

            try {
                $pdo->beginTransaction();

                $balanceStmt = $pdo->prepare('SELECT coins FROM coin_balances WHERE user_id = :user_id FOR UPDATE');
                $balanceStmt->execute(['user_id' => $user['id']]);
                $balanceRow = $balanceStmt->fetch();
                $currentCoins = $balanceRow !== false ? (int) $balanceRow['coins'] : 0;

                if ($priceCoins > $currentCoins) {
                    $pdo->rollBack();
                    $purchaseErrors[] = 'You do not have enough coins to buy this product.';
                    audit_log((int) $user['id'], 'shop_purchase_failed', [
                        'product_id' => $productId,
                        'character' => $characterNameInput,
                        'coins' => $currentCoins,
                        'price' => $priceCoins,
                    ]);
                } else {
                    if ($balanceRow === false) {
                        $balanceInsert = $pdo->prepare('INSERT INTO coin_balances (user_id, coins) VALUES (:user_id, :coins)');
                        $balanceInsert->execute([
                            'user_id' => $user['id'],
                            'coins' => 0,
                        ]);
                    }

                    $coinsAfter = $currentCoins;

                    if ($priceCoins > 0) {
                        $updateStmt = $pdo->prepare('UPDATE coin_balances SET coins = coins - :price WHERE user_id = :user_id');
                        $updateStmt->execute([
                            'price' => $priceCoins,
                            'user_id' => $user['id'],
                        ]);
                        $coinsAfter = $currentCoins - $priceCoins;
                    }

                    $orderStmt = $pdo->prepare('INSERT INTO shop_orders (user_id, product_id, player_name) VALUES (:user_id, :product_id, :player_name)');
                    $orderStmt->execute([
                        'user_id' => $user['id'],
                        'product_id' => $productId,
                        'player_name' => $characterNameInput,
                    ]);
                    $orderId = (int) $pdo->lastInsertId();

                    $pdo->commit();

                    audit_log((int) $user['id'], 'shop_purchase_created', [
                        'product_id' => $productId,
                        'player_name' => $characterNameInput,
                        'coins_before' => $currentCoins,
                        'delivery_item' => $deliveryItemId,
                        'delivery_count' => $deliveryCount,
                    ], [
                        'order_id' => $orderId,
                        'coins_after' => max(0, $coinsAfter),
                    ]);

                    flash('success', 'Your order has been placed and will be processed soon.');
                    redirect('?p=shop');
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $purchaseErrors[] = 'Unable to place your order right now. Please try again.';
                audit_log((int) $user['id'], 'shop_purchase_error', [
                    'product_id' => $selectedProductId,
                    'player_name' => $characterNameInput,
                ], [
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }
}

$productsStmt = $pdo->query('SELECT p.*, i.name AS index_name, i.description AS index_description, i.stackable AS index_stackable, i.weight AS index_weight
    FROM shop_products p
    LEFT JOIN item_index i ON i.id = p.item_index_id
    WHERE p.is_active = 1
    ORDER BY p.name ASC');
$products = $productsStmt->fetchAll();

foreach ($products as &$product) {
    $product['meta_decoded'] = [];
    if (isset($product['meta']) && $product['meta'] !== null) {
        $decoded = json_decode((string) $product['meta'], true);
        if (is_array($decoded)) {
            $product['meta_decoded'] = $decoded;
        }
    }
}
unset($product);

$coinBalance = 0;

if ($user !== null) {
    $coinsStmt = $pdo->prepare('SELECT coins FROM coin_balances WHERE user_id = :user_id LIMIT 1');
    $coinsStmt->execute(['user_id' => $user['id']]);
    $coinsRow = $coinsStmt->fetch();
    $coinBalance = $coinsRow !== false ? (int) $coinsRow['coins'] : 0;
}
?>
<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-bag-heart me-2"></i>Shop</h4>
    <div class="text-muted small">Spend your Nexus coins</div>
</div>

<?php if ($errorMessage): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo sanitize($errorMessage); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($successMessage): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo sanitize($successMessage); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!$user): ?>
    <div class="alert alert-info">You need to <a class="alert-link" href="?p=account">log in</a> to buy products from the shop.</div>
<?php else: ?>
    <div class="card nx-glow mb-3">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Current balance</div>
                <div class="h5 mb-0"><i class="bi bi-coin me-2"></i><?php echo sanitize(number_format($coinBalance)); ?> coins</div>
            </div>
            <span class="badge bg-primary-subtle text-primary-emphasis px-3 py-2">Happy shopping!</span>
        </div>
    </div>

    <?php if ($purchaseErrors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                <?php foreach ($purchaseErrors as $error): ?>
                    <li><?php echo sanitize($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($products === []): ?>
        <p class="text-muted">The shop is currently closed. Please check back later.</p>
    <?php else: ?>
        <div class="row g-3">
          <?php foreach ($products as $product): ?>
            <?php
                $productId = (int) $product['id'];
                $meta = $product['meta_decoded'];
                $deliveryCount = (int) ($meta['count'] ?? 1);
                $description = (string) ($meta['description'] ?? ($product['index_description'] ?? ''));
                $itemName = $product['index_name'] ?? $product['name'];
                $image = $product['image'] ?? null;
                $imageUrl = $image !== null && $image !== '' ? $image : '/assets/img/item-default.png';
            ?>
            <div class="col-12 col-sm-6 col-lg-4">
              <div class="card nx-glow h-100">
                <div class="card-body">
                  <div class="d-flex align-items-center mb-2">
                    <img src="<?php echo htmlspecialchars($imageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="me-2" style="height:32px;width:32px;border-radius:8px" alt="">
                    <div>
                      <h6 class="mb-0 text-light"><?php echo sanitize($product['name']); ?></h6>
                      <div class="text-muted small"><?php echo sanitize($itemName); ?> × <?php echo $deliveryCount; ?></div>
                    </div>
                  </div>
                  <?php if ($description !== ''): ?>
                    <p class="text-muted small mb-2"><?php echo sanitize($description); ?></p>
                  <?php endif; ?>
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="badge bg-primary-subtle text-primary-emphasis px-3 py-2"><i class="bi bi-coin me-1"></i><?php echo (int) $product['price_coins']; ?> coins</span>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis px-3 py-2">ID <?php echo (int) $product['item_id']; ?></span>
                  </div>
                  <?php if (isset($product['index_weight']) || isset($product['index_stackable'])): ?>
                    <div class="d-flex gap-2 mb-3">
                      <?php if (isset($product['index_weight'])): ?>
                        <span class="badge bg-dark-subtle text-light-emphasis">Weight: <?php echo (int) $product['index_weight']; ?></span>
                      <?php endif; ?>
                      <?php if (isset($product['index_stackable'])): ?>
                        <span class="badge bg-dark-subtle text-light-emphasis"><?php echo ((int) $product['index_stackable']) === 1 ? 'Stackable' : 'Single'; ?></span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <form method="post" class="d-grid gap-2">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">
                    <input type="hidden" name="product_id" value="<?php echo $productId; ?>">
                    <input type="text" class="form-control form-control-sm" name="character_name" placeholder="Character name" value="<?php echo $selectedProductId === $productId ? sanitize($characterNameInput) : ''; ?>" required>
                    <button class="btn btn-primary btn-sm" type="submit">Buy</button>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
