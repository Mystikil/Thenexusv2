<?php

declare(strict_types=1);


require_once __DIR__ . '/partials/bootstrap.php';
require_once __DIR__ . '/../auth.php';
require_admin('admin');

$adminPageTitle = 'Themes';
$adminNavActive = 'themes';

require_once __DIR__ . '/partials/init.php';
require_once __DIR__ . '/../includes/theme.php';

function nx_admin_theme_remove_directory(string $directory, bool $restrictToThemes = true): bool
{
    if ($directory === '' || !is_dir($directory)) {
        return true;
    }

    $targetReal = realpath($directory);

    if ($targetReal === false) {
        return false;
    }

    if ($restrictToThemes) {
        $themesBase = realpath(nx_themes_directory());

        if ($themesBase === false || strpos($targetReal, $themesBase) !== 0) {
            return false;
        }
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $fileInfo) {
        $path = $fileInfo->getPathname();

        if ($fileInfo->isDir()) {
            if (!@rmdir($path)) {
                return false;
            }
        } else {
            if (!@unlink($path)) {
                return false;
            }
        }
    }

    return @rmdir($directory);
}

function nx_admin_theme_store_image(string $slug, array $file, array &$errors): ?string
{
    if (!isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'The image upload failed. Please try again.';

        return null;
    }

    $tmpName = $file['tmp_name'] ?? '';

    if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
        $errors[] = 'Invalid image upload data.';

        return null;
    }

    $imageInfo = @getimagesize($tmpName);

    if ($imageInfo === false) {
        $errors[] = 'The uploaded file is not a valid image.';

        return null;
    }

    $mime = isset($imageInfo['mime']) && is_string($imageInfo['mime'])
        ? strtolower($imageInfo['mime'])
        : '';

    $extensionMap = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

    if (!isset($extensionMap[$mime])) {
        $errors[] = 'Unsupported image format. Please upload PNG, JPG, GIF, SVG, or WebP images.';

        return null;
    }

    $uploadsDir = nx_theme_path($slug, 'assets/uploads');

    if (!is_dir($uploadsDir) && !@mkdir($uploadsDir, 0775, true) && !is_dir($uploadsDir)) {
        $errors[] = 'Unable to create the upload directory for this theme.';

        return null;
    }

    $filename = uniqid('opt_', true) . '.' . $extensionMap[$mime];
    $destination = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if (!@move_uploaded_file($tmpName, $destination)) {
        $errors[] = 'Unable to store the uploaded image on the server.';

        return null;
    }

    return 'assets/uploads/' . $filename;
}

$errors = [];
$successMessages = [];
$optionMessages = [];
$pendingOptionValues = null;

$user = current_user();
$userId = $user !== null ? (int) $user['id'] : null;
$actorIsMaster = $user !== null && is_master($user);

$themes = nx_themes_list();
$themeSlugs = array_keys($themes);

if (!nx_database_available()) {
    echo '<section class="admin-section"><h2>Themes</h2><div class="admin-alert admin-alert--error">Database connection unavailable.</div></section>';
    require __DIR__ . '/partials/footer.php';

    return;
}

$activeSlug = nx_theme_setting('active_theme');

if ($activeSlug === null || !isset($themes[$activeSlug])) {
    if (isset($themes['default'])) {
        $activeSlug = 'default';
    } else {
        $activeSlug = $themeSlugs[0] ?? null;
    }
}

$previewSlug = null;

if (isset($_SESSION['preview_theme'])) {
    $candidate = nx_theme_normalize_slug((string) $_SESSION['preview_theme']);

    if ($candidate !== '' && isset($themes[$candidate])) {
        $previewSlug = $candidate;
    } else {
        unset($_SESSION['preview_theme']);
    }
}

if (isset($_GET['preview_theme'])) {
    $previewRequest = nx_theme_normalize_slug((string) $_GET['preview_theme']);
    $token = $_GET['csrf_token'] ?? null;

    if (!csrf_validate($token)) {
        flash('error', 'The preview request could not be validated.');
        redirect('themes.php');
    }

    if ($previewRequest === '' || !isset($themes[$previewRequest])) {
        flash('error', 'The requested theme is not available for preview.');
        redirect('themes.php');
    }

    $_SESSION['preview_theme'] = $previewRequest;
    audit_log($userId, 'preview_theme', null, [
        'slug' => $previewRequest,
        'a_is_master' => $actorIsMaster ? 1 : 0,
    ]);
    $label = $themes[$previewRequest]['name'] ?? ucfirst($previewRequest);
    flash('success', 'Preview mode enabled for the “' . $label . '” theme.');
    redirect('themes.php?theme=' . urlencode($previewRequest));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;

    if (!csrf_validate($token)) {
        $errors[] = 'The request could not be validated. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'activate':
                $slug = nx_theme_normalize_slug((string) ($_POST['theme_slug'] ?? ''));

                if ($slug === '' || !isset($themes[$slug])) {
                    $errors[] = 'The selected theme could not be found.';
                    break;
                }

                if ($slug === $activeSlug) {
                    flash('success', 'The “' . ($themes[$slug]['name'] ?? $slug) . '” theme is already active.');
                    redirect('themes.php?theme=' . urlencode($slug));
                }

                $pdo = db();

                if (!$pdo instanceof PDO) {
                    $errors[] = 'Database connection unavailable. Please try again later.';
                    break;
                }
                $stmt = $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (:key, :value)
                    ON DUPLICATE KEY UPDATE value = VALUES(value)');
                $stmt->execute([
                    'key' => 'active_theme',
                    'value' => $slug,
                ]);
                audit_log(
                    $userId,
                    'activate_theme',
                    ['slug' => $activeSlug],
                    [
                        'slug' => $slug,
                        'a_is_master' => $actorIsMaster ? 1 : 0,
                    ]
                );

                if ($previewSlug === $slug) {
                    unset($_SESSION['preview_theme']);
                }

                flash('success', 'The “' . ($themes[$slug]['name'] ?? $slug) . '” theme is now active.');
                redirect('themes.php?theme=' . urlencode($slug));

            case 'apply_preview':
                if ($previewSlug === null || !isset($themes[$previewSlug])) {
                    $errors[] = 'No preview theme is currently active.';
                    break;
                }

                $pdo = db();

                if (!$pdo instanceof PDO) {
                    $errors[] = 'Database connection unavailable. Please try again later.';
                    break;
                }
                $stmt = $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (:key, :value)
                    ON DUPLICATE KEY UPDATE value = VALUES(value)');
                $stmt->execute([
                    'key' => 'active_theme',
                    'value' => $previewSlug,
                ]);
                audit_log(
                    $userId,
                    'apply_preview_theme',
                    ['slug' => $activeSlug],
                    [
                        'slug' => $previewSlug,
                        'a_is_master' => $actorIsMaster ? 1 : 0,
                    ]
                );
                unset($_SESSION['preview_theme']);
                flash('success', 'Preview applied. The “' . ($themes[$previewSlug]['name'] ?? $previewSlug) . '” theme is now active.');
                redirect('themes.php?theme=' . urlencode($previewSlug));

            case 'clear_preview':
                if ($previewSlug !== null) {
                    audit_log($userId, 'clear_preview_theme', ['slug' => $previewSlug], [
                        'a_is_master' => $actorIsMaster ? 1 : 0,
                    ]);
                }
                unset($_SESSION['preview_theme']);
                flash('success', 'Theme preview mode has been cleared.');
                redirect('themes.php');

            case 'delete':
                $slug = nx_theme_normalize_slug((string) ($_POST['theme_slug'] ?? ''));

                if ($slug === '' || !isset($themes[$slug])) {
                    $errors[] = 'The selected theme could not be found.';
                    break;
                }

                if ($slug === $activeSlug) {
                    $errors[] = 'The active theme cannot be deleted. Please activate a different theme first.';
                    break;
                }

                if ($previewSlug === $slug) {
                    $errors[] = 'A theme that is currently being previewed cannot be deleted.';
                    break;
                }

                if ($slug === 'default') {
                    $errors[] = 'The default theme cannot be removed.';
                    break;
                }

                $themePath = nx_theme_path($slug);

                if (!nx_admin_theme_remove_directory($themePath)) {
                    $errors[] = 'Unable to delete the theme directory from disk. Please check file permissions.';
                    break;
                }

                audit_log($userId, 'delete_theme', ['slug' => $slug], [
                    'a_is_master' => $actorIsMaster ? 1 : 0,
                ]);
                flash('success', 'The “' . ($themes[$slug]['name'] ?? $slug) . '” theme has been removed.');
                redirect('themes.php');

            case 'upload':
                $file = $_FILES['theme_zip'] ?? null;

                if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    $errors[] = 'Please choose a theme package (.zip) to upload.';
                    break;
                }

                if ((int) $file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'The upload failed. Please try again.';
                    break;
                }

                $tmpPath = $file['tmp_name'] ?? '';

                if (!is_string($tmpPath) || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
                    $errors[] = 'Invalid upload payload received.';
                    break;
                }

                $size = isset($file['size']) ? (int) $file['size'] : 0;

                if ($size <= 0 || $size > 10 * 1024 * 1024) {
                    $errors[] = 'Theme uploads are limited to 10MB.';
                    break;
                }

                $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

                if ($extension !== 'zip') {
                    $errors[] = 'Only .zip theme packages are supported.';
                    break;
                }

                $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nx_theme_' . bin2hex(random_bytes(8));
                if (!@mkdir($tempDir, 0775, true)) {
                    $errors[] = 'Unable to allocate a temporary directory for extraction.';
                    break;
                }

                $archivePath = $tempDir . DIRECTORY_SEPARATOR . 'upload.zip';

                if (!@move_uploaded_file($tmpPath, $archivePath)) {
                    nx_admin_theme_remove_directory($tempDir, false);
                    $errors[] = 'Unable to move the uploaded file into place.';
                    break;
                }

                $zip = new ZipArchive();
                $openResult = $zip->open($archivePath);

                if ($openResult !== true) {
                    nx_admin_theme_remove_directory($tempDir, false);
                    $errors[] = 'Unable to open the uploaded archive. Please ensure it is a valid .zip file.';
                    break;
                }

                $invalidEntry = false;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    if (!is_string($entryName)) {
                        continue;
                    }

                    $entryName = trim($entryName);

                    if ($entryName === '' || strpos($entryName, '../') !== false || strpos($entryName, '..\\') !== false) {
                        $invalidEntry = true;
                        break;
                    }

                    if (isset($entryName[0]) && ($entryName[0] === '/' || $entryName[0] === '\\')) {
                        $invalidEntry = true;
                        break;
                    }
                }

                if ($invalidEntry) {
                    $zip->close();
                    nx_admin_theme_remove_directory($tempDir, false);
                    $errors[] = 'The archive contains invalid paths and cannot be extracted.';
                    break;
                }

                $extractDir = $tempDir . DIRECTORY_SEPARATOR . 'extract';

                if (!@mkdir($extractDir, 0775, true)) {
                    $zip->close();
                    nx_admin_theme_remove_directory($tempDir, false);
                    $errors[] = 'Unable to prepare the extraction directory.';
                    break;
                }

                if (!$zip->extractTo($extractDir)) {
                    $zip->close();
                    nx_admin_theme_remove_directory($tempDir, false);
                    $errors[] = 'Unable to extract the theme archive.';
                    break;
                }

                $zip->close();

                $manifestPath = null;
                $directoryIterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($extractDir, \FilesystemIterator::SKIP_DOTS)
                );

                foreach ($directoryIterator as $fileInfo) {
                    if ($fileInfo->isFile() && strtolower($fileInfo->getFilename()) === 'manifest.json') {
                        $manifestPath = $fileInfo->getPathname();
                        break;
                    }
                }

                if ($manifestPath === null) {
                    nx_admin_theme_remove_directory($tempDir, false);
                    $errors[] = 'The uploaded theme is missing a manifest.json file.';
                    break;
                }

                $manifestJson = @file_get_contents($manifestPath);
                $manifest = is_string($manifestJson) ? json_decode($manifestJson, true) : null;

                if (!is_array($manifest)) {
                    nx_admin_theme_remove_directory($tempDir, false);
                    $errors[] = 'The manifest.json file is invalid JSON.';
                    break;
                }

                $slug = '';
                if (isset($manifest['slug']) && is_string($manifest['slug'])) {
                    $slug = nx_theme_normalize_slug($manifest['slug']);
                }

                if ($slug === '') {
                    $slug = nx_theme_normalize_slug(basename(dirname($manifestPath)));
                }

                if ($slug === '') {
                    nx_admin_theme_remove_directory($tempDir, false);
                    $errors[] = 'The theme manifest does not declare a valid slug.';
                    break;
                }

                $themeRoot = dirname($manifestPath);
                $themeRootReal = realpath($themeRoot);
                $extractReal = realpath($extractDir);

                if ($themeRootReal === false || $extractReal === false || strpos($themeRootReal, $extractReal) !== 0) {
                    nx_admin_theme_remove_directory($tempDir, false);
                    $errors[] = 'Unable to locate the extracted theme directory.';
                    break;
                }

                $destination = nx_theme_path($slug);

                if (is_dir($destination)) {
                    if ($slug === $activeSlug) {
                        nx_admin_theme_remove_directory($tempDir, false);
                        $errors[] = 'The active theme cannot be replaced while in use.';
                        break;
                    }

                    if ($previewSlug === $slug) {
                        nx_admin_theme_remove_directory($tempDir, false);
                        $errors[] = 'The theme is currently in preview and cannot be replaced.';
                        break;
                    }

                    if (!nx_admin_theme_remove_directory($destination)) {
                        nx_admin_theme_remove_directory($tempDir, false);
                        $errors[] = 'Unable to replace the existing theme directory. Please check file permissions.';
                        break;
                    }
                }

                if (!@rename($themeRoot, $destination)) {
                    nx_admin_theme_remove_directory($tempDir, false);
                    $errors[] = 'Unable to finalize the uploaded theme on the server.';
                    break;
                }

                nx_admin_theme_remove_directory($tempDir, false);

                $themes = nx_themes_list(true);
                $label = $themes[$slug]['name'] ?? ucfirst($slug);
                audit_log($userId, 'upload_theme', null, [
                    'slug' => $slug,
                    'a_is_master' => $actorIsMaster ? 1 : 0,
                ]);
                flash('success', 'Theme uploaded successfully: “' . $label . '”.');
                redirect('themes.php?theme=' . urlencode($slug));

            case 'save_options':
                $slug = nx_theme_normalize_slug((string) ($_POST['theme_slug'] ?? ''));

                if ($slug === '' || !isset($themes[$slug])) {
                    $errors[] = 'Unable to save options for an unknown theme.';
                    break;
                }

                $schema = nx_theme_options_schema($slug);

                if ($schema === []) {
                    $errors[] = 'This theme does not define configurable options.';
                    break;
                }

                $pendingOptionValues = isset($_POST['options']) && is_array($_POST['options'])
                    ? $_POST['options']
                    : [];

                $optionsClear = [];
                if (isset($_POST['options_clear']) && is_array($_POST['options_clear'])) {
                    foreach ($_POST['options_clear'] as $clearKey => $value) {
                        if (!is_string($clearKey)) {
                            continue;
                        }

                        $clearKey = trim($clearKey);

                        if ($clearKey === '') {
                            continue;
                        }

                        $normalized = strtolower((string) $value);
                        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                            $optionsClear[$clearKey] = true;
                        }
                    }
                }

                $files = $_FILES['option_files'] ?? [];
                $existingOptions = nx_theme_get_options($slug);
                $before = [];
                $after = [];
                $changes = 0;
                $errorCountBefore = count($errors);

                foreach ($schema as $optionKey => $definition) {
                    $type = strtolower((string) ($definition['type'] ?? 'text'));
                    $choices = $definition['choices'] ?? [];
                    $postedValue = '';

                    if (isset($pendingOptionValues[$optionKey])) {
                        $postedValue = is_string($pendingOptionValues[$optionKey])
                            ? trim((string) $pendingOptionValues[$optionKey])
                            : '';
                    }

                    $newValue = null;
                    $clearRequested = isset($optionsClear[$optionKey]);

                    if ($clearRequested) {
                        $postedValue = '';
                        $newValue = null;
                    } elseif ($type === 'select' && is_array($choices)) {
                        if ($postedValue !== '' && in_array($postedValue, $choices, true)) {
                            $newValue = $postedValue;
                        }
                    } elseif ($type === 'color') {
                        if ($postedValue !== '') {
                            $newValue = preg_match('/^#[0-9a-f]{3,8}$/i', $postedValue)
                                ? strtolower($postedValue)
                                : null;

                            if ($newValue === null) {
                                $labelText = $definition['label'] ?? ucfirst($optionKey);
                                $errors[] = 'Invalid color value provided for “' . $labelText . '”.';
                            }
                        }
                    } elseif ($type === 'image') {
                        $filePayload = [
                            'name' => $files['name'][$optionKey] ?? null,
                            'type' => $files['type'][$optionKey] ?? null,
                            'tmp_name' => $files['tmp_name'][$optionKey] ?? null,
                            'error' => $files['error'][$optionKey] ?? UPLOAD_ERR_NO_FILE,
                            'size' => $files['size'][$optionKey] ?? 0,
                        ];

                        $imagePath = nx_admin_theme_store_image($slug, $filePayload, $errors);

                        if ($imagePath !== null) {
                            $newValue = $imagePath;
                        } elseif (!$clearRequested && $postedValue !== '') {
                            $newValue = $postedValue;
                        }
                    } else {
                        if ($postedValue !== '') {
                            $newValue = mb_substr($postedValue, 0, 1024);
                        }
                    }

                    $currentValue = $existingOptions[$optionKey] ?? null;

                    if ($newValue === null && $postedValue === '' && $type !== 'image') {
                        $newValue = null;
                    }

                    if ($newValue === null && $currentValue === null) {
                        continue;
                    }

                    if ($newValue !== null && $currentValue !== null && (string) $currentValue === (string) $newValue) {
                        continue;
                    }

                    nx_theme_set_option($slug, $optionKey, $newValue);
                    $before[$optionKey] = $currentValue;
                    $after[$optionKey] = $newValue;
                    $changes++;
                }

                $errorsAfter = count($errors);

                if ($changes > 0) {
                    $after['a_is_master'] = $actorIsMaster ? 1 : 0;
                    audit_log($userId, 'update_theme_options', $before, $after);
                }

                if ($errorsAfter > $errorCountBefore) {
                    if ($changes > 0) {
                        $optionMessages[] = 'Some theme options were saved, but please review the highlighted issues.';
                    }
                } else {
                    $pendingOptionValues = null;
                    if ($changes > 0) {
                        $optionMessages[] = 'Theme options updated successfully.';
                    } else {
                        $optionMessages[] = 'No changes were detected for the selected theme options.';
                    }
                }

                $existingOptions = nx_theme_get_options($slug);
                break;
        }
    }
}

$flashSuccess = take_flash('success');
$flashError = take_flash('error');

if ($flashSuccess !== null) {
    $successMessages[] = $flashSuccess;
}

if ($flashError !== null) {
    $errors[] = $flashError;
}

$requestedTheme = isset($_GET['theme']) ? nx_theme_normalize_slug((string) $_GET['theme']) : '';

if ($pendingOptionValues !== null && isset($_POST['theme_slug'])) {
    $requestedTheme = nx_theme_normalize_slug((string) $_POST['theme_slug']);
}

if ($requestedTheme !== '' && isset($themes[$requestedTheme])) {
    $selectedThemeSlug = $requestedTheme;
} elseif ($previewSlug !== null && isset($themes[$previewSlug])) {
    $selectedThemeSlug = $previewSlug;
} elseif ($activeSlug !== null && isset($themes[$activeSlug])) {
    $selectedThemeSlug = $activeSlug;
} else {
    $selectedThemeSlug = $themeSlugs[0] ?? null;
}

$selectedSchema = $selectedThemeSlug !== null ? nx_theme_options_schema($selectedThemeSlug) : [];
$currentOptions = $selectedThemeSlug !== null ? nx_theme_get_options($selectedThemeSlug) : [];

require __DIR__ . '/partials/header.php';

?>
<section class="admin-section">
    <h2>Theme Management</h2>
    <p>Install, preview, and configure layout and skin themes for the site. Changes are logged for auditing.</p>

    <?php if ($errors !== []): ?>
        <div class="admin-alert admin-alert--error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo sanitize($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php $allSuccess = array_merge($successMessages, $optionMessages); ?>
    <?php if ($allSuccess !== []): ?>
        <div class="admin-alert admin-alert--success">
            <ul>
                <?php foreach ($allSuccess as $message): ?>
                    <li><?php echo sanitize($message); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($previewSlug !== null && isset($themes[$previewSlug])): ?>
        <div class="admin-alert admin-alert--info" style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem;">
            <p style="margin: 0;">Previewing <strong><?php echo sanitize($themes[$previewSlug]['name'] ?? ucfirst($previewSlug)); ?></strong> &ndash; Apply or cancel below.</p>
            <form method="post" style="display: inline-flex; gap: 0.5rem; align-items: center;">
                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                <input type="hidden" name="action" value="apply_preview">
                <button type="submit" class="admin-button">Apply</button>
            </form>
            <form method="post" style="display: inline-flex; gap: 0.5rem; align-items: center;">
                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                <input type="hidden" name="action" value="clear_preview">
                <button type="submit" class="admin-button admin-button--secondary">Cancel</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($themes === []): ?>
        <p>No themes were found. Upload a theme package to get started.</p>
    <?php else: ?>
        <div class="admin-widget-layout">
            <?php foreach ($themes as $slug => $theme): ?>
                <?php
                    $isActive = $activeSlug !== null && $activeSlug === $slug;
                    $isPreview = $previewSlug !== null && $previewSlug === $slug;
                    $screenshot = null;
                    if (isset($theme['screenshot']) && is_string($theme['screenshot'])) {
                        $screenshot = base_url('../themes/' . rawurlencode($slug) . '/' . basename($theme['screenshot']));
                    }
                    $type = $theme['manifest']['type'] ?? ($theme['type'] ?? '');
                ?>
                <div class="admin-card">
                    <?php if ($screenshot !== null): ?>
                        <div style="margin-bottom: 1rem; border-radius: 0.75rem; overflow: hidden;">
                            <img src="<?php echo sanitize($screenshot); ?>" alt="<?php echo sanitize(($theme['name'] ?? ucfirst($slug)) . ' screenshot'); ?>" style="width: 100%; display: block;">
                        </div>
                    <?php endif; ?>
                    <h3 style="margin-top: 0; margin-bottom: 0.5rem;">
                        <?php echo sanitize($theme['name'] ?? ucfirst($slug)); ?>
                    </h3>
                    <p class="admin-table__meta">
                        Slug: <code><?php echo sanitize($slug); ?></code>
                        <?php if (is_string($type) && $type !== ''): ?>
                            · Type: <?php echo sanitize($type); ?>
                        <?php endif; ?>
                        <?php if (!empty($theme['version'])): ?>
                            · Version: <?php echo sanitize((string) $theme['version']); ?>
                        <?php endif; ?>
                        <?php if (!empty($theme['author'])): ?>
                            · Author: <?php echo sanitize((string) $theme['author']); ?>
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($theme['description'])): ?>
                        <p><?php echo sanitize((string) $theme['description']); ?></p>
                    <?php endif; ?>
                    <?php if ($isActive): ?>
                        <p><strong>Status:</strong> Active theme</p>
                    <?php elseif ($isPreview): ?>
                        <p><strong>Status:</strong> In preview</p>
                    <?php endif; ?>
                    <div class="admin-form__actions" style="margin-top: 1rem; gap: 0.5rem; flex-wrap: wrap;">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                            <input type="hidden" name="action" value="activate">
                            <input type="hidden" name="theme_slug" value="<?php echo sanitize($slug); ?>">
                            <button type="submit" class="admin-button"<?php echo $isActive ? ' disabled' : ''; ?>>Activate</button>
                        </form>
                        <?php if ($isPreview): ?>
                            <span class="admin-button admin-button--secondary" style="pointer-events: none; opacity: 0.7;">Previewing</span>
                        <?php else: ?>
                            <a class="admin-button admin-button--secondary" href="<?php echo sanitize('themes.php?preview_theme=' . urlencode($slug) . '&csrf_token=' . urlencode(csrf_token())); ?>">Preview</a>
                        <?php endif; ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="theme_slug" value="<?php echo sanitize($slug); ?>">
                            <button type="submit" class="admin-button admin-button--danger"<?php echo ($isActive || $isPreview || $slug === 'default') ? ' disabled' : ''; ?>>Delete</button>
                        </form>
                        <a class="admin-button admin-button--secondary" href="<?php echo sanitize('themes.php?theme=' . urlencode($slug)); ?>">Options</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="admin-section">
    <h3>Upload Theme</h3>
    <p>Upload a packaged theme (.zip). The archive must include a manifest.json file.</p>
    <form method="post" enctype="multipart/form-data" class="admin-form">
        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
        <input type="hidden" name="action" value="upload">
        <div class="admin-form__group">
            <label for="theme_zip">Theme package (.zip)</label>
            <input type="file" id="theme_zip" name="theme_zip" accept=".zip" required>
        </div>
        <button type="submit" class="admin-button">Upload Theme</button>
    </form>
</section>

<?php if ($selectedThemeSlug !== null && $selectedSchema !== []): ?>
    <?php
        $currentThemeLabel = $themes[$selectedThemeSlug]['name'] ?? ucfirst($selectedThemeSlug);
    ?>
    <section class="admin-section" id="theme-options">
        <h3>Theme Options: <?php echo sanitize($currentThemeLabel); ?></h3>
        <p>Configure theme-specific options defined by the theme author.</p>
        <form method="post" enctype="multipart/form-data" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
            <input type="hidden" name="action" value="save_options">
            <input type="hidden" name="theme_slug" value="<?php echo sanitize($selectedThemeSlug); ?>">
            <?php foreach ($selectedSchema as $optionKey => $definition): ?>
                <?php
                    $label = $definition['label'] ?? ucfirst($optionKey);
                    $type = strtolower((string) ($definition['type'] ?? 'text'));
                    $choices = $definition['choices'] ?? [];
                    $currentValue = $pendingOptionValues[$optionKey] ?? ($currentOptions[$optionKey] ?? ($definition['default'] ?? ''));
                ?>
                <div class="admin-form__group">
                    <label for="option-<?php echo sanitize($optionKey); ?>"><?php echo sanitize($label); ?></label>
                    <?php if ($type === 'select' && is_array($choices) && $choices !== []): ?>
                        <select id="option-<?php echo sanitize($optionKey); ?>" name="options[<?php echo sanitize($optionKey); ?>]">
                            <option value="">-- Select --</option>
                            <?php foreach ($choices as $choice): ?>
                                <option value="<?php echo sanitize($choice); ?>"<?php echo ($currentValue === $choice) ? ' selected' : ''; ?>><?php echo sanitize($choice); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($type === 'color'): ?>
                        <input type="color" id="option-<?php echo sanitize($optionKey); ?>" name="options[<?php echo sanitize($optionKey); ?>]" value="<?php echo sanitize($currentValue !== '' ? (string) $currentValue : '#000000'); ?>">
                    <?php elseif ($type === 'image'): ?>
                        <input type="file" id="option-<?php echo sanitize($optionKey); ?>" name="option_files[<?php echo sanitize($optionKey); ?>]" accept="image/*">
                        <?php if (!empty($currentOptions[$optionKey])): ?>
                            <p class="admin-table__meta">Current: <code><?php echo sanitize((string) $currentOptions[$optionKey]); ?></code></p>
                            <label class="admin-table__meta" style="display: inline-flex; align-items: center; gap: 0.35rem;">
                                <input type="checkbox" name="options_clear[<?php echo sanitize($optionKey); ?>]" value="1">
                                Remove current image
                            </label>
                        <?php elseif (!empty($definition['default'])): ?>
                            <p class="admin-table__meta">Default: <code><?php echo sanitize((string) $definition['default']); ?></code></p>
                        <?php endif; ?>
                        <input type="hidden" name="options[<?php echo sanitize($optionKey); ?>]" value="<?php echo sanitize((string) $currentValue); ?>">
                    <?php else: ?>
                        <input type="text" id="option-<?php echo sanitize($optionKey); ?>" name="options[<?php echo sanitize($optionKey); ?>]" value="<?php echo sanitize((string) $currentValue); ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="admin-button">Save Theme Options</button>
        </form>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php';
