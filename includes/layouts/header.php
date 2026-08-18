<?php
require_once __DIR__ . '/../functions.php';
start_secure_session();
$csp_nonce = send_security_headers();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csp-nonce" content="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . " - myshop" : "myshop"; ?></title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="<?php echo htmlspecialchars(get_asset_integrity('https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css'), ENT_QUOTES, 'UTF-8'); ?>" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet" integrity="<?php echo htmlspecialchars(get_asset_integrity('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css'), ENT_QUOTES, 'UTF-8'); ?>" crossorigin="anonymous">
    <!-- Google Fonts: Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php if (isset($extra_css)): ?>
        <?php foreach ($extra_css as $css_url): ?>
            <?php $css_integrity = get_asset_integrity($css_url); ?>
            <link href="<?php echo htmlspecialchars($css_url, ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet"<?php if ($css_integrity !== null): ?> integrity="<?php echo htmlspecialchars($css_integrity, ENT_QUOTES, 'UTF-8'); ?>" crossorigin="anonymous"<?php endif; ?>>
        <?php endforeach; ?>
    <?php endif; ?>
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="<?php echo isset($body_class) ? htmlspecialchars($body_class, ENT_QUOTES, 'UTF-8') : 'bg-light'; ?>"
      data-feedback-success="<?php echo htmlspecialchars((string)($success ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
      data-feedback-error="<?php echo htmlspecialchars((string)($error ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
      data-completed-order-id="<?php echo (int)($completed_order_id ?? 0); ?>">
