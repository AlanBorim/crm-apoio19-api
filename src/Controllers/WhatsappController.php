<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Services\WhatsappService;
use Apoio19\Crm\Middleware\AuthMiddleware;
use Apoio19\Crm\Models\Lead;
use Apoio19\Crm\Models\Contact;
use Apoio19\Crm\Models\Database; // For history retrieval
use \PDO;

// Placeholder for Request/Response handling
class WhatsappController
{
    private AuthMiddleware $authMiddleware;
    private WhatsappService $whatsappService;

    public function __construct()
    {
        $this->authMiddleware = new AuthMiddleware();
        $this->whatsappService = new WhatsappService();
    }

    /**
     * Send a WhatsApp message to a lead or contact.
     *
     * @param array $headers Request headers.
     * @param array $requestData Expected keys: target_type ("lead" or "contact"), target_id, message
     * @return array JSON response.
     */
    public function sendMessage(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Comercial", "Admin"]); // Or specific roles allowed to send
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        // Validate input
        $targetType = $requestData["target_type"] ?? null;
        $targetId = isset($requestData["target_id"]) ? (int)$requestData["target_id"] : null;
        $message = $requestData["message"] ?? null;

        if (!$targetType || !$targetId || !$message || !in_array($targetType, ["lead", "contact"])) {
            http_response_code(400);
            return ["error" => "Dados inválidos. Forneça target_type ('lead' ou 'contact'), target_id e message."];
        }

        $phoneNumber = null;
        $leadId = null;
        $contactId = null;

        // Get phone number based on target type
        if ($targetType === "lead") {
            $lead = Lead::findById($targetId);
            if (!$lead || empty($lead->telefone)) {
                http_response_code(404);
                return ["error" => "Lead não encontrado ou sem número de telefone cadastrado."];
            }
            $phoneNumber = $lead->telefone;
            $leadId = $targetId;
        } else { // targetType === "contact"
            $contact = Contact::findById($targetId);
            if (!$contact || empty($contact->telefone)) {
                http_response_code(404);
                return ["error" => "Contato não encontrado ou sem número de telefone cadastrado."];
            }
            $phoneNumber = $contact->telefone;
            $contactId = $targetId;
        }
        
        // Format phone number if necessary for ZDG API (e.g., add @c.us)
        // This depends heavily on how the ZDG API expects the number.
        // Assuming it needs the format 55119XXXXXXXX@c.us
        $formattedPhoneNumber = preg_replace("/[^0-9]/", "", $phoneNumber); // Remove non-digits
        if (strlen($formattedPhoneNumber) >= 10) { // Basic check for BR numbers
             if (strlen($formattedPhoneNumber) == 10) { // Add 9 for mobile without it
                 $formattedPhoneNumber = substr($formattedPhoneNumber, 0, 2) . '9' . substr($formattedPhoneNumber, 2);
             }
             if (substr($formattedPhoneNumber, 0, 2) !== '55') { // Add country code if missing
                 $formattedPhoneNumber = '55' . $formattedPhoneNumber;
             }
             $formattedPhoneNumber .= "@c.us";
        } else {
             http_response_code(400);
             return ["error" => "Número de telefone inválido ou não formatado corretamente para WhatsApp: {$phoneNumber}"];
        }
        

        $result = $this->whatsappService->sendMessage(
            $formattedPhoneNumber, 
            $message, 
            $userData->userId, 
            $leadId, 
            $contactId
        );

        if ($result["success"]) {
            http_response_code(200);
            return ["message" => $result["message"], "external_id" => $result["external_id"]];
        } else {
            http_response_code(500); // Or specific error code based on result
            return ["error" => $result["message"]];
        }
    }

    /**
     * Get WhatsApp message history for a specific lead or contact.
     *
     * @param array $headers Request headers.
     * @param array $queryParams Expected keys: target_type ("lead" or "contact"), target_id
     * @return array JSON response.
     */
    public function getHistory(array $headers, array $queryParams): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        $targetType = $queryParams["target_type"] ?? null;
        $targetId = isset($queryParams["target_id"]) ? (int)$queryParams["target_id"] : null;

        if (!$targetType || !$targetId || !in_array($targetType, ["lead", "contact"])) {
            http_response_code(400);
            return ["error" => "Dados inválidos. Forneça target_type ('lead' ou 'contact') e target_id."];
        }

        $sql = "SELECT wh.*, u.nome as usuario_nome 
                FROM whatsapp_historico wh
                LEFT JOIN usuarios u ON wh.usuario_id = u.id 
                WHERE ";
        $params = [];

        if ($targetType === "lead") {
            $sql .= "wh.lead_id = :target_id";
            $params[":target_id"] = $targetId;
        } else { // targetType === "contact"
            $sql .= "wh.contato_id = :target_id";
            $params[":target_id"] = $targetId;
        }

        $sql .= " ORDER BY wh.data_registro DESC";
        // Add pagination later if needed

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            http_response_code(200);
            return ["data" => $history];

        } catch (PDOException $e) {
            error_log("Erro ao buscar histórico do WhatsApp para {$targetType} ID {$targetId}: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao buscar histórico do WhatsApp."];
        }
    }

    // TODO: Implement webhook endpoint to receive incoming messages and status updates from ZDG API
    // This would require a public endpoint in the CRM accessible by the ZDG API server.
    // public function handleWebhook(array $requestData): array { ... }
}

