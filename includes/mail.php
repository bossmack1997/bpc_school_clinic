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
function send_account_activation_email(string $email,string $name,string $link): bool {
    $mailer=new PHPMailer(true);
    try {
        $mailer->isSMTP();$mailer->Timeout=15;$mailer->getSMTPInstance()->Timelimit=20;
        $mailer->Host=getenv('MAIL_HOST')?:'smtp.gmail.com';$mailer->SMTPAuth=true;
        $mailer->Username=getenv('MAIL_USERNAME')?:'';$mailer->Password=getenv('MAIL_PASSWORD')?:'';
        $mailer->SMTPSecure=getenv('MAIL_ENCRYPTION')?:PHPMailer::ENCRYPTION_SMTPS;$mailer->Port=(int)(getenv('MAIL_PORT')?:587);
        $mailer->setFrom(getenv('MAIL_FROM_ADDRESS')?:getenv('MAIL_USERNAME')?:'no-reply@bpc-clinic.local',getenv('MAIL_FROM_NAME')?:APP_NAME);
        $mailer->addAddress($email,$name);$mailer->isHTML(true);$mailer->Subject='BPC School Clinic — activate your account';
        $mailer->Body='<p>Hello '.htmlspecialchars($name,ENT_QUOTES,'UTF-8').',</p><p>MIS provisioned your school clinic account. <a href="'.htmlspecialchars($link,ENT_QUOTES,'UTF-8').'">Verify your email and choose your private password</a>.</p><p>This single-use link expires in 24 hours. Nurse clinical access requires separate Doctor authorization. If you did not expect this account, contact MIS.</p>';
        $mailer->AltBody="MIS provisioned your school clinic account. Verify your email and choose your private password: $link\nSingle use; expires in 24 hours. Contact MIS if unexpected.";
        return $mailer->send();
    }catch(Throwable $e){error_log('Provisioned account activation transport failed; no recipient or token logged.');return false;}
}

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

/**
 * Send a Registration OTP Email for institutional identity verification.
 *
 * @param string $toEmail Recipient institutional email
 * @param string $otpCode 6-digit verification code
 * @param string $recipientName Optional applicant full name
 * @param string $role User role
 * @return bool True on success, false on failure
 */
function send_registration_otp_email($toEmail, $otpCode, $recipientName = '', $role = 'student') {
    $mailer = new PHPMailer(true);

    try {
        $mailer->isSMTP();
        $mailer->Host       = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
        $mailer->SMTPAuth   = true;
        $mailer->Username   = getenv('MAIL_USERNAME') ?: '';
        $mailer->Password   = getenv('MAIL_PASSWORD') ?: '';
        $mailer->SMTPSecure = getenv('MAIL_ENCRYPTION') ?: PHPMailer::ENCRYPTION_SMTPS;
        $mailer->Port       = (int)(getenv('MAIL_PORT') ?: 587);

        $fromEmail = getenv('MAIL_FROM_ADDRESS') ?: getenv('MAIL_USERNAME') ?: 'no-reply@bpc-clinic.local';
        $fromName  = getenv('MAIL_FROM_NAME') ?: APP_NAME;

        $mailer->setFrom($fromEmail, $fromName);
        $mailer->addAddress($toEmail, $recipientName ?: 'Applicant');

        $mailer->isHTML(true);
        $mailer->Subject = 'BPC School Clinic - Email Verification Code';

        $greeting = $recipientName ? 'Hello ' . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . ',' : 'Hello,';
        $roleName = ucfirst($role);

        $bodyHtml = '
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="UTF-8">
          <title>Email Verification</title>
        </head>
        <body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;padding:24px 0;">
            <tr>
              <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                  <tr>
                    <td style="background-color:#0E7A3E;padding:24px 32px;text-align:center;">
                      <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:700;">AI-Enhanced School Clinic Management System</h1>
                      <p style="margin:6px 0 0;color:#e8f5ec;font-size:13px;">Bulacan Polytechnic College Healthcare Service Improvement</p>
                    </td>
                  </tr>
                  <tr>
                    <td style="padding:32px;">
                      <p style="margin:0 0 16px;color:#333333;font-size:15px;line-height:1.6;">' . $greeting . '</p>
                      <p style="margin:0 0 16px;color:#333333;font-size:15px;line-height:1.6;">
                        You are registering for a <strong>' . htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8') . '</strong> account on the BPC School Clinic Management System.
                      </p>
                      <p style="margin:0 0 20px;color:#333333;font-size:15px;line-height:1.6;">
                        Please use the following One-Time Password (OTP) to verify ownership of your email address:
                      </p>
                      <div style="background-color:#f0fdf4;border:2px dashed #0E7A3E;border-radius:8px;padding:20px;text-align:center;margin:0 0 24px;">
                        <span style="font-size:32px;font-weight:800;letter-spacing:8px;color:#0E7A3E;font-family:monospace;">' . htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8') . '</span>
                      </div>
                      <p style="margin:0 0 8px;color:#555555;font-size:13px;line-height:1.5;">
                        <strong style="color:#b45309;">Important Security Notes:</strong>
                      </p>
                      <ul style="margin:0 0 20px;padding-left:20px;color:#555555;font-size:13px;line-height:1.5;">
                        <li>This OTP will expire in <strong>10 minutes</strong>.</li>
                        <li>You have a maximum of <strong>5 verification attempts</strong>.</li>
                        <li>Never share this code with anyone. BPC Clinic staff will never ask for your OTP.</li>
                      </ul>
                      <p style="margin:0;color:#777777;font-size:12px;line-height:1.5;">
                        If you did not initiate this registration, someone may be attempting to use your institutional identity. Please disregard this email or inform the Clinic Administrator.
                      </p>
                    </td>
                  </tr>
                  <tr>
                    <td style="background-color:#f4f6f8;padding:16px 32px;text-align:center;border-top:1px solid #e5e7eb;">
                      <p style="margin:0;color:#888888;font-size:12px;">&copy; ' . date('Y') . ' Bulacan Polytechnic College School Clinic System</p>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>
        </body>
        </html>';

        $mailer->Body    = $bodyHtml;
        $mailer->AltBody = "BPC Clinic Verification Code: $otpCode\n\nExpires in 10 minutes. Do not share this code.";

        $mailer->send();
        error_log('[MAIL] Registration OTP accepted by transport.');
        return true;
    } catch (MailException $e) {
        error_log('[MAIL] Registration OTP transport failed.');
        return false;
    } catch (Throwable $e) {
        error_log('[MAIL] Registration OTP transport error.');
        return false;
    }
}



/**
 * Send Doctor / Clinical Co-Administrator invitation and account setup link.
 *
 * @param string $toEmail Recipient email address
 * @param string $doctorName Doctor full name
 * @param string $setupLink Unique single-use account setup link
 * @param string $expiresAt Expiration datetime description
 * @param string $appointmentRef Official appointment reference
 * @param string|null $errorMessage Reference to capture error if delivery fails
 * @return bool true on success, false on failure
 */
function send_doctor_invitation_email($toEmail, $doctorName, $setupLink, $expiresAt, $appointmentRef = '', ?string &$errorMessage = null): bool {
    $mailer = new PHPMailer(true);

    try {
        $mailer->isSMTP();
        $mailer->Timeout = 15;
        $mailer->getSMTPInstance()->Timelimit = 20;
        $mailer->Host       = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
        $mailer->SMTPAuth   = true;
        $mailer->Username   = getenv('MAIL_USERNAME') ?: '';
        $mailer->Password   = getenv('MAIL_PASSWORD') ?: '';
        $mailer->SMTPSecure = getenv('MAIL_ENCRYPTION') ?: PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port       = (int)(getenv('MAIL_PORT') ?: 587);

        // Fail fast if credentials are not configured
        if (empty($mailer->Username) || empty($mailer->Password)) {
            $errorMessage = 'SMTP credentials not configured in environment (.env).';
            error_log('[MAIL] Doctor invitation aborted: ' . $errorMessage);
            return false;
        }

        $fromEmail = getenv('MAIL_FROM_ADDRESS') ?: getenv('MAIL_USERNAME') ?: 'no-reply@bpc-clinic.local';
        $fromName  = getenv('MAIL_FROM_NAME') ?: APP_NAME;

        $mailer->setFrom($fromEmail, $fromName);
        $mailer->addAddress($toEmail, $doctorName);

        $mailer->isHTML(true);
        $mailer->Subject = 'Official Invitation: Clinical Co-Administrator Account Setup — BPC School Clinic';

        $safeName = htmlspecialchars($doctorName, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($setupLink, ENT_QUOTES, 'UTF-8');
        $safeRef  = htmlspecialchars($appointmentRef ?: 'Institutional Appointment', ENT_QUOTES, 'UTF-8');
        $safeExp  = htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8');
        $appName  = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');

        $bodyHtml = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Clinical Co-Administrator Account Setup</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;padding:32px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
          <tr>
            <td style="background-color:#0f5132;padding:24px 32px;text-align:center;">
              <h1 style="color:#ffffff;font-size:20px;margin:0;font-weight:700;letter-spacing:0.5px;">{$appName}</h1>
              <p style="color:#d1e7dd;font-size:13px;margin:4px 0 0 0;">Bulacan Polytechnic College — Health Services</p>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;">
              <h2 style="color:#0f172a;font-size:18px;margin-top:0;">Dear {$safeName},</h2>
              <p style="color:#334155;font-size:14px;line-height:1.6;">
                You have been officially provisioned as a <strong>Clinical Co-Administrator (Doctor)</strong> for the Bulacan Polytechnic College School Clinic Management System.
              </p>
              <div style="background-color:#f8fafc;border-left:4px solid #0f5132;padding:12px 16px;margin:20px 0;border-radius:4px;">
                <p style="margin:0 0 6px 0;font-size:13px;color:#64748b;"><strong>Role:</strong> Clinical Co-Administrator</p>
                <p style="margin:0;font-size:13px;color:#64748b;"><strong>Appointment Authority Reference:</strong> {$safeRef}</p>
              </div>
              <p style="color:#334155;font-size:14px;line-height:1.6;">
                To activate your account and establish your secure credentials, please click the link below. This single-use setup link is cryptographically protected and will expire on <strong>{$safeExp}</strong>.
              </p>
              <div style="text-align:center;margin:32px 0;">
                <a href="{$safeLink}" style="background-color:#0f5132;color:#ffffff;text-decoration:none;padding:14px 28px;border-radius:8px;font-weight:bold;font-size:14px;display:inline-block;box-shadow:0 2px 4px rgba(15,81,50,0.3);">
                  Set Up Doctor Account &amp; Password
                </a>
              </div>
              <p style="color:#64748b;font-size:12px;line-height:1.5;">
                If the button above does not work, copy and paste the following URL into your browser:<br>
                <a href="{$safeLink}" style="color:#0f5132;word-break:break-all;">{$safeLink}</a>
              </p>
              <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
              <p style="color:#94a3b8;font-size:12px;margin:0;">
                If you were not expecting this appointment, please notify the BPC MIS Department immediately at clinic@bpc.edu.ph.
              </p>
            </td>
          </tr>
          <tr>
            <td style="background-color:#f8fafc;padding:16px 32px;text-align:center;border-top:1px solid #e2e8f0;">
              <p style="color:#94a3b8;font-size:11px;margin:0;">
                &copy; Bulacan Polytechnic College — School Clinic Management System. All rights reserved.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

        $mailer->Body    = $bodyHtml;
        $mailer->AltBody = "Dear {$doctorName},\n\nYou have been provisioned as Clinical Co-Administrator (Doctor) for the BPC School Clinic Management System.\nAppointment Reference: {$appointmentRef}\n\nPlease activate your account and set your password here:\n{$setupLink}\n\nThis link expires on {$expiresAt}.\n";

        $mailer->send();
        error_log('[MAIL] Doctor invitation email accepted by transport.');
        return true;
    } catch (MailException $e) {
        $errorMessage = $mailer->ErrorInfo ?: $e->getMessage();
        error_log('[MAIL] Doctor invitation email transport failed.');
        return false;
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        error_log('[MAIL] Doctor invitation email transport error.');
        return false;
    }
}
