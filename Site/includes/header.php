<?php require_once __DIR__ . '/theme.php';

$themes = nx_themes_list();
$previewSlug = null;

if (isset($_SESSION['preview_theme'])) {
    $candidate = nx_theme_normalize_slug((string) $_SESSION['preview_theme']);

    if ($candidate !== '' && isset($themes[$candidate])) {
        $previewSlug = $candidate;
    }
}

$themeSlug = $previewSlug ?? nx_theme_active_slug();
$csrfMetaToken = csrf_token();
$themeAssets = nx_theme_assets($themeSlug);
$themeCssFiles = [];
$themeJsFiles = [];
$themeBasePath = 'themes/' . rawurlencode($themeSlug) . '/';

if (is_file(nx_theme_path($themeSlug, 'theme.css'))) {
    $themeCssFiles[] = base_url($themeBasePath . 'theme.css');
}

if (isset($themeAssets['css'])) {
    foreach ($themeAssets['css'] as $cssFile) {
        $cssFile = trim((string) $cssFile);
        if ($cssFile === '') {
            continue;
        }
        if (preg_match('#^(https?:)?//#', $cssFile) === 1) {
            $themeCssFiles[] = $cssFile;
            continue;
        }
        $themeCssFiles[] = base_url($themeBasePath . ltrim($cssFile, '/'));
    }
}

if (isset($themeAssets['js'])) {
    foreach ($themeAssets['js'] as $jsFile) {
        $jsFile = trim((string) $jsFile);
        if ($jsFile === '') {
            continue;
        }
        if (preg_match('#^(https?:)?//#', $jsFile) === 1) {
            $themeJsFiles[] = $jsFile;
            continue;
        }
        $themeJsFiles[] = base_url($themeBasePath . ltrim($jsFile, '/'));
    }
}

$themeCssFiles = array_values(array_unique($themeCssFiles));
$themeJsFiles = array_values(array_unique($themeJsFiles));

$GLOBALS['nx_theme_loaded_assets'] = [
    'css' => $themeCssFiles,
    'js' => $themeJsFiles,
];
$GLOBALS['nx_active_theme_slug'] = $themeSlug;

$previewThemeName = null;

if ($previewSlug !== null) {
    $previewThemeName = $themes[$previewSlug]['name'] ?? ucfirst($previewSlug);
}

$bodyThemeClass = 'theme-' . $themeSlug;

$themeHooksFile = nx_theme_path($themeSlug, 'theme.php');

if (is_file($themeHooksFile)) {
    require_once $themeHooksFile;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo sanitize($csrfMetaToken); ?>">
    <title><?php echo sanitize(SITE_TITLE); ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons (optional) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Our theme -->
    <link rel="stylesheet" href="/assets/css/theme.css">
    <?php foreach ($themeCssFiles as $cssHref): ?>
        <link rel="stylesheet" href="<?php echo sanitize($cssHref); ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="/assets/css/overrides.css">
    <?php if (function_exists('theme_head')): ?>
        <?php theme_head(); ?>
    <?php endif; ?>
</head>
<body data-theme="<?php echo sanitize($themeSlug); ?>" class="<?php echo sanitize($bodyThemeClass); ?>">
<?php if ($previewThemeName !== null): ?>
    <div class="bg-warning text-dark py-1 px-3 small" style="position: sticky; top: 0; z-index: 1050; display: flex; justify-content: center; gap: 1rem; align-items: center;">
        <span>Previewing <?php echo sanitize($previewThemeName); ?></span>
        <form action="<?php echo sanitize(base_url('admin/themes.php')); ?>" method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfMetaToken); ?>">
            <input type="hidden" name="action" value="apply_preview">
            <button type="submit" class="btn btn-sm btn-outline-dark">Apply</button>
        </form>
        <form action="<?php echo sanitize(base_url('admin/themes.php')); ?>" method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfMetaToken); ?>">
            <input type="hidden" name="action" value="clear_preview">
            <button type="submit" class="btn btn-sm btn-outline-dark">Exit preview</button>
        </form>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/nav.php'; ?>
<main class="py-3 py-lg-4">
