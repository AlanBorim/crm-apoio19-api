<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Lead;
use Apoio19\Crm\Models\HistoricoInteracoes;
use Apoio19\Crm\Middleware\AuthMiddleware;
use League\Csv\Reader;
use League\Csv\Statement;

/**
 * Controlador para gerenciamento de leads
 */
class LeadController extends BaseController
{
    private AuthMiddleware $authMiddleware;

    public function __construct()
    {
        parent::__construct();
        $this->authMiddleware = new AuthMiddleware();
    }

    /**
     * Listar leads com filtros
     *
     * @param array $headers Cabeçalhos da requisição
     * @param array $queryParams Filtros (status, responsavel_id, origem, etc.)
     * @return array Resposta JSON
     */
    public function index(array $headers, array $queryParams = []): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        // Check permission
        $this->requirePermission($userData, 'leads', 'view');

        try {
            // Montar condições dinâmicas
            $conditions = [];
            $params = [];

            // Filtro por estágio (stage)
            if (!empty($queryParams['stage'])) {
                $conditions[] = "stage = :stage";
                $params[':stage'] = $queryParams['stage'];
            }

            // Filtro por temperatura
            if (!empty($queryParams['temperature'])) {
                $conditions[] = "temperature = :temperature";
                $params[':temperature'] = $queryParams['temperature'];
            }

            // Filtro por origem
            if (!empty($queryParams['source'])) {
                $conditions[] = "source = :source";
                $params[':source'] = $queryParams['source'];
            }

            // Filtro por responsável
            if (!empty($queryParams['assigned_to'])) {
                $conditions[] = "assigned_to = :assigned_to";
                $params[':assigned_to'] = (int)$queryParams['assigned_to'];
            }

            // Filtro de busca textual (name, company, email)
            if (!empty($queryParams['search'])) {
                $search = '%' . $queryParams['search'] . '%';
                $conditions[] = "(name LIKE :search1 OR company LIKE :search2 OR email LIKE :search3)";
                $params[':search1'] = $search;
                $params[':search2'] = $search;
                $params[':search3'] = $search;
            }

            // Adicionar restrição de responsável para usuários que não têm permissão de ver todos
            if (!$this->can($userData, "leads", "view")) {
                $conditions[] = "assigned_to = :user_id";
                $params[':user_id'] = $userData->id;
            }

            //adicionando a parte onde indica que só os leads ativos devem ser retornados
            $conditions[] = "active = :active";
            $params[':active'] = '1';

            // Construir WHERE
            $where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";

            // Buscar leads filtrados
            $leads = Lead::findAllWithWhere($where, $params);

            return $this->successResponse($leads, null, 200, $traceId);
        } catch (\PDOException $e) {
            $mapped = $this->mapPdoError($e);
            return $this->errorResponse($mapped['status'], $mapped['message'], $mapped['code'], $traceId, $this->debugDetails($e));
        } catch (\Throwable $e) {
            return $this->errorResponse(500, "Erro ao buscar leads.", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * Criar novo lead
     *
     * @param array $headers Cabeçalhos da requisição
     * @param array $requestData Dados do lead
     * @return array Resposta JSON
     */
    public function store(array $headers, array $requestData): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        // Check permission
        $this->requirePermission($userData, 'leads', 'create');

        // Validação básica
        $validation = $this->validateLeadData($requestData);
        if (!$validation['valid']) {
            return $this->errorResponse(400, $validation['message'], "VALIDATION_ERROR", $traceId);
        }

        // Definir responsável padrão se não fornecido
        $requestData["assigned_to"] = $requestData["assigned_to"] ?? $userData->id;
        $requestData["created_at"] = date('Y-m-d H:i:s');

        try {
            $leadId = Lead::create($requestData);

            if ($leadId) {
                $newLead = Lead::findById($leadId);

                // 🟢 AUDIT LOG - Log lead creation
                $this->logAudit(
                    $userData->id,
                    'create',
                    'leads',
                    $leadId,
                    null,
                    $newLead
                );

                // Registrar histórico
                HistoricoInteracoes::logAction(
                    $leadId,
                    null,
                    $userData->id,
                    "Lead Criado",
                    "Lead criado no sistema."
                );

                // Notificar responsável se diferente do criador
                if ($newLead && $newLead->responsavel_id && $newLead->responsavel_id !== $userData->id) {
                    // 🔔 NOTIFICATION - Notify assigned user
                    $this->notify(
                        $newLead->responsavel_id,
                        "Novo Lead Atribuído",
                        "Lead '{$newLead->nome}' foi atribuído para você.",
                        "info",
                        "/leads/{$leadId}"
                    );
                    $this->notifyLeadAssignment($newLead, $userData, "novo_lead_atribuido");
                }

                return $this->successResponse($newLead, "Lead criado com sucesso.", 201, $traceId);
            } else {
                return $this->errorResponse(500, "Falha ao criar lead.", "CREATE_FAILED", $traceId);
            }
        } catch (\PDOException $e) {
            $this->logAudit($userData->id, 'create_failed', 'leads', null, null, ['error' => $e->getMessage()]);
            $mapped = $this->mapPdoError($e);
            return $this->errorResponse($mapped['status'], $mapped['message'], $mapped['code'], $traceId, $this->debugDetails($e));
        } catch (\Throwable $e) {
            $this->logAudit($userData->id, 'create_failed', 'leads', null, null, ['error' => $e->getMessage()]);
            return $this->errorResponse(500, "Erro interno ao criar lead.", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * Exibir detalhes de um lead específico
     *
     * @param array $headers Cabeçalhos da requisição
     * @param int $leadId ID do lead
     * @return array Resposta JSON
     */
    public function show(array $headers, int $leadId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        try {
            $lead = Lead::findById($leadId);
            if (!$lead) {
                return $this->errorResponse(404, "Lead não encontrado.");
            }

            // Verificar autorização
            if (!$this->can($userData, "leads", "view", $lead->responsavel_id)) {
                return $this->forbidden("Acesso negado a este lead.");
            }

            return $this->successResponse(["lead" => $lead]);
            // $history = HistoricoInteracoes::findByLeadId($leadId);

            // return $this->successResponse([
            //     "lead" => $lead,
            //     "historico" => $history
            // ]);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao buscar lead.", $e->getMessage());
        }
    }

    /**
     * Atualizar lead existente
     *
     * @param array $headers Cabeçalhos da requisição
     * @param int $leadId ID do lead
     * @param array $requestData Dados para atualização
     * @return array Resposta JSON
     */
    public function update(array $headers, int $leadId, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        // Check permission
        $this->requirePermission($userData, 'leads', 'edit');

        if (empty($requestData)) {
            return $this->errorResponse(400, "Nenhum dado fornecido para atualização.");
        }

        try {
            $lead = Lead::findById($leadId);

            if (!$lead) {
                return $this->errorResponse(404, "Lead não encontrado.");
            }

            if ($lead->active === 0) {
                return $this->errorResponse(404, "Lead não encontrado ou lead desativado.");
            }

            // Verificar autorização
            if (!$this->can($userData, "leads", "edit", $lead->responsavel_id)) {
                return $this->forbidden("Você não tem permissão para atualizar este lead.");
            }

            // Verificar mudança de responsável para notificação
            $oldAssigneeId = $lead->responsavel_id;
            $newAssigneeId = isset($requestData["responsavel_id"]) ? (int)$requestData["responsavel_id"] : $oldAssigneeId;
            $notifyAssignee = ($newAssigneeId !== $oldAssigneeId && $newAssigneeId !== null && $newAssigneeId !== $userData->id);

            $requestData["atualizado_por"] = $userData->id;
            $requestData["data_atualizacao"] = date('Y-m-d H:i:s');

            if (Lead::update($leadId, $requestData)) {
                $updatedLead = Lead::findById($leadId);

                // 🟢 AUDIT LOG - Log lead update
                $this->logAudit($userData->id, 'update', 'leads', $leadId, $lead, $updatedLead);

                // Registrar histórico
                $logDetails = $this->generateUpdateLogDetails($requestData, $lead);
                HistoricoInteracoes::logAction(
                    $leadId,
                    null,
                    $userData->id,
                    "Lead Atualizado",
                    $logDetails . json_encode($requestData, JSON_UNESCAPED_SLASHES)
                );

                // Notificar novo responsável
                if ($notifyAssignee && $updatedLead) {
                    $this->notifyLeadAssignment($updatedLead, $userData, "lead_atribuido");
                }

                return $this->successResponse($updatedLead, "Lead Atualizado com sucesso - " . json_encode($requestData, JSON_UNESCAPED_UNICODE));
            } else {
                return $this->errorResponse(500, "Falha ao atualizar lead.");
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno ao atualizar lead.", $e->getMessage());
        }
    }

    /**
     * Excluir lead
     *
     * @param array $headers Cabeçalhos da requisição
     * @param int $leadId ID do lead
     * @return array Resposta JSON
     */
    public function destroy(array $headers, int $leadId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        // Check permission
        $this->requirePermission($userData, 'leads', 'delete');

        try {
            $lead = Lead::findById($leadId);
            if (!$lead) {
                return $this->errorResponse(404, "Lead não encontrado.");
            }

            if (Lead::delete($leadId)) {
                // 🟢 AUDIT LOG - Log lead deletion
                $this->logAudit($userData->id, 'delete', 'leads', $leadId, $lead, null);

                // Registrar histórico antes da exclusão
                HistoricoInteracoes::logAction(
                    $leadId,
                    null,
                    $userData->id,
                    "Lead Excluído",
                    "Lead excluído do sistema."
                );

                return $this->successResponse(null, "Lead excluído com sucesso.");
            } else {
                return $this->errorResponse(500, "Falha ao excluir lead.");
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno ao excluir lead.", $e->getMessage());
        }
    }

    /**
     * Importar leads de arquivo CSV
     *
     * @param array $headers Cabeçalhos da requisição
     * @param string $csvFilePath Caminho do arquivo CSV
     * @param array $fieldMapping Mapeamento de campos CSV para BD
     * @param int|null $defaultResponsavelId ID do responsável padrão
     * @return array Resposta JSON
     */
    public function importCsv(array $headers, string $csvFilePath, array $fieldMapping, ?int $defaultResponsavelId = null): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        // Check permission
        $this->requirePermission($userData, 'leads', 'create'); // Import requires create permission

        if (!file_exists($csvFilePath) || !is_readable($csvFilePath)) {
            return $this->errorResponse(400, "Arquivo CSV não encontrado ou ilegível.");
        }

        if (empty($fieldMapping) || !isset($fieldMapping["nome"])) {
            return $this->errorResponse(400, "Mapeamento de campos inválido ou campo 'nome' ausente.");
        }

        $importedCount = 0;
        $errorCount = 0;
        $errors = [];

        try {
            $csv = Reader::createFromPath($csvFilePath, 'r');
            $csv->setHeaderOffset(0);
            $stmt = Statement::create();
            $records = $stmt->process($csv);

            foreach ($records as $record) {
                $leadData = [];

                // Mapear campos do CSV
                foreach ($fieldMapping as $csvHeader => $dbField) {
                    if (isset($record[$csvHeader])) {
                        $leadData[$dbField] = trim($record[$csvHeader]);
                    }
                }

                // Validar dados mínimos
                if (empty($leadData["nome"])) {
                    $errorCount++;
                    $errors[] = "Registro ignorado: campo 'nome' ausente ou vazio.";
                    continue;
                }

                // Definir responsável padrão
                if ($defaultResponsavelId !== null && !isset($leadData["responsavel_id"])) {
                    $leadData["responsavel_id"] = $defaultResponsavelId;
                }

                $leadData["criado_por"] = $userData->id;
                $leadData["data_criacao"] = date('Y-m-d H:i:s');

                // Tentar criar o lead
                $leadId = Lead::create($leadData);
                if ($leadId) {
                    $importedCount++;

                    // Registrar histórico
                    HistoricoInteracoes::logAction(
                        $leadId,
                        null,
                        $userData->id,
                        "Lead Importado",
                        "Lead importado via CSV."
                    );

                    // Notificar responsável se diferente do importador
                    if (isset($leadData["responsavel_id"]) && $leadData["responsavel_id"] !== $userData->id) {
                        $newLead = Lead::findById($leadId);
                        if ($newLead) {
                            $this->notifyLeadAssignment($newLead, $userData, "lead_importado_atribuido");
                        }
                    }
                } else {
                    $errorCount++;
                    $errors[] = "Falha ao importar: " . ($leadData["nome"] ?? "(sem nome)");
                }
            }

            return $this->successResponse([
                "imported_count" => $importedCount,
                "error_count" => $errorCount,
                "errors" => $errors
            ], "Importação CSV concluída.");
        } catch (\Exception $e) {
            error_log("Erro durante importação CSV: " . $e->getMessage());
            return $this->errorResponse(500, "Erro interno durante importação CSV.", $e->getMessage());
        }
    }

    /**
     * Atualização em lote de status
     */
    public function batchUpdateStatus(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        // Check permission
        $this->requirePermission($userData, 'leads', 'edit'); // Batch update requires edit permission

        $ids = $requestData["ids"] ?? $requestData["leadIds"] ?? [];
        $status = $requestData["status"] ?? null;

        if (empty($ids) || !$status) {
            return $this->errorResponse(400, "IDs e novo status são obrigatórios.");
        }

        $updatedCount = 0;
        foreach ($ids as $id) {
            try {
                $lead = Lead::findById($id);
                if (!$lead) continue;

                // Verificar autorização
                if (!$this->can($userData, "leads", "edit", $lead->responsavel_id)) {
                    continue;
                }

                if (Lead::update($id, ["status" => $status])) {
                    $updatedCount++;
                    HistoricoInteracoes::logAction(
                        $id,
                        null,
                        $userData->id,
                        "Status Atualizado",
                        "Status alterado para '$status' em lote."
                    );
                }
            } catch (\Exception $e) {
                error_log("Erro ao atualizar lead $id: " . $e->getMessage());
            }
        }

        return $this->successResponse([
            "updated_count" => $updatedCount,
            "total_requested" => count($ids)
        ], "Atualização em lote concluída.");
    }

    /**
     * Atribuição em lote de responsável
     */
    public function batchAssignResponsible(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        // Check permission
        $this->requirePermission($userData, 'leads', 'edit');

        $ids = $requestData["ids"] ?? $requestData["leadIds"] ?? [];
        $responsavelId = $requestData["responsavel_id"] ?? $requestData["responsavelId"] ?? null;

        if (empty($ids) || !$responsavelId) {
            return $this->errorResponse(400, "IDs e ID do responsável são obrigatórios.");
        }

        $updatedCount = 0;
        foreach ($ids as $id) {
            try {
                $lead = Lead::findById($id);
                if (!$lead) continue;

                if (Lead::update($id, ["responsavel_id" => $responsavelId])) {
                    $updatedCount++;
                    HistoricoInteracoes::logAction(
                        $id,
                        null,
                        $userData->id,
                        "Responsável Atribuído",
                        "Lead atribuído ao usuário ID $responsavelId em lote."
                    );
                }
            } catch (\Exception $e) {
                error_log("Erro ao atribuir lead $id: " . $e->getMessage());
            }
        }

        return $this->successResponse([
            "updated_count" => $updatedCount,
            "total_requested" => count($ids)
        ], "Atribuição em lote concluída.");
    }

    /**
     * Exclusão em lote
     */
    public function batchDelete(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        // Check permission
        $this->requirePermission($userData, 'leads', 'delete');

        $ids = $requestData["ids"] ?? $requestData["leadIds"] ?? [];
        if (empty($ids)) {
            return $this->errorResponse(400, "IDs obrigatórios.");
        }

        $deletedCount = 0;
        foreach ($ids as $id) {
            try {
                $lead = Lead::findById($id);
                if (!$lead) continue;

                if (Lead::delete($id)) {
                    $deletedCount++;
                    HistoricoInteracoes::logAction(
                        $id,
                        null,
                        $userData->id,
                        "Lead Excluído",
                        "Lead excluído em lote."
                    );
                }
            } catch (\Exception $e) {
                error_log("Erro ao excluir lead $id: " . $e->getMessage());
            }
        }

        return $this->successResponse([
            "deleted_count" => $deletedCount,
            "total_requested" => count($ids)
        ], "Exclusão em lote concluída.");
    }

    /**
     * Obter estatísticas de leads
     *
     * @param array $headers Cabeçalhos da requisição
     * @return array Resposta JSON com estatísticas
     */
    public function stats(array $headers): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        // Check permission
        $this->requirePermission($userData, 'leads', 'view');

        try {
            $stats = Lead::getStats();

            if (!$stats) {
                return $this->successResponse([], "Nenhum dado de estatísticas encontrado.", 200, $traceId);
            }

            return $this->successResponse($stats, null, 200, $traceId);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao buscar estatísticas de leads.", "STATS_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    // Métodos auxiliares privados

    /**
     * Validar dados do lead
     */
    private function validateLeadData(array $data): array
    {
        if (empty($data["name"])) {
            return ["valid" => false, "message" => "O nome do lead é obrigatório."];
        }

        if (isset($data["email"]) && !empty($data["email"]) && !filter_var($data["email"], FILTER_VALIDATE_EMAIL)) {
            return ["valid" => false, "message" => "Email inválido."];
        }

        return ["valid" => true, "message" => ""];
    }

    /**
     * Gerar detalhes do log de atualização
     */
    private function generateUpdateLogDetails(array $requestData, $lead): string
    {
        $details = [];

        if (isset($requestData["status"]) && $requestData["status"] !== $lead->status) {
            $details[] = "Status alterado para " . $requestData["status"];
        }

        if (isset($requestData["responsavel_id"]) && $requestData["responsavel_id"] !== $lead->responsavel_id) {
            $details[] = "Responsável alterado";
        }

        return empty($details) ? "Lead atualizado." : implode(", ", $details) . ".";
    }

    /**
     * Notificar atribuição de lead
     */
    private function notifyLeadAssignment($lead, $userData, string $type): void
    {
        try {
            $this->notificationService->createNotification(
                $type,
                "Lead Atribuído: " . $lead->nome,
                "O lead \"{$lead->nome}\" foi atribuído a você por {$userData->userName}.",
                [$lead->responsavel_id],
                "/leads/" . $lead->id,
                "lead",
                $lead->id,
                true
            );
        } catch (\Exception $e) {
            error_log("Erro ao enviar notificação: " . $e->getMessage());
        }
    }

    /**
     * Area de settings de leads
     */
    /**
     * Obter configurações de leads com filtro opcional por tipo
     *
     * @param array $headers Cabeçalhos da requisição
     * @param string|null $type Tipo de configuração (source, stage, temperature)
     * @return array Resposta JSON
     */
    public function getSettings(array $headers, ?string $type = null): array
    {
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        // Check permission
        $this->requirePermission($userData, 'configuracoes', 'view'); // Lead settings are configuration

        try {
            $leadModel = new Lead();
            $settings = $leadModel->loadLeadSettings($type);

            if (!$settings) {
                return $this->errorResponse(404, "Configurações de leads não encontradas.");
            }

            return $this->successResponse($settings);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao carregar configurações de leads.", $e->getMessage());
        }
    }

    /**
     * Criar nova configuração de lead
     *
     * @param array $headers Cabeçalhos da requisição
     * @param array $requestData Dados da configuração
     * @return array Resposta JSON
     */
    public function storeSettings(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        // Check permission
        $this->requirePermission($userData, 'configuracoes', 'create'); // Lead settings are configuration

        // Validação básica
        $validation = $this->validateSettingsData($requestData);
        if (!$validation['valid']) {
            return $this->errorResponse(400, $validation['message']);
        }

        try {
            $leadModel = new Lead();

            // Preparar dados para inserção
            $settingData = [
                'type' => $requestData['type'],
                'value' => $requestData['value'],
                'meta_config' => isset($requestData['meta_config']) ? json_encode($requestData['meta_config']) : null,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $settingId = $leadModel->createLeadSettings($settingData);

            if ($settingId) {
                $newSetting = $leadModel->findLeadSettingById($settingId);

                return $this->successResponse(json_encode($newSetting), "Configuração criada com sucesso.", 201);
            } else {
                return $this->errorResponse(500, "Falha ao criar configuração.");
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno ao criar configuração.", $e->getMessage());
        }
    }

    /**
     * Atualizar configuração existente
     *
     * @param array $headers Cabeçalhos da requisição
     * @param int $settingId ID da configuração
     * @param array $requestData Dados para atualização
     * @return array Resposta JSON
     */
    public function updateSettings(array $headers, int $settingId, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        // Check permission
        $this->requirePermission($userData, 'configuracoes', 'edit'); // Lead settings are configuration

        if (empty($requestData)) {
            return $this->errorResponse(400, "Nenhum dado fornecido para atualização.");
        }

        try {
            $leadModel = new Lead();

            // Verificar se a configuração existe
            $existingSetting = $leadModel->findLeadSettingById($settingId);
            if (!$existingSetting) {
                return $this->errorResponse(404, "Configuração não encontrada.");
            }

            // Preparar dados para atualização
            $updateData = [];

            if (isset($requestData['type'])) {
                $updateData['type'] = $requestData['type'];
            }

            if (isset($requestData['value'])) {
                $updateData['value'] = $requestData['value'];
            }

            if (isset($requestData['meta_config'])) {
                $updateData['meta_config'] = json_encode($requestData['meta_config']);
            }

            if (empty($updateData)) {
                return $this->errorResponse(400, "Nenhum campo válido fornecido para atualização.");
            }

            $success = $leadModel->updateLeadSettings($settingId, $updateData);

            if ($success) {
                $updatedSetting = $leadModel->findLeadSettingById($settingId);

                return $this->successResponse($updatedSetting, "Configuração atualizada com sucesso.");
            } else {
                return $this->errorResponse(500, "Falha ao atualizar configuração.");
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno ao atualizar configuração.", $e->getMessage());
        }
    }

    /**
     * Excluir configuração
     *
     * @param array $headers Cabeçalhos da requisição
     * @param int $settingId ID da configuração
     * @return array Resposta JSON
     */
    public function deleteSettings(array $headers, int $settingId): array
    {
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        // Check permission
        $this->requirePermission($userData, 'configuracoes', 'delete'); // Lead settings are configuration

        try {
            $leadModel = new Lead();

            // Verificar se a configuração existe
            $existingSetting = $leadModel->findLeadSettingById($settingId);
            if (!$existingSetting) {
                return $this->errorResponse(404, "Configuração não encontrada.");
            }

            $success = $leadModel->deleteLeadSettings($settingId);

            if ($success) {
                return $this->successResponse(
                    ["message" => "Configuração excluída com sucesso."],
                    "Configuração excluída com sucesso."
                );
            } else {
                return $this->errorResponse(500, "Falha ao excluir configuração.");
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno ao excluir configuração.", $e->getMessage());
        }
    }

    /**
     * Realiza a auditoria do site e calcula o score do lead sob demanda
     *
     * @param array $headers
     * @param int $leadId
     * @return array
     */
    public function auditAndScore(array $headers, int $leadId): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        // Check permission
        $this->requirePermission($userData, 'leads', 'edit');

        try {
            $lead = Lead::findById($leadId);
            if (!$lead) {
                return $this->errorResponse(404, "Lead não encontrado.", "NOT_FOUND", $traceId);
            }

            // Verificar autorização
            if (!$this->can($userData, "leads", "edit", $lead->assigned_to)) {
                return $this->forbidden("Você não tem permissão para auditar este lead.");
            }

            // Instanciar o WebsiteAuditor
            $auditor = new \Apoio19\Crm\Services\WebsiteAuditor();
            $result = $auditor->auditAndScore($lead);

            // Carregar lead atualizado para retornar
            $updatedLead = Lead::findById($leadId);

            // Registrar histórico da ação de auditoria
            \Apoio19\Crm\Models\HistoricoInteracoes::logAction(
                $leadId,
                null,
                $userData->id,
                "Auditoria Realizada",
                "Auditoria técnica de site e score recalculado sob demanda: Score obtido = " . $result['score']
            );

            return $this->successResponse($updatedLead, "Auditoria do lead realizada com sucesso.", 200, $traceId);
        } catch (\PDOException $e) {
            $mapped = $this->mapPdoError($e);
            return $this->errorResponse($mapped['status'], $mapped['message'], $mapped['code'], $traceId, $this->debugDetails($e));
        } catch (\Throwable $e) {
            return $this->errorResponse(500, "Erro ao processar auditoria.", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * Validar dados de configuração
     *
     * @param array $data Dados a serem validados
     * @return array Resultado da validação
     */
    private function validateSettingsData(array $data): array
    {
        if (empty($data['type'])) {
            return ["valid" => false, "message" => "O tipo da configuração é obrigatório."];
        }

        if (!in_array($data['type'], ['source', 'stage', 'temperature'])) {
            return ["valid" => false, "message" => "Tipo de configuração inválido. Use: source, stage ou temperature."];
        }

        if (empty($data['value'])) {
            return ["valid" => false, "message" => "O valor da configuração é obrigatório."];
        }

        // Validar meta_config se fornecido
        if (isset($data['meta_config'])) {
            if (!is_array($data['meta_config'])) {
                return ["valid" => false, "message" => "meta_config deve ser um objeto válido."];
            }

            // Validar estrutura do extra_field se presente
            if (isset($data['meta_config']['extra_field'])) {
                $extraField = $data['meta_config']['extra_field'];

                if (empty($extraField['label'])) {
                    return ["valid" => false, "message" => "Label do campo extra é obrigatório."];
                }

                if (empty($extraField['type'])) {
                    return ["valid" => false, "message" => "Tipo do campo extra é obrigatório."];
                }

                $validTypes = ['text', 'email', 'tel', 'number', 'textarea', 'select'];
                if (!in_array($extraField['type'], $validTypes)) {
                    return ["valid" => false, "message" => "Tipo de campo extra inválido."];
                }

                // Se for select, deve ter opções
                if ($extraField['type'] === 'select' && empty($extraField['options'])) {
                    return ["valid" => false, "message" => "Campos do tipo select devem ter opções."];
                }
            }
        }

        return ["valid" => true, "message" => ""];
    }

    /**
     * Obtém o template de e-mail de primeiro contato pré-preenchido
     *
     * @param array $headers Headers da requisição
     * @param int $leadId ID do lead
     * @return array
     */
    public function getFirstContactTemplate(array $headers, int $leadId): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        // Check permission
        $this->requirePermission($userData, 'leads', 'view');

        try {
            $lead = Lead::findById($leadId);
            if (!$lead) {
                return $this->errorResponse(404, "Lead não encontrado.", "NOT_FOUND", $traceId);
            }

            // Buscar usuário completo para pegar o link de agendamento se existir
            $currentUser = \Apoio19\Crm\Models\User::findById($userData->id);
            $bookingLink = ($currentUser && !empty($currentUser->booking_link)) 
                ? $currentUser->booking_link 
                : 'https://apoio19.com.br/agendar'; // Fallback para link corporativo da Apoio19

            // Preparar a lista de dores mapeadas no formato HTML
            $painPoints = [];
            if (!empty($lead->site_pain_points)) {
                $painPoints = is_string($lead->site_pain_points) 
                    ? json_decode($lead->site_pain_points, true) 
                    : $lead->site_pain_points;
            }
            if (!is_array($painPoints)) {
                $painPoints = [];
            }

            $painHtml = "";
            $hasRelevantPains = false;
            foreach ($painPoints as $pain) {
                if (isset($pain['type']) && ($pain['type'] === 'danger' || $pain['type'] === 'warning')) {
                    $hasRelevantPains = true;
                    $color = $pain['type'] === 'danger' ? '#ef4444' : '#f59e0b';
                    $painHtml .= "<div style='margin-bottom: 12px; font-family: sans-serif;'>";
                    $painHtml .= "<span style='color: {$color}; font-weight: bold; font-size: 14px;'>⚠️ " . htmlspecialchars($pain['title']) . "</span>";
                    $painHtml .= "<p style='color: #64748b; font-size: 13px; margin: 3px 0 0 0;'>" . htmlspecialchars($pain['description']) . "</p>";
                    $painHtml .= "</div>";
                }
            }

            // Fallback se não houver dores escaneadas
            if (!$hasRelevantPains) {
                $painHtml = "<div style='margin-bottom: 12px; font-family: sans-serif;'>";
                $painHtml .= "<span style='color: #3b82f6; font-weight: bold; font-size: 14px;'>ℹ️ Análise de Presença Digital</span>";
                $painHtml .= "<p style='color: #64748b; font-size: 13px; margin: 3px 0 0 0;'>Identificamos excelentes oportunidades para alavancar a conversão de novos clientes, otimizar a velocidade de resposta e estruturar canais automatizados de vendas integrados no seu site.</p>";
                $painHtml .= "</div>";
            }

            // Nome da empresa
            $companyName = !empty($lead->company) ? $lead->company : 'sua empresa';
            $leadName = !empty($lead->name) ? $lead->name : 'Prezado Cliente';
            $siteUrl = !empty($lead->source_extra) ? $lead->source_extra : '';

            $subject = "Diagnóstico de Performance e Oportunidades Digitais - " . ($lead->company ? $lead->company : $lead->name);

            // Gerar o e-mail HTML usando o template premium
            $body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f6fc; }
        .container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        .header { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: #ffffff; padding: 35px 25px; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.5px; color: #ffffff; }
        .header p { margin: 8px 0 0 0; opacity: 0.9; font-size: 14px; color: #ffffff; }
        .content { padding: 30px 25px; background-color: #ffffff; }
        .content p { font-size: 15px; color: #475569; margin-bottom: 20px; }
        .pain-list { background-color: #f8fafc; border-left: 4px solid #ef4444; border-radius: 4px; padding: 15px; margin: 20px 0; }
        .solution-box { background-color: #f0fdf4; border-left: 4px solid #22c55e; border-radius: 4px; padding: 15px; margin: 20px 0; font-family: sans-serif; }
        .btn-container { text-align: center; margin: 35px 0 20px 0; }
        .btn { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #ffffff !important; padding: 14px 28px; text-align: center; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 15px; display: inline-block; box-shadow: 0 4px 10px rgba(37,99,235,0.2); }
        .footer { text-align: center; padding: 25px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Diagnóstico de Presença Digital & Performance</h1>
            <p>Oportunidades de Conversão para {$companyName}</p>
        </div>
        <div class="content">
            <p>Olá, <strong>{$leadName}</strong>,</p>
            <p>Espero que este e-mail o encontre bem.</p>
            <p>Eu sou <strong>{$userData->nome}</strong> da <strong>Apoio19</strong>. Analisei detalhadamente a presença digital da <strong>{$companyName}</strong> e elaborei um diagnóstico técnico diretamente sobre o site da sua empresa (<strong>{$siteUrl}</strong>).</p>
            
            <p>Identificamos alguns pontos importantes que podem estar afetando o tempo de carregamento do seu site, o posicionamento orgânico no Google (SEO) e, principalmente, <strong>a taxa de conversão e atração de novos clientes</strong>. Veja abaixo o diagnóstico resumido das principais dores:</p>
            
            <div class="pain-list">
                {$painHtml}
            </div>

            <div class="solution-box">
                <strong style="color: #15803d; font-size: 14px; display: block; margin-bottom: 4px;">Como a Apoio19 resolve isso?</strong>
                <p style="color: #166534; font-size: 13px; margin: 0;">Nós da Apoio19 somos especialistas em aceleração e otimização de infraestrutura web, bem como na integração de canais de conversão imediata (como canais automatizados de WhatsApp e CRM de Atendimento). Conseguimos acelerar a velocidade do seu site, corrigir falhas de SEO e implementar fluxos integrados de atração e engajamento que aumentam suas conversões em até 40% já nas primeiras semanas.</p>
            </div>
            
            <p>Gostaria de propor uma breve conversa de 10 minutos para lhe apresentar os detalhes deste diagnóstico e soluções práticas aplicadas.</p>
            
            <div class="btn-container">
                <a href="{$bookingLink}" class="btn" target="_blank">Agendar Reunião Sem Compromisso</a>
            </div>
            
            <p style="margin-top: 30px;">Fico à total disposição e aguardo seu agendamento!</p>
            
            <p>Atenciosamente,<br>
            <strong>{$userData->nome}</strong><br>
            Apoio19 CRM</p>
        </div>
        <div class="footer">
            <p>Este e-mail foi gerado e enviado de forma personalizada através do Apoio19 CRM.<br>
            Apoio19 - Soluções de CRM e Integração WhatsApp. Todos os direitos reservados.</p>
        </div>
    </div>
</body>
</html>
HTML;

            return $this->successResponse([
                'subject' => $subject,
                'body' => $body,
                'to_email' => $lead->email,
                'to_name' => $lead->name
            ], "Template carregado com sucesso.", 200, $traceId);

        } catch (\Throwable $e) {
            return $this->errorResponse(500, "Erro ao obter template de primeiro contato.", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * Envia o e-mail de primeiro contato
     *
     * @param array $headers Headers da requisição
     * @param int $leadId ID do lead
     * @param array $input Payload com subject e body
     * @return array
     */
    public function sendFirstContactEmail(array $headers, int $leadId, array $input): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        // Check permission
        $this->requirePermission($userData, 'leads', 'edit');

        if (empty($input['subject']) || empty($input['body'])) {
            return $this->errorResponse(400, "Os campos 'subject' (assunto) e 'body' (conteúdo) são obrigatórios.", "BAD_REQUEST", $traceId);
        }

        try {
            $lead = Lead::findById($leadId);
            if (!$lead) {
                return $this->errorResponse(404, "Lead não encontrado.", "NOT_FOUND", $traceId);
            }

            if (empty($lead->email)) {
                return $this->errorResponse(400, "O lead não possui e-mail cadastrado.", "BAD_REQUEST", $traceId);
            }

            // Instanciar o EmailService e disparar o e-mail por SMTP
            $emailService = new \Apoio19\Crm\Services\EmailService();
            $sent = $emailService->send(
                $lead->email,
                $lead->name,
                $input['subject'],
                $input['body']
            );

            if ($sent) {
                // Registrar log de histórico
                \Apoio19\Crm\Models\HistoricoInteracoes::logAction(
                    $leadId,
                    null,
                    $userData->id,
                    "E-mail Enviado",
                    "E-mail de Primeiro Contato com diagnóstico de site enviado.\nAssunto: " . $input['subject']
                );

                return $this->successResponse(null, "E-mail enviado com sucesso!", 200, $traceId);
            } else {
                return $this->errorResponse(500, "Não foi possível disparar o e-mail por SMTP. Verifique as credenciais no .env.", "SEND_ERROR", $traceId);
            }

        } catch (\Throwable $e) {
            return $this->errorResponse(500, "Erro inesperado ao enviar o e-mail.", "UNEXPECTED_ERROR", $traceId, $this->debugDetails($e));
        }
    }
}
