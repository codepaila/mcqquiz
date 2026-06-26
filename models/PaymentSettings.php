<?php
namespace Quiznosis\Models;

require_once __DIR__ . '/BaseModel.php';

use Quiznosis\Core\Database;

/**
 * Single-row settings table — there's always exactly one row with id=1.
 * Stores QR image URL, bank/eSewa/Khalti IDs, WhatsApp number, free-form instructions.
 */
class PaymentSettings
{
    /** Get the settings row, ensuring it exists. */
    public static function get(): array
    {
        $pdo = Database::pdo();
        $row = $pdo->query("SELECT * FROM payment_settings WHERE id = 1")->fetch();
        if (!$row) {
            $pdo->exec("INSERT INTO payment_settings (id, currency) VALUES (1, 'NPR')");
            $row = $pdo->query("SELECT * FROM payment_settings WHERE id = 1")->fetch();
        }
        return $row;
    }

    /** Patch the single row. Only known columns are written. */
    public static function save(array $patch): array
    {
        self::get(); // ensure row exists
        $allowed = ['qr_image_url','instructions','whatsapp_number','telegram_username','bank_name',
                    'bank_account_name','bank_account_number','esewa_id','khalti_id','currency'];
        $set = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $patch)) {
                $set[] = "`$col` = ?";
                $params[] = $patch[$col] === '' ? null : $patch[$col];
            }
        }
        if (empty($set)) return self::get();
        $sql = "UPDATE payment_settings SET " . implode(', ', $set) . " WHERE id = 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return self::get();
    }
}
