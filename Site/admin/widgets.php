<?php

declare(strict_types=1);


require_once __DIR__ . '/partials/bootstrap.php';
require_once __DIR__ . '/../auth.php';
require_admin('admin');

$adminPageTitle = 'Widgets';
$adminNavActive = 'widgets';

require __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../widgets/_registry.php';

function nx_admin_widget_page_slugs(): array
{
    static $cache;

    if (is_array($cache)) {
        return $cache;
    }

    $pages = [];
    $directory = __DIR__ . '/../pages';

    foreach (glob($directory . '/*.php') as $file) {
        $slug = basename($file, '.php');
        $normalized = nx_widget_normalize_page_slug($slug);

        if ($normalized === '') {
            continue;
        }

        $pages[$normalized] = true;
    }

    $cache = array_keys($pages);
    sort($cache);

    return $cache;
}

function nx_admin_widget_sanitize_order(array $order, array $registry): array
{
    $sanitized = [];

    foreach ($order as $slug) {
        $normalized = nx_widget_normalize_slug(is_string($slug) ? $slug : null);

        if ($normalized === '' || isset($sanitized[$normalized])) {
            continue;
        }

        if (!array_key_exists($normalized, $registry)) {
            continue;
        }

        $sanitized[$normalized] = true;
    }

    foreach (array_keys($registry) as $slug) {
        if (!isset($sanitized[$slug])) {
            $sanitized[$slug] = true;
        }
    }

    return array_keys($sanitized);
}

function nx_admin_widget_sanitize_enabled(array $enabled, array $registry): array
{
    $result = [];

    foreach ($enabled as $slug => $value) {
        $normalized = nx_widget_normalize_slug(is_string($slug) ? $slug : null);

        if ($normalized === '' || !array_key_exists($normalized, $registry)) {
            continue;
        }

        $result[$normalized] = true;
    }

    return $result;
}

function nx_admin_widget_apply_move(array $order, string $slug, string $direction): array
{
    $index = array_search($slug, $order, true);

    if ($index === false) {
        return $order;
    }

    if ($direction === 'up' && $index > 0) {
        $swap = $index - 1;
    } elseif ($direction === 'down' && $index < count($order) - 1) {
        $swap = $index + 1;
    } else {
        return $order;
    }

    [$order[$index], $order[$swap]] = [$order[$swap], $order[$index]];

    return array_values($order);
}

$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="admin-section"><h2>Widgets</h2><div class="admin-alert admin-alert--error">Database connection unavailable.</div></section>';
    require __DIR__ . '/partials/footer.php';

    return;
}
$csrfToken = csrf_token();
$successMessage = take_flash('success');
$errorMessage = take_flash('error');
$allWidgets = nx_widget_registry();
$availablePages = nx_admin_widget_page_slugs();
$selectedPage = $_GET['page'] ?? '';
$selectedPage = nx_widget_normalize_page_slug($selectedPage);

if ($selectedPage === '' || ($availablePages !== [] && !in_array($selectedPage, $availablePages, true))) {
    $selectedPage = $availablePages[0] ?? '';
}

$redirectBase = 'widgets.php' . ($selectedPage !== '' ? '?page=' . urlencode($selectedPage) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $context = $_POST['context'] ?? '';
    $token = $_POST['csrf_token'] ?? null;

    if (!csrf_validate($token)) {
        flash('error', 'The request could not be validated. Please try again.');
        redirect($redirectBase);
    }

    if ($context === 'default') {
        $leftOrder = nx_admin_widget_sanitize_order($_POST['left_order'] ?? [], $allWidgets);
        $rightOrder = nx_admin_widget_sanitize_order($_POST['right_order'] ?? [], $allWidgets);
        $leftEnabled = nx_admin_widget_sanitize_enabled($_POST['left_enabled'] ?? [], $allWidgets);
        $rightEnabled = nx_admin_widget_sanitize_enabled($_POST['right_enabled'] ?? [], $allWidgets);

        if (isset($_POST['reset_default'])) {
            nx_widget_delete_configuration($pdo, 'left', null);
            nx_widget_delete_configuration($pdo, 'right', null);
            flash('success', 'Default layout reset to the original order.');
            redirect($redirectBase);
        }

        $moveAction = false;

        if (isset($_POST['move']['left']) && is_array($_POST['move']['left'])) {
            foreach ($_POST['move']['left'] as $slug => $direction) {
                $slug = nx_widget_normalize_slug(is_string($slug) ? $slug : null);
                $direction = $direction === 'down' ? 'down' : 'up';
                $leftOrder = nx_admin_widget_apply_move($leftOrder, $slug, $direction);
                $moveAction = true;
                break;
            }
        }

        if (isset($_POST['move']['right']) && is_array($_POST['move']['right'])) {
            foreach ($_POST['move']['right'] as $slug => $direction) {
                $slug = nx_widget_normalize_slug(is_string($slug) ? $slug : null);
                $direction = $direction === 'down' ? 'down' : 'up';
                $rightOrder = nx_admin_widget_apply_move($rightOrder, $slug, $direction);
                $moveAction = true;
                break;
            }
        }

        $leftEnabledOrdered = [];
        foreach ($leftOrder as $slug) {
            if (isset($leftEnabled[$slug])) {
                $leftEnabledOrdered[] = $slug;
            }
        }

        $rightEnabledOrdered = [];
        foreach ($rightOrder as $slug) {
            if (isset($rightEnabled[$slug])) {
                $rightEnabledOrdered[] = $slug;
            }
        }

        nx_widget_save_enabled_slugs($pdo, 'left', null, $leftEnabledOrdered);
        nx_widget_save_enabled_slugs($pdo, 'right', null, $rightEnabledOrdered);

        if ($moveAction) {
            flash('success', 'Default widget order updated.');
        } else {
            flash('success', 'Default layout saved.');
        }

        redirect($redirectBase);
    }

    if ($context === 'override') {
        $page = nx_widget_normalize_page_slug($_POST['page'] ?? $selectedPage);

        if ($page === '' || !in_array($page, $availablePages, true)) {
            flash('error', 'Invalid page selected.');
            redirect('widgets.php');
        }

        $leftOrder = nx_admin_widget_sanitize_order($_POST['left_order'] ?? [], $allWidgets);
        $rightOrder = nx_admin_widget_sanitize_order($_POST['right_order'] ?? [], $allWidgets);
        $leftEnabled = nx_admin_widget_sanitize_enabled($_POST['left_enabled'] ?? [], $allWidgets);
        $rightEnabled = nx_admin_widget_sanitize_enabled($_POST['right_enabled'] ?? [], $allWidgets);

        if (isset($_POST['reset_override'])) {
            nx_widget_delete_configuration($pdo, 'left', $page);
            nx_widget_delete_configuration($pdo, 'right', $page);
            flash('success', 'Override removed. This page now follows the default layout.');
            redirect('widgets.php?page=' . urlencode($page));
        }

        $moveAction = false;

        if (isset($_POST['move']['left']) && is_array($_POST['move']['left'])) {
            foreach ($_POST['move']['left'] as $slug => $direction) {
                $slug = nx_widget_normalize_slug(is_string($slug) ? $slug : null);
                $direction = $direction === 'down' ? 'down' : 'up';
                $leftOrder = nx_admin_widget_apply_move($leftOrder, $slug, $direction);
                $moveAction = true;
                break;
            }
        }

        if (isset($_POST['move']['right']) && is_array($_POST['move']['right'])) {
            foreach ($_POST['move']['right'] as $slug => $direction) {
                $slug = nx_widget_normalize_slug(is_string($slug) ? $slug : null);
                $direction = $direction === 'down' ? 'down' : 'up';
                $rightOrder = nx_admin_widget_apply_move($rightOrder, $slug, $direction);
                $moveAction = true;
                break;
            }
        }

        $leftEnabledOrdered = [];
        foreach ($leftOrder as $slug) {
            if (isset($leftEnabled[$slug])) {
                $leftEnabledOrdered[] = $slug;
            }
        }

        $rightEnabledOrdered = [];
        foreach ($rightOrder as $slug) {
            if (isset($rightEnabled[$slug])) {
                $rightEnabledOrdered[] = $slug;
            }
        }

        nx_widget_save_enabled_slugs($pdo, 'left', $page, $leftEnabledOrdered);
        nx_widget_save_enabled_slugs($pdo, 'right', $page, $rightEnabledOrdered);

        if ($moveAction) {
            flash('success', 'Page widget order updated.');
        } else {
            flash('success', 'Page override saved.');
        }

        redirect('widgets.php?page=' . urlencode($page));
    }
}

$defaultLayout = nx_widget_default_layout();
$defaultLeftSetting = nx_widget_setting_fetch($pdo, 'left', null);
$defaultRightSetting = nx_widget_setting_fetch($pdo, 'right', null);
$defaultLeftSlugs = $defaultLeftSetting['found'] ? $defaultLeftSetting['value'] : ($defaultLayout['left'] ?? []);
$defaultRightSlugs = $defaultRightSetting['found'] ? $defaultRightSetting['value'] : ($defaultLayout['right'] ?? []);
$defaultLeftWidgets = nx_widget_order_from_slugs($defaultLeftSlugs);
$defaultRightWidgets = nx_widget_order_from_slugs($defaultRightSlugs);

$pageLeft = nx_widget_resolve_layout($pdo, 'left', $selectedPage);
$pageRight = nx_widget_resolve_layout($pdo, 'right', $selectedPage);
$pageLeftWidgets = nx_widget_order_from_slugs($pageLeft['slugs']);
$pageRightWidgets = nx_widget_order_from_slugs($pageRight['slugs']);
$pageHasOverride = nx_widget_setting_fetch($pdo, 'left', $selectedPage)['found']
    || nx_widget_setting_fetch($pdo, 'right', $selectedPage)['found'];

$widgetTitles = [];
foreach ($allWidgets as $slug => $meta) {
    $widgetTitles[$slug] = isset($meta['title']) ? (string) $meta['title'] : ucfirst(str_replace('_', ' ', $slug));
}
?>
<section class="admin-section">
    <h2>Sidebar Widgets</h2>
    <p>Control which widgets appear in each sidebar, adjust their order, and toggle them on or off.</p>

    <?php if ($errorMessage !== null): ?>
        <div class="admin-alert admin-alert--error">
            <?php echo sanitize($errorMessage); ?>
        </div>
    <?php endif; ?>

    <?php if ($successMessage !== null): ?>
        <div class="admin-alert admin-alert--success">
            <?php echo sanitize($successMessage); ?>
        </div>
    <?php endif; ?>

    <h3>Default Layout</h3>
    <p>The default layout is used on all pages that do not have a custom override.</p>

    <form method="post" class="admin-form">
        <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">
        <input type="hidden" name="context" value="default">
        <div class="admin-widget-layout">
            <div class="admin-widget-column">
                <h4>Left Sidebar</h4>
                <ul class="admin-widget-list">
                    <?php foreach ($defaultLeftWidgets as $index => $widget): ?>
                        <?php $slug = $widget['slug']; ?>
                        <li class="admin-widget-item">
                            <input type="hidden" name="left_order[]" value="<?php echo sanitize($slug); ?>">
                            <div class="admin-widget-item__body">
                                <div class="admin-widget-item__info">
                                    <strong><?php echo sanitize($widgetTitles[$slug] ?? ucfirst($slug)); ?></strong>
                                    <span class="admin-widget-item__slug"><?php echo sanitize($slug); ?></span>
                                </div>
                                <div class="admin-widget-item__actions">
                                    <div class="admin-widget-move">
                                        <button type="submit" name="move[left][<?php echo sanitize($slug); ?>]" value="up" class="admin-widget-move__button" <?php echo $index === 0 ? 'disabled' : ''; ?> aria-label="Move <?php echo sanitize($slug); ?> up">▲</button>
                                        <button type="submit" name="move[left][<?php echo sanitize($slug); ?>]" value="down" class="admin-widget-move__button" <?php echo $index === count($defaultLeftWidgets) - 1 ? 'disabled' : ''; ?> aria-label="Move <?php echo sanitize($slug); ?> down">▼</button>
                                    </div>
                                    <label class="admin-widget-toggle">
                                        <input type="checkbox" name="left_enabled[<?php echo sanitize($slug); ?>]" <?php echo !empty($widget['enabled']) ? 'checked' : ''; ?>>
                                        <span>Enabled</span>
                                    </label>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="admin-widget-column">
                <h4>Right Sidebar</h4>
                <ul class="admin-widget-list">
                    <?php foreach ($defaultRightWidgets as $index => $widget): ?>
                        <?php $slug = $widget['slug']; ?>
                        <li class="admin-widget-item">
                            <input type="hidden" name="right_order[]" value="<?php echo sanitize($slug); ?>">
                            <div class="admin-widget-item__body">
                                <div class="admin-widget-item__info">
                                    <strong><?php echo sanitize($widgetTitles[$slug] ?? ucfirst($slug)); ?></strong>
                                    <span class="admin-widget-item__slug"><?php echo sanitize($slug); ?></span>
                                </div>
                                <div class="admin-widget-item__actions">
                                    <div class="admin-widget-move">
                                        <button type="submit" name="move[right][<?php echo sanitize($slug); ?>]" value="up" class="admin-widget-move__button" <?php echo $index === 0 ? 'disabled' : ''; ?> aria-label="Move <?php echo sanitize($slug); ?> up">▲</button>
                                        <button type="submit" name="move[right][<?php echo sanitize($slug); ?>]" value="down" class="admin-widget-move__button" <?php echo $index === count($defaultRightWidgets) - 1 ? 'disabled' : ''; ?> aria-label="Move <?php echo sanitize($slug); ?> down">▼</button>
                                    </div>
                                    <label class="admin-widget-toggle">
                                        <input type="checkbox" name="right_enabled[<?php echo sanitize($slug); ?>]" <?php echo !empty($widget['enabled']) ? 'checked' : ''; ?>>
                                        <span>Enabled</span>
                                    </label>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="admin-form__actions">
            <button type="submit" name="save_default" value="1" class="admin-button">Save Default Layout</button>
            <button type="submit" name="reset_default" value="1" class="admin-button admin-button--secondary">Reset to defaults</button>
        </div>
    </form>
</section>

<section class="admin-section">
    <h3>Per-Page Overrides</h3>
    <p>Create optional overrides for individual pages to customize their sidebars.</p>

    <?php if ($availablePages === []): ?>
        <p>No pages were found to configure.</p>
    <?php else: ?>
        <form method="get" class="admin-form admin-form--inline">
            <div class="admin-form__group">
                <label for="selected_page">Select Page</label>
                <select id="selected_page" name="page">
                    <?php foreach ($availablePages as $page): ?>
                        <option value="<?php echo sanitize($page); ?>"<?php echo $page === $selectedPage ? ' selected' : ''; ?>><?php echo sanitize(ucfirst(str_replace('_', ' ', $page))); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-form__actions">
                <button type="submit" class="admin-button">Load Layout</button>
            </div>
        </form>

        <p class="admin-widget-note">
            <?php if ($pageHasOverride): ?>
                This page currently uses a custom widget order.
            <?php elseif ($pageLeft['source'] === 'default' || $pageRight['source'] === 'default'): ?>
                This page inherits the default layout.
            <?php else: ?>
                This page uses the built-in fallback layout.
            <?php endif; ?>
        </p>

        <form method="post" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">
            <input type="hidden" name="context" value="override">
            <input type="hidden" name="page" value="<?php echo sanitize($selectedPage); ?>">
            <div class="admin-widget-layout">
                <div class="admin-widget-column">
                    <h4>Left Sidebar</h4>
                    <ul class="admin-widget-list">
                        <?php foreach ($pageLeftWidgets as $index => $widget): ?>
                            <?php $slug = $widget['slug']; ?>
                            <li class="admin-widget-item">
                                <input type="hidden" name="left_order[]" value="<?php echo sanitize($slug); ?>">
                                <div class="admin-widget-item__body">
                                    <div class="admin-widget-item__info">
                                        <strong><?php echo sanitize($widgetTitles[$slug] ?? ucfirst($slug)); ?></strong>
                                        <span class="admin-widget-item__slug"><?php echo sanitize($slug); ?></span>
                                    </div>
                                    <div class="admin-widget-item__actions">
                                        <div class="admin-widget-move">
                                            <button type="submit" name="move[left][<?php echo sanitize($slug); ?>]" value="up" class="admin-widget-move__button" <?php echo $index === 0 ? 'disabled' : ''; ?> aria-label="Move <?php echo sanitize($slug); ?> up">▲</button>
                                            <button type="submit" name="move[left][<?php echo sanitize($slug); ?>]" value="down" class="admin-widget-move__button" <?php echo $index === count($pageLeftWidgets) - 1 ? 'disabled' : ''; ?> aria-label="Move <?php echo sanitize($slug); ?> down">▼</button>
                                        </div>
                                        <label class="admin-widget-toggle">
                                            <input type="checkbox" name="left_enabled[<?php echo sanitize($slug); ?>]" <?php echo !empty($widget['enabled']) ? 'checked' : ''; ?>>
                                            <span>Enabled</span>
                                        </label>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="admin-widget-column">
                    <h4>Right Sidebar</h4>
                    <ul class="admin-widget-list">
                        <?php foreach ($pageRightWidgets as $index => $widget): ?>
                            <?php $slug = $widget['slug']; ?>
                            <li class="admin-widget-item">
                                <input type="hidden" name="right_order[]" value="<?php echo sanitize($slug); ?>">
                                <div class="admin-widget-item__body">
                                    <div class="admin-widget-item__info">
                                        <strong><?php echo sanitize($widgetTitles[$slug] ?? ucfirst($slug)); ?></strong>
                                        <span class="admin-widget-item__slug"><?php echo sanitize($slug); ?></span>
                                    </div>
                                    <div class="admin-widget-item__actions">
                                        <div class="admin-widget-move">
                                            <button type="submit" name="move[right][<?php echo sanitize($slug); ?>]" value="up" class="admin-widget-move__button" <?php echo $index === 0 ? 'disabled' : ''; ?> aria-label="Move <?php echo sanitize($slug); ?> up">▲</button>
                                            <button type="submit" name="move[right][<?php echo sanitize($slug); ?>]" value="down" class="admin-widget-move__button" <?php echo $index === count($pageRightWidgets) - 1 ? 'disabled' : ''; ?> aria-label="Move <?php echo sanitize($slug); ?> down">▼</button>
                                        </div>
                                        <label class="admin-widget-toggle">
                                            <input type="checkbox" name="right_enabled[<?php echo sanitize($slug); ?>]" <?php echo !empty($widget['enabled']) ? 'checked' : ''; ?>>
                                            <span>Enabled</span>
                                        </label>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <div class="admin-form__actions">
                <button type="submit" name="save_override" value="1" class="admin-button">Save Page Override</button>
                <button type="submit" name="reset_override" value="1" class="admin-button admin-button--secondary">Reset to defaults</button>
            </div>
        </form>
    <?php endif; ?>
</section>
<?php
require __DIR__ . '/partials/footer.php';
