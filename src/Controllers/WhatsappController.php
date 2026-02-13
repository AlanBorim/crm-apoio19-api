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
    public function getConversations(array $headers, array $queryParams = []): array
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

            // Build filters
            $filters = ['limit' => 100];

            error_log("=== DEBUG CONVERSAS ===");
            error_log("Query params recebidos (args): " . json_encode($queryParams));
            error_log("Query params globais (_GET): " . json_encode($_GET));

            // Merge query params with $_GET to ensure we catch parameters even if router misses them
            $allParams = array_merge($_GET, $queryParams);

            // Convert internal phone number ID to Meta API phone_number_id
            if (isset($allParams['phone_number_id']) && !empty($allParams['phone_number_id'])) {
                $internalPhoneId = (int)$allParams['phone_number_id'];
                error_log("ID interno recebido: " . $internalPhoneId);

                // Get the Meta API phone_number_id from whatsapp_phone_numbers table
                $db = \Apoio19\Crm\Models\Database::getInstance();
                $stmt = $db->prepare('SELECT id, phone_number_id, name FROM whatsapp_phone_numbers WHERE id = ?');
                $stmt->execute([$internalPhoneId]);
                $phoneData = $stmt->fetch(\PDO::FETCH_ASSOC);

                error_log("Dados do número encontrado: " . json_encode($phoneData));

                if ($phoneData && !empty($phoneData['phone_number_id'])) {
                    $filters['phone_number_id'] = $phoneData['phone_number_id'];
                    error_log("Phone Number ID da Meta aplicado ao filtro: " . $phoneData['phone_number_id']);
                } else {
                    error_log("AVISO: Número não encontrado ou phone_number_id vazio!");
                }
            } else {
                error_log("Nenhum phone_number_id fornecido - mostrando todas as conversas");
            }

            error_log("Filtros finais: " . json_encode($filters));

            $contacts = $whatsappContact->getAll($filters);

            error_log("Total de conversas retornadas: " . count($contacts));
            if (count($contacts) > 0) {
                error_log("Primeira conversa: " . json_encode($contacts[0]));
            }

            // Enrich with last message and unread count
            foreach ($contacts as &$contact) {
                $lastMessage = $whatsappMessage->getLastMessage($contact['id']);
                $contact['last_message'] = $lastMessage;
                $contact['unread_count'] = $whatsappMessage->getUnreadCount($contact['id']);
            }

            return $this->successResponse($contacts, "Conversas obtidas", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Erro ao obter conversas: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
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

            // Convert internal phone number ID to Meta API phone_number_id
            $phoneNumberId = null;
            if (isset($queryParams['phone_number_id']) && !empty($queryParams['phone_number_id'])) {
                $internalPhoneId = (int)$queryParams['phone_number_id'];

                // Get the Meta API phone_number_id from whatsapp_phone_numbers table
                $db = \Apoio19\Crm\Models\Database::getInstance();
                $stmt = $db->prepare('SELECT phone_number_id FROM whatsapp_phone_numbers WHERE id = ?');
                $stmt->execute([$internalPhoneId]);
                $phoneData = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($phoneData && !empty($phoneData['phone_number_id'])) {
                    $phoneNumberId = $phoneData['phone_number_id'];
                }
            }

            $messages = $whatsappMessage->getConversation($contactId, $limit, $offset, $phoneNumberId);

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

    /**
     * Sync phone numbers from Meta API to database
     */
    public function syncPhoneNumbers(array $headers): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'configuracoes', 'edit');

        try {
            // Get phone numbers from Meta API
            $result = $this->whatsappService->getPhoneNumbersFromMeta();

            if (!$result['success']) {
                return $this->errorResponse(500, $result['error'] ?? "Erro ao buscar números", "SYNC_ERROR", $traceId);
            }

            $phoneNumbers = $result['data'] ?? [];
            $db = \Apoio19\Crm\Models\Database::getInstance();
            $synced = [];
            $errors = [];

            foreach ($phoneNumbers as $phoneData) {
                try {
                    $phoneNumberId = $phoneData['id'] ?? null;
                    $displayPhoneNumber = $phoneData['display_phone_number'] ?? null;
                    $verifiedName = $phoneData['verified_name'] ?? 'Desconhecido';
                    $qualityRating = $phoneData['quality_rating'] ?? null;

                    if (!$phoneNumberId || !$displayPhoneNumber) {
                        continue;
                    }

                    // Check if number already exists
                    $stmt = $db->prepare("
                        SELECT id FROM whatsapp_phone_numbers 
                        WHERE phone_number_id = :phone_number_id
                    ");
                    $stmt->execute(['phone_number_id' => $phoneNumberId]);
                    $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

                    // Get current config for access_token
                    $config = \Apoio19\Crm\Models\Whatsapp::getConfig();
                    $accessToken = $config['access_token'] ?? '';
                    $businessAccountId = $config['business_account_id'] ?? '';

                    $metadata = json_encode([
                        'quality_rating' => $qualityRating,
                        'synced_at' => date('Y-m-d H:i:s')
                    ]);

                    if ($existing) {
                        // Update existing
                        $stmt = $db->prepare("
                            UPDATE whatsapp_phone_numbers 
                            SET name = :name,
                                phone_number = :phone_number,
                                metadata = :metadata,
                                updated_at = NOW()
                            WHERE phone_number_id = :phone_number_id
                        ");
                        $stmt->execute([
                            'name' => $verifiedName,
                            'phone_number' => $displayPhoneNumber,
                            'metadata' => $metadata,
                            'phone_number_id' => $phoneNumberId
                        ]);
                        $synced[] = ['id' => $existing['id'], 'action' => 'updated', 'phone_number' => $displayPhoneNumber];
                    } else {
                        // Insert new
                        $stmt = $db->prepare("
                            INSERT INTO whatsapp_phone_numbers 
                            (name, phone_number, phone_number_id, business_account_id, access_token, status, metadata)
                            VALUES (:name, :phone_number, :phone_number_id, :business_account_id, :access_token, 'active', :metadata)
                        ");
                        $stmt->execute([
                            'name' => $verifiedName,
                            'phone_number' => $displayPhoneNumber,
                            'phone_number_id' => $phoneNumberId,
                            'business_account_id' => $businessAccountId,
                            'access_token' => $accessToken,
                            'metadata' => $metadata
                        ]);
                        $synced[] = ['id' => $db->lastInsertId(), 'action' => 'created', 'phone_number' => $displayPhoneNumber];
                    }
                } catch (\Exception $e) {
                    $errors[] = [
                        'phone_number' => $phoneData['display_phone_number'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ];
                }
            }

            // ALSO SYNC TEMPLATES
            $templateResult = $this->whatsappService->syncTemplates();
            $templatesInfo = [];
            if ($templateResult['success']) {
                $templatesInfo = [
                    'templates_synced' => $templateResult['count'] ?? 0,
                    'message' => $templateResult['message'] ?? 'Templates sincronizados'
                ];
            } else {
                $templatesInfo = [
                    'templates_synced' => 0,
                    'error' => $templateResult['error'] ?? 'Erro ao sincronizar templates'
                ];
            }

            return $this->successResponse([
                'synced' => $synced,
                'errors' => $errors,
                'total' => count($phoneNumbers),
                'templates' => $templatesInfo
            ], "Sincronização concluída", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Sync error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro na sincronização", "ERROR", $traceId);
        }
    }

    /**
     * Get stored phone numbers from database
     */
    public function getPhoneNumbers(array $headers): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'configuracoes', 'view');

        try {
            $db = \Apoio19\Crm\Models\Database::getInstance();
            $stmt = $db->query("
                SELECT id, name, phone_number, phone_number_id, business_account_id, 
                       status, daily_limit, current_daily_count, metadata, 
                       created_at, updated_at
                FROM whatsapp_phone_numbers
                ORDER BY created_at DESC
            ");
            $phoneNumbers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Parse metadata JSON
            foreach ($phoneNumbers as &$number) {
                $number['metadata'] = json_decode($number['metadata'] ?? '{}', true);
            }

            return $this->successResponse($phoneNumbers, "Números obtidos", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Get phone numbers error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao buscar números", "ERROR", $traceId);
        }
    }

    /**
     * Get all campaigns
     */
    public function getCampaigns(array $headers, array $queryParams = []): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'whatsapp', 'view');

        try {
            $campaignModel = new \Apoio19\Crm\Models\WhatsappCampaign();

            $filters = [];
            if (isset($queryParams['status'])) {
                $filters['status'] = $queryParams['status'];
            }
            if (isset($queryParams['user_id'])) {
                $filters['user_id'] = $queryParams['user_id'];
            }

            $campaigns = $campaignModel->getAll($filters);

            // Add stats to each campaign
            foreach ($campaigns as &$campaign) {
                $stats = $campaignModel->getStats($campaign['id']);
                $campaign['stats'] = $stats;
            }

            return $this->successResponse($campaigns, "Campanhas obtidas", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Get campaigns error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao buscar campanhas", "ERROR", $traceId);
        }
    }

    /**
     * Get campaign by ID
     */
    public function getCampaign(array $headers, int $campaignId): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'whatsapp', 'view');

        try {
            $campaignModel = new \Apoio19\Crm\Models\WhatsappCampaign();
            $campaign = $campaignModel->findById($campaignId);

            if (!$campaign) {
                return $this->errorResponse(404, "Campanha não encontrada", "NOT_FOUND", $traceId);
            }

            // Add stats
            $campaign['stats'] = $campaignModel->getStats($campaignId);

            return $this->successResponse($campaign, "Campanha obtida", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Get campaign error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao buscar campanha", "ERROR", $traceId);
        }
    }

    /**
     * Create campaign
     */
    public function createCampaign(array $headers, array $requestData): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'whatsapp', 'edit');

        try {
            // Validate required fields
            if (empty($requestData['name'])) {
                return $this->errorResponse(400, "Nome da campanha é obrigatório", "VALIDATION_ERROR", $traceId);
            }

            $campaignModel = new \Apoio19\Crm\Models\WhatsappCampaign();

            $data = [
                'user_id' => $userData->id,
                'name' => $requestData['name'],
                'description' => $requestData['description'] ?? null,
                'phone_number_id' => $requestData['phone_number_id'] ?? null,
                'status' => $requestData['status'] ?? 'draft',
                'scheduled_at' => $requestData['scheduled_at'] ?? null
            ];

            $campaignId = $campaignModel->create($data);
            $campaign = $campaignModel->findById($campaignId);

            return $this->successResponse($campaign, "Campanha criada", 201, $traceId);
        } catch (\Exception $e) {
            error_log("Create campaign error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao criar campanha", "ERROR", $traceId);
        }
    }

    /**
     * Update campaign
     */
    public function updateCampaign(array $headers, int $campaignId, array $requestData): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'whatsapp', 'edit');

        try {
            $campaignModel = new \Apoio19\Crm\Models\WhatsappCampaign();

            // Check if campaign exists
            $campaign = $campaignModel->findById($campaignId);
            if (!$campaign) {
                return $this->errorResponse(404, "Campanha não encontrada", "NOT_FOUND", $traceId);
            }

            $success = $campaignModel->update($campaignId, $requestData);

            if ($success) {
                $updated = $campaignModel->findById($campaignId);
                return $this->successResponse($updated, "Campanha atualizada", 200, $traceId);
            }

            return $this->errorResponse(500, "Erro ao atualizar campanha", "UPDATE_ERROR", $traceId);
        } catch (\Exception $e) {
            error_log("Update campaign error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao atualizar campanha", "ERROR", $traceId);
        }
    }

    /**
     * Delete campaign
     */
    public function deleteCampaign(array $headers, int $campaignId): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'whatsapp', 'edit');

        try {
            $campaignModel = new \Apoio19\Crm\Models\WhatsappCampaign();

            // Check if campaign exists
            $campaign = $campaignModel->findById($campaignId);
            if (!$campaign) {
                return $this->errorResponse(404, "Campanha não encontrada", "NOT_FOUND", $traceId);
            }

            $success = $campaignModel->delete($campaignId);

            if ($success) {
                return $this->successResponse(null, "Campanha deletada", 200, $traceId);
            }

            return $this->errorResponse(500, "Erro ao deletar campanha", "DELETE_ERROR", $traceId);
        } catch (\Exception $e) {
            error_log("Delete campaign error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao deletar campanha", "ERROR", $traceId);
        }
    }

    /**
     * Update campaign status
     */
    public function updateCampaignStatus(array $headers, int $campaignId, array $requestData): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'whatsapp', 'edit');

        try {
            if (empty($requestData['status'])) {
                return $this->errorResponse(400, "Status é obrigatório", "VALIDATION_ERROR", $traceId);
            }

            $campaignModel = new \Apoio19\Crm\Models\WhatsappCampaign();

            // Check if campaign exists
            $campaign = $campaignModel->findById($campaignId);
            if (!$campaign) {
                return $this->errorResponse(404, "Campanha não encontrada", "NOT_FOUND", $traceId);
            }

            $success = $campaignModel->updateStatus($campaignId, $requestData['status']);

            if ($success) {
                $updated = $campaignModel->findById($campaignId);
                return $this->successResponse($updated, "Status atualizado", 200, $traceId);
            }

            return $this->errorResponse(500, "Erro ao atualizar status", "UPDATE_ERROR", $traceId);
        } catch (\Exception $e) {
            error_log("Update campaign status error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao atualizar status", "ERROR", $traceId);
        }
    }

    /**
     * Get campaign messages
     */
    public function getCampaignMessages(array $headers, int $campaignId): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'whatsapp', 'view');

        try {
            $messageModel = new \Apoio19\Crm\Models\WhatsappCampaignMessage();
            $messages = $messageModel->getByCampaignId($campaignId);

            // Parse JSON params if present
            foreach ($messages as &$message) {
                if ($message['template_params']) {
                    $message['template_params'] = json_decode($message['template_params'], true);
                }
            }

            return $this->successResponse($messages, "Mensagens obtidas", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Get campaign messages error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao buscar mensagens", "ERROR", $traceId);
        }
    }

    /**
     * Get campaign contacts summary
     */
    public function getCampaignContacts(array $headers, int $campaignId): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId);
        }

        try {
            $messageModel = new \Apoio19\Crm\Models\WhatsappCampaignMessage();
            $contacts = $messageModel->getCampaignContacts($campaignId);
            return $this->successResponse($contacts, "Contatos da campanha obtidos", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Get campaign contacts error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao buscar contatos da campanha", "ERROR", $traceId);
        }
    }

    /**
     * Create campaign message
     */
    public function createCampaignMessage(array $headers, int $campaignId, array $requestData): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'whatsapp', 'edit');

        try {
            if (empty($requestData['template_id']) || empty($requestData['contact_id'])) {
                return $this->errorResponse(400, "Template e contato são obrigatórios", "VALIDATION_ERROR", $traceId);
            }

            $messageModel = new \Apoio19\Crm\Models\WhatsappCampaignMessage();

            $data = [
                'campaign_id' => $campaignId,
                'template_id' => $requestData['template_id'],
                'contact_id' => $requestData['contact_id'],
                'template_params' => $requestData['template_params'] ?? [],
                'status' => $requestData['status'] ?? 'pending'
            ];

            $messageId = $messageModel->create($data);
            $message = $messageModel->findById($messageId);

            return $this->successResponse($message, "Mensagem criada", 201, $traceId);
        } catch (\Exception $e) {
            error_log("Create campaign message error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao criar mensagem", "ERROR", $traceId);
        }
    }

    /**
     * Update campaign message
     */
    public function updateCampaignMessage(array $headers, int $campaignId, int $messageId, array $requestData): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'whatsapp', 'edit');

        try {
            $messageModel = new \Apoio19\Crm\Models\WhatsappCampaignMessage();

            $message = $messageModel->findById($messageId);
            if (!$message || $message['campaign_id'] != $campaignId) {
                return $this->errorResponse(404, "Mensagem não encontrada", "NOT_FOUND", $traceId);
            }

            $success = $messageModel->update($messageId, $requestData);

            if ($success) {
                $updated = $messageModel->findById($messageId);
                return $this->successResponse($updated, "Mensagem atualizada", 200, $traceId);
            }

            return $this->errorResponse(500, "Erro ao atualizar mensagem", "UPDATE_ERROR", $traceId);
        } catch (\Exception $e) {
            error_log("Update campaign message error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao atualizar mensagem", "ERROR", $traceId);
        }
    }

    /**
     * Delete campaign message
     */
    public function deleteCampaignMessage(array $headers, int $campaignId, int $messageId): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'whatsapp', 'edit');

        try {
            $messageModel = new \Apoio19\Crm\Models\WhatsappCampaignMessage();

            $message = $messageModel->findById($messageId);
            if (!$message || $message['campaign_id'] != $campaignId) {
                return $this->errorResponse(404, "Mensagem não encontrada", "NOT_FOUND", $traceId);
            }

            $success = $messageModel->delete($messageId);

            if ($success) {
                return $this->successResponse(null, "Mensagem deletada", 200, $traceId);
            }

            return $this->errorResponse(500, "Erro ao deletar mensagem", "DELETE_ERROR", $traceId);
        } catch (\Exception $e) {
            error_log("Delete campaign message error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao deletar mensagem", "ERROR", $traceId);
        }
    }

    /**
     * Resend campaign message
     */
    public function resendCampaignMessage(array $headers, int $campaignId, int $messageId): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'whatsapp', 'edit');

        try {
            $messageModel = new \Apoio19\Crm\Models\WhatsappCampaignMessage();

            $message = $messageModel->findById($messageId);
            if (!$message || $message['campaign_id'] != $campaignId) {
                return $this->errorResponse(404, "Mensagem não encontrada", "NOT_FOUND", $traceId);
            }

            // Reset message to pending status for resend
            $success = $messageModel->resetForResend($messageId);

            if ($success) {
                $updated = $messageModel->findById($messageId);
                return $this->successResponse($updated, "Mensagem agendada para reenvio", 200, $traceId);
            }

            return $this->errorResponse(500, "Erro ao agendar reenvio", "RESEND_ERROR", $traceId);
        } catch (\Exception $e) {
            error_log("Resend campaign message error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao agendar reenvio", "ERROR", $traceId);
        }
    }

    /**
     * Get templates
     */
    public function getTemplates(array $headers): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'whatsapp', 'view');

        try {
            $templateModel = new \Apoio19\Crm\Models\WhatsappTemplate();
            $templates = $templateModel->getAll();

            // Parse components JSON if present
            foreach ($templates as &$template) {
                if ($template['components']) {
                    // Only decode if it's a string (not already decoded)
                    if (is_string($template['components'])) {
                        $template['components'] = json_decode($template['components'], true);
                    }
                }
            }

            return $this->successResponse($templates, "Templates obtidos", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Get templates error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao buscar templates", "ERROR", $traceId);
        }
    }
}
