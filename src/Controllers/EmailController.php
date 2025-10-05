<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Services\EmailService;
use Apoio19\Crm\Views\EmailView;
use Error;

class EmailController
{
    private EmailService $emailService;

    public function __construct()
    {
        $this->emailService = new EmailService();
    }

     /**
     * Exemplo de método para enviar um e-mail de boas-vindas.
     *
     * @param array $requestData Deve conter 'user_id' ou 'email' e 'nome'.
     * @return array Resposta da API.
     */
    public function sendWelcomeEmail(array $requestData): array
    {
        $email = $requestData['email'] ?? null;
        $nome = $requestData['nome'] ?? null;

        if (!$email || !$nome) {
            error_log("EmailController: Dados insuficientes para enviar o e-mail de boas-vindas.", 400);
            return ['status' => 'error', 'message' => 'Dados insuficientes para enviar o e-mail.'];
        }

        // Renderiza o template de e-mail
        $emailBody = EmailView::render('boas_vindas.html', ['nome_usuario' => $nome]);
        if ($emailBody === false) {
            error_log("EmailController: Template de e-mail não encontrado.", 500);
            return ['status' => 'error', 'message' => 'Erro ao carregar o template de e-mail.'];
        }

        $subject = "Bem-vindo ao CRM Triade Nous!";
        $success = $this->emailService->send($email, $nome, $subject, $emailBody);

        // retorna a resposta
        if ($success) {
            return ['status' => 'success', 'message' => 'E-mail de boas-vindas enviado com sucesso.'];
        } else {
            error_log("EmailController: Falha ao enviar o e-mail para $email", 500);
            return ['status' => 'error', 'message' => 'Falha ao enviar o e-mail.'];
        }
    }
}