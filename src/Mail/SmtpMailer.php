<?php

namespace Onekana\Api\Mail;

use Onekana\Api\Support\Env;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class SmtpMailer implements Mailer
{
    public function sendPasswordLink(string $email, string $name, string $token, bool $invitation = false): void
    {
        if (! Env::bool('MAIL_ENABLED', false)) {
            throw new RuntimeException('SMTP delivery is not enabled.');
        }

        $frontend = rtrim((string) Env::get('FRONTEND_APP_URL', ''), '/');
        if ($frontend === '') {
            throw new RuntimeException('FRONTEND_APP_URL must be configured.');
        }

        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = (string) Env::get('MAIL_HOST', '');
        $mailer->Port = Env::int('MAIL_PORT', 587);
        $mailer->SMTPAuth = true;
        $mailer->Username = (string) Env::get('MAIL_USERNAME', '');
        $mailer->Password = (string) Env::get('MAIL_PASSWORD', '');
        $encryption = strtolower((string) Env::get('MAIL_ENCRYPTION', 'tls'));
        $mailer->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom(
            (string) Env::get('MAIL_FROM_ADDRESS', 'no-reply@onekana.com'),
            (string) Env::get('MAIL_FROM_NAME', 'ONEKANA')
        );
        $mailer->addAddress($email, $name);
        $mailer->isHTML(true);

        $url = $frontend.'/reset-password?token='.rawurlencode($token);
        $mailer->Subject = $invitation ? 'Activez votre accès ONEKANA' : 'Réinitialisez votre mot de passe ONEKANA';
        $action = $invitation ? 'Créer mon mot de passe' : 'Réinitialiser mon mot de passe';
        $mailer->Body = '<p>Bonjour '.htmlspecialchars($name, ENT_QUOTES, 'UTF-8').',</p>'
            .'<p>'.($invitation ? 'Votre accès ONEKANA est prêt.' : 'Une demande de réinitialisation a été reçue.').'</p>'
            .'<p><a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">'.$action.'</a></p>'
            .'<p>Ce lien expire dans 30 minutes et ne peut être utilisé qu’une fois.</p>';
        $mailer->AltBody = $action.' : '.$url;
        $mailer->send();
    }
}
