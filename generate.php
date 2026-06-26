<?php
/**
 * generate_password.php
 * 
 * Generate bcrypt password hashes for direct database insertion
 * Usage: php generate_password.php [password]
 * Or: php generate_password.php --interactive
 */

// If running from web context, ensure CLI only
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

/**
 * Hash a password using bcrypt (same as Auth::hashPassword)
 */
function hashPassword(string $password): string
{
    // Use cost 12 as Laravel's default
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify a password against a hash
 */
function verifyPassword(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

// Parse command line arguments
$interactive = false;
$password = null;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--interactive' || $arg === '-i') {
        $interactive = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "\n";
        echo "Password Hash Generator for Quiznosis\n";
        echo "=====================================\n\n";
        echo "Usage:\n";
        echo "  php generate_password.php <password>     Generate hash for a single password\n";
        echo "  php generate_password.php --interactive  Interactive mode (multiple passwords)\n";
        echo "  php generate_password.php --help         Show this help message\n\n";
        echo "Examples:\n";
        echo "  php generate_password.php mySecret123\n";
        echo "  php generate_password.php -i\n\n";
        exit(0);
    } elseif (!$password && !str_starts_with($arg, '-')) {
        $password = $arg;
    }
}

// Interactive mode
if ($interactive) {
    echo "\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "        Quiznosis Password Hash Generator (Interactive)        \n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "\n";
    
    $hashes = [];
    
    while (true) {
        echo "───────────────────────────────────────────────────────────────\n";
        $plain = readline("Enter password (or 'quit' to exit): ");
        
        if ($plain === false || strtolower(trim($plain)) === 'quit' || strtolower(trim($plain)) === 'q') {
            break;
        }
        
        if (trim($plain) === '') {
            echo "❌ Password cannot be empty\n\n";
            continue;
        }
        
        if (strlen($plain) < 8) {
            $confirm = readline("⚠️  Password is less than 8 characters. Continue? (y/N): ");
            if (strtolower(trim($confirm)) !== 'y') {
                echo "   Skipped.\n\n";
                continue;
            }
        }
        
        $hash = hashPassword($plain);
        $hashes[] = ['password' => $plain, 'hash' => $hash];
        
        echo "\n✅ Generated hash:\n";
        echo "   Password: {$plain}\n";
        echo "   Hash:     {$hash}\n";
        
        // Show SQL update statement
        echo "\n📝 SQL Update Statement:\n";
        echo "   UPDATE users SET password = '{$hash}' WHERE email = 'user@example.com';\n";
        echo "\n";
    }
    
    if (!empty($hashes)) {
        echo "\n═══════════════════════════════════════════════════════════════\n";
        echo "                     Summary of Generated Hashes                \n";
        echo "═══════════════════════════════════════════════════════════════\n\n";
        
        foreach ($hashes as $i => $item) {
            $n = $i + 1;
            echo "{$n}. Password: {$item['password']}\n";
            echo "   Hash:     {$item['hash']}\n\n";
        }
    }
    
    echo "Goodbye!\n\n";
    exit(0);
}

// Single password mode
if ($password) {
    if (strlen($password) < 1) {
        echo "❌ Password cannot be empty\n";
        exit(1);
    }
    
    $hash = hashPassword($password);
    $verified = verifyPassword($password, $hash);
    
    echo "\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "                   Password Hash Generated                      \n";
    echo "═══════════════════════════════════════════════════════════════\n\n";
    echo "📝 Original Password: {$password}\n";
    echo "🔒 Bcrypt Hash:       {$hash}\n";
    echo "✅ Verification:      " . ($verified ? "PASSED" : "FAILED") . "\n\n";
    
    echo "📝 SQL Update Statement:\n";
    echo "   UPDATE users SET password = '{$hash}' WHERE email = 'user@example.com';\n\n";
    
    echo "📝 SQL Update for specific user:\n";
    echo "   UPDATE users \n";
    echo "   SET password = '{$hash}', updated_at = NOW(3)\n";
    echo "   WHERE email = 'admin@quiznosis.local';\n\n";
    
    exit(0);
}

// No arguments - show usage
echo "\n";
echo "Password Hash Generator for Quiznosis\n";
echo "=====================================\n\n";
echo "Usage:\n";
echo "  php generate_password.php <password>     Generate hash for a single password\n";
echo "  php generate_password.php --interactive  Interactive mode (multiple passwords)\n";
echo "  php generate_password.php --help         Show this help message\n\n";
echo "Examples:\n";
echo "  php generate_password.php mySecret123\n";
echo "  php generate_password.php -i\n\n";
exit(1);