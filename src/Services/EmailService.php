<?php

namespace Apoio19\Crm\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    private PHPMailer $mailer;

    public function __construct()
    {
        $this->mailer = new PHPMailer(true);
        $this->configure();
    }

    /**
     * Configura o PHPMailer com as credenciais do .env
     */
    private function configure(): void
    {
        // Configurações do servidor
        $this->mailer->isSMTP();
        $this->mailer->Host       = $_ENV['MAIL_HOST'];
        $this->mailer->SMTPAuth   = true;
        $this->mailer->Username   = $_ENV['MAIL_USERNAME'];
        $this->mailer->Password   = $_ENV['MAIL_PASSWORD'];
        $this->mailer->SMTPSecure = $_ENV['MAIL_ENCRYPTION']; // PHPMailer::ENCRYPTION_SMTPS
        $this->mailer->Port       = $_ENV['MAIL_PORT'];
        $this->mailer->CharSet    = 'UTF-8';

        // Remetente
        $this->mailer->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
    }

    /**
     * Envia um e-mail.
     *
     * @param string $toEmail E-mail do destinatário
     * @param string $toName Nome do destinatário
     * @param string $subject Assunto do e-mail
     * @param string $body Corpo do e-mail (pode ser HTML)
     * @param string $altBody Corpo alternativo em texto puro
     * @return bool Retorna true se enviado, false se houver erro.
     */
    public function send(string $toEmail, string $toName, string $subject, string $body, string $altBody = ''): bool
    {
        try {
            // Destinatários
            $this->mailer->addAddress($toEmail, $toName);

            // Conteúdo
            $this->mailer->isHTML(true);
            $this->mailer->Subject = $subject;
            $this->mailer->Body    = $body;
            $this->mailer->AltBody = $altBody ?: strip_tags($body); // Cria altbody a partir do HTML se não for fornecido

            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            // Em um ambiente de produção, você deveria logar este erro
            error_log("Erro ao enviar e-mail: {$this->mailer->ErrorInfo}");
            return false;
        }
    }
}