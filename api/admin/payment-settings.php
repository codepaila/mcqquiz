<?php
/**
 * Admin · Payment Settings
 *
 *   GET   /api/admin/payment-settings           — current settings
 *   POST  /api/admin/payment-settings           — update (JSON body; text fields)
 *   PUT   /api/admin/payment-settings           — multipart; replaces QR image with uploaded "qr" file
 *                                                 (works via POST + _method=PUT in form-data)
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Core\Auth;
use Quiznosis\Core\Audit;
use Quiznosis\Core\Uploader;
use Quiznosis\Models\PaymentSettings;

$me = Auth::requireAdmin();
$method = Request::method();

if ($method === 'GET') {
    Response::ok(['data' => PaymentSettings::get()]);
}

if ($method === 'POST') {
    $body = Request::body();
    // Non-uploading text patch
    $updated = PaymentSettings::save($body);
    Audit::log([
        'user_id'     => $me['id'],
        'action'      => 'PAYMENT_SETTINGS_UPDATED',
        'entity_type' => 'PAYMENT_SETTINGS',
        'entity_id'   => '1',
    ]);
    Response::ok(['data' => $updated]);
}

if ($method === 'PUT') {
    // Multipart: caller sent the QR image and possibly text fields too.
    $patch = [];
    foreach (['instructions','whatsapp_number','telegram_username','bank_name','bank_account_name',
              'bank_account_number','esewa_id','khalti_id','currency'] as $field) {
        if (isset($_POST[$field])) $patch[$field] = $_POST[$field];
    }
    if (!empty($_FILES['qr']) && ($_FILES['qr']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $url = Uploader::save($_FILES['qr'], 'qr');
            $patch['qr_image_url'] = $url;
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }
    $updated = PaymentSettings::save($patch);
    Audit::log([
        'user_id'     => $me['id'],
        'action'      => 'PAYMENT_SETTINGS_UPDATED',
        'entity_type' => 'PAYMENT_SETTINGS',
        'entity_id'   => '1',
        'details'     => $patch,
    ]);
    Response::ok(['data' => $updated]);
}

Response::error('Method not allowed', 405);
