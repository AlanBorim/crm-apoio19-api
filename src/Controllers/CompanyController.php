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
        $userData = $this->authMiddleware->handle($headers, ["comercial", "admin"]);
        if (!$userData) {
            http_response_code(401); // Or 403
            return ["error" => "Acesso não autorizado ou autenticação necessária."];
        }

        // Basic Validation
        if (empty($requestData["nome"])) {
            http_response_code(400);
            return ["error" => "O nome da empresa é obrigatório."];
        }
        // Add more validation (e.g., CNPJ format, email format)

        try {
            $companyId = Company::create($requestData);

            if ($companyId) {
                http_response_code(201); // Created
                $newCompany = Company::findById($companyId);
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
        $userData = $this->authMiddleware->handle($headers, ["comercial", "admin"]);
        if (!$userData) {
            http_response_code(401); // Or 403
            return ["error" => "Acesso não autorizado ou autenticação necessária."];
        }

        // Add validation for requestData

        try {
            $companyExists = Company::findById($id);
            if (!$companyExists) {
                http_response_code(404);
                return ["error" => "Empresa não encontrada para atualização."];
            }

            // Add logic here to check if the user ($userData->userId) is allowed to update this company

            if (Company::update($id, $requestData)) {
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
        $userData = $this->authMiddleware->handle($headers, ["Admin"]);
        if (!$userData) {
            http_response_code(403); // Forbidden
            return ["error" => "Acesso negado. Permissão de Administrador necessária."];
        }

        try {
            $companyExists = Company::findById($id);
            if (!$companyExists) {
                http_response_code(404);
                return ["error" => "Empresa não encontrada para exclusão."];
            }

            // Consider adding checks here: e.g., cannot delete if there are active proposals/contracts?

            if (Company::delete($id)) {
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
