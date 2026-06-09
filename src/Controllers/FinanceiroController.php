<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\SystemConfig;
use Apoio19\Crm\Models\Client;
use Apoio19\Crm\Models\Subscription;
use Apoio19\Crm\Models\Invoice;
use Apoio19\Crm\Models\ScheduledPayment;
use Apoio19\Crm\Models\NfeTaxRule;
use Apoio19\Crm\Models\Withdrawal;
use Apoio19\Crm\Services\PagarMeService;
use Apoio19\Crm\Services\InvoiceEmailService;
use Apoio19\Crm\Services\NfseService;

class FinanceiroController extends BaseController
{
    /**
     * Get settings for Pagar.me
     */
    public function getConfig(array $headers): array
    {
        try {
            $webhookSecret = SystemConfig::get('pagarme_webhook_secret');
            
            $mask = function($key) {
                if (!$key) return '';
                // Mask keeping first 7 and last 4
                $len = strlen($key);
                if ($len < 12) return str_repeat('*', $len);
                return substr($key, 0, 7) . str_repeat('*', $len - 11) . substr($key, -4);
            };

            $secretConfig = SystemConfig::get('pagarme_secret_key');
            $prodKey = $_ENV['PAGARME_API_KEY'] ?? ($secretConfig ? $secretConfig['config_value'] : '');
            $testKey = $_ENV['PAGARME_TEST_API_KEY'] ?? '';

            $nfeEnvironment = SystemConfig::get('nfe_environment');
            $emitirViaPortal = SystemConfig::get('nfe_emitir_via_portal');

            http_response_code(200);
            return [
                "success" => true,
                "data" => [
                    "pagarme_secret_key" => $mask($prodKey),
                    "pagarme_test_key" => $mask($testKey),
                    "pagarme_webhook_secret" => $webhookSecret ? $webhookSecret['config_value'] : '',
                    "nfe_environment" => $nfeEnvironment ? $nfeEnvironment['config_value'] : 'homologacao',
                    "emitir_via_portal" => $emitirViaPortal ? filter_var($emitirViaPortal['config_value'], FILTER_VALIDATE_BOOLEAN) : false
                ]
            ];
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->getConfig: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => "Erro interno no servidor."];
        }
    }

    /**
     * Update settings for Pagar.me
     */
    public function updateConfig(array $headers, array $input): array
    {
        try {
            // As chaves principais agora ficam no .env, atualizamos apenas o webhook secret pelo painel.
            if (isset($input['pagarme_webhook_secret'])) {
                SystemConfig::set('pagarme_webhook_secret', $input['pagarme_webhook_secret']);
            }
            if (isset($input['nfe_environment'])) {
                SystemConfig::set('nfe_environment', $input['nfe_environment']);
            }
            if (isset($input['emitir_via_portal'])) {
                SystemConfig::set('nfe_emitir_via_portal', $input['emitir_via_portal'] ? 'true' : 'false');
            }
            
            http_response_code(200);
            return ["success" => true, "message" => "Configurações atualizadas com sucesso."];
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->updateConfig: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => "Erro interno no servidor."];
        }
    }

    /**
     * Get NFE Tax Rules
     */
    public function getNfeTaxRules(array $headers): array
    {
        try {
            $rules = NfeTaxRule::findAll();
            http_response_code(200);
            return ["success" => true, "data" => $rules];
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->getNfeTaxRules: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => "Erro interno no servidor."];
        }
    }

    /**
     * Create NFE Tax Rule
     */
    public function createNfeTaxRule(array $headers, array $input): array
    {
        try {
            $id = NfeTaxRule::create($input);
            http_response_code(201);
            return ["success" => true, "message" => "Regra fiscal criada com sucesso.", "id" => $id];
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->createNfeTaxRule: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => "Erro interno no servidor."];
        }
    }

    /**
     * Update NFE Tax Rule
     */
    public function updateNfeTaxRule(array $headers, int $id, array $input): array
    {
        try {
            $success = NfeTaxRule::update($id, $input);
            if ($success) {
                http_response_code(200);
                return ["success" => true, "message" => "Regra fiscal atualizada com sucesso."];
            }
            http_response_code(400);
            return ["success" => false, "message" => "Não foi possível atualizar a regra fiscal."];
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->updateNfeTaxRule: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => "Erro interno no servidor."];
        }
    }

    /**
     * Delete NFE Tax Rule
     */
    public function deleteNfeTaxRule(array $headers, int $id): array
    {
        try {
            $success = NfeTaxRule::delete($id);
            if ($success) {
                http_response_code(200);
                return ["success" => true, "message" => "Regra fiscal excluída."];
            }
            http_response_code(400);
            return ["success" => false, "message" => "Não foi possível excluir a regra fiscal."];
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->deleteNfeTaxRule: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => "Erro interno no servidor."];
        }
    }

    /**
     * Testa a conexão com a API da Pagar.me
     */
    public function testConnection(array $headers, array $input): array
    {
        try {
            $useTestKey = isset($input['use_test_key']) ? (bool)$input['use_test_key'] : false;
            $pagarMeService = new PagarMeService($useTestKey);
            
            $success = $pagarMeService->testConnection();
            
            http_response_code(200);
            if ($success) {
                return ["success" => true, "message" => "Conexão bem sucedida com a Pagar.me!"];
            } else {
                return ["success" => false, "message" => "Falha na conexão com a Pagar.me. Verifique as chaves no arquivo .env."];
            }
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->testConnection: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => "Erro interno ao testar conexão."];
        }
    }

    /**
     * List all invoices
     */
    public function getInvoices(array $headers, array $filters = []): array
    {
        try {
            $clientId = $filters['client_id'] ?? null;
            $invoices = Invoice::findAll(50, 0, $filters);
            
            // Format invoices
            foreach ($invoices as $k => $inv) {
                $invoices[$k]->item_type = 'invoice';
            }

            if ($clientId) {
                $subscriptions = Subscription::findByClientId((int)$clientId);
                $scheduled = ScheduledPayment::findByClientId((int)$clientId);
            } else {
                $subscriptions = Subscription::findAll(100, 0); // limiting to 100 for global view
                $scheduled = ScheduledPayment::findAll(100, 0);
            }

            foreach ($subscriptions as $sub) {
                $sub->item_type = 'subscription';
                $sub->due_date = $sub->next_billing_date;
                $sub->boleto_url = null;
                $invoices[] = $sub;
            }

            foreach ($scheduled as $sch) {
                $sch->item_type = 'scheduled';
                $sch->boleto_url = null;
                $invoices[] = $sch;
            }

            // Sort mixed array by created_at desc
            usort($invoices, function($a, $b) {
                return strtotime($b->created_at) - strtotime($a->created_at);
            });

            http_response_code(200);
            return ["success" => true, "data" => $invoices];
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->getInvoices: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => "Erro interno no servidor."];
        }
    }

    /**
     * Generate an invoice for a client (Legacy alias to generateBoleto)
     */
    public function generateInvoice(array $headers, array $input): array
    {
        return $this->generateBoleto($headers, $input);
    }

    /**
     * Generate a subscription for a client
     */
    public function generateSubscription(array $headers, array $input): array
    {
        try {
            $clientId = $input['client_id'] ?? null;
            $amount = $input['amount'] ?? 0;
            $dueDate = $input['due_date'] ?? null;

            if (!$clientId || $amount <= 0) {
                http_response_code(400);
                return ["success" => false, "message" => "Dados inválidos para gerar assinatura."];
            }

            $client = Client::findById((int)$clientId);
            if (!$client) {
                http_response_code(404);
                return ["success" => false, "message" => "Cliente não encontrado."];
            }

            $pagarme = new PagarMeService();
            $customerResp = $pagarme->createCustomer(
                $client->corporate_name ?? 'Cliente Recorrente',
                'cliente_recorrente@exemplo.com',
                $client->document ?? '00000000000'
            );

            $customerId = $customerResp['id'] ?? null;
            
            $subResp = $pagarme->createSubscription($customerId, (float)$amount, null, 'boleto', $dueDate);

            Subscription::create([
                'client_id' => $clientId,
                'amount' => $amount,
                'billing_cycle' => 'monthly',
                'status' => 'active',
                'next_billing_date' => $dueDate ? date('Y-m-d', strtotime($dueDate)) : date('Y-m-d', strtotime('+1 month')),
                'pagarme_subscription_id' => $subResp['id'] ?? null
            ]);

            http_response_code(201);
            return ["success" => true, "message" => "Assinatura criada com sucesso."];
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->generateSubscription: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => $e->getMessage()];
        }
    }

    /**
     * Create a scheduled payment
     */
    public function createScheduledPayment(array $headers, array $input): array
    {
        try {
            $clientId = $input['client_id'] ?? null;
            $amount = $input['amount'] ?? 0;
            $description = $input['description'] ?? null;
            $scheduleDate = $input['schedule_date'] ?? null;
            $dueDate = $input['due_date'] ?? null;
            $sendEmail = $input['send_email'] ?? false;

            if (!$clientId || $amount <= 0 || !$scheduleDate || !$dueDate) {
                http_response_code(400);
                return ["success" => false, "message" => "Dados inválidos para agendamento."];
            }

            $client = Client::findById((int)$clientId);
            if (!$client) {
                http_response_code(404);
                return ["success" => false, "message" => "Cliente não encontrado."];
            }

            ScheduledPayment::create([
                'client_id' => $clientId,
                'amount' => $amount,
                'description' => $description,
                'schedule_date' => $scheduleDate,
                'due_date' => $dueDate,
                'send_email' => $sendEmail ? 1 : 0,
                'status' => 'pending'
            ]);

            http_response_code(201);
            return ["success" => true, "message" => "Pagamento agendado com sucesso."];
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->createScheduledPayment: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => "Erro interno no servidor."];
        }
    }

    /**
     * List scheduled payments
     */
    public function getScheduledPayments(array $headers, array $filters = []): array
    {
        try {
            $clientId = $filters['client_id'] ?? null;
            if (!$clientId) {
                http_response_code(400);
                return ["success" => false, "message" => "O ID do cliente é obrigatório."];
            }

            $payments = ScheduledPayment::findByClientId((int)$clientId);
            http_response_code(200);
            return ["success" => true, "data" => $payments];
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->getScheduledPayments: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => "Erro interno no servidor."];
        }
    }

    /**
     * Webhook Handler for Pagar.me
     */
    public function webhook(array $headers, string $payload): array
    {
        try {
            $pagarme = new PagarMeService();
            $signature = '';
            
            // Search for signature header (HTTP_X_HUB_SIGNATURE is typically what PHP gets if header is X-Hub-Signature)
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'x-hub-signature' || strtolower($key) === 'http_x_hub_signature') {
                    $signature = $value;
                }
            }

            // Ignored for local testing or if not set, but in production should be checked
            // if (!$pagarme->verifyWebhookSignature($payload, $signature)) {
            //     http_response_code(401);
            //     return ["success" => false, "message" => "Assinatura inválida."];
            // }

            $data = json_decode($payload, true);
            $type = $data['type'] ?? '';

            if ($type === 'charge.paid') {
                $chargeId = $data['data']['id'] ?? '';
                if ($chargeId) {
                    $invoice = Invoice::findByChargeId($chargeId);
                    if ($invoice) {
                        Invoice::update($invoice->id, [
                            'status' => 'paid',
                            'payment_date' => date('Y-m-d H:i:s')
                        ]);
                    }
                }
            } elseif ($type === 'charge.failed' || $type === 'charge.payment_failed') {
                $chargeId = $data['data']['id'] ?? '';
                if ($chargeId) {
                    $invoice = Invoice::findByChargeId($chargeId);
                    if ($invoice) {
                        Invoice::update($invoice->id, ['status' => 'failed']);
                    }
                }
            }

            http_response_code(200);
            return ["success" => true, "message" => "Webhook processado com sucesso."];
        } catch (\Exception $e) {
            error_log("Erro no Webhook: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => "Erro interno no servidor."];
        }
    }

    /**
     * List Withdrawals
     */
    public function getWithdrawals(array $headers): array
    {
        try {
            $withdrawals = Withdrawal::findAll();
            http_response_code(200);
            return ["success" => true, "data" => $withdrawals];
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->getWithdrawals: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => "Erro interno no servidor."];
        }
    }

    /**
     * Request a Withdrawal
     */
    public function requestWithdrawal(array $headers, array $input, int $userId): array
    {
        try {
            $amount = $input['amount'] ?? 0;
            if ($amount <= 0) {
                http_response_code(400);
                return ["success" => false, "message" => "Valor inválido."];
            }

            // A chamada ao Pagar.me seria implementada aqui com recipientId
            // $pagarme = new PagarMeService();
            // $resp = $pagarme->createTransfer($amount, 're_xxxxxxxxxxxxx');

            Withdrawal::create([
                'user_id' => $userId,
                'amount' => $amount,
                'status' => 'processing', // or completed if instant via API
                'pagarme_transfer_id' => 'simulated_id_for_now'
            ]);

            http_response_code(201);
            return ["success" => true, "message" => "Saque solicitado com sucesso."];
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->requestWithdrawal: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => $e->getMessage()];
        }
    }

    public function generateBoleto(array $headers, array $input): array
    {
        try {
            $clientId = $input['client_id'] ?? null;
            $amount = $input['amount'] ?? 0;
            $dueDate = $input['due_date'] ?? null;
            $description = $input['description'] ?? 'Fatura CRM';
            $sendEmail = isset($input['send_email']) ? (bool)$input['send_email'] : false;

            if (!$clientId || $amount <= 0) {
                http_response_code(400);
                return ["success" => false, "message" => "Dados inválidos."];
            }

            $client = Client::findById($clientId);
            if (!$client) {
                http_response_code(404);
                return ["success" => false, "message" => "Cliente não encontrado."];
            }

            $email = 'financeiro@apoio19.com.br'; // Fallback
            $name = $client->corporate_name ?: ($client->fantasy_name ?: 'Cliente Apoio19');
            $phone = '11999999999';
            
            $pdo = \Apoio19\Crm\Models\Database::getInstance();
            if ($client->contact_id) {
                $stmt = $pdo->prepare("SELECT email, phone FROM contacts WHERE id = ?");
                $stmt->execute([$client->contact_id]);
                $cData = $stmt->fetch();
                if ($cData && $cData['email']) $email = $cData['email'];
                if ($cData && $cData['phone']) $phone = $cData['phone'];
            } elseif ($client->lead_id) {
                $stmt = $pdo->prepare("SELECT email, phone FROM leads WHERE id = ?");
                $stmt->execute([$client->lead_id]);
                $lData = $stmt->fetch();
                if ($lData && $lData['email']) $email = $lData['email'];
                if ($lData && $lData['phone']) $phone = $lData['phone'];
            }

            $clientData = [
                'name' => $name,
                'email' => $email,
                'document' => $client->document,
                'phone' => $phone,
                'state' => $client->state,
                'city' => $client->city,
                'zip_code' => $client->zip_code,
                'address' => $client->address,
                'address_number' => $client->address_number,
                'neighborhood' => $client->district
            ];

            $pagarme = new PagarMeService();
            $isSuccess = false;
            $orderId = null;
            $chargeId = null;
            $boletoUrl = null;
            $barcode = null;
            $pagarmeError = "";

            try {
                $response = $pagarme->createBoletoOrder($clientData, $amount, $description, $dueDate);
                $isSuccess = isset($response['id']);
                $orderId = $response['id'] ?? null;
                $chargeId = $response['charges'][0]['id'] ?? null;
                $boletoUrl = $response['charges'][0]['last_transaction']['url'] ?? null;
                $barcode = $response['charges'][0]['last_transaction']['barcode'] ?? null;
            } catch (\Exception $e) {
                $pagarmeError = $e->getMessage();
            }

            $invoiceId = Invoice::create([
                'client_id' => $clientId,
                'amount' => $amount,
                'status' => $isSuccess ? 'pending' : 'failed',
                'due_date' => $dueDate ?: date('Y-m-d', strtotime('+3 days')),
                'pagarme_order_id' => $orderId,
                'pagarme_charge_id' => $chargeId,
                'boleto_url' => $boletoUrl,
                'boleto_barcode' => $barcode
            ]);

            // Emissão da Nota Fiscal vinculada
            if ($isSuccess && $invoiceId) {
                try {
                    $taxRule = NfeTaxRule::getDefault();
                    if ($taxRule) {
                        $nfeService = new NfseService(true); // Forçando uso do token de homologação
                        $nfeData = [
                            'prestador' => [
                                'cnpj' => '', // Preenchido via token/config da API ou banco
                                'inscricao_municipal' => '',
                                'codigo_municipio' => ''
                            ],
                            'tomador' => [
                                'documento' => $client->document,
                                'nome' => $name,
                                'email' => $email,
                                'endereco' => [
                                    'cep' => $client->zip_code,
                                    'logradouro' => $client->address,
                                    'numero' => $client->address_number,
                                    'bairro' => $client->district,
                                    'codigo_municipio' => '', // Pode ser obtido do cliente se houver a coluna
                                    'uf' => $client->state
                                ]
                            ],
                            'servico' => [
                                'valor' => $amount,
                                'codigo_tributacao_municipio' => $taxRule->city_tax_code,
                                'item_lc116' => $taxRule->lc116_code,
                                'cnae' => $taxRule->cnae,
                                'descricao' => $description,
                                'aliquota_iss' => $taxRule->iss_rate,
                                'aliquota_pis' => $taxRule->pis_rate,
                                'aliquota_cofins' => $taxRule->cofins_rate,
                                'aliquota_inss' => $taxRule->inss_rate,
                                'aliquota_ir' => $taxRule->ir_rate,
                                'aliquota_csll' => $taxRule->csll_rate,
                            ]
                        ];
                        
                        $nfeResp = $nfeService->emitirNfse($nfeData);
                        
                        // Atualiza fatura com dados da NFe
                        Invoice::update($invoiceId, [
                            'nfe_id' => $nfeResp['id'] ?? null,
                            'nfe_status' => $nfeResp['status'] ?? 'processing',
                            'nfe_url' => $nfeResp['url'] ?? null,
                            'nfe_xml' => $nfeResp['xml'] ?? null,
                            'nfe_pdf' => $nfeResp['pdf'] ?? null
                        ]);
                    } else {
                        Invoice::update($invoiceId, [
                            'nfe_status' => 'error'
                        ]);
                        error_log("Nenhuma regra fiscal padrão configurada para a fatura {$invoiceId}");
                    }
                } catch (\Exception $e) {
                    error_log("Erro ao emitir NFS-e para a fatura {$invoiceId}: " . $e->getMessage());
                    Invoice::update($invoiceId, [
                        'nfe_status' => 'error'
                    ]);
                    // A falha da NFe não deve invalidar a criação do boleto.
                }
            }

            if ($isSuccess) {
                if ($sendEmail && $invoiceId && $boletoUrl) {
                    $emailService = new InvoiceEmailService();
                    $invoiceData = [
                        'valor' => $amount,
                        'data_vencimento' => $dueDate ?: date('Y-m-d', strtotime('+3 days')),
                        'link_boleto' => $boletoUrl,
                        'codigo_barras' => $barcode
                    ];
                    $emailService->sendInvoiceEmail($email, $name, $invoiceData);
                }

                http_response_code(201);
                return ["success" => true, "message" => "Boleto gerado com sucesso.", "boleto_url" => $boletoUrl];
            } else {
                http_response_code(400);
                return ["success" => false, "message" => "Erro na Pagar.me: " . $pagarmeError];
            }
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->generateBoleto: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => "Erro interno: " . $e->getMessage()];
        }
    }

    public function sendInvoiceEmail(array $headers, int $id): array
    {
        try {
            $invoice = Invoice::findById($id);
            if (!$invoice) {
                http_response_code(404);
                return ["success" => false, "message" => "Fatura não encontrada."];
            }
            if (!$invoice->boleto_url) {
                http_response_code(400);
                return ["success" => false, "message" => "Esta fatura não possui boleto associado."];
            }

            $client = Client::findById($invoice->client_id);
            if (!$client) {
                http_response_code(404);
                return ["success" => false, "message" => "Cliente não encontrado."];
            }

            $email = 'financeiro@apoio19.com.br';
            $name = $client->corporate_name ?: ($client->fantasy_name ?: 'Cliente Apoio19');
            $pdo = \Apoio19\Crm\Models\Database::getInstance();
            if ($client->contact_id) {
                $stmt = $pdo->prepare("SELECT email FROM contacts WHERE id = ?");
                $stmt->execute([$client->contact_id]);
                $cData = $stmt->fetch();
                if ($cData && $cData['email']) $email = $cData['email'];
            } elseif ($client->lead_id) {
                $stmt = $pdo->prepare("SELECT email FROM leads WHERE id = ?");
                $stmt->execute([$client->lead_id]);
                $lData = $stmt->fetch();
                if ($lData && $lData['email']) $email = $lData['email'];
            }

            $emailService = new InvoiceEmailService();
            $invoiceData = [
                'valor' => $invoice->amount,
                'data_vencimento' => $invoice->due_date,
                'link_boleto' => $invoice->boleto_url,
                'codigo_barras' => $invoice->boleto_barcode
            ];
            
            $success = $emailService->sendInvoiceEmail($email, $name, $invoiceData);

            if ($success) {
                http_response_code(200);
                return ["success" => true, "message" => "E-mail enviado com sucesso."];
            } else {
                http_response_code(500);
                return ["success" => false, "message" => "Falha ao enviar e-mail."];
            }
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->sendInvoiceEmail: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => "Erro interno: " . $e->getMessage()];
        }
    }

    public function checkStatus(array $headers, int $id, array $input): array
    {
        try {
            $type = $input['type'] ?? 'invoice';
            $pagarme = new PagarMeService();

            if ($type === 'subscription') {
                $subscription = Subscription::findById($id);
                if (!$subscription) {
                    http_response_code(404);
                    return ["success" => false, "message" => "Assinatura não encontrada."];
                }
                
                if (!$subscription->pagarme_subscription_id) {
                    http_response_code(400);
                    return ["success" => false, "message" => "Esta assinatura não possui ID na Pagar.me."];
                }

                $resp = $pagarme->getSubscription($subscription->pagarme_subscription_id);
                $status = $resp['status'] ?? null;
                
                if ($status) {
                    $dbStatus = $subscription->status;
                    if ($status === 'canceled') {
                        $dbStatus = 'canceled';
                    } elseif ($status === 'active') {
                        $dbStatus = 'active';
                    }
                    
                    Subscription::update($id, [
                        'status' => $dbStatus
                    ]);
                    
                    http_response_code(200);
                    return ["success" => true, "message" => "Status atualizado com sucesso.", "status" => $status];
                }
            } elseif ($type === 'invoice') {
                $invoice = Invoice::findById($id);
                if (!$invoice) {
                    http_response_code(404);
                    return ["success" => false, "message" => "Fatura não encontrada."];
                }
                
                if (!$invoice->pagarme_order_id) {
                    http_response_code(400);
                    return ["success" => false, "message" => "Esta fatura não possui Order ID na Pagar.me."];
                }

                $resp = $pagarme->getOrder($invoice->pagarme_order_id);
                $status = $resp['status'] ?? null;
                
                if ($status) {
                    $dbStatus = $invoice->status;
                    if ($status === 'paid') {
                        $dbStatus = 'paid';
                    } elseif ($status === 'canceled' || $status === 'failed') {
                        $dbStatus = 'failed';
                    }
                    
                    Invoice::update($id, [
                        'status' => $dbStatus
                    ]);
                    
                    http_response_code(200);
                    return ["success" => true, "message" => "Status atualizado com sucesso.", "status" => $status];
                }
            }

            http_response_code(400);
            return ["success" => false, "message" => "Não foi possível verificar o status."];

        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->checkStatus: " . $e->getMessage());
            http_response_code(500);
            return ["success" => false, "message" => "Erro ao consultar Pagar.me: " . $e->getMessage()];
        }
    }

    public function handleNfeWebhook(array $input): array
    {
        // Documentação do BrasilNFE geralmente envia o ID da nota e o novo status
        $nfeId = $input['id'] ?? null;
        $status = $input['status'] ?? null;

        if (!$nfeId || !$status) {
            http_response_code(400);
            return ["success" => false, "message" => "Dados incompletos no webhook da NFS-e"];
        }

        $pdo = \Apoio19\Crm\Models\Database::getInstance();
        $stmt = $pdo->prepare("SELECT id FROM invoices WHERE nfe_id = ?");
        $stmt->execute([$nfeId]);
        $invoice = $stmt->fetch();

        if ($invoice) {
            $updateData = ['nfe_status' => $status];
            if (isset($input['url'])) $updateData['nfe_url'] = $input['url'];
            if (isset($input['pdf'])) $updateData['nfe_pdf'] = $input['pdf'];
            if (isset($input['xml'])) $updateData['nfe_xml'] = $input['xml'];

            Invoice::update($invoice['id'], $updateData);
            return ["success" => true, "message" => "Fatura atualizada via webhook da NFS-e"];
        }

        return ["success" => true, "message" => "NFS-e não atrelada a nenhuma fatura local"];
    }

    public function previewNfeForInvoice(array $headers, $invoiceId): array
    {
        $invoice = Invoice::findById($invoiceId);
        if (!$invoice) {
            http_response_code(404);
            return ["success" => false, "message" => "Fatura não encontrada."];
        }

        $client = Client::findById($invoice->client_id);
        if (!$client) {
            return ["success" => false, "message" => "Cliente vinculado à fatura não encontrado."];
        }

        $email = 'financeiro@apoio19.com.br';
        $name = $client->corporate_name ?: ($client->fantasy_name ?: 'Cliente Apoio19');
        $pdo = \Apoio19\Crm\Models\Database::getInstance();
        
        if ($client->contact_id) {
            $stmt = $pdo->prepare("SELECT email FROM contacts WHERE id = ?");
            $stmt->execute([$client->contact_id]);
            $cData = $stmt->fetch();
            if ($cData && $cData['email']) $email = $cData['email'];
        } elseif ($client->lead_id) {
            $stmt = $pdo->prepare("SELECT email FROM leads WHERE id = ?");
            $stmt->execute([$client->lead_id]);
            $lData = $stmt->fetch();
            if ($lData && $lData['email']) $email = $lData['email'];
        }

        try {
            $taxRule = NfeTaxRule::getDefault();
            if (!$taxRule) {
                return ["success" => false, "message" => "Nenhuma regra fiscal padrão configurada."];
            }

            $nfeService = new NfseService(true);
            $nfeData = [
                'prestador' => [
                    'cnpj' => '',
                    'inscricao_municipal' => '',
                    'codigo_municipio' => ''
                ],
                'tomador' => [
                    'documento' => $client->document,
                    'nome' => $name,
                    'email' => $email,
                    'endereco' => [
                        'cep' => $client->zip_code,
                        'logradouro' => $client->address,
                        'numero' => $client->address_number,
                        'bairro' => $client->district,
                        'codigo_municipio' => '',
                        'uf' => $client->state
                    ]
                ],
                'servico' => [
                    'valor' => $invoice->amount,
                    'codigo_tributacao_municipio' => $taxRule->city_tax_code,
                    'item_lc116' => $taxRule->lc116_code,
                    'cnae' => $taxRule->cnae,
                    'descricao' => 'Fatura CRM #' . $invoice->id,
                    'aliquota_iss' => $taxRule->iss_rate,
                    'aliquota_pis' => $taxRule->pis_rate,
                    'aliquota_cofins' => $taxRule->cofins_rate,
                    'aliquota_inss' => $taxRule->inss_rate,
                    'aliquota_ir' => $taxRule->ir_rate,
                    'aliquota_csll' => $taxRule->csll_rate,
                ]
            ];
            
            $payload = $nfeService->buildPayload($nfeData);

            return ["success" => true, "data" => $payload];
        } catch (\Exception $e) {
            return ["success" => false, "message" => "Erro ao gerar preview da NFS-e: " . $e->getMessage()];
        }
    }

    public function emitNfeForInvoice(array $headers, $invoiceId): array
    {

        $invoice = Invoice::findById($invoiceId);
        if (!$invoice) {
            http_response_code(404);
            return ["success" => false, "message" => "Fatura não encontrada."];
        }

        if ($invoice->nfe_id && $invoice->nfe_status !== 'error') {
            return ["success" => false, "message" => "Esta fatura já possui uma NFS-e vinculada."];
        }

        $client = Client::findById($invoice->client_id);
        if (!$client) {
            return ["success" => false, "message" => "Cliente vinculado à fatura não encontrado."];
        }

        $email = 'financeiro@apoio19.com.br';
        $name = $client->corporate_name ?: ($client->fantasy_name ?: 'Cliente Apoio19');
        $pdo = \Apoio19\Crm\Models\Database::getInstance();
        
        if ($client->contact_id) {
            $stmt = $pdo->prepare("SELECT email FROM contacts WHERE id = ?");
            $stmt->execute([$client->contact_id]);
            $cData = $stmt->fetch();
            if ($cData && $cData['email']) $email = $cData['email'];
        } elseif ($client->lead_id) {
            $stmt = $pdo->prepare("SELECT email FROM leads WHERE id = ?");
            $stmt->execute([$client->lead_id]);
            $lData = $stmt->fetch();
            if ($lData && $lData['email']) $email = $lData['email'];
        }

        try {
            $taxRule = NfeTaxRule::getDefault();
            if (!$taxRule) {
                return ["success" => false, "message" => "Nenhuma regra fiscal padrão configurada."];
            }

            $emitirViaPortal = SystemConfig::get('nfe_emitir_via_portal');
            $usePortalNacional = $emitirViaPortal && $emitirViaPortal['config_value'] === 'true';

            if ($usePortalNacional) {
                $nfeResp = $this->emitirViaPortalNacional($client, $invoice, $taxRule, 'Fatura CRM #' . $invoice->id);
            } else {
                $nfeService = new NfseService(true);
                $nfeData = [
                'prestador' => [
                    'cnpj' => '',
                    'inscricao_municipal' => '',
                    'codigo_municipio' => ''
                ],
                'tomador' => [
                    'documento' => $client->document,
                    'nome' => $name,
                    'email' => $email,
                    'endereco' => [
                        'cep' => $client->zip_code,
                        'logradouro' => $client->address,
                        'numero' => $client->address_number,
                        'bairro' => $client->district,
                        'codigo_municipio' => '',
                        'uf' => $client->state
                    ]
                ],
                'servico' => [
                    'valor' => $invoice->amount,
                    'codigo_tributacao_municipio' => $taxRule->city_tax_code,
                    'item_lc116' => $taxRule->lc116_code,
                    'cnae' => $taxRule->cnae,
                    'descricao' => 'Fatura CRM #' . $invoice->id,
                    'aliquota_iss' => $taxRule->iss_rate,
                    'aliquota_pis' => $taxRule->pis_rate,
                    'aliquota_cofins' => $taxRule->cofins_rate,
                    'aliquota_inss' => $taxRule->inss_rate,
                    'aliquota_ir' => $taxRule->ir_rate,
                    'aliquota_csll' => $taxRule->csll_rate,
                ]
            ];
            
                $nfeResp = $nfeService->emitirNfse($nfeData);
            }
            
            Invoice::update($invoice->id, [
                'nfe_id' => $nfeResp['id'] ?? null,
                'nfe_status' => $nfeResp['status'] ?? 'processing',
                'nfe_url' => $nfeResp['url'] ?? null,
                'nfe_xml' => $nfeResp['xml'] ?? null,
                'nfe_pdf' => $nfeResp['pdf'] ?? null
            ]);

            return ["success" => true, "message" => "NFS-e emitida com sucesso.", "data" => $nfeResp];
        } catch (\Exception $e) {
            error_log("Erro ao emitir NFS-e avulsa: " . $e->getMessage());
            Invoice::update($invoice->id, [
                'nfe_status' => 'error'
            ]);
            return ["success" => false, "message" => "Erro ao emitir NFS-e: " . $e->getMessage()];
        }
    }

    private function emitirViaPortalNacional($client, $invoice, $taxRule, $description) {
        $cnpjPrestador = "35008980000153";
        $senhaPrestador = "050714Af@2025";
        $dataCompetencia = date("d/m/Y");

        $payload = [
            "cnpj_prestador" => $cnpjPrestador,
            "senha_prestador" => $senhaPrestador,
            "data_competencia" => $dataCompetencia,
            "cnpj_tomador" => preg_replace('/[^0-9]/', '', $client->document),
            "nome_tomador" => $client->corporate_name ?: ($client->fantasy_name ?: 'Cliente Apoio19'),
            "cep_tomador" => preg_replace('/[^0-9]/', '', $client->zip_code),
            "logradouro_tomador" => $client->address,
            "numero_tomador" => $client->address_number,
            "bairro_tomador" => $client->district,
            "codigo_municipio_tomador" => "3539301",
            "nome_municipio_tomador" => "Pirassununga",
            "codigo_municipio_prestacao" => "3539301",
            "codigo_ctn" => $taxRule->lc116_code ?: "01.01.01",
            "descricao_servico" => $description,
            "valor_servico" => (float) $invoice->amount,
            "is_optante_simples" => true // Ajuste para ler da tabela de client ou defaults se existir futuramente
        ];

        $pythonScriptPath = __DIR__ . '/../../../emissor_nacional_py/emitir.py';
        // Ajuste de caminho absoluto pro Windows baseado no root do API
        $pythonScriptPath = realpath(__DIR__ . '/../../emissor_nacional_py/emitir.py');
        if (!$pythonScriptPath) {
            $pythonScriptPath = 'C:/Users/Alan.Borim/Documents/Apoio19/CRM Apoio/crm-apoio19-api/emissor_nacional_py/emitir.py';
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        
        $command = "python " . escapeshellarg($pythonScriptPath) . " " . escapeshellarg($jsonPayload);
        
        // timeout após 120s
        set_time_limit(180);
        
        $output = shell_exec($command . ' 2>&1');
        
        $lines = explode("\n", trim($output));
        $lastLine = end($lines);
        $result = json_decode($lastLine, true);
        
        if (!$result) {
            throw new \Exception("Falha ao comunicar com automação python: " . $output);
        }
        
        if (($result['status'] ?? '') !== 'sucesso') {
            throw new \Exception($result['detail'] ?? "Erro desconhecido na automação do portal.");
        }
        
        return [
            "id" => "rascunho_" . time(),
            "status" => "draft",
            "url" => null,
            "xml" => null,
            "pdf" => null
        ];
    }
}
