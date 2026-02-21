<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\WhatsappCampaign;
use Apoio19\Crm\Models\WhatsappTemplate;
use Apoio19\Crm\Models\WhatsappContact;
use Apoio19\Crm\Middleware\AuthMiddleware;

class WhatsappCampaignController extends BaseController
{
    private WhatsappCampaign $campaignModel;
    private WhatsappTemplate $templateModel;
    private WhatsappContact $contactModel;
    private AuthMiddleware $authMiddleware;

    public function __construct()
    {
        parent::__construct();
        $this->campaignModel = new WhatsappCampaign();
        $this->templateModel = new WhatsappTemplate();
        $this->contactModel = new WhatsappContact();
        $this->authMiddleware = new AuthMiddleware();
    }

    /**
     * Listar todas as campanhas
     */
    public function index(array $headers): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária"];
        }

        try {
            $filters = [];

            // Check permission
            $this->requirePermission($userData, 'whatsapp', 'view');

            $filters = [];

            // Se não tiver permissão de ver todas, mostrar apenas suas campanhas (handled by requirePermission internally? No, requirePermission just checks access. Scope filtering is separate.)
            // Actually, requirePermission throws if no access.
            // For listing, we might want to allow if they have 'view' permission, but filter by scope.
            // The current implementation of requirePermission checks if they have ANY view permission.
            // If they have 'own', requirePermission passes (if no ownerId passed).
            // So we just need to keep the filter logic.

            if (!$this->can($userData, "campaigns", "view")) {
                // This check is redundant if requirePermission is called, but requirePermission with no ownerId checks generic access.
                // Let's rely on requirePermission for the gatekeeping.
            }

            // If user has 'own' permission, we should filter.
            // Let's check the permission type to decide on filtering.
            $perms = $this->permissionService->getUserPermissions($userData);
            $viewPerm = $perms['campaigns']['view'] ?? false;

            if ($viewPerm === 'own') {
                $filters['user_id'] = $userData->id;
            }

            // Aplicar filtros da query string
            // Merge $_GET to ensure we catch parameters even if router misses them
            $requestParams = array_merge($_GET, $filters);

            error_log("=== DEBUG CAMPANHAS AVANÇADO ===");
            error_log("QUERY_STRING: " . ($_SERVER['QUERY_STRING'] ?? 'NÃO DEFINIDA'));
            error_log("REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'NÃO DEFINIDA'));
            error_log("_GET keys: " . json_encode(array_keys($_GET)));
            error_log("_REQUEST keys: " . json_encode(array_keys($_REQUEST)));

            if (isset($requestParams['status'])) {
                $filters['status'] = $requestParams['status'];
            }

            if (isset($requestParams['limit'])) {
                $filters['limit'] = (int)$requestParams['limit'];
            }

            if (isset($requestParams['phone_number_id'])) {
                $internalId = (int)$requestParams['phone_number_id'];
                error_log("WhatsappCampaignController::index - ID interno recebido: " . $internalId);

                $db = \Apoio19\Crm\Models\Database::getInstance();
                $stmt = $db->prepare('SELECT phone_number_id FROM whatsapp_phone_numbers WHERE id = ?');
                $stmt->execute([$internalId]);
                $phoneData = $stmt->fetch(\PDO::FETCH_ASSOC);

                error_log("WhatsappCampaignController::index - Dados do número encontrado: " . json_encode($phoneData));

                if ($phoneData && !empty($phoneData['phone_number_id'])) {
                    $filters['phone_number_id'] = $phoneData['phone_number_id'];
                    error_log("WhatsappCampaignController::index - Filtro Meta ID aplicado: " . $phoneData['phone_number_id']);
                } else {
                    $filters['phone_number_id'] = -1;
                    error_log("WhatsappCampaignController::index - Número não encontrado! Aplicando filtro -1.");
                }
            }

            error_log("WhatsappCampaignController::index - Filtros finais: " . json_encode($filters));

            $campaigns = $this->campaignModel->getAll($filters);

            http_response_code(200);
            return ["success" => true, "data" => $campaigns];
        } catch (\Exception $e) {
            error_log("Erro ao listar campanhas: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro ao listar campanhas"];
        }
    }

    /**
     * Obter detalhes de uma campanha específica
     */
    public function show(array $headers, int $id): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária"];
        }

        try {
            $campaign = $this->campaignModel->findById($id);

            if (!$campaign) {
                http_response_code(404);
                return ["error" => "Campanha não encontrada"];
            }

            // Verificar permissão
            $this->requirePermission($userData, "whatsapp", "view", $campaign['user_id']);

            // Buscar estatísticas
            $stats = $this->campaignModel->getStats($id);
            $campaign['stats'] = $stats;

            http_response_code(200);
            return ["success" => true, "data" => $campaign];
        } catch (\Exception $e) {
            error_log("Erro ao buscar campanha: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro ao buscar campanha"];
        }
    }

    /**
     * Criar nova campanha
     */
    public function store(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária"];
        }

        // Check permission
        $this->requirePermission($userData, 'whatsapp', 'create');

        try {
            // Validar dados obrigatórios
            if (empty($requestData['name'])) {
                http_response_code(400);
                return ["error" => "Nome da campanha é obrigatório"];
            }

            // Verificar se o template existe e está aprovado (se fornecido)
            if (!empty($requestData['template_id'])) {
                $template = $this->templateModel->findById($requestData['template_id']);
                if (!$template || $template['status'] !== 'APPROVED') {
                    http_response_code(400);
                    return ["error" => "Template inválido ou não aprovado"];
                }
            }

            // Criar campanha
            $campaignData = [
                'user_id' => $userData->id,
                'phone_number_id' => $requestData['phone_number_id'] ?? null,
                'name' => $requestData['name'],
                'description' => $requestData['description'] ?? null,
                'status' => 'draft',
                'scheduled_at' => $requestData['scheduled_at'] ?? null
            ];

            $campaignId = $this->campaignModel->create($campaignData);

            // Processar contatos
            $contactIds = [];

            // Opcional: Processar contatos via Wizard (separadamente) ou na criação
            if (!empty($_FILES['contacts_csv']) || !empty($requestData['contact_ids']) || !empty($requestData['import_from_leads'])) {
                // Opção 1: Upload de CSV
                if (isset($_FILES['contacts_csv']) && $_FILES['contacts_csv']['error'] === UPLOAD_ERR_OK) {
                    $csvFile = $_FILES['contacts_csv']['tmp_name'];
                    $handle = fopen($csvFile, 'r');

                    // Pular cabeçalho
                    fgetcsv($handle);

                    while (($row = fgetcsv($handle)) !== false) {
                        if (empty($row[0])) continue;

                        $phoneNumber = preg_replace('/[^0-9]/', '', $row[0]);
                        $name = $row[1] ?? null;

                        // Buscar ou criar contato
                        $contact = $this->contactModel->findByPhoneNumber($phoneNumber);

                        if ($contact) {
                            $contactId = $contact['id'];
                        } else {
                            $contactId = $this->contactModel->create([
                                'phone_number' => $phoneNumber,
                                'name' => $name
                            ]);
                        }

                        $contactIds[] = $contactId;
                    }

                    fclose($handle);
                }
                // Opção 2: IDs de contatos fornecidos
                else if (!empty($requestData['contact_ids']) && is_array($requestData['contact_ids'])) {
                    $contactIds = $requestData['contact_ids'];
                }
                // Opção 3: Importar de leads
                else if (!empty($requestData['import_from_leads'])) {
                    // Buscar leads com telefone e criar contatos WhatsApp
                    $db = \Apoio19\Crm\Models\Database::getInstance();
                    $stmt = $db->query("SELECT id, name, phone FROM leads WHERE phone IS NOT NULL AND phone != ''");
                    $leads = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                    foreach ($leads as $lead) {
                        $phoneNumber = preg_replace('/[^0-9]/', '', $lead['phone']);

                        $contact = $this->contactModel->findByPhoneNumber($phoneNumber);

                        if ($contact) {
                            $contactId = $contact['id'];
                        } else {
                            $contactId = $this->contactModel->create([
                                'phone_number' => $phoneNumber,
                                'name' => $lead['name'],
                                'lead_id' => $lead['id']
                            ]);
                        }

                        $contactIds[] = $contactId;
                    }
                }

                if (!empty($contactIds) && !empty($requestData['template_id'])) {
                    // Criar mensagens para cada contato
                    $db = \Apoio19\Crm\Models\Database::getInstance();
                    $stmt = $db->prepare('
                        INSERT INTO whatsapp_campaign_messages (campaign_id, contact_id, template_id, status)
                        VALUES (?, ?, ?, ?)
                    ');

                    foreach ($contactIds as $contactId) {
                        $stmt->execute([$campaignId, $contactId, $requestData['template_id'], 'pending']);
                    }
                }
            }

            http_response_code(201);
            return [
                "success" => true,
                "data" => [
                    "campaign_id" => $campaignId,
                    "total_contacts" => count($contactIds)
                ],
                "message" => "Campanha criada com sucesso!"
            ];
        } catch (\Exception $e) {
            error_log("Erro ao criar campanha: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro ao criar campanha: " . $e->getMessage()];
        }
    }

    /**
     * Atualizar campanha
     */
    public function update(array $headers, int $id, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária"];
        }

        try {
            $campaign = $this->campaignModel->findById($id);

            if (!$campaign) {
                http_response_code(404);
                return ["error" => "Campanha não encontrada"];
            }

            // Verificar permissão
            $this->requirePermission($userData, "whatsapp", "edit", $campaign['user_id']);

            // Não permitir atualização se já estiver completa
            if (in_array($campaign['status'], ['completed', 'cancelled'])) {
                http_response_code(400);
                return ["error" => "Não é possível atualizar campanha concluída ou cancelada"];
            }

            // Manipulate settings to inject template_id if provided
            if (array_key_exists('template_id', $requestData)) {
                $settings = json_decode($campaign['settings'] ?? '{}', true) ?: [];
                $settings['template_id'] = $requestData['template_id'];
                $requestData['settings'] = json_encode($settings, JSON_UNESCAPED_UNICODE);
            }

            $this->campaignModel->update($id, $requestData);

            // 🟢 AUDIT LOG - Log campaign update
            $updatedCampaign = $this->campaignModel->findById($id);
            $this->logAudit($userData->id, 'update', 'whatsapp_campaigns', $id, $campaign, $updatedCampaign);

            http_response_code(200);
            return ["success" => true, "message" => "Campanha atualizada com sucesso"];
        } catch (\Exception $e) {
            error_log("Erro ao atualizar campanha: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro ao atualizar campanha"];
        }
    }

    /**
     * Iniciar processamento da campanha
     */
    public function start(array $headers, int $id): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária"];
        }

        try {
            $campaign = $this->campaignModel->findById($id);

            if (!$campaign) {
                http_response_code(404);
                return ["error" => "Campanha não encontrada"];
            }

            // Verificar permissão
            $this->requirePermission($userData, "whatsapp", "edit", $campaign['user_id']);

            // Verificar se pode ser iniciada (incluindo processing para destravar campanhas antigas)
            if (!in_array($campaign['status'], ['draft', 'scheduled', 'paused', 'processing'])) {
                http_response_code(400);
                return ["error" => "Campanha não pode ser iniciada no status atual"];
            }

            $this->campaignModel->markAsStarted($id);

            // Iniciar o disparo imediato das mensagens pendentes
            $messageModel = new \Apoio19\Crm\Models\WhatsappCampaignMessage();
            $whatsappService = new \Apoio19\Crm\Services\WhatsappService();

            $pendingMessages = $messageModel->getByStatus($id, 'pending');
            $sentCount = 0;
            $failedCount = 0;

            foreach ($pendingMessages as $msg) {
                error_log("Campaign Start - Message Dump: " . json_encode($msg));
                // Ensure we have contact phone number and template details
                if (empty($msg['contact_phone']) || empty($msg['template_name']) || empty($msg['template_language'])) {
                    $messageModel->updateStatus($msg['id'], 'failed', null, 'Dados incompletos do contato ou template');
                    $failedCount++;
                    continue;
                }

                $components = isset($msg['template_params']) ? json_decode($msg['template_params'], true) : [];

                $result = $whatsappService->sendTemplateMessage(
                    $msg['contact_phone'],
                    $msg['template_name'],
                    $msg['template_language'],
                    $components ?: [],
                    $userData->id,
                    $campaign['phone_number_id']
                );

                if ($result['success']) {
                    $messageModel->updateStatus($msg['id'], 'sent', $result['message_id'] ?? null, null, $result['phone_number_id'] ?? null);
                    $sentCount++;
                } else {
                    $messageModel->updateStatus($msg['id'], 'failed', null, $result['error'] ?? 'Erro desconhecido');
                    $failedCount++;
                }

                // Optional: sleep to respect WhatsApp API rate limits if there are many messages
                if (count($pendingMessages) > 10) {
                    usleep(100000); // sleep for 100ms
                }
            }

            $this->campaignModel->markAsCompleted($id);

            http_response_code(200);
            return [
                "success" => true,
                "message" => "Campanha processada: {$sentCount} enviadas, {$failedCount} falhas."
            ];
        } catch (\Exception $e) {
            error_log("Erro ao iniciar campanha: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro ao iniciar campanha"];
        }
    }

    /**
     * Pausar campanha
     */
    public function pause(array $headers, int $id): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária"];
        }

        try {
            $campaign = $this->campaignModel->findById($id);

            if (!$campaign) {
                http_response_code(404);
                return ["error" => "Campanha não encontrada"];
            }

            $this->requirePermission($userData, "whatsapp", "edit", $campaign['user_id']);

            $this->campaignModel->updateStatus($id, 'paused');

            http_response_code(200);
            return ["success" => true, "message" => "Campanha pausada"];
        } catch (\Exception $e) {
            error_log("Erro ao pausar campanha: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro ao pausar campanha"];
        }
    }

    /**
     * Cancelar campanha
     */
    public function cancel(array $headers, int $id): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária"];
        }

        try {
            $campaign = $this->campaignModel->findById($id);

            if (!$campaign) {
                http_response_code(404);
                return ["error" => "Campanha não encontrada"];
            }

            $this->requirePermission($userData, "whatsapp", "delete", $campaign['user_id']);

            $this->campaignModel->updateStatus($id, 'cancelled');

            http_response_code(200);
            return ["success" => true, "message" => "Campanha cancelada"];
        } catch (\Exception $e) {
            error_log("Erro ao cancelar campanha: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro ao cancelar campanha"];
        }
    }

    /**
     * Deletar campanha
     */
    public function delete(array $headers, int $id): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária"]; // Changed error message slightly to be generic
        }

        // Check permission
        $this->requirePermission($userData, 'whatsapp', 'delete');

        try {
            $campaign = $this->campaignModel->findById($id);

            if (!$campaign) {
                http_response_code(404);
                return ["error" => "Campanha não encontrada"];
            }

            // Não permitir deletar se estiver em processamento
            if ($campaign['status'] === 'processing') {
                http_response_code(400);
                return ["error" => "Não é possível deletar campanha em processamento"];
            }

            $this->campaignModel->delete($id);

            // 🟢 AUDIT LOG - Log campaign deletion
            $this->logAudit($userData->id, 'delete', 'whatsapp_campaigns', $id, $campaign, null);

            http_response_code(200);
            return ["success" => true, "message" => "Campanha deletada"];
        } catch (\Exception $e) {
            error_log("Erro ao deletar campanha: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro ao deletar campanha"];
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
     * Adicionar contatos à campanha
     */
    public function addContacts(array $headers, int $campaignId, ?array $requestData = []): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        try {
            $campaign = $this->campaignModel->findById($campaignId);
            if (!$campaign) {
                return $this->errorResponse(404, "Campanha não encontrada", "NOT_FOUND", $traceId);
            }

            $this->requirePermission($userData, 'whatsapp', 'edit');

            $settings = json_decode($campaign['settings'] ?? '{}', true) ?: [];
            $templateId = $settings['template_id'] ?? null;

            if (!$templateId) {
                return $this->errorResponse(400, "A campanha precisa de um template antes de adicionar contatos", "VALIDATION_ERROR", $traceId);
            }

            $contactIds = [];

            // Operação 1: Array de contacts_ids direto no JSON
            if (!empty($requestData['contact_ids']) && is_array($requestData['contact_ids'])) {
                $contactIds = $requestData['contact_ids'];
            }
            // Operação 2: Importar Leads
            else if (!empty($requestData['import_from_leads'])) {
                $db = \Apoio19\Crm\Models\Database::getInstance();
                $stmt = $db->query("SELECT id, name, phone FROM leads WHERE phone IS NOT NULL AND phone != ''");
                $leads = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($leads as $lead) {
                    $phoneNumber = preg_replace('/[^0-9]/', '', $lead['phone']);
                    $contact = $this->contactModel->findByPhoneNumber($phoneNumber);

                    if ($contact) {
                        $contactId = $contact['id'];
                    } else {
                        $contactId = $this->contactModel->create([
                            'phone_number' => $phoneNumber,
                            'name' => $lead['name'],
                            'lead_id' => $lead['id']
                        ]);
                    }
                    $contactIds[] = $contactId;
                }
            }
            // Operação 3: Upload de CSV (virá via $_FILES)
            else if (isset($_FILES['contacts_csv']) && $_FILES['contacts_csv']['error'] === UPLOAD_ERR_OK) {
                $csvFile = $_FILES['contacts_csv']['tmp_name'];
                $handle = fopen($csvFile, 'r');
                fgetcsv($handle); // Pular cabeçalho

                while (($row = fgetcsv($handle)) !== false) {
                    if (empty($row[0])) continue;
                    $phoneNumber = preg_replace('/[^0-9]/', '', $row[0]);
                    $name = $row[1] ?? null;

                    $contact = $this->contactModel->findByPhoneNumber($phoneNumber);
                    if ($contact) {
                        $contactId = $contact['id'];
                    } else {
                        $contactId = $this->contactModel->create([
                            'phone_number' => $phoneNumber,
                            'name' => $name
                        ]);
                    }
                    $contactIds[] = $contactId;
                }
                fclose($handle);
            }

            if (empty($contactIds)) {
                return $this->errorResponse(400, "Nenhum contato fornecido", "VALIDATION_ERROR", $traceId);
            }

            // Inserir as mensagens
            $db = \Apoio19\Crm\Models\Database::getInstance();
            $stmt = $db->prepare('
                INSERT IGNORE INTO whatsapp_campaign_messages (campaign_id, contact_id, template_id, status)
                VALUES (?, ?, ?, ?)
            ');

            $added = 0;
            foreach (array_unique($contactIds) as $contactId) {
                $stmt->execute([$campaignId, $contactId, $templateId, 'pending']);
                if ($stmt->rowCount() > 0) {
                    $added++;
                }
            }

            return $this->successResponse(["added" => $added], "{$added} contatos adicionados à campanha com sucesso", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Erro ao adicionar contatos à campanha: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao processar contatos", "ERROR", $traceId);
        }
    }

    /**
     * Salvar configuração de respostas (Flows)
     */
    public function saveResponsesConfig(array $headers, int $campaignId, ?array $requestData = []): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        try {
            $campaign = $this->campaignModel->findById($campaignId);
            if (!$campaign) {
                return $this->errorResponse(404, "Campanha não encontrada", "NOT_FOUND", $traceId);
            }

            $this->requirePermission($userData, 'whatsapp', 'edit');

            // Save response configs merging into settings
            $settings = json_decode($campaign['settings'] ?? '{}', true) ?: [];
            $settings['responses_config'] = $requestData['config'] ?? [];

            $this->campaignModel->update($campaignId, ['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);

            return $this->successResponse([], "Configurações de resposta salvas com sucesso!", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Erro ao salvar opções de resposta da campanha: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao salvar configurações", "ERROR", $traceId);
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
     * Obter mensagens disparadas de uma campanha
     */
    public function getMessages(array $headers, int $campaignId): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            $errorDetails = $this->authMiddleware->getLastError();
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHORIZED", $traceId, $errorDetails);
        }

        try {
            // Validate campaign exists
            $campaign = $this->campaignModel->findById($campaignId);
            if (!$campaign) {
                return $this->errorResponse(404, "Campanha não encontrada", "NOT_FOUND", $traceId);
            }

            $messageModel = new \Apoio19\Crm\Models\WhatsappCampaignMessage();
            $messages = $messageModel->getByCampaignId($campaignId);
            return $this->successResponse($messages, "Mensagens da campanha obtidas", 200, $traceId);
        } catch (\Exception $e) {
            error_log("Erro ao buscar mensagens da campanha: " . $e->getMessage());
            return $this->errorResponse(500, "Erro ao buscar mensagens da campanha", "ERROR", $traceId);
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
}
