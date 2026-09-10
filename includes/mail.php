<?php
/**
 * Centralized Mail Service
 *
 * Uses PHPMailer over SMTP with configuration from .env.
 * Provides reusable helpers for application emails.
 */

if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config/config.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once BASE_PATH . '/vendor/autoload.php';

/**
 * Send a password reset email via SMTP.
 *
 * @param string $toEmail  Recipient email address
 * @param string $resetLink Full reset URL
 * @param string $subject   Optional subject override
 * @param string $bodyHtml  Optional HTML body override
 *
 * @return bool  true on successful SMTP delivery, false on failure
 */
function send_password_reset_email($toEmail, $resetLink, $subject = '', $bodyHtml = '') {
    $mailer = new PHPMailer(true);

    try {
        // -----------------------------------------------------------------
        // SMTP Configuration (from .env)
        // -----------------------------------------------------------------
        $mailer->isSMTP();
        $mailer->Host       = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
        $mailer->SMTPAuth   = true;
        $mailer->Username   = getenv('MAIL_USERNAME') ?: '';
        $mailer->Password   = getenv('MAIL_PASSWORD') ?: '';
        $mailer->SMTPSecure = getenv('MAIL_ENCRYPTION') ?: PHPMailer::ENCRYPTION_SMTPS;
        $mailer->Port       = (int)(getenv('MAIL_PORT') ?: 587);

        // -----------------------------------------------------------------
        // Sender / Recipient
        // -----------------------------------------------------------------
        $fromEmail = getenv('MAIL_FROM_ADDRESS') ?: getenv('MAIL_USERNAME') ?: 'no-reply@bpc-clinic.local';
        $fromName  = getenv('MAIL_FROM_NAME') ?: APP_NAME;

        $mailer->setFrom($fromEmail, $fromName);
        $mailer->addAddress($toEmail);

        // -----------------------------------------------------------------
        // Content
        // -----------------------------------------------------------------
        $mailer->isHTML(true);
        $mailer->Subject = $subject ?: 'BPC School Clinic - Password Reset';

        if (!$bodyHtml) {
            $appUrl = rtrim(APP_URL, '/');
            $bodyHtml = '
            <!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="UTF-8">
              <meta name="viewport" content="width=device-width, initial-scale=1.0">
              <title>Password Reset</title>
            </head>
            <body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;padding:24px 0;">
                <tr>
                  <td align="center">
                    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                      <tr>
                        <td style="background-color:#0E7A3E;padding:24px 32px;text-align:center;">
                          <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">BPC School Clinic</h1>
                          <p style="margin:6px 0 0;color:#e8f5ec;font-size:13px;">Management System</p>
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:32px;">
                          <p style="margin:0 0 16px;color:#333333;font-size:15px;line-height:1.6;">Hello,</p>
                          <p style="margin:0 0 16px;color:#333333;font-size:15px;line-height:1.6;">
                            We received a request to reset your password for the <strong>BPC School Clinic Management System</strong>.
                            We received a request to reset your password for the <strong>AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement</strong>.
                          </p>
                          <p style="margin:0 0 24px;color:#333333;font-size:15px;line-height:1.6;">
                            Click the button below to reset your password:
                          </p>
                          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                            <tr>
                              <td style="background-color:#0E7A3E;border-radius:6px;text-align:center;">
                                <a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 24px;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">Reset Password</a>
                              </td>
                            </tr>
                          </table>
                          <p style="margin:0 0 8px;color:#555555;font-size:13px;line-height:1.5;">
                            If the button does not work, copy and paste this link into your browser:
                          </p>
                          <p style="margin:0 0 24px;color:#0E7A3E;font-size:13px;word-break:break-all;">
                            ' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '
                          </p>
                          <p style="margin:0 0 8px;color:#555555;font-size:13px;line-height:1.5;">
                            This link will expire after 30 minutes.
                          </p>
                          <p style="margin:0;color:#555555;font-size:13px;line-height:1.5;">
                            If you did not request a password reset, you can safely ignore this email.
                          </p>
                        </td>
                      </tr>
                      <tr>
                        <td style="background-color:#f4f6f8;padding:16px 32px;text-align:center;border-top:1px solid #e5e7eb;">
                          <p style="margin:0;color:#888888;font-size:12px;">&copy; ' . date('Y') . ' BPC School Clinic Management System</p>
                          <p style="margin:0;color:#888888;font-size:12px;">&copy; ' . date('Y') . ' AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </body>
            </html>';
        }

        $mailer->Body    = $bodyHtml;
        $mailer->AltBody = 'Reset your password: ' . $resetLink . "\n\nThis link will expire after 30 minutes.\nIf you did not request this, ignore this email.";

        // -----------------------------------------------------------------
        // Send
        // -----------------------------------------------------------------
        $mailer->send();
        error_log('[MAIL] Password reset email sent successfully to ' . $toEmail);
        return true;
    } catch (MailException $e) {
        error_log('[MAIL] Password reset email failed to ' . $toEmail . ': ' . $mailer->ErrorInfo);
        return false;
    } catch (Throwable $e) {
        error_log('[MAIL] Password reset email unexpected error for ' . $toEmail . ': ' . $e->getMessage());
        return false;
    }
}
