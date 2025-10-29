<?php
require_once __DIR__ . '/../widgets/_registry.php';
require_once __DIR__ . '/theme.php';

$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<div class="card nx-glow mb-3"><div class="card-body"><p class="text-muted mb-0">Unavailable.</p></div></div>';

    return;
}

$pageSlug = nx_current_page_slug();
$widgets = nx_widget_order($pdo, 'left', $pageSlug);
$registry = nx_widget_registry();

$renderWidget = static function (string $slug, ?int $limit = null): string {
    $effectiveLimit = $limit ?? 5;

    return render_widget_box($slug, $effectiveLimit, null, false);
};

$template = nx_theme_locate(nx_theme_active_slug(), 'sidebar-left');

if (is_string($template) && $template !== '') {
    $orderedWidgets = $widgets;
    $renderWidgetBox = $renderWidget;
    $currentPageSlug = $pageSlug;
    include $template;

    return;
}

foreach ($widgets as $widget) {
    if (!is_array($widget)) {
        continue;
    }

    if (empty($widget['enabled'])) {
        continue;
    }

    $slug = $widget['slug'] ?? '';

    if (!is_string($slug) || $slug === '') {
        continue;
    }

    $limit = $widget['limit'] ?? null;
    $limit = is_int($limit) ? $limit : null;

    $title = $registry[$slug]['title'] ?? $slug;
    $effectiveLimit = $limit ?? 5;
    $attributes = widget_collect_attributes($slug, $effectiveLimit);
    $attrString = widget_resolve_attributes($attributes);
    $inner = $renderWidget($slug, $effectiveLimit);

    echo '<div class="card nx-glow mb-3"' . $attrString . '><div class="card-header py-2"><h6 class="mb-0">'
        . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</h6></div><div class="card-body">' . $inner . '</div></div>';
}
