<?php
namespace Quiznosis\Core;

/**
 * Mailer — minimal. In production swap for PHPMailer/Symfony Mailer. In log
 * mode we just write the message to storage/mail.log so dev flows still work
 * without an SMTP server.
 */
class Mailer
{
    public static function send(string $to, string $subject, string $html, ?string $idempotencyKey = null): array
    {
        $cfg = App::config('mail');
        $driver = $cfg['driver'] ?? 'log';

        if ($driver === 'log') {
            $dir = dirname(__DIR__) . '/storage';
            if (!is_dir($dir)) @mkdir($dir, 0700, true);
            $line = sprintf(
                "[%s] to=%s key=%s subject=%s\n%s\n---\n",
                date('c'), $to, $idempotencyKey ?? '-', $subject, $html
            );
            @file_put_contents($dir . '/mail.log', $line, FILE_APPEND);
            return ['success' => true, 'message' => 'Logged to storage/mail.log'];
        }

        // Native mail() fallback. Replace with PHPMailer in prod.
        $from = $cfg['from'];
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . ($cfg['from_name'] ?? 'No-reply') . " <{$from}>\r\n";
        $ok = @mail($to, $subject, $html, $headers);
        return ['success' => (bool)$ok, 'message' => $ok ? 'Sent' : 'mail() failed'];
    }

    public static function templateWelcome(string $firstName, string $verifyUrl): array
    {
        $safeName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
        $subject = 'Welcome to Quiznosis — verify your email';
        $html = "
<div style='font-family:Inter,Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#111'>
  <h2 style='margin:0 0 12px'>Welcome, {$safeName}!</h2>
  <p>Thanks for signing up. Please verify your email by clicking the button below.</p>
  <p style='margin:24px 0'>
    <a href='{$verifyUrl}' style='display:inline-block;background:#6d28d9;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px'>Verify email</a>
  </p>
  <p style='font-size:12px;color:#666'>If the button doesn't work, copy this link: {$verifyUrl}</p>
</div>";
        return ['subject' => $subject, 'html' => $html];
    }

    public static function templatePasswordReset(string $firstName, string $resetUrl, int $hours = 1): array
    {
        $safeName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
        $subject = '🔐 Password reset request — Quiznosis';
        $html = "
<div style='font-family:Inter,Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#111'>
  <h2 style='margin:0 0 12px'>Hi {$safeName},</h2>
  <p>We received a request to reset your password. The link below expires in {$hours} hour(s).</p>
  <p style='margin:24px 0'>
    <a href='{$resetUrl}' style='display:inline-block;background:#6d28d9;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px'>Reset password</a>
  </p>
  <p style='font-size:12px;color:#666'>If you didn't request this, you can safely ignore this email.</p>
</div>";
        return ['subject' => $subject, 'html' => $html];
    }
}
