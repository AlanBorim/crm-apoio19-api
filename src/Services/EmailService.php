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

    /**
     * Envia proposta comercial com PDF anexado.
     *
     * @param string $clientEmail E-mail do cliente
     * @param string $clientName Nome do cliente
     * @param string $proposalTitle Título da proposta
     * @param int $proposalId ID da proposta
     * @param float $totalValue Valor total
     * @param string $validityDate Data de validade
     * @param string $responsibleName Nome do responsável
     * @param string $pdfPath Caminho do PDF
     * @param string|null $managerEmail E-mail do gestor (CC)
     * @return bool
     */
    public function sendProposal(
        string $clientEmail,
        string $clientName,
        string $proposalTitle,
        int $proposalId,
        float $totalValue,
        string $validityDate,
        string $responsibleName,
        string $pdfPath,
        ?string $managerEmail = null
    ): bool {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            
            // Destinatário
            $this->mailer->addAddress($clientEmail, $clientName);
            
            // CC para gestor
            if ($managerEmail) {
                $this->mailer->addCC($managerEmail);
            }
            
            // Assunto
            $this->mailer->Subject = "Proposta Comercial #{$proposalId} - {$proposalTitle}";
            
            // Corpo do e-mail
            $emailBody = $this->getProposalEmailTemplate(
                $clientName,
                $proposalTitle,
                $proposalId,
                $totalValue,
                $validityDate,
                $responsibleName
            );
            
            $this->mailer->isHTML(true);
            $this->mailer->Body = $emailBody;
            $this->mailer->AltBody = strip_tags($emailBody);
            
            // Anexar PDF
            if (file_exists($pdfPath)) {
                $this->mailer->addAttachment($pdfPath, "Proposta_{$proposalId}.pdf");
            } else {
                error_log("PDF não encontrado: {$pdfPath}");
                throw new Exception("Arquivo PDF não encontrado.");
            }
            
            $this->mailer->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Erro ao enviar proposta #{$proposalId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Template HTML para e-mail de proposta.
     */
    private function getProposalEmailTemplate(
        string $clientName,
        string $proposalTitle,
        int $proposalId,
        float $totalValue,
        string $validityDate,
        string $responsibleName
    ): string {
        $valueFormatted = 'R$ ' . number_format($totalValue, 2, ',', '.');
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .highlight-box { background: white; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="margin: 0;">Proposta Comercial</h1>
            <p style="margin: 10px 0 0 0; opacity: 0.9;">Apoio19 CRM</p>
        </div>
        <div class="content">
            <p>Prezado(a) <strong>{$clientName}</strong>,</p>
            <p>É com grande satisfação que apresentamos nossa proposta comercial.</p>
            <div class="highlight-box">
                <h3 style="margin-top: 0; color: #667eea;">Detalhes da Proposta</h3>
                <p><strong>Número:</strong> #{$proposalId}</p>
                <p><strong>Título:</strong> {$proposalTitle}</p>
                <p><strong>Valor Total:</strong> {$valueFormatted}</p>
                <p><strong>Validade:</strong> {$validityDate}</p>
            </div>
            <p>Segue em anexo o documento completo com todos os detalhes.</p>
            <p>Atenciosamente,<br><strong>{$responsibleName}</strong><br>Apoio19 CRM</p>
        </div>
        <div class="footer">
            <p>Este é um e-mail automático. Para mais informações: contato@apoio19.com.br</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}