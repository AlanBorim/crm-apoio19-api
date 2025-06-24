<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Lead;
use Apoio19\Crm\Models\HistoricoInteracoes; // Assuming this model exists
use Apoio19\Crm\Middleware\AuthMiddleware;
use Apoio19\Crm\Services\NotificationService; // Import NotificationService
use League\Csv\Reader;
use League\Csv\Statement;

// Placeholder for Request/Response handling
class LeadController
{
    private AuthMiddleware $authMiddleware;
    private NotificationService $notificationService; // Add NotificationService instance

    public function __construct()
    {
        $this->authMiddleware = new AuthMiddleware();
        $this->notificationService = new NotificationService(); // Instantiate NotificationService
    }

    /**
     * List leads based on filters.
     *
     * @param array $headers Request headers.
     * @param array $queryParams Filters (e.g., status, responsavel_id, origem).
     * @return array JSON response.
     */
    public function index(array $headers, array $queryParams = []): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        // Basic filtering example
        $filters = [];
        if (isset($queryParams["status"])) {
            $filters["status"] = $queryParams["status"];
        }
        if (isset($queryParams["responsavel_id"])) {
            $filters["responsavel_id"] = (int)$queryParams["responsavel_id"];
        }
        if (isset($queryParams["origem"])) {
            $filters["origem"] = $queryParams["origem"];
        }
        // Add role-based filtering if necessary
        // if ($userData->role !== "Admin") {
        //     $filters["responsavel_id"] = $userData->userId;
        // }

        $leads = Lead::findBy($filters);
        http_response_code(200);
        return ["data" => $leads];
    }

    /**
     * Create a new lead.
     *
     * @param array $headers Request headers.
     * @param array $requestData Lead data (nome, email, telefone, etc.).
     * @return array JSON response.
     */
    public function store(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin", "Comercial"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária ou permissão insuficiente."];
        }

        if (empty($requestData["nome"])) {
            http_response_code(400);
            return ["error" => "O nome do lead é obrigatório."];
        }
        // Add more validation

        // Set creator/responsible if not provided?
        $requestData["responsavel_id"] = $requestData["responsavel_id"] ?? $userData->userId;

        $leadId = Lead::create($requestData);

        if ($leadId) {
            $newLead = Lead::findById($leadId);
            // Log history
            HistoricoInteracoes::logAction($leadId, null, $userData->userId, "Lead Criado", "Lead criado no sistema.");

            // --- Notification ---
            if ($newLead && $newLead->responsavel_id && $newLead->responsavel_id !== $userData->userId) { // Notify assignee if different from creator
                $this->notificationService->createNotification(
                    "novo_lead_atribuido",
                    "Novo Lead Atribuído: " . $newLead->nome,
                    "Você foi atribuído(a) ao novo lead: \"{$newLead->nome}\" criado por {$userData->userName}.", // Assuming userName is available
                    [$newLead->responsavel_id],
                    "/leads/" . $leadId, // Example link
                    "lead",
                    $leadId,
                    true // Send email
                );
            }
            // --- End Notification ---

            http_response_code(201);
            return ["message" => "Lead criado com sucesso.", "lead" => $newLead];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao criar lead."];
        }
    }

    /**
     * Get details of a specific lead.
     *
     * @param array $headers Request headers.
     * @param int $leadId Lead ID.
     * @return array JSON response.
     */
    public function show(array $headers, int $leadId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        $lead = Lead::findById($leadId);
        if (!$lead) {
            http_response_code(404);
            return ["error" => "Lead não encontrado."];
        }

        // Authorization check
        // if ($userData->role !== "Admin" && $lead->responsavel_id !== $userData->userId) {
        //     http_response_code(403);
        //     return ["error" => "Acesso negado a este lead."];
        // }

        $history = HistoricoInteracoes::findByLeadId($leadId);

        http_response_code(200);
        return ["data" => ["lead" => $lead, "historico" => $history]];
    }

    /**
     * Update an existing lead.
     *
     * @param array $headers Request headers.
     * @param int $leadId Lead ID.
     * @param array $requestData Data to update.
     * @return array JSON response.
     */
    public function update(array $headers, int $leadId, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin", "Comercial"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária ou permissão insuficiente."];
        }

        $lead = Lead::findById($leadId);
        if (!$lead) {
            http_response_code(404);
            return ["error" => "Lead não encontrado para atualização."];
        }

        // Authorization check
        // if ($userData->role !== "Admin" && $lead->responsavel_id !== $userData->userId) {
        //     http_response_code(403);
        //     return ["error" => "Você não tem permissão para atualizar este lead."];
        // }

        if (empty($requestData)) {
            http_response_code(400);
            return ["error" => "Nenhum dado fornecido para atualização."];
        }

        // --- Notification Check (Before Update) ---
        $oldAssigneeId = $lead->responsavel_id;
        $newAssigneeId = isset($requestData["responsavel_id"]) ? (int)$requestData["responsavel_id"] : $oldAssigneeId;
        $notifyAssignee = ($newAssigneeId !== $oldAssigneeId && $newAssigneeId !== null && $newAssigneeId !== $userData->userId);
        // --- End Notification Check ---

        if (Lead::update($leadId, $requestData)) {
            $updatedLead = Lead::findById($leadId);
            // Log history
            $logDetails = "Lead atualizado.";
            if (isset($requestData["status"]) && $requestData["status"] !== $lead->status) {
                $logDetails = "Status do lead alterado para " . $requestData["status"] . ".";
            }
            if (isset($requestData["responsavel_id"]) && $requestData["responsavel_id"] !== $lead->responsavel_id) {
                 $logDetails = "Responsável pelo lead alterado."; // Improve log message later
            }
            HistoricoInteracoes::logAction($leadId, null, $userData->userId, "Lead Atualizado", $logDetails);

            // --- Notification (After Update) ---
            if ($notifyAssignee && $updatedLead) {
                 $this->notificationService->createNotification(
                    "lead_atribuido",
                    "Lead Atribuído a Você: " . $updatedLead->nome,
                    "O lead \"{$updatedLead->nome}\" foi atribuído a você por {$userData->userName}.", // Assuming userName is available
                    [$newAssigneeId],
                    "/leads/" . $leadId, // Example link
                    "lead",
                    $leadId,
                    true // Send email
                );
            }
            // TODO: Add notification for status changes if needed (e.g., lead qualified)
            // --- End Notification ---

            http_response_code(200);
            return ["message" => "Lead atualizado com sucesso.", "lead" => $updatedLead];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao atualizar lead."];
        }
    }

    /**
     * Delete a lead.
     *
     * @param array $headers Request headers.
     * @param int $leadId Lead ID.
     * @return array JSON response.
     */
    public function destroy(array $headers, int $leadId): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin"]); // Only Admins can delete?
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária ou permissão insuficiente."];
        }

        $lead = Lead::findById($leadId);
        if (!$lead) {
            http_response_code(404);
            return ["error" => "Lead não encontrado para exclusão."];
        }

        // Consider implications: delete associated history? proposals? tasks?
        // Current setup uses ON DELETE SET NULL or CASCADE where appropriate.

        if (Lead::delete($leadId)) {
            // Log history (optional, as lead is gone)
            // HistoricoInteracoes::logAction($leadId, null, $userData->userId, "Lead Excluído", "Lead ID {$leadId} excluído.");
            http_response_code(200); // Or 204
            return ["message" => "Lead excluído com sucesso."];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao excluir lead."];
        }
    }

    /**
     * Import leads from a CSV file.
     *
     * @param array $headers Request headers.
     * @param string $csvFilePath Absolute path to the uploaded CSV file.
     * @param array $fieldMapping Associative array mapping CSV columns to DB fields (e.g., ["Nome Completo" => "nome", "Email Principal" => "email"]).
     * @param int|null $defaultResponsavelId Optional default assignee ID for imported leads.
     * @return array JSON response.
     */
    public function importCsv(array $headers, string $csvFilePath, array $fieldMapping, ?int $defaultResponsavelId = null): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin", "Comercial"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária ou permissão insuficiente."];
        }

        if (!file_exists($csvFilePath) || !is_readable($csvFilePath)) {
            http_response_code(400);
            return ["error" => "Arquivo CSV não encontrado ou ilegível."];
        }
        if (empty($fieldMapping) || !isset($fieldMapping["nome"])) { // Assuming 'nome' is the minimum required field from mapping
             http_response_code(400);
            return ["error" => "Mapeamento de campos inválido ou campo obrigatório 'nome' ausente."];
        }

        $importedCount = 0;
        $errorCount = 0;
        $errors = [];

        try {
            $csv = Reader::createFromPath($csvFilePath, 'r');
            $csv->setHeaderOffset(0); // Assumes first row is header
            $stmt = Statement::create();
            $records = $stmt->process($csv);

            foreach ($records as $record) {
                $leadData = [];
                foreach ($fieldMapping as $csvHeader => $dbField) {
                    if (isset($record[$csvHeader])) {
                        // Basic sanitization/trimming
                        $leadData[$dbField] = trim($record[$csvHeader]);
                    }
                }

                // Ensure required fields are present after mapping
                if (empty($leadData["nome"])) {
                    $errorCount++;
                    $errors[] = "Registro ignorado: campo 'nome' ausente ou vazio no CSV/mapeamento.";
                    continue;
                }

                // Set default assignee if provided and not mapped
                if ($defaultResponsavelId !== null && !isset($leadData["responsavel_id"])) {
                    $leadData["responsavel_id"] = $defaultResponsavelId;
                }
                
                // Set creator (optional, could be the importer)
                // $leadData["criador_id"] = $userData->userId;

                // Attempt to create the lead
                $leadId = Lead::create($leadData);
                if ($leadId) {
                    $importedCount++;
                    // Log history for imported lead
                    HistoricoInteracoes::logAction($leadId, null, $userData->userId, "Lead Importado", "Lead importado via CSV.");
                    // Optionally notify assignee if assigned during import
                    if (isset($leadData["responsavel_id"]) && $leadData["responsavel_id"] !== $userData->userId) {
                         $newLead = Lead::findById($leadId);
                         if ($newLead) {
                             $this->notificationService->createNotification(
                                "lead_importado_atribuido",
                                "Lead Importado Atribuído: " . $newLead->nome,
                                "O lead importado \"{$newLead->nome}\" foi atribuído a você.",
                                [$leadData["responsavel_id"]],
                                "/leads/" . $leadId,
                                "lead",
                                $leadId,
                                true
                            );
                         }
                    }
                } else {
                    $errorCount++;
                    $errors[] = "Falha ao importar registro: " . ($leadData["nome"] ?? "(sem nome)"); // Add more details if possible
                }
            }

            http_response_code(200);
            return [
                "message" => "Importação CSV concluída.",
                "imported_count" => $importedCount,
                "error_count" => $errorCount,
                "errors" => $errors
            ];

        } catch (\Exception $e) {
            error_log("Erro durante importação CSV de leads: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno durante a importação CSV.", "details" => $e->getMessage()];
        }
    }
}

