<?php

namespace Apoio19\Crm\Services;

use Apoio19\Crm\Services\EmailService;
use Apoio19\Crm\Views\EmailView;

class InvoiceEmailService
{
    private EmailService $emailService;

    public function __construct()
    {
        $this->emailService = new EmailService();
    }

    /**
     * Envia o e-mail de boleto gerado.
     *
     * @param string $email E-mail do cliente
     * @param string $nome Nome do cliente
     * @param array $invoiceData Dados do boleto: valor, data_vencimento, link_boleto, codigo_barras
     * @return bool Retorna true se o envio foi bem-sucedido.
     */
    public function sendInvoiceEmail(string $email, string $nome, array $invoiceData): bool
    {
        if (!$email || !$nome) {
            error_log("InvoiceEmailService: Dados insuficientes para enviar o e-mail de fatura.");
            return false;
        }

        // Prepara os dados para o template
        $data = [
            'nome_cliente' => $nome,
            'valor' => number_format((float)$invoiceData['valor'], 2, ',', '.'),
            'data_vencimento' => date('d/m/Y', strtotime($invoiceData['data_vencimento'])),
            'link_boleto' => $invoiceData['link_boleto'] ?? '#',
            'codigo_barras' => $invoiceData['codigo_barras'] ?? 'Código de barras não disponível'
        ];

        // Renderiza o template de e-mail
        $emailBody = EmailView::render('boleto_financeiro.html', $data);
        if ($emailBody === false) {
            error_log("InvoiceEmailService: Template de e-mail não encontrado.");
            return false;
        }

        $subject = "Novo Boleto Gerado - Apoio19";
        
        // Retorna o resultado do envio
        return $this->emailService->send($email, $nome, $subject, $emailBody);
    }
}
