<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Company;
use Apoio19\Crm\Middleware\AuthMiddleware;

// Placeholder for Request/Response handling & Validation
class CompanyController extends BaseController
{
    private AuthMiddleware $authMiddleware;

    public function __construct()
    {
        parent::__construct();
        $this->authMiddleware = new AuthMiddleware();
    }

    /**
     * List companies with pagination.
     * Requires authentication.
     *
     * @param array $headers Request headers.
     * @param array $queryParams Query parameters (e.g., page, limit).
     * @return array JSON response.
     */
    public function index(array $headers, array $queryParams = []): array
    {
        $userData = $this->authMiddleware->handle($headers); // Basic authentication check
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária."];
        }

        // Check permission (assuming companies are part of 'configuracoes' or have their own resource)
        // Since there isn't a specific 'companies' resource in the config, I'll use 'configuracoes' or 'leads' if it's related to clients.
        // Given it's likely "Configurações > Empresas" or similar, I'll use 'configuracoes'.
        // Wait, 'leads' has company info. But this controller seems to be for managing the user's own company or client companies?
        // If it's for CRM settings (My Company), it's 'configuracoes'.
        // If it's for Client Companies (B2B), it might be related to 'leads' or a new 'companies' resource.
        // Looking at the code, it has `Company::findAll`.
        // Let's assume it falls under 'configuracoes' for now as it seems administrative, or maybe 'leads' if it's client database.
        // Let's use 'configuracoes' based on typical CRM structures for "Company" settings, OR if it's a list of client companies, maybe 'leads'.
        // Actually, let's look at `ROLE_PERMISSIONS` in `PermissionService.php` again.
        // It has 'usuarios', 'leads', 'proposals', 'whatsapp', 'configuracoes', 'relatorios', 'kanban', 'dashboard'.
        // 'Company' is not explicitly there.
        // If this is for "Minha Empresa" settings, it's 'configuracoes'.
        // If it's for "Empresas (Clientes)", it should probably be under 'leads' or added as a new resource.
        // Let's assume 'configuracoes' for safety as it seems like admin work.
        $this->requirePermission($userData, 'configuracoes', 'view');

        // Basic Pagination
        $page = isset($queryParams["page"]) ? max(1, (int)$queryParams["page"]) : 1;
        $limit = isset($queryParams["limit"]) ? max(1, (int)$queryParams["limit"]) : 25;
        $offset = ($page - 1) * $limit;

        try {
            $companies = Company::findAll($limit, $offset);
            $totalCompanies = Company::countAll();
            $totalPages = ceil($totalCompanies / $limit);

            http_response_code(200);
            return [
                "data" => $companies,
                "pagination" => [
                    "total_items" => $totalCompanies,
                    "total_pages" => $totalPages,
                    "current_page" => $page,
                    "per_page" => $limit
                ]
            ];
        } catch (\Exception $e) {
            error_log("Erro ao listar empresas: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao buscar empresas."];
        }
    }

    /**
     * Create a new company.
     * Requires authentication (e.g., Comercial or Admin role).
     *
     * @param array $headers Request headers.
     * @param array $requestData Company data.
     * @return array JSON response.
     */
    public function store(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401); // Or 403
            return ["error" => "Acesso não autorizado ou autenticação necessária."];
        }

        // Check permission
        $this->requirePermission($userData, 'configuracoes', 'create');

        // Basic Validation
        if (empty($requestData["nome"])) {
            http_response_code(400);
            return ["error" => "O nome da empresa é obrigatório."];
        }
        // Add more validation (e.g., CNPJ format, email format)

        try {
            $companyId = Company::create($requestData);

            if ($companyId) {
                $newCompany = Company::findById($companyId);

                // 🟢 AUDIT LOG - Log company creation
                $this->logAudit($userData->id, 'create', 'companies', $companyId, null, $newCompany);

                http_response_code(201); // Created
                return ["message" => "Empresa criada com sucesso.", "company" => $newCompany];
            } else {
                http_response_code(500);
                return ["error" => "Não foi possível criar a empresa."];
            }
        } catch (\Exception $e) {
            error_log("Erro ao criar empresa: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao criar empresa."];
        }
    }

    /**
     * Show a specific company and its contacts.
     * Requires authentication.
     *
     * @param array $headers Request headers.
     * @param int $id Company ID.
     * @return array JSON response.
     */
    public function show(array $headers, int $id): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária."];
        }

        // Check permission
        $this->requirePermission($userData, 'configuracoes', 'view');

        try {
            $company = Company::findById($id);
            if ($company) {
                $contacts = Company::getContacts($id); // Fetch associated contacts
                // TODO: Fetch payment history and fiscal notes if needed
                http_response_code(200);
                return ["company" => $company, "contacts" => $contacts];
            } else {
                http_response_code(404);
                return ["error" => "Empresa não encontrada."];
            }
        } catch (\Exception $e) {
            error_log("Erro ao buscar empresa ID {$id}: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao buscar empresa."];
        }
    }

    /**
     * Update a company.
     * Requires authentication.
     *
     * @param array $headers Request headers.
     * @param int $id Company ID.
     * @param array $requestData Data to update.
     * @return array JSON response.
     */
    public function update(array $headers, int $id, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401); // Or 403
            return ["error" => "Acesso não autorizado ou autenticação necessária."];
        }

        // Check permission
        $this->requirePermission($userData, 'configuracoes', 'edit');

        // Add validation for requestData

        try {
            $companyExists = Company::findById($id);
            if (!$companyExists) {
                http_response_code(404);
                return ["error" => "Empresa não encontrada para atualização."];
            }

            // Add logic here to check if the user ($userData->userId) is allowed to update this company

            if (Company::update($id, $requestData)) {
                $updatedCompany = Company::findById($id);

                // 🟢 AUDIT LOG - Log company update
                $this->logAudit($userData->id, 'update', 'companies', $id, $companyExists, $updatedCompany);

                http_response_code(200);
                $updatedCompany = Company::findById($id);
                return ["message" => "Empresa atualizada com sucesso.", "company" => $updatedCompany];
            } else {
                http_response_code(500); // Or 304?
                return ["error" => "Não foi possível atualizar a empresa."];
            }
        } catch (\Exception $e) {
            error_log("Erro ao atualizar empresa ID {$id}: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao atualizar empresa."];
        }
    }

    /**
     * Delete a company.
     * Requires authentication (e.g., Admin role).
     *
     * @param array $headers Request headers.
     * @param int $id Company ID.
     * @return array JSON response.
     */
    public function destroy(array $headers, int $id): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária."];
        }

        // Check permission
        $this->requirePermission($userData, 'configuracoes', 'delete');

        try {
            $companyExists = Company::findById($id);
            if (!$companyExists) {
                http_response_code(404);
                return ["error" => "Empresa não encontrada para exclusão."];
            }

            // Consider adding checks here: e.g., cannot delete if there are active proposals/contracts?

            if (Company::delete($id)) {
                // 🟢 AUDIT LOG - Log company deletion
                $this->logAudit($userData->id, 'delete', 'companies', $id, $companyExists, null);

                http_response_code(200); // Or 204 No Content
                return ["message" => "Empresa excluída com sucesso."];
            } else {
                http_response_code(500);
                return ["error" => "Não foi possível excluir a empresa."];
            }
        } catch (\Exception $e) {
            error_log("Erro ao excluir empresa ID {$id}: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao excluir empresa."];
        }
    }
}
