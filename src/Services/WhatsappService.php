<?php

namespace Apoio19\Crm\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;
use \Exception;

class WhatsappService
{
    private Client $httpClient;
    private string $apiUrl;
    private ?string $apiKey;

    public function __construct()
    {
        // Load environment variables (simple approach)
        $envPath = __DIR__ . '/../../.env';
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                list($name, $value) = explode('=', $line, 2);
                $_ENV[trim($name)] = trim($value);
            }
        }

        $apiUrl = $_ENV['WHATSAPP_API_URL'] ?? '';
        $apiKey = $_ENV['WHATSAPP_API_KEY'] ?? null;

        // Sanitize values to remove quotes and whitespace that might be left by manual parsing
        $this->apiUrl = trim($apiUrl, " \t\n\r\0\x0B\"'");
        if ($apiKey) {
            $this->apiKey = trim($apiKey, " \t\n\r\0\x0B\"'");
        } else {
            $this->apiKey = null;
        }

        if (empty($this->apiUrl)) {
            // Log or handle the error appropriately - API cannot function without URL
            error_log("URL da API do WhatsApp (WHATSAPP_API_URL) não configurada no .env");
            // Potentially throw an exception or set a state indicating misconfiguration
        }

        $this->httpClient = new Client([
            'base_uri' => $this->apiUrl,
            'timeout'  => 10.0, // Request timeout
        ]);
    }

    /**
     * Sends a text message using the configured WhatsApp API (ZDG).
     *
     * @param string $phoneNumber Target phone number (e.g., 55119XXXXXXXX@c.us).
     * @param string $message Text message content.
     * @param int|null $crmUserId ID of the CRM user sending the message.
     * @param int|null $leadId Associated Lead ID.
     * @param int|null $contactId Associated Contact ID.
     * @return array ['success' => bool, 'message' => string, 'external_id' => string|null]
     */
    public function sendMessage(string $phoneNumber, string $message, ?int $crmUserId = null, ?int $leadId = null, ?int $contactId = null): array
    {
        if (empty($this->apiUrl)) {
            return ['success' => false, 'message' => 'API URL não configurada.', 'external_id' => null];
        }

        // Adapt the endpoint and payload based on the ZDG API documentation
        // This is a *guess* based on common patterns for whatsapp-web.js APIs
        $endpoint = '/send-message'; // Example endpoint
        $payload = [
            'number' => $phoneNumber,
            'message' => $message
        ];
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if ($this->apiKey) {
            // Add API key header if configured (e.g., 'Authorization: Bearer YOUR_KEY' or 'X-API-Key: YOUR_KEY')
            // Adjust header name based on ZDG API requirements
            $headers['X-API-Key'] = $this->apiKey;
        }

        try {
            $response = $this->httpClient->post($endpoint, [
                'headers' => $headers,
                'json' => $payload
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            if ($statusCode >= 200 && $statusCode < 300 && ($body['success'] ?? true)) { // Check ZDG API success indicator
                $externalId = $body['id'] ?? $body['messageId'] ?? null; // Get the message ID from ZDG API response

                // Save to history
                $this->saveHistoryRecord([
                    'mensagem_id_externo' => $externalId,
                    'lead_id' => $leadId,
                    'contato_id' => $contactId,
                    'usuario_id' => $crmUserId,
                    'numero_destino' => $phoneNumber,
                    'mensagem' => $message,
                    'tipo' => 'enviada',
                    'status_envio' => 'sent', // Initial status, might be updated by webhook
                ]);

                return ['success' => true, 'message' => 'Mensagem enviada com sucesso.', 'external_id' => $externalId];
            } else {
                $errorMessage = $body['error'] ?? $body['message'] ?? 'Erro desconhecido da API ZDG.';
                $this->saveHistoryRecord([
                    'lead_id' => $leadId,
                    'contato_id' => $contactId,
                    'usuario_id' => $crmUserId,
                    'numero_destino' => $phoneNumber,
                    'mensagem' => $message,
                    'tipo' => 'erro',
                    'status_envio' => 'failed',
                    'detalhes_erro' => "API ZDG Error: {$statusCode} - {$errorMessage}" // Add error details if table supports it
                ]);
                return ['success' => false, 'message' => "Falha ao enviar mensagem via API ZDG: {$errorMessage}", 'external_id' => null];
            }
        } catch (RequestException $e) {
            $errorMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $errorMessage .= " | Response: " . $e->getResponse()->getBody()->getContents();
            }
            error_log("Erro de requisição ao enviar mensagem WhatsApp: " . $errorMessage);
            $this->saveHistoryRecord([
                'lead_id' => $leadId,
                'contato_id' => $contactId,
                'usuario_id' => $crmUserId,
                'numero_destino' => $phoneNumber,
                'mensagem' => $message,
                'tipo' => 'erro',
                'status_envio' => 'failed',
                'detalhes_erro' => "Request Error: {$e->getMessage()}" // Add error details if table supports it
            ]);
            return ['success' => false, 'message' => "Erro de comunicação com a API ZDG: {$e->getMessage()}", 'external_id' => null];
        } catch (Exception $e) {
            error_log("Erro inesperado ao enviar mensagem WhatsApp: " . $e->getMessage());
            $this->saveHistoryRecord([
                'lead_id' => $leadId,
                'contato_id' => $contactId,
                'usuario_id' => $crmUserId,
                'numero_destino' => $phoneNumber,
                'mensagem' => $message,
                'tipo' => 'erro',
                'status_envio' => 'failed',
                'detalhes_erro' => "General Error: {$e->getMessage()}" // Add error details if table supports it
            ]);
            return ['success' => false, 'message' => "Erro inesperado: {$e->getMessage()}", 'external_id' => null];
        }
    }

    /**
     * Saves a WhatsApp message interaction to the database history.
     *
     * @param array $data Associative array matching columns in `whatsapp_historico`.
     * @return bool Success status.
     */
    public function saveHistoryRecord(array $data): bool
    {
        // Basic validation
        if (empty($data['numero_destino']) || empty($data['mensagem']) || empty($data['tipo'])) {
            error_log("Tentativa de salvar registro de histórico do WhatsApp com dados incompletos.");
            return false;
        }

        $sql = "INSERT INTO whatsapp_historico 
                    (mensagem_id_externo, lead_id, contato_id, usuario_id, numero_destino, numero_origem, mensagem, tipo, status_envio, timestamp_api) 
                VALUES 
                    (:mensagem_id_externo, :lead_id, :contato_id, :usuario_id, :numero_destino, :numero_origem, :mensagem, :tipo, :status_envio, :timestamp_api)";

        // Add handling for error details if the column exists
        // if (isset($data['detalhes_erro'])) { ... }

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);

            $stmt->bindValue(":mensagem_id_externo", $data['mensagem_id_externo'] ?? null);
            $stmt->bindValue(":lead_id", $data['lead_id'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":contato_id", $data['contato_id'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":usuario_id", $data['usuario_id'] ?? null, PDO::PARAM_INT);
            $stmt->bindParam(":numero_destino", $data['numero_destino']);
            $stmt->bindValue(":numero_origem", $data['numero_origem'] ?? null);
            $stmt->bindParam(":mensagem", $data['mensagem']);
            $stmt->bindParam(":tipo", $data['tipo']);
            $stmt->bindValue(":status_envio", $data['status_envio'] ?? null);
            $stmt->bindValue(":timestamp_api", $data['timestamp_api'] ?? null, PDO::PARAM_INT);
            // Bind error details if applicable

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao salvar histórico do WhatsApp no banco de dados: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Updates the status of a previously sent message based on webhook data.
     *
     * @param string $externalId The external message ID provided by the API.
     * @param string $status The new status (e.g., delivered, read, failed).
     * @return bool Success status.
     */
    public function updateMessageStatus(string $externalId, string $status): bool
    {
        $sql = "UPDATE whatsapp_historico SET status_envio = :status, atualizado_em = NOW() 
                 WHERE mensagem_id_externo = :external_id AND tipo = 'enviada'";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":status", $status);
            $stmt->bindParam(":external_id", $externalId);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao atualizar status da mensagem WhatsApp (ID Externo: {$externalId}): " . $e->getMessage());
            return false;
        }
    }

    // TODO: Add methods for sending media if required and supported by ZDG API.
    // TODO: Add method to check API connection status if ZDG API provides an endpoint for it.

    /**
     * Test the connection to the WhatsApp API.
     * Returns an associative array indicating success and optional data.
     */
    public function testConnection(): array
    {
        if (empty($this->apiUrl)) {
            return ['connected' => false, 'error' => 'API URL not configured'];
        }

        $headers = [];
        $endpoint = '/status'; // Default ZDG endpoint

        // Detect Meta/Facebook URL
        $isMeta = strpos($this->apiUrl, 'facebook.com') !== false;

        if ($this->apiKey) {
            if ($isMeta) {
                // Meta Graph API requires Bearer Token
                $headers['Authorization'] = 'Bearer ' . $this->apiKey;
                // Meta endpoint to verify token identity
                $endpoint = '/me?fields=id,name';
            } else {
                // Default/ZDG usually uses X-API-Key or similar
                $headers['X-API-Key'] = $this->apiKey;
            }
        }

        try {
            $response = $this->httpClient->get($endpoint, [
                'headers' => $headers
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            $connected = $statusCode === 200;

            // Extra verification for Meta
            if ($isMeta && $connected) {
                $connected = isset($body['id']);
            }

            return ['connected' => $connected, 'data' => $body];
        } catch (RequestException $e) {
            $msg = $e->getMessage();
            if ($e->hasResponse()) {
                $msg .= ' | Response: ' . $e->getResponse()->getBody()->getContents();
            }
            return ['connected' => false, 'error' => $msg];
        } catch (Exception $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }
}
