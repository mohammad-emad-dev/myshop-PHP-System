<?php
require_once __DIR__ . '/../functions.php';
start_secure_session();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . " - myshop" : "myshop"; ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <?php if (isset($extra_css)): ?>
        <?php foreach ($extra_css as $css_url): ?>
            <link href="<?php echo htmlspecialchars($css_url); ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="<?php echo isset($body_class) ? htmlspecialchars($body_class) : 'bg-light'; ?>">
