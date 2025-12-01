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

            // Se não for admin, mostrar apenas suas campanhas
            if ($userData->funcao !== 'admin') {
                $filters['user_id'] = $userData->userId;
            }

            // Aplicar filtros da query string
            if (isset($_GET['status'])) {
                $filters['status'] = $_GET['status'];
            }

            if (isset($_GET['limit'])) {
                $filters['limit'] = (int)$_GET['limit'];
            }

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
            if ($userData->funcao !== 'admin' && $campaign['user_id'] != $userData->userId) {
                http_response_code(403);
                return ["error" => "Acesso negado"];
            }

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
        $userData = $this->authMiddleware->handle($headers, ["admin", "gerente", "comercial"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária"];
        }

        try {
            // Validar dados obrigatórios
            if (empty($requestData['name'])) {
                http_response_code(400);
                return ["error" => "Nome da campanha é obrigatório"];
            }

            if (empty($requestData['template_id'])) {
                http_response_code(400);
                return ["error" => "Template é obrigatório"];
            }

            // Verificar se o template existe e está aprovado
            $template = $this->templateModel->findById($requestData['template_id']);
            if (!$template || $template['status'] !== 'APPROVED') {
                http_response_code(400);
                return ["error" => "Template inválido ou não aprovado"];
            }

            // Criar campanha
            $campaignData = [
                'user_id' => $userData->userId,
                'phone_number_id' => $requestData['phone_number_id'] ?? null,
                'name' => $requestData['name'],
                'description' => $requestData['description'] ?? null,
                'status' => 'draft',
                'scheduled_at' => $requestData['scheduled_at'] ?? null
            ];

            $campaignId = $this->campaignModel->create($campaignData);

            // Processar contatos
            $contactIds = [];

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

            if (empty($contactIds)) {
                http_response_code(400);
                return ["error" => "Nenhum contato fornecido para a campanha"];
            }

            // Criar mensagens para cada contato
            $db = \Apoio19\Crm\Models\Database::getInstance();
            $stmt = $db->prepare('
                INSERT INTO whatsapp_campaign_messages (campaign_id, contact_id, template_id, status)
                VALUES (?, ?, ?, ?)
            ');

            foreach ($contactIds as $contactId) {
                $stmt->execute([$campaignId, $contactId, $requestData['template_id'], 'pending']);
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
        $userData = $this->authMiddleware->handle($headers, ["admin", "gerente", "comercial"]);
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
            if ($userData->funcao !== 'admin' && $campaign['user_id'] != $userData->userId) {
                http_response_code(403);
                return ["error" => "Acesso negado"];
            }

            // Não permitir atualização se já estiver processando ou completa
            if (in_array($campaign['status'], ['processing', 'completed'])) {
                http_response_code(400);
                return ["error" => "Não é possível atualizar campanha em processamento ou concluída"];
            }

            $this->campaignModel->update($id, $requestData);

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
        $userData = $this->authMiddleware->handle($headers, ["admin", "gerente", "comercial"]);
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
            if ($userData->funcao !== 'admin' && $campaign['user_id'] != $userData->userId) {
                http_response_code(403);
                return ["error" => "Acesso negado"];
            }

            // Verificar se pode ser iniciada
            if (!in_array($campaign['status'], ['draft', 'scheduled', 'paused'])) {
                http_response_code(400);
                return ["error" => "Campanha não pode ser iniciada no status atual"];
            }

            $this->campaignModel->markAsStarted($id);

            // TODO: Adicionar à fila de processamento ou chamar serviço de envio

            http_response_code(200);
            return ["success" => true, "message" => "Campanha iniciada com sucesso"];
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
        $userData = $this->authMiddleware->handle($headers, ["admin", "gerente", "comercial"]);
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

            if ($userData->funcao !== 'admin' && $campaign['user_id'] != $userData->userId) {
                http_response_code(403);
                return ["error" => "Acesso negado"];
            }

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
        $userData = $this->authMiddleware->handle($headers, ["admin", "gerente", "comercial"]);
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

            if ($userData->funcao !== 'admin' && $campaign['user_id'] != $userData->userId) {
                http_response_code(403);
                return ["error" => "Acesso negado"];
            }

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
        $userData = $this->authMiddleware->handle($headers, ["admin"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Apenas administradores podem deletar campanhas"];
        }

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

            http_response_code(200);
            return ["success" => true, "message" => "Campanha deletada"];
        } catch (\Exception $e) {
            error_log("Erro ao deletar campanha: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro ao deletar campanha"];
        }
    }
}
