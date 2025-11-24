<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Proposal;
use Apoio19\Crm\Models\Lead;
use Apoio19\Crm\Models\User;
use Apoio19\Crm\Services\PdfService;
use Apoio19\Crm\Services\EmailService;
use Apoio19\Crm\Middleware\AuthMiddleware;

class ProposalController
{
    private AuthMiddleware $authMiddleware;
    private PdfService $pdfService;
    private EmailService $emailService;

    public function __construct()
    {
        $this->authMiddleware = new AuthMiddleware();
        $this->pdfService = new PdfService();
        $this->emailService = new EmailService();
    }

    /**
     * List all proposals with filters.
     */
    public function index(array $headers, array $queryParams = []): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária."];
        }

        $filters = [];
        if (isset($queryParams["status"])) {
            $filters["status"] = $queryParams["status"];
        }
        if (isset($queryParams["responsavel_id"])) {
            $filters["responsavel_id"] = (int)$queryParams["responsavel_id"];
        }

        $page = isset($queryParams["page"]) ? (int)$queryParams["page"] : 1;
        $limit = isset($queryParams["limit"]) ? (int)$queryParams["limit"] : 25;
        $offset = ($page - 1) * $limit;

        $proposals = Proposal::findAll($filters, $limit, $offset);
        $total = Proposal::countAll($filters);

        http_response_code(200);
        return [
            "success" => true,
            "data" => $proposals,
            "pagination" => [
                "page" => $page,
                "limit" => $limit,
                "total" => $total,
                "totalPages" => ceil($total / $limit)
            ]
        ];
    }

    /**
     * Create new proposal.
     */
    public function store(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin", "Comercial", "gerente"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária."];
        }

        if (empty($requestData["titulo"])) {
            http_response_code(400);
            return ["error" => "Título é obrigatório."];
        }

        $requestData["responsavel_id"] = $requestData["responsavel_id"] ?? $userData->userId;
        $items = $requestData["itens"] ?? [];

        $proposalId = Proposal::create($requestData, $items);

        if ($proposalId) {
            $newProposal = Proposal::findById($proposalId);
            http_response_code(201);
            return [
                "success" => true,
                "message" => "Proposta criada com sucesso.",
                "data" => $newProposal
            ];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao criar proposta."];
        }
    }

    /**
     * Show proposal details.
     */
    public function show(array $headers, int $proposalId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária."];
        }

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) {
            http_response_code(404);
            return ["error" => "Proposta não encontrada."];
        }

        $items = Proposal::getItems($proposalId);
        $history = Proposal::getHistory($proposalId);

        http_response_code(200);
        return [
            "success" => true,
            "data" => [
                "proposta" => $proposal,
                "itens" => $items,
                "historico" => $history
            ]
        ];
    }

    /**
     * Update proposal.
     */
    public function update(array $headers, int $proposalId, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin", "Comercial", "gerente"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária."];
        }

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) {
            http_response_code(404);
            return ["error" => "Proposta não encontrada."];
        }

        $items = $requestData["itens"] ?? [];
        $updated = Proposal::update($proposalId, $requestData, $items, $userData->userId);

        if ($updated) {
            $updatedProposal = Proposal::findById($proposalId);
            http_response_code(200);
            return [
                "success" => true,
                "message" => "Proposta atualizada com sucesso.",
                "data" => $updatedProposal
            ];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao atualizar proposta."];
        }
    }

    /**
     * Delete proposal.
     */
    public function destroy(array $headers, int $proposalId): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Apenas administradores podem excluir propostas."];
        }

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) {
            http_response_code(404);
            return ["error" => "Proposta não encontrada."];
        }

        if (Proposal::delete($proposalId)) {
            http_response_code(200);
            return [
                "success" => true,
                "message" => "Proposta excluída com sucesso."
            ];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao excluir proposta."];
        }
    }

    /**
     * Generate PDF for proposal.
     */
    public function generatePdf(array $headers, int $proposalId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária."];
        }

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) {
            http_response_code(404);
            return ["error" => "Proposta não encontrada."];
        }

        try {
            $pdfPath = $this->pdfService->generateProposalPdf($proposalId);
            
            if ($pdfPath) {
                Proposal::addHistory($proposalId, $userData->userId, "PDF Gerado", "PDF da proposta foi gerado.");
                
                http_response_code(200);
                return [
                    "success" => true,
                    "message" => "PDF gerado com sucesso.",
                    "pdf_path" => $pdfPath
                ];
            } else {
                http_response_code(500);
                return ["error" => "Falha ao gerar PDF."];
            }
        } catch (\Exception $e) {
            error_log("Erro ao gerar PDF da proposta {$proposalId}: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao gerar PDF."];
        }
    }

    /**
     * Send proposal via email with PDF attachment.
     */
    public function sendProposal(array $headers, int $proposalId): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin", "Comercial", "gerente"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária."];
        }

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) {
            http_response_code(404);
            return ["error" => "Proposta não encontrada."];
        }

        // Generate PDF if not exists
        if (empty($proposal->pdf_path) || !file_exists($proposal->pdf_path)) {
            $pdfPath = $this->pdfService->generateProposalPdf($proposalId);
            if (!$pdfPath) {
                http_response_code(500);
                return ["error" => "Falha ao gerar PDF."];
            }
        } else {
            $pdfPath = $proposal->pdf_path;
        }

        // Get client data
        $clientData = $this->getClientData($proposal);
        if (empty($clientData['email'])) {
            http_response_code(400);
            return ["error" => "E-mail do cliente não encontrado."];
        }

        // Get manager email
        $managerEmail = null;
        $responsibleName = 'Não definido';
        if ($proposal->responsavel_id) {
            $manager = User::findById($proposal->responsavel_id);
            if ($manager) {
                $managerEmail = $manager->email;
                $responsibleName = $manager->name;
            }
        }

        // Format validity date
        $validityDate = $proposal->data_validade ? date('d/m/Y', strtotime($proposal->data_validade)) : 'Não definida';

        // Send email
        $sent = $this->emailService->sendProposal(
            $clientData['email'],
            $clientData['contact_name'] ?? 'Cliente',
            $proposal->titulo,
            $proposal->id,
            $proposal->valor_total,
            $validityDate,
            $responsibleName,
            $pdfPath,
            $managerEmail
        );

        if ($sent) {
            // Update proposal status
            Proposal::update($proposalId, [
                'status' => 'enviada',
                'data_envio' => date('Y-m-d H:i:s')
            ], [], $userData->userId);

            // Add history
            Proposal::addHistory(
                $proposalId,
                $userData->userId,
                "Proposta Enviada",
                "Enviada para {$clientData['email']}"
            );

            http_response_code(200);
            return [
                "success" => true,
                "message" => "Proposta enviada com sucesso.",
                "sent_to" => $clientData['email'],
                "cc" => $managerEmail
            ];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao enviar e-mail."];
        }
    }

    /**
     * Get client data from proposal (lead, contact, or company).
     */
    private function getClientData($proposal): array
    {
        $data = [
            'company_name' => null,
            'contact_name' => null,
            'email' => null,
            'phone' => null,
        ];

        // Try to get from lead first
        if ($proposal->lead_id) {
            $lead = Lead::findById($proposal->lead_id);
            if ($lead) {
                $data['contact_name'] = $lead->name;
                $data['company_name'] = $lead->company;
                $data['email'] = $lead->email;
                $data['phone'] = $lead->phone;
            }
        }

        return $data;
    }
}
