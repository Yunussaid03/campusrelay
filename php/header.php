<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campus Relay</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <!-- Leaflet.js CDN for Campus P2P Maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body class="<?php echo isset($body_class) ? $body_class : ''; ?>">
    <header class="main-header sticky-header">
        <div class="nav-container">
            <h1 class="logo">Campus Relay</h1>
        </div>
    </header>
    <main class="<?php echo isset($container_class) ? $container_class : 'container'; ?>"></main>