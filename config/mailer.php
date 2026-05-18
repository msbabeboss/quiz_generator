<?php
/**
 * config/mailer.php — PHPMailer helper.
 * Sends password reset codes via SMTP.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/../.env');

/**
 * Send a password reset code to the given email address.
 *
 * @param string $toEmail   Recipient email
 * @param string $toName    Recipient display name
 * @param string $resetCode The 6-digit reset code
 * @param int    $expiresMin Minutes until expiry (default 15)
 * @return bool  true on success, false on failure
 */
function sendPasswordResetEmail(string $toEmail, string $toName, string $resetCode, int $expiresMin = 15): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_HOST']     ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USERNAME']  ?? '';
        $mail->Password   = $_ENV['MAIL_PASSWORD']  ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) ($_ENV['MAIL_PORT'] ?? 587);

        $fromEmail = $_ENV['MAIL_FROM']      ?? $_ENV['MAIL_USERNAME'] ?? '';
        $fromName  = $_ENV['MAIL_FROM_NAME'] ?? 'QuizGen';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = 'Your QuizGen Password Reset Code';
        $mail->Body    = '
<!DOCTYPE html>
<html>
<body style="font-family:Segoe UI,sans-serif;background:#0d0d1a;color:#e0e0f0;padding:2rem;">
  <div style="max-width:480px;margin:0 auto;background:#12122a;border-radius:1rem;padding:2rem;border:1px solid rgba(67,97,238,0.3);">
    <h2 style="color:#4361ee;margin-bottom:.5rem;">🧠 QuizGen</h2>
    <h3 style="margin-bottom:1rem;">Password Reset Code</h3>
    <p>Hi <strong>' . htmlspecialchars($toName, ENT_QUOTES) . '</strong>,</p>
    <p>Use the code below to reset your password. It expires in <strong>' . $expiresMin . ' minutes</strong>.</p>
    <div style="text-align:center;margin:2rem 0;">
      <span style="font-size:2.5rem;font-weight:900;letter-spacing:.3em;color:#f72585;background:rgba(247,37,133,0.1);padding:.75rem 1.5rem;border-radius:.75rem;border:1px solid rgba(247,37,133,0.3);">' . htmlspecialchars($resetCode, ENT_QUOTES) . '</span>
    </div>
    <p style="color:#9090b0;font-size:.85rem;">If you did not request a password reset, ignore this email.</p>
  </div>
</body>
</html>';
        $mail->AltBody = "Your QuizGen password reset code is: $resetCode\nExpires in $expiresMin minutes.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mailer error: ' . $mail->ErrorInfo);
        return false;
    }
}
