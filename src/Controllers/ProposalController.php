<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Proposal;
use Apoio19\Crm\Models\ProposalItem;
use Apoio19\Crm\Models\HistoricoPropostas; // Assuming this model exists for logging
use Apoio19\Crm\Services\PdfService;
use Apoio19\Crm\Middleware\AuthMiddleware;
use Apoio19\Crm\Services\NotificationService; // Import NotificationService

// Placeholder for Request/Response handling
class ProposalController
{
    private AuthMiddleware $authMiddleware;
    private PdfService $pdfService;
    private NotificationService $notificationService; // Add NotificationService instance

    public function __construct()
    {
        $this->authMiddleware = new AuthMiddleware();
        $this->pdfService = new PdfService();
        $this->notificationService = new NotificationService(); // Instantiate NotificationService
    }

    /**
     * List proposals based on filters.
     *
     * @param array $headers Request headers.
     * @param array $queryParams Filters (e.g., status, responsavel_id, lead_id).
     * @return array JSON response.
     */
    public function index(array $headers, array $queryParams = []): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        // Basic filtering example (add more as needed)
        $filters = [];
        if (isset($queryParams["status"])) {
            $filters["status"] = $queryParams["status"];
        }
        if (isset($queryParams["responsavel_id"])) {
            $filters["responsavel_id"] = (int)$queryParams["responsavel_id"];
        }
        // Add role-based filtering if necessary (e.g., non-admins only see their own proposals)
        // if ($userData->role !== "Admin") {
        //     $filters["responsavel_id"] = $userData->userId;
        // }

        $proposals = Proposal::findBy($filters);
        http_response_code(200);
        return ["data" => $proposals];
    }

    /**
     * Create a new proposal.
     *
     * @param array $headers Request headers.
     * @param array $requestData Proposal data (titulo, lead_id, etc.).
     * @return array JSON response.
     */
    public function store(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin", "Comercial"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária ou permissão insuficiente."];
        }

        if (empty($requestData["titulo"])) {
            http_response_code(400);
            return ["error" => "O título da proposta é obrigatório."];
        }
        // Add more validation

        // Set creator/responsible if not provided?
        $requestData["responsavel_id"] = $requestData["responsavel_id"] ?? $userData->userId;

        $proposalId = Proposal::create($requestData);

        if ($proposalId) {
            $newProposal = Proposal::findById($proposalId);
            // Log history
            HistoricoPropostas::logAction($proposalId, $userData->userId, "Proposta Criada");
            
            // --- Notification (Optional: Notify admin/manager?) ---
            // Example: Notify admin about a new proposal
            // $adminUsers = User::findByRole("Admin"); // Assuming User model exists
            // $adminIds = array_map(fn($u) => $u->id, $adminUsers);
            // if (!empty($adminIds)) {
            //     $this->notificationService->createNotification(
            //         "nova_proposta",
            //         "Nova Proposta Criada: " . $newProposal->titulo,
            //         "Uma nova proposta \"{$newProposal->titulo}\" foi criada por {$userData->userName}.",
            //         $adminIds,
            //         "/propostas/" . $proposalId,
            //         "proposta",
            //         $proposalId
            //     );
            // }
            // --- End Notification ---
            
            http_response_code(201);
            return ["message" => "Proposta criada com sucesso.", "proposta" => $newProposal];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao criar proposta."];
        }
    }

    /**
     * Get details of a specific proposal.
     *
     * @param array $headers Request headers.
     * @param int $proposalId Proposal ID.
     * @return array JSON response.
     */
    public function show(array $headers, int $proposalId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) {
            http_response_code(404);
            return ["error" => "Proposta não encontrada."];
        }

        // Authorization check (e.g., can user view this proposal?)
        // if ($userData->role !== "Admin" && $proposal->responsavel_id !== $userData->userId) {
        //     http_response_code(403);
        //     return ["error" => "Acesso negado a esta proposta."];
        // }

        $items = ProposalItem::findByProposalId($proposalId);
        $history = HistoricoPropostas::findByProposalId($proposalId);

        http_response_code(200);
        return ["data" => ["proposta" => $proposal, "itens" => $items, "historico" => $history]];
    }

    /**
     * Update an existing proposal.
     *
     * @param array $headers Request headers.
     * @param int $proposalId Proposal ID.
     * @param array $requestData Data to update.
     * @return array JSON response.
     */
    public function update(array $headers, int $proposalId, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin", "Comercial"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária ou permissão insuficiente."];
        }

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) {
            http_response_code(404);
            return ["error" => "Proposta não encontrada para atualização."];
        }

        // Authorization check
        // if ($userData->role !== "Admin" && $proposal->responsavel_id !== $userData->userId) {
        //     http_response_code(403);
        //     return ["error" => "Você não tem permissão para atualizar esta proposta."];
        // }

        if (empty($requestData)) {
            http_response_code(400);
            return ["error" => "Nenhum dado fornecido para atualização."];
        }

        // --- Notification Check (Before Update) ---
        $oldStatus = $proposal->status;
        $newStatus = $requestData["status"] ?? $oldStatus;
        $statusChanged = ($newStatus !== $oldStatus);
        // --- End Notification Check ---

        if (Proposal::update($proposalId, $requestData)) {
            $updatedProposal = Proposal::findById($proposalId);
            $logDetails = "Proposta atualizada.";
            if ($statusChanged) {
                $logDetails = "Status da proposta alterado de {$oldStatus} para {$newStatus}.";
            }
            HistoricoPropostas::logAction($proposalId, $userData->userId, "Proposta Atualizada", $logDetails);

            // --- Notification (After Update) ---
            if ($statusChanged && $updatedProposal && $updatedProposal->responsavel_id) {
                $notificationTitle = "";
                $notificationMessage = "";
                $notifyUser = false;

                switch ($newStatus) {
                    case "aceita":
                        $notificationTitle = "Proposta Aceita: " . $updatedProposal->titulo;
                        $notificationMessage = "Parabéns! A proposta \"{$updatedProposal->titulo}\" foi marcada como ACEITA.";
                        $notifyUser = true;
                        break;
                    case "rejeitada":
                        $notificationTitle = "Proposta Rejeitada: " . $updatedProposal->titulo;
                        $notificationMessage = "A proposta \"{$updatedProposal->titulo}\" foi marcada como REJEITADA.";
                        $notifyUser = true;
                        break;
                    // Add notifications for other relevant status changes if needed (e.g., enviada)
                }

                if ($notifyUser) {
                    $this->notificationService->createNotification(
                        "proposta_status_alterado",
                        $notificationTitle,
                        $notificationMessage,
                        [$updatedProposal->responsavel_id],
                        "/propostas/" . $proposalId,
                        "proposta",
                        $proposalId,
                        true // Send email
                    );
                }
            }
            // --- End Notification ---

            http_response_code(200);
            return ["message" => "Proposta atualizada com sucesso.", "proposta" => $updatedProposal];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao atualizar proposta."];
        }
    }

    /**
     * Delete a proposal.
     *
     * @param array $headers Request headers.
     * @param int $proposalId Proposal ID.
     * @return array JSON response.
     */
    public function destroy(array $headers, int $proposalId): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin"]); // Only Admins can delete?
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária ou permissão insuficiente."];
        }

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) {
            http_response_code(404);
            return ["error" => "Proposta não encontrada para exclusão."];
        }

        // Consider business logic: Can proposals be deleted? Maybe only if in draft status?
        // if ($proposal->status !== "rascunho") {
        //     http_response_code(400);
        //     return ["error" => "Apenas propostas em rascunho podem ser excluídas."];
        // }

        if (Proposal::delete($proposalId)) {
            // Log history (optional, as proposal is gone)
            // HistoricoPropostas::logAction($proposalId, $userData->userId, "Proposta Excluída");
            http_response_code(200); // Or 204
            return ["message" => "Proposta excluída com sucesso."];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao excluir proposta."];
        }
    }

    /**
     * Generate PDF for a proposal.
     *
     * @param array $headers Request headers.
     * @param int $proposalId Proposal ID.
     * @return array JSON response (or potentially trigger file download).
     */
    public function generatePdf(array $headers, int $proposalId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) {
            http_response_code(404);
            return ["error" => "Proposta não encontrada."];
        }

        // Authorization check
        // ...

        $items = ProposalItem::findByProposalId($proposalId);
        
        // Define where to save the PDF
        $saveDir = "/home/ubuntu/crm_apoio19/storage/proposals"; // Ensure this dir exists and is writable
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0775, true);
        }
        $pdfFileName = "proposta_" . $proposalId . "_" . time() . ".pdf";
        $pdfPath = $saveDir . "/" . $pdfFileName;

        try {
            $success = $this->pdfService->generateProposalPdf($proposal, $items, $pdfPath);
            
            if ($success) {
                // Update proposal record with PDF path
                Proposal::update($proposalId, ["pdf_path" => $pdfPath]);
                HistoricoPropostas::logAction($proposalId, $userData->userId, "PDF da Proposta Gerado");
                
                http_response_code(200);
                // Return path or link for download
                return ["message" => "PDF da proposta gerado com sucesso.", "pdf_path" => $pdfPath]; 
            } else {
                http_response_code(500);
                return ["error" => "Falha ao gerar PDF da proposta."];
            }
        } catch (\Exception $e) {
            error_log("Erro ao gerar PDF da proposta {$proposalId}: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao gerar PDF da proposta."];
        }
    }

    // --- Methods for Proposal Items --- 

    /**
     * Add an item to a proposal.
     *
     * @param array $headers
     * @param int $proposalId
     * @param array $requestData (descricao, quantidade, valor_unitario)
     * @return array
     */
    public function addItem(array $headers, int $proposalId, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin", "Comercial"]);
        if (!$userData) { /* ... */ }

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) { /* 404 */ }
        // Authorization check...

        // Validation...
        if (empty($requestData["descricao"]) || !isset($requestData["quantidade"]) || !isset($requestData["valor_unitario"])) {
             http_response_code(400);
             return ["error" => "Descrição, quantidade e valor unitário são obrigatórios para o item."];
        }

        $itemId = ProposalItem::create($proposalId, $requestData);
        if ($itemId) {
            Proposal::recalculateTotal($proposalId); // Recalculate proposal total
            $newItem = ProposalItem::findById($itemId);
            HistoricoPropostas::logAction($proposalId, $userData->userId, "Item Adicionado à Proposta", "Item: " . $requestData["descricao"]);
            http_response_code(201);
            return ["message" => "Item adicionado com sucesso.", "item" => $newItem];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao adicionar item."];
        }
    }

    /**
     * Update an item in a proposal.
     *
     * @param array $headers
     * @param int $proposalId
     * @param int $itemId
     * @param array $requestData
     * @return array
     */
    public function updateItem(array $headers, int $proposalId, int $itemId, array $requestData): array
    {
         $userData = $this->authMiddleware->handle($headers, ["Admin", "Comercial"]);
        if (!$userData) { /* ... */ }

        $item = ProposalItem::findById($itemId);
        if (!$item || $item->proposta_id !== $proposalId) {
             http_response_code(404);
             return ["error" => "Item não encontrado ou não pertence a esta proposta."];
        }
        // Authorization check...

        if (empty($requestData)) { /* 400 */ }

        if (ProposalItem::update($itemId, $requestData)) {
            Proposal::recalculateTotal($proposalId); // Recalculate proposal total
            $updatedItem = ProposalItem::findById($itemId);
             HistoricoPropostas::logAction($proposalId, $userData->userId, "Item da Proposta Atualizado", "Item ID: " . $itemId);
            http_response_code(200);
            return ["message" => "Item atualizado com sucesso.", "item" => $updatedItem];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao atualizar item."];
        }
    }

    /**
     * Remove an item from a proposal.
     *
     * @param array $headers
     * @param int $proposalId
     * @param int $itemId
     * @return array
     */
    public function removeItem(array $headers, int $proposalId, int $itemId): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin", "Comercial"]);
        if (!$userData) { /* ... */ }

        $item = ProposalItem::findById($itemId);
        if (!$item || $item->proposta_id !== $proposalId) {
             http_response_code(404);
             return ["error" => "Item não encontrado ou não pertence a esta proposta."];
        }
        // Authorization check...

        if (ProposalItem::delete($itemId)) {
            Proposal::recalculateTotal($proposalId); // Recalculate proposal total
            HistoricoPropostas::logAction($proposalId, $userData->userId, "Item Removido da Proposta", "Item ID: " . $itemId);
            http_response_code(200);
            return ["message" => "Item removido com sucesso."];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao remover item."];
        }
    }
}

