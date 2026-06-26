<?php
/**
 * GET /api/payment-settings — public read of payment instructions/QR/bank details.
 * Used by the student-facing purchase page so they know how to pay.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Quiznosis\Core\Request;
use Quiznosis\Core\Response;
use Quiznosis\Models\PaymentSettings;

Request::requireMethod('GET');
$s = PaymentSettings::get();
// Hide nothing — these are intentionally public payment instructions.
Response::ok(['data' => $s]);
