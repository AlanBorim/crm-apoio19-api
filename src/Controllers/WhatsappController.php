<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Middleware\AuthMiddleware;
use Apoio19\Crm\Models\Whatsapp;
use Exception;


use Apoio19\Crm\Services\WhatsappService;

// Placeholder for Request/Response handling
class WhatsappController extends BaseController
{
    private AuthMiddleware $authMiddleware;
    private WhatsappService $whatsappService;

    public function __construct()
    {
        parent::__construct(); // Call BaseController constructor
        $this->authMiddleware = new AuthMiddleware();
        $this->whatsappService = new WhatsappService();
    }

    /**
     * Get WhatsApp configuration
     */
    public function getConfig(array $headers): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId);
        }

        $this->requirePermission($userData, 'configuracoes', 'view');

        try {
            $config = Whatsapp::getConfig();

            if (!$config) {
                return $this->successResponse(null, "Nenhuma configuração encontrada", 200, $traceId);
            }

            return $this->successResponse($config, "Configuração obtida", 200, $traceId);
        } catch (Exception $e) {
            error_log("Get config error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao obter configuração", "DB_ERROR", $traceId);
        }
    }


    /**
     * Verify webhook token
     */
    public function verifyWebhook(array $queryParams)
    {
        // Log para debug
        error_log("Verificação do webhook - Parâmetros recebidos: " . json_encode($queryParams));

        $mode = $queryParams['hub_mode'] ?? null;
        $token = $queryParams['hub_verify_token'] ?? null;
        $challenge = $queryParams['hub_challenge'] ?? null;

        error_log("Mode: $mode, Token recebido: $token, Challenge: $challenge");

        if ($mode && $token && $challenge) {
            try {
                $verifyToken = Whatsapp::getConfig()['webhook_verify_token'];
                error_log("Token configurado: $verifyToken");

                if ($mode === 'subscribe' && $token === $verifyToken) {
                    error_log("Webhook verificado com sucesso!");
                    http_response_code(200);
                    echo $challenge;
                    return;
                } else {
                    error_log("Falha na verificação - Mode: $mode, Token match: " . ($token === $verifyToken ? 'SIM' : 'NÃO'));
                }
            } catch (\Exception $e) {
                error_log("Webhook verify db error: " . $e->getMessage());
            }
        } else {
            error_log("Parâmetros obrigatórios ausentes - Mode: " . ($mode ? 'OK' : 'FALTANDO') .
                ", Token: " . ($token ? 'OK' : 'FALTANDO') .
                ", Challenge: " . ($challenge ? 'OK' : 'FALTANDO'));
        }

        http_response_code(403);
        echo "Forbidden";
    }

    /**
     * Process incoming webhook messages
     */
    public function processWebhook(array $requestData): array
    {
        // Log webhook data
        error_log("Webhook recebido: " . json_encode($requestData));

        try {
            // Process the webhook through the service
            $this->whatsappService->processIncomingWebhook($requestData);

            // Return success (Meta expects 200 OK)
            http_response_code(200);
            return ["success" => true];
        } catch (\Exception $e) {
            error_log("Erro processando webhook: " . $e->getMessage());
            // Still return 200 to prevent retry spam
            http_response_code(200);
            return ["success" => false, "error" => $e->getMessage()];
        }
    }

    /**
     * Get all conversations
     */
    public function getConversations(array $headers): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        try {
            $whatsappContact = new \Apoio19\Crm\Models\WhatsappContact();
            $whatsappMessage = new \Apoio19\Crm\Models\WhatsappChatMessage();

            $contacts = $whatsappContact->getAll(['limit' => 100]);

            // Enrich with last message and unread count
            foreach ($contacts as &$contact) {
                $lastMessage = $whatsappMessage->getLastMessage($contact['id']);
                $contact['last_message'] = $lastMessage;
                $contact['unread_count'] = $whatsappMessage->getUnreadCount($contact['id']);
            }

            return $this->successResponse($contacts, "Conversas obtidas", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Erro ao obter conversas: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao obter conversas", "ERROR", $traceId);
        }
    }

    /**
     * Get messages for a specific contact
     */
    public function getMessages(array $headers, int $contactId, array $queryParams = []): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        try {
            $whatsappMessage = new \Apoio19\Crm\Models\WhatsappChatMessage();

            $limit = (int)($queryParams['limit'] ?? 100);
            $offset = (int)($queryParams['offset'] ?? 0);

            $messages = $whatsappMessage->getConversation($contactId, $limit, $offset);

            // Mark messages as read
            $whatsappMessage->markAsRead($contactId);

            return $this->successResponse($messages, "Mensagens obtidas", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Erro ao obter mensagens: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao obter mensagens", "ERROR", $traceId);
        }
    }

    /**
     * Send a message to a contact
     */
    public function sendMessage(array $headers, array $requestData): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'whatsapp', 'edit');

        try {
            $contactId = (int)($requestData['contact_id'] ?? 0);
            $message = $requestData['message'] ?? '';

            if (!$contactId || !$message) {
                return $this->errorResponse(400, "contact_id e message são obrigatórios", "VALIDATION_ERROR", $traceId);
            }

            // Get contact
            $whatsappContact = new \Apoio19\Crm\Models\WhatsappContact();
            $contact = $whatsappContact->findById($contactId);

            if (!$contact) {
                return $this->errorResponse(404, "Contato não encontrado", "NOT_FOUND", $traceId);
            }

            // Send message via API
            $result = $this->whatsappService->sendTextMessage(
                $contact['phone_number'],
                $message,
                $userData->id,
                $contactId
            );

            if ($result['success']) {
                return $this->successResponse($result, "Mensagem enviada", 200, $traceId);
            } else {
                return $this->errorResponse(500, $result['error'] ?? "Erro ao enviar mensagem", "SEND_ERROR", $traceId);
            }
        } catch (\Exception $e) {
            error_log("Erro ao enviar mensagem: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao enviar mensagem", "ERROR", $traceId);
        }
    }
    /**
     * Process individual message
     */
    private function processMessage($messageData)
    {
        try {
            // Aqui você implementa o processamento das mensagens
            error_log("Mensagem processada: " . json_encode($messageData));

            // Salvar mensagem processada se necessário
            $filename = 'hook/message_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.json';
            file_put_contents($filename, json_encode($messageData, JSON_PRETTY_PRINT), LOCK_EX);
        } catch (\Exception $e) {
            error_log("Erro ao processar mensagem: " . $e->getMessage());
        }
    }

    /**
     * Test WhatsApp connection
     */
    public function testConnection(array $headers): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'configuracoes', 'view');

        try {
            $result = $this->whatsappService->testConnection();
            return $this->successResponse($result, "Teste realizado", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Test error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro no teste", "ERROR", $traceId);
        }
    }

    // /**
    //  * Send a WhatsApp message to a lead or contact.
    //  *
    //  * @param array $headers Request headers.
    //  * @param array $requestData Expected keys: target_type ("lead" or "contact"), target_id, message
    //  * @return array JSON response.
    //  */
    // public function sendMessage(array $headers, array $requestData): array
    // {
    //     $userData = $this->authMiddleware->handle($headers, ["comercial", "admin"]); // Or specific roles allowed to send
    //     if (!$userData) {
    //         http_response_code(401);
    //         return ["error" => "Autenticação do CRM necessária."];
    //     }

    //     // Validate input
    //     $targetType = $requestData["target_type"] ?? null;
    //     $targetId = isset($requestData["target_id"]) ? (int)$requestData["target_id"] : null;
    //     $message = $requestData["message"] ?? null;

    //     if (!$targetType || !$targetId || !$message || !in_array($targetType, ["lead", "contact"])) {
    //         http_response_code(400);
    //         return ["error" => "Dados inválidos. Forneça target_type ('lead' ou 'contact'), target_id e message."];
    //     }

    //     $phoneNumber = null;
    //     $leadId = null;
    //     $contactId = null;

    //     // Get phone number based on target type
    //     if ($targetType === "lead") {
    //         $lead = Lead::findById($targetId);
    //         if (!$lead || empty($lead->telefone)) {
    //             http_response_code(404);
    //             return ["error" => "Lead não encontrado ou sem número de telefone cadastrado."];
    //         }
    //         $phoneNumber = $lead->telefone;
    //         $leadId = $targetId;
    //     } else { // targetType === "contact"
    //         $contact = Contact::findById($targetId);
    //         if (!$contact || empty($contact->telefone)) {
    //             http_response_code(404);
    //             return ["error" => "Contato não encontrado ou sem número de telefone cadastrado."];
    //         }
    //         $phoneNumber = $contact->telefone;
    //         $contactId = $targetId;
    //     }

    //     // Format phone number if necessary for ZDG API (e.g., add @c.us)
    //     // This depends heavily on how the ZDG API expects the number.
    //     // Assuming it needs the format 55119XXXXXXXX@c.us
    //     $formattedPhoneNumber = preg_replace("/[^0-9]/", "", $phoneNumber); // Remove non-digits
    //     if (strlen($formattedPhoneNumber) >= 10) { // Basic check for BR numbers
    //         if (strlen($formattedPhoneNumber) == 10) { // Add 9 for mobile without it
    //             $formattedPhoneNumber = substr($formattedPhoneNumber, 0, 2) . '9' . substr($formattedPhoneNumber, 2);
    //         }
    //         if (substr($formattedPhoneNumber, 0, 2) !== '55') { // Add country code if missing
    //             $formattedPhoneNumber = '55' . $formattedPhoneNumber;
    //         }
    //         $formattedPhoneNumber .= "@c.us";
    //     } else {
    //         http_response_code(400);
    //         return ["error" => "Número de telefone inválido ou não formatado corretamente para WhatsApp: {$phoneNumber}"];
    //     }


    //     $result = $this->whatsappService->sendMessage(
    //         $formattedPhoneNumber,
    //         $message,
    //         $userData->id,
    //         $leadId,
    //         $contactId
    //     );

    //     if ($result["success"]) {
    //         http_response_code(200);
    //         return ["message" => $result["message"], "external_id" => $result["external_id"]];
    //     } else {
    //         http_response_code(500); // Or specific error code based on result
    //         return ["error" => $result["message"]];
    //     }
    // }

    // /**
    //  * Get WhatsApp message history for a specific lead or contact.
    //  *
    //  * @param array $headers Request headers.
    //  * @param array $queryParams Expected keys: target_type ("lead" or "contact"), target_id
    //  * @return array JSON response.
    //  */
    // public function getHistory(array $headers, array $queryParams): array
    // {
    //     $userData = $this->authMiddleware->handle($headers);
    //     if (!$userData) {
    //         http_response_code(401);
    //         return ["error" => "Autenticação do CRM necessária."];
    //     }

    //     $targetType = $queryParams["target_type"] ?? null;
    //     $targetId = isset($queryParams["target_id"]) ? (int)$queryParams["target_id"] : null;

    //     if (!$targetType || !$targetId || !in_array($targetType, ["lead", "contact"])) {
    //         http_response_code(400);
    //         return ["error" => "Dados inválidos. Forneça target_type ('lead' ou 'contact') e target_id."];
    //     }

    //     $sql = "SELECT wh.*, u.nome as usuario_nome 
    //             FROM whatsapp_historico wh
    //             LEFT JOIN usuarios u ON wh.usuario_id = u.id 
    //             WHERE ";
    //     $params = [];

    //     if ($targetType === "lead") {
    //         $sql .= "wh.lead_id = :target_id";
    //         $params[":target_id"] = $targetId;
    //     } else { // targetType === "contact"
    //         $sql .= "wh.contato_id = :target_id";
    //         $params[":target_id"] = $targetId;
    //     }

    //     $sql .= " ORDER BY wh.data_registro DESC";
    //     // Add pagination later if needed

    //     try {
    //         $pdo = Database::getInstance();
    //         $stmt = $pdo->prepare($sql);
    //         $stmt->execute($params);
    //         $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    //         http_response_code(200);
    //         return ["data" => $history];
    //     } catch (PDOException $e) {
    //         error_log("Erro ao buscar histórico do WhatsApp para {$targetType} ID {$targetId}: " . $e->getMessage());
    //         http_response_code(500);
    //         return ["error" => "Erro interno ao buscar histórico do WhatsApp."];
    //     }
    // }



    // /**
    //  * Save WhatsApp configuration
    //  */
    // public function saveConfig(array $headers, array $requestData): array
    // {
    //     $traceId = bin2hex(random_bytes(8));
    //     $userData = $this->authMiddleware->handle($headers);

    //     if (!$userData) {
    //         return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId);
    //     }

    //     $this->requirePermission($userData, 'configuracoes', 'edit');

    //     try {
    //         $pdo = Database::getInstance();

    //         // Check if exists
    //         $stmt = $pdo->query('SELECT id FROM whatsapp_phone_numbers WHERE status = "active" LIMIT 1');
    //         $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    //         if ($existing) {
    //             // Update
    //             $stmt = $pdo->prepare('
    //                 UPDATE whatsapp_phone_numbers
    //                 SET name = ?, phone_number = ?, phone_number_id = ?,
    //                     business_account_id = ?, webhook_verify_token = ?,
    //                     updated_at = NOW()
    //                 WHERE id = ?
    //             ');
    //             $stmt->execute([
    //                 $requestData['nome'] ?? '',
    //                 $requestData['numero'] ?? '',
    //                 $requestData['phoneNumberId'] ?? '',
    //                 $requestData['businessAccountId'] ?? '',
    //                 $requestData['webhookVerifyToken'] ?? '',
    //                 $existing['id']
    //             ]);
    //         } else {
    //             // Insert
    //             $stmt = $pdo->prepare('
    //                 INSERT INTO whatsapp_phone_numbers
    //                 (name, phone_number, phone_number_id, business_account_id, webhook_verify_token, status, created_at, updated_at)
    //                 VALUES (?, ?, ?, ?, ?, "active", NOW(), NOW())
    //             ');
    //             $stmt->execute([
    //                 $requestData['nome'] ?? '',
    //                 $requestData['numero'] ?? '',
    //                 $requestData['phoneNumberId'] ?? '',
    //                 $requestData['businessAccountId'] ?? '',
    //                 $requestData['webhookVerifyToken'] ?? ''
    //             ]);
    //         }

    //         return $this->successResponse(null, "Configuração salva com sucesso", 200, $traceId);
    //     } catch (\PDOException $e) {
    //         error_log("Save config error: " . $e->getMessage());
    //         return $this->errorResponse(500, "Erro ao salvar configuração", "DB_ERROR", $traceId);
    //     }
    // }


    // /**
    //  * Send test message
    //  */
    // public function sendTestMessage(array $headers, array $requestData): array
    // {
    //     $traceId = bin2hex(random_bytes(8));
    //     $userData = $this->authMiddleware->handle($headers);

    //     if (!$userData) {
    //         return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId);
    //     }

    //     $this->requirePermission($userData, 'configuracoes', 'edit');

    //     $number = $requestData['number'] ?? null;
    //     $message = $requestData['message'] ?? null;

    //     if (!$number || !$message) {
    //         return $this->errorResponse(400, "Número e mensagem obrigatórios", "VALIDATION_ERROR", $traceId);
    //     }

    //     try {
    //         $result = $this->whatsappService->sendTextMessage($number, $message);
    //         return $this->successResponse($result, "Mensagem enviada", 200, $traceId);
    //     } catch (\Exception $e) {
    //         error_log("Send test error: " . $e->getMessage());
    //         return $this->errorResponse(500, "Erro ao enviar", "ERROR", $traceId);
    //     }
    // }

    // TODO: Implement webhook endpoint to receive incoming messages and status updates from ZDG API
    // This would require a public endpoint in the CRM accessible by the ZDG API server.
    // public function handleWebhook(array $request Data): array { ... }
}
