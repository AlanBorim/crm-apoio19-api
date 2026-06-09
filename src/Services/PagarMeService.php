<?php

namespace Apoio19\Crm\Services;

use Apoio19\Crm\Models\SystemConfig;
use Exception;

class PagarMeService
{
    private string $baseUrl = 'https://api.pagar.me/core/v5';
    private ?string $secretKey;

    public function __construct(bool $useTestKey = false)
    {
        $this->secretKey = $useTestKey 
            ? ($_ENV['PAGARME_TEST_API_KEY'] ?? null)
            : ($_ENV['PAGARME_API_KEY'] ?? null);

        // Fallback for backwards compatibility if not in .env
        if (!$this->secretKey) {
            $config = SystemConfig::get('pagarme_secret_key');
            $this->secretKey = $config['config_value'] ?? null;
        }
    }

    /**
     * Define a chave secret dinamicamente (útil para testes).
     */
    public function setSecretKey(string $key): void
    {
        $this->secretKey = $key;
    }

    /**
     * Testa a conexão fazendo uma listagem simples de clientes.
     */
    public function testConnection(): bool
    {
        try {
            $this->request('GET', '/customers?page=1&size=1');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Faz requisição para a API da Pagar.me V5.
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->secretKey) {
            throw new Exception("Chave secreta da Pagar.me não configurada.");
        }

        $url = $this->baseUrl . $endpoint;
        $ch = curl_init();

        $headers = [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($this->secretKey . ':')
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Erro de conexão com Pagar.me: $error");
        }

        $decodedResponse = json_decode($response, true) ?? [];

        if ($httpCode >= 400) {
            $msg = $decodedResponse['message'] ?? 'Erro desconhecido na API Pagar.me';
            throw new Exception("Pagar.me API Error ($httpCode): " . $msg . " - " . json_encode($decodedResponse));
        }

        return $decodedResponse;
    }

    /**
     * Cria um cliente no Pagar.me
     */
    public function createCustomer(string $name, string $email, string $document, string $type = 'company'): array
    {
        // format document (only numbers)
        $document = preg_replace('/[^0-9]/', '', $document);
        $type = strlen($document) > 11 ? 'company' : 'individual';

        $data = [
            'name' => $name,
            'email' => $email,
            'document' => $document,
            'type' => $type
        ];

        return $this->request('POST', '/customers', $data);
    }

    /**
     * Cria uma assinatura (recorrência)
     */
    public function createSubscription(string $customerId, float $amount, string $planId = null, string $paymentMethod = 'boleto', string $dueDate = null): array
    {
        $amountInCents = (int)round($amount * 100);

        $data = [
            'customer_id' => $customerId,
            'payment_method' => $paymentMethod,
            'currency' => 'BRL',
            'interval' => 'month',
            'interval_count' => 1,
            'billing_type' => 'prepaid',
            'installments' => 1,
            'items' => [
                [
                    'name' => 'Pagamento recorrente',
                    'description' => 'Pagamento recorrente',
                    'quantity' => 1,
                    'pricing_scheme' => [
                        'scheme_type' => 'unit',
                        'price' => $amountInCents
                    ]
                ]
            ]
        ];

        if ($dueDate) {
            $data['start_at'] = date('Y-m-d\T00:00:00\Z', strtotime($dueDate));
        }

        return $this->request('POST', '/subscriptions', $data);
    }

    /**
     * Gera um pedido avulso de boleto (Invoice / Order)
     */
    public function createBoletoOrder(array $clientData, float $amount, string $description, string $dueDate = null): array
    {
        $amountInCents = (int)round($amount * 100);
        $dueAt = $dueDate ? date('Y-m-d\T23:59:59\Z', strtotime($dueDate)) : date('Y-m-d\T23:59:59\Z', strtotime("+3 days"));

        $document = preg_replace('/[^0-9]/', '', $clientData['document'] ?? '');
        $type = strlen($document) > 11 ? 'company' : 'individual';

        // Telefone padronizado (Pagar.me V5 exige DDD e Número separados)
        $phoneStr = preg_replace('/[^0-9]/', '', $clientData['phone'] ?? '11999999999');
        if (strlen($phoneStr) >= 10) {
            $countryCode = '55';
            $areaCode = substr($phoneStr, 0, 2);
            $number = substr($phoneStr, 2);
        } else {
            $countryCode = '55';
            $areaCode = '11';
            $number = '999999999';
        }

        $address = [
            'country' => 'BR',
            'state' => $clientData['state'] ?? 'SP',
            'city' => $clientData['city'] ?? 'São Paulo',
            'zip_code' => preg_replace('/[^0-9]/', '', $clientData['zip_code'] ?? '01000000'),
            'line_1' => ($clientData['address_number'] ?? 'S/N') . ', ' . ($clientData['address'] ?? 'Rua Centro') . ', ' . ($clientData['neighborhood'] ?? 'Centro')
        ];

        $data = [
            'items' => [
                [
                    'amount' => $amountInCents,
                    'description' => $description,
                    'quantity' => 1,
                    'code' => 'item_1'
                ]
            ],
            'customer' => [
                'name' => $clientData['name'] ?? 'Cliente sem nome',
                'email' => $clientData['email'] ?? 'email@dominio.com',
                'type' => $type,
                'document' => $document ?: '00000000000',
                'phones' => [
                    'mobile_phone' => [
                        'country_code' => $countryCode,
                        'area_code' => $areaCode,
                        'number' => $number
                    ]
                ],
                'address' => $address
            ],
            'payments' => [
                [
                    'payment_method' => 'boleto',
                    'boleto' => [
                        'instructions' => 'Pagável em qualquer banco até o vencimento.',
                        'due_at' => $dueAt,
                        'document_number' => $document ?: '00000000000'
                    ]
                ]
            ]
        ];

        return $this->request('POST', '/orders', $data);
    }

    /**
     * Busca uma assinatura no Pagar.me
     */
    public function getSubscription(string $subscriptionId): array
    {
        return $this->request('GET', "/subscriptions/{$subscriptionId}");
    }

    /**
     * Busca um pedido no Pagar.me
     */
    public function getOrder(string $orderId): array
    {
        return $this->request('GET', "/orders/{$orderId}");
    }

    /**
     * Cria uma transferência (Saque) do saldo do recebedor
     */
    public function createTransfer(int $amount, string $recipientId): array
    {
        $amountInCents = (int)round($amount * 100);
        
        $data = [
            'amount' => $amountInCents
        ];

        return $this->request('POST', "/recipients/{$recipientId}/transfers", $data);
    }

    /**
     * Verifica assinatura do Webhook recebido
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        $config = SystemConfig::get('pagarme_webhook_secret');
        $webhookSecret = $config['config_value'] ?? null;

        if (!$webhookSecret) {
            return false;
        }

        // Pagar.me signature is constructed as: sha1=<hash> (v4) or simply hash depending on version.
        // For V5, it's typically HMAC SHA256.
        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);
        
        // As Pagar.me V5 might send 'sha256=....' in the header:
        $signatureParts = explode('=', $signatureHeader);
        $providedSignature = count($signatureParts) > 1 ? $signatureParts[1] : $signatureHeader;

        return hash_equals($expectedSignature, $providedSignature);
    }
}
