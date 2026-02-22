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
            $contactId    = (int)($requestData['contact_id'] ?? 0);
            $message      = $requestData['message'] ?? '';
            // phone_number_id: ID da Meta do número que está sendo usado na conversa
            // Enviado pelo frontend ao selecionar o número no seletor
            $phoneNumberId = $requestData['phone_number_id'] ?? null;

            if (!$contactId || !$message) {
                return $this->errorResponse(400, "contact_id e message são obrigatórios", "VALIDATION_ERROR", $traceId);
            }

            // Get contact
            $whatsappContact = new \Apoio19\Crm\Models\WhatsappContact();
            $contact = $whatsappContact->findById($contactId);

            if (!$contact) {
                return $this->errorResponse(404, "Contato não encontrado", "NOT_FOUND", $traceId);
            }

            // Send message via API (phone_number_id garante envio pelo número correto)
            $result = $this->whatsappService->sendTextMessage(
                $contact['phone_number'],
                $message,
                $userData->id,
                $contactId,
                $phoneNumberId
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
            $activePhoneIds = [];

            foreach ($phoneNumbers as $phoneData) {
                try {
                    $phoneNumberId = $phoneData['id'] ?? null;
                    $displayPhoneNumber = $phoneData['display_phone_number'] ?? null;
                    $verifiedName = $phoneData['verified_name'] ?? 'Desconhecido';
                    $qualityRating = $phoneData['quality_rating'] ?? null;

                    if (!$phoneNumberId || !$displayPhoneNumber) {
                        continue;
                    }

                    $activePhoneIds[] = $phoneNumberId;

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

            // Marcar como inativos os números que não vieram na listagem da Meta
            if (!empty($activePhoneIds)) {
                $placeholders = implode(',', array_fill(0, count($activePhoneIds), '?'));
                $sql = "UPDATE whatsapp_phone_numbers SET status = 'inactive' WHERE phone_number_id NOT IN ($placeholders)";
                $stmt = $db->prepare($sql);
                $stmt->execute($activePhoneIds);
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
     * Send a test template message
     */
    public function sendTestMessage(array $headers, array $requestData): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'whatsapp', 'edit');

        try {
            $phoneNumber = $requestData['phone_number'] ?? '';
            $templateName = $requestData['template_name'] ?? '';
            $language = $requestData['language'] ?? 'pt_BR';

            if (empty($phoneNumber) || empty($templateName)) {
                return $this->errorResponse(400, "phone_number e template_name são obrigatórios", "VALIDATION_ERROR", $traceId);
            }

            // Remove non-numeric characters from phone number
            $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

            // Fetch the phone number ID from the request or fallback to config
            $phoneNumberId = $requestData['phone_number_id'] ?? null;

            if (!$phoneNumberId) {
                $config = \Apoio19\Crm\Models\Whatsapp::getConfig();
                $phoneNumberId = $config['phone_number_id'] ?? null;
            }

            if (!$phoneNumberId) {
                return $this->errorResponse(500, "WhatsApp não está configurado ou número não fornecido", "CONFIG_ERROR", $traceId);
            }

            // Send template via Service
            $result = $this->whatsappService->sendTemplateMessage(
                $phoneNumber,
                $templateName,
                $language,
                [], // No components for basic test
                $userData->id,
                $phoneNumberId
            );

            if ($result['success']) {
                return $this->successResponse($result, "Mensagem de teste enviada", 200, $traceId);
            } else {
                return $this->errorResponse(500, $result['error'] ?? "Erro ao enviar mensagem de teste", "SEND_ERROR", $traceId);
            }
        } catch (\Exception $e) {
            error_log("Erro ao enviar mensagem de teste: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao enviar mensagem", "ERROR", $traceId);
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

    /**
     * Get all raw contacts for selection
     */
    public function getContacts(array $headers, array $queryParams = []): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        try {
            $contactModel = new \Apoio19\Crm\Models\WhatsappContact();
            $filters = [];

            if (!empty($queryParams['search'])) {
                $filters['search'] = $queryParams['search'];
            }

            $contacts = $contactModel->getAllRaw($filters);
            return $this->successResponse($contacts, "Contatos obtidos", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Erro ao buscar contatos base: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao buscar contatos", "ERROR", $traceId);
        }
    }

    /**
     * Get WhatsApp analytics data
     */
    public function getAnalytics(array $headers, array $queryParams = []): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        $this->requirePermission($userData, 'whatsapp', 'view');

        try {
            $internalPhoneId = $queryParams['phone_number_id'] ?? null;
            $db = \Apoio19\Crm\Models\Database::getInstance();

            // Convert internal phone number ID to Meta API phone_number_id
            $metaPhoneNumberId = null;
            if (!empty($internalPhoneId)) {
                $stmt = $db->prepare('SELECT phone_number_id FROM whatsapp_phone_numbers WHERE id = ?');
                $stmt->execute([(int)$internalPhoneId]);
                $phoneData = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($phoneData && !empty($phoneData['phone_number_id'])) {
                    $metaPhoneNumberId = $phoneData['phone_number_id'];
                }
            }

            $sql = "
                SELECT 
                    COUNT(DISTINCT contact_id) as new_contacts,
                    SUM(CASE WHEN direction = 'outgoing' THEN 1 ELSE 0 END) as total_sent,
                    SUM(CASE WHEN direction = 'outgoing' AND (status IN ('delivered', 'read') OR delivered_at IS NOT NULL OR read_at IS NOT NULL) THEN 1 ELSE 0 END) as delivered_count,
                    SUM(CASE WHEN direction = 'outgoing' AND (status = 'read' OR read_at IS NOT NULL) THEN 1 ELSE 0 END) as read_count
                FROM whatsapp_chat_messages
            ";
            $params = [];

            if (!empty($metaPhoneNumberId)) {
                $sql .= " WHERE phone_number_id = ?";
                $params[] = $metaPhoneNumberId;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            $newContacts = $result['new_contacts'] ?? 0;
            $totalSent = $result['total_sent'] ?? 0;
            $deliveredCount = $result['delivered_count'] ?? 0;
            $readCount = $result['read_count'] ?? 0;

            $deliveryRate = $totalSent > 0 ? round(($deliveredCount / $totalSent) * 100) : 0;
            $readRate = $totalSent > 0 ? round(($readCount / $totalSent) * 100) : 0;

            return $this->successResponse([
                'new_contacts' => (int)$newContacts,
                'total_sent' => (int)$totalSent,
                'delivery_rate' => (int)$deliveryRate,
                'read_rate' => (int)$readRate
            ], "Métricas obtidas com sucesso", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Get analytics error: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao buscar métricas", "ERROR", $traceId);
        }
    }
}
