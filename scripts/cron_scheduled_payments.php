<?php

/**
 * Script para processar agendamentos futuros e gerar boletos na Pagar.me
 * Deve ser rodado via CRON diariamente
 * Exemplo: 0 8 * * * cd /var/www/html/crm/scripts && php cron_scheduled_payments.php >> /var/log/scheduled_payments.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Apoio19\Crm\Models\ScheduledPayment;
use Apoio19\Crm\Models\Invoice;
use Apoio19\Crm\Models\Client;
use Apoio19\Crm\Services\PagarMeService;
use Apoio19\Crm\Services\InvoiceEmailService;

// Carregar variáveis de ambiente
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, ' "\'');
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Iniciando processamento de agendamentos...\n";

try {
    $pagarme = new PagarMeService();
    $emailService = new InvoiceEmailService();
    $pending = ScheduledPayment::getPendingPaymentsUntilToday();

    if (empty($pending)) {
        echo "Nenhum agendamento pendente para processar hoje.\n";
        exit(0);
    }

    echo "Encontrados " . count($pending) . " agendamentos pendentes.\n";

    foreach ($pending as $payment) {
        echo "Processando agendamento ID: {$payment->id} para Cliente ID: {$payment->client_id} (Valor: R$ {$payment->amount})\n";
        
        try {
            $client = Client::findById($payment->client_id);
            if (!$client) {
                throw new \Exception("Cliente não encontrado.");
            }

            // Gerar boleto na Pagar.me
            $customerResp = $pagarme->createCustomer(
                $client->corporate_name ?? 'Cliente Avulso',
                'cliente_avulso@exemplo.com',
                $client->document ?? '00000000000'
            );

            $customerId = $customerResp['id'] ?? null;
            $items = [
                [
                    'amount' => (int)round($payment->amount * 100),
                    'description' => $payment->description ?? 'Pagamento Agendado',
                    'quantity' => 1,
                    'code' => 'item_1'
                ]
            ];

            // Calcula o número de dias até o vencimento baseado no due_date
            $dueDays = 1;
            if ($payment->due_date) {
                $diff = strtotime($payment->due_date) - time();
                $dueDays = max(1, floor($diff / (60 * 60 * 24)));
            }

            $orderResp = $pagarme->createBoletoOrder($customerId, $items, $dueDays);

            if (isset($orderResp['charges'][0]['last_transaction']['url'])) {
                $boletoUrl = $orderResp['charges'][0]['last_transaction']['url'];
                $chargeId = $orderResp['charges'][0]['id'];
                
                // Salvar como uma fatura normal no CRM
                $invoiceId = Invoice::create([
                    'client_id' => $payment->client_id,
                    'amount' => $payment->amount,
                    'due_date' => $payment->due_date,
                    'status' => 'pending',
                    'boleto_url' => $boletoUrl,
                    'pagarme_order_id' => $orderResp['id'],
                    'pagarme_charge_id' => $chargeId,
                    'description' => $payment->description ?? 'Pagamento Agendado'
                ]);

                // Enviar e-mail se solicitado
                if ($payment->send_email && $invoiceId) {
                    $emailService->sendBoletoEmail((int)$invoiceId);
                    echo "  -> E-mail enviado com sucesso!\n";
                }

                // Atualizar status do agendamento para processado
                ScheduledPayment::update($payment->id, ['status' => 'processed']);
                echo "  -> Agendamento {$payment->id} processado com sucesso! (Fatura Gerada: {$invoiceId})\n";
            } else {
                throw new \Exception("Erro ao obter URL do boleto na resposta da Pagar.me.");
            }
        } catch (\Exception $e) {
            echo "  -> Falha ao processar agendamento {$payment->id}: " . $e->getMessage() . "\n";
            ScheduledPayment::update($payment->id, ['status' => 'failed']);
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] Processamento finalizado.\n";

} catch (\Exception $e) {
    echo "Erro fatal no script: " . $e->getMessage() . "\n";
    exit(1);
}
