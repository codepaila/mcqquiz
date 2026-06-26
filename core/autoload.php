<?php
/**
 * Autoloader — maps Quiznosis\Core\* → core/*.php, Quiznosis\Models\* → models/*.php
 * Avoids needing Composer for a simple LAMP deploy.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'Quiznosis\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    $rel = str_replace('\\', '/', $rel);
    // Quiznosis\Core\Foo -> core/Foo.php
    // Quiznosis\Models\Foo -> models/Foo.php
    $parts = explode('/', $rel, 2);
    $base = strtolower($parts[0]);     // 'core' / 'models'
    $tail = $parts[1] ?? '';
    $path = dirname(__DIR__) . '/' . $base . '/' . $tail . '.php';
    if (is_file($path)) {
        require $path;
    }
});
