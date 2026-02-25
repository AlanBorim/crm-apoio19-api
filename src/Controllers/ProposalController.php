<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Proposal;
use Apoio19\Crm\Models\Lead;
use Apoio19\Crm\Models\User;
use Apoio19\Crm\Models\Client;
use Apoio19\Crm\Services\PdfService;
use Apoio19\Crm\Services\EmailService;
use Apoio19\Crm\Middleware\AuthMiddleware;

class ProposalController extends BaseController
{
    private AuthMiddleware $authMiddleware;
    private PdfService $pdfService;
    private EmailService $emailService;

    public function __construct()
    {
        parent::__construct();
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

        // Check permission
        $this->requirePermission($userData, 'proposals', 'view');

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
        $traceId = bin2hex(random_bytes(8));

        try {
            $userData = $this->authMiddleware->handle($headers);
            if (!$userData) {
                http_response_code(401);
                return [
                    "success" => false,
                    "error" => "Autenticação necessária.",
                    "code" => "UNAUTHENTICATED",
                    "trace_id" => $traceId
                ];
            }

            // Check permission
            $this->requirePermission($userData, 'proposals', 'create');

            if (empty($requestData["titulo"])) {
                http_response_code(400);
                return [
                    "success" => false,
                    "error" => "Título é obrigatório.",
                    "code" => "VALIDATION_ERROR",
                    "trace_id" => $traceId
                ];
            }

            $requestData["responsavel_id"] = $requestData["responsavel_id"] ?? $userData->id;
            $items = $requestData["itens"] ?? [];

            // Dica: garanta que o PDO esteja em modo de exceção:
            // $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $proposalId = Proposal::create($requestData, $items);

            if (!$proposalId) {
                // Se a camada de modelo não lançar exceção, forçamos um erro aqui para capturar como "Unexpected"
                throw new \RuntimeException("Create retornou ID inválido/falsy.");
            }

            $newProposal = Proposal::findById($proposalId);

            // 🟢 AUDIT LOG - Log proposal creation
            $this->logAudit($userData->id, 'create', 'proposals', $proposalId, null, $newProposal);

            // Criar notificação
            try {
                $leadName = "Cliente";
                if ($newProposal->lead_id) {
                    $lead = Lead::findById($newProposal->lead_id);
                    if ($lead) {
                        $leadName = $lead->name;
                    }
                }

                $message = "Nova proposta criada para {$leadName}.";
                // Se lead_id não veio no request, assumimos que foi criado um novo lead (ou era intencional sem lead, mas Proposal::create lida com isso)
                if (empty($requestData['lead_id'])) {
                    $message = "Nova proposta e novo lead criados: {$leadName}.";
                }

                $this->notificationService->createNotification(
                    $userData->id,
                    "Nova Proposta",
                    $message,
                    "proposta",
                    "/propostas/{$proposalId}"
                );
            } catch (\Exception $e) {
                error_log("Erro ao criar notificação de proposta: " . $e->getMessage());
                // Não falhar a request por causa da notificação
            }

            http_response_code(201);
            return [
                "success" => true,
                "message" => "Proposta criada com sucesso.",
                "data" => $newProposal,
                "trace_id" => $traceId
            ];
        } catch (\PDOException $e) {
            $mapped = $this->mapPdoError($e);

            // Log detalhado (PSR-3 se disponível)
            if (property_exists($this, 'logger') && $this->logger) {
                $this->logger->error('DB error on Proposal::create', [
                    'trace_id' => $traceId,
                    'sqlstate' => $e->getCode(),
                    'errorInfo' => $e->errorInfo ?? null,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            } else {
                error_log("[{$traceId}] DB error: " . $e->getMessage());
            }

            http_response_code($mapped['status']);
            return [
                "success" => false,
                "error" => $mapped['message'],
                "code" => $mapped['code'],
                "trace_id" => $traceId,
                // Só expõe detalhes técnicos se APP_DEBUG=true
                "details" => $this->debugDetails($e),
            ];
        } catch (\Throwable $e) {
            // Log detalhado
            if (property_exists($this, 'logger') && $this->logger) {
                $this->logger->error('Unexpected error on Proposal::store', [
                    'trace_id' => $traceId,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'class' => get_class($e),
                ]);
            } else {
                error_log("[{$traceId}] Unexpected error: " . $e->getMessage());
            }

            http_response_code(500);
            return [
                "success" => false,
                "error" => "Erro interno ao criar proposta.",
                "code" => "UNEXPECTED_ERROR",
                "trace_id" => $traceId,
                "details" => $this->debugDetails($e),
            ];
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

        // Check permission
        $this->requirePermission($userData, 'proposals', 'view');

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) {
            http_response_code(404);
            return ["error" => "Proposta não encontrada."];
        }

        $items = Proposal::getItems($proposalId);

        // Map items to match frontend expectations
        $mappedItems = array_map(function ($item) {
            return [
                'id' => $item['id'],
                'descricao' => $item['description'],
                'quantidade' => (float)$item['quantity'],
                'valor_unitario' => (float)$item['unit_price'],
                'valor_total' => (float)$item['total_price']
            ];
        }, $items);

        $history = Proposal::getHistory($proposalId);

        http_response_code(200);
        return [
            "success" => true,
            "data" => [
                "proposta" => $proposal,
                "itens" => $mappedItems,
                "historico" => $history
            ]
        ];
    }

    /**
     * Update proposal.
     */
    public function update(array $headers, int $proposalId, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária."];
        }

        // Check permission
        $this->requirePermission($userData, 'proposals', 'edit');

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) {
            http_response_code(404);
            return ["error" => "Proposta não encontrada."];
        }

        $items = $requestData["itens"] ?? [];
        $updated = Proposal::update($proposalId, $requestData, $items, $userData->id);

        if ($updated) {
            $updatedProposal = Proposal::findById($proposalId);

            // 🟢 AUDIT LOG - Log proposal update
            $this->logAudit($userData->id, 'update', 'proposals', $proposalId, $proposal, $updatedProposal);

            // ---------------------------------------------------------
            // 🚀 AUTOMATION: Create Client if Proposal is Accepted
            // ---------------------------------------------------------
            $newStatus = $requestData['status'] ?? $proposal->status;
            if ($proposal->status !== 'aceita' && $newStatus === 'aceita' && $proposal->lead_id) {
                $existingClient = Client::findByLeadId($proposal->lead_id);

                if (!$existingClient) {
                    $lead = Lead::findById($proposal->lead_id);
                    if ($lead) {
                        $clientData = [
                            'lead_id' => $lead->id,
                            'status' => 'active',
                            'start_date' => date('Y-m-d'),
                            'notes' => "Cliente criado automaticamente a partir da aceitação da proposta #{$proposalId}",
                            // Map address fields from Lead
                            'zip_code' => $lead->cep,
                            'address' => $lead->address,
                            'city' => $lead->city,
                            'state' => $lead->state,
                            // Map other fields if possible
                            'fantasy_name' => $lead->company ?? $lead->name,
                            // Client model has: lead_id, company_id, contact_id... 
                            // It also has fiscal fields.
                        ];

                        // Check if Client model has create method (yes)
                        Client::create($clientData);
                    }
                }
            }
            // ---------------------------------------------------------

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
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Apenas administradores podem excluir propostas."];
        }

        // Check permission
        $this->requirePermission($userData, 'proposals', 'delete');

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) {
            http_response_code(404);
            return ["error" => "Proposta não encontrada."];
        }

        if (Proposal::delete($proposalId)) {
            // 🟢 AUDIT LOG - Log proposal deletion
            $this->logAudit($userData->id, 'delete', 'proposals', $proposalId, $proposal, null);

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

        // Check permission (view is enough to generate PDF, or create a specific 'export' permission)
        $this->requirePermission($userData, 'proposals', 'view');

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) {
            http_response_code(404);
            return ["error" => "Proposta não encontrada."];
        }

        try {
            $pdfPath = $this->pdfService->generateProposalPdf($proposalId);

            if ($pdfPath) {
                Proposal::addHistory($proposalId, $userData->id, "PDF Gerado", "PDF da proposta foi gerado.");

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
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária."];
        }

        // Check permission (sending proposal is part of editing/managing process)
        $this->requirePermission($userData, 'proposals', 'edit');

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) {
            http_response_code(404);
            return ["error" => "Proposta não encontrada."];
        }

        // Use uploaded PDF if available, otherwise generate one
        if (!empty($proposal->uploaded_pdf_path)) {
            $absPath = $this->relativeToAbsolutePath($proposal->uploaded_pdf_path);
            if ($absPath && file_exists($absPath)) {
                $pdfPath = $absPath;
            }
        }

        if (empty($pdfPath)) {
            if (!empty($proposal->pdf_path) && file_exists($proposal->pdf_path)) {
                $pdfPath = $proposal->pdf_path;
            } else {
                $pdfPath = $this->pdfService->generateProposalPdf($proposalId);
                if (!$pdfPath) {
                    http_response_code(500);
                    return ["error" => "Falha ao gerar PDF."];
                }
                // Save generated pdf_path to the proposal
                Proposal::update($proposalId, ['pdf_path' => $pdfPath], [], $userData->id);
            }
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
                $responsibleName = $manager->name ?? 'Não definido';
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
            ], [], $userData->id);

            // Add history
            Proposal::addHistory(
                $proposalId,
                $userData->id,
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
     * Upload an external PDF file for a proposal.
     */
    public function uploadPdf(array $headers, int $proposalId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária."];
        }

        $this->requirePermission($userData, 'proposals', 'edit');

        $proposal = Proposal::findById($proposalId);
        if (!$proposal) {
            http_response_code(404);
            return ["error" => "Proposta não encontrada."];
        }

        if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            $uploadError = $_FILES['pdf']['error'] ?? 'Arquivo não enviado';
            http_response_code(400);
            return ["error" => "Arquivo PDF inválido ou não enviado. Código de erro: {$uploadError}"];
        }

        $file = $_FILES['pdf'];

        // Validate MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if ($mimeType !== 'application/pdf') {
            http_response_code(400);
            return ["error" => "Apenas arquivos PDF são permitidos."];
        }

        // Validate file size (10 MB max)
        $maxSize = 10 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            http_response_code(400);
            return ["error" => "O arquivo PDF não pode ser maior que 10 MB."];
        }

        // Create upload directory in the dedicated storage path
        $uploadDir = '/var/www/html/crm/storage/proposals/' . $proposalId . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Remove old uploaded PDF if exists (stored path is relative, convert to absolute)
        if (!empty($proposal->uploaded_pdf_path)) {
            $oldAbsPath = $this->relativeToAbsolutePath($proposal->uploaded_pdf_path);
            if ($oldAbsPath && file_exists($oldAbsPath)) {
                @unlink($oldAbsPath);
            }
        }

        $filename = 'uploaded_' . time() . '.pdf';
        $destination = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            http_response_code(500);
            return ["error" => "Falha ao salvar o arquivo PDF."];
        }

        // Store RELATIVE path in DB so it can be served via /api/storage/proposals/ route
        $relativePath = '/storage/proposals/' . $proposalId . '/' . $filename;

        Proposal::update($proposalId, ['uploaded_pdf_path' => $relativePath], [], $userData->id);
        Proposal::addHistory($proposalId, $userData->id, 'PDF Externo Enviado', 'PDF externo enviado pelo usuário.');

        http_response_code(200);
        return [
            "success" => true,
            "message" => "PDF enviado com sucesso.",
            "uploaded_pdf_path" => $relativePath
        ];
    }

    /**
     * Convert a stored relative PDF path to an absolute filesystem path.
     * Supports: /storage/proposals/... and /uploads/...
     */
    private function relativeToAbsolutePath(string $relativePath): ?string
    {
        if (str_starts_with($relativePath, '/storage/')) {
            return '/var/www/html/crm/storage' . substr($relativePath, strlen('/storage'));
        }
        if (str_starts_with($relativePath, '/uploads/')) {
            return BASE_PATH . $relativePath;
        }
        // Already absolute
        if (str_starts_with($relativePath, '/')) {
            return $relativePath;
        }
        return null;
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
