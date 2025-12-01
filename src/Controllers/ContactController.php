<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Contact;
use Apoio19\Crm\Models\Company;
use Apoio19\Crm\Middleware\AuthMiddleware;
use Apoio19\Crm\Utils\DataTransformer;

// Placeholder for Request/Response handling & Validation
class ContactController extends BaseController
{
    private AuthMiddleware $authMiddleware;

    public function __construct()
    {
        $this->authMiddleware = new AuthMiddleware();
    }

    /**
     * List contacts with pagination.
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
            $contacts = Contact::findAll($limit, $offset);
            $totalContacts = Contact::countAll();
            $totalPages = ceil($totalContacts / $limit);

            http_response_code(200);
            return [
                "data" => $contacts,
                "pagination" => [
                    "total_items" => $totalContacts,
                    "total_pages" => $totalPages,
                    "current_page" => $page,
                    "per_page" => $limit
                ]
            ];
        } catch (\Exception $e) {
            error_log("Erro ao listar contatos: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao buscar contatos."];
        }
    }

    /**
     * Create a new contact.
     * Requires authentication (e.g., Comercial or Admin role).
     *
     * @param array $headers Request headers.
     * @param array $requestData Contact data.
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
            return ["error" => "O nome do contato é obrigatório."];
        }
        if (!empty($requestData["email"]) && !filter_var($requestData["email"], FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            return ["error" => "Formato de email inválido."];
        }
        // Validate if empresa_id exists if provided
        if (!empty($requestData["empresa_id"])) {
            if (!Company::findById((int)$requestData["empresa_id"])) {
                http_response_code(400);
                return ["error" => "Empresa associada não encontrada."];
            }
        }

        try {
            // Transform Portuguese field names to English for database
            $transformedData = DataTransformer::transformContactToEnglish($requestData);
            $contactId = Contact::create($transformedData);

            if ($contactId) {
                // Add initial history entry
                Contact::addInteractionHistory($contactId, $userData->userId, "nota", "Contato criado.");

                http_response_code(201); // Created
                $newContact = Contact::findById($contactId);
                return ["message" => "Contato criado com sucesso.", "contact" => $newContact];
            } else {
                http_response_code(500);
                return ["error" => "Não foi possível criar o contato."];
            }
        } catch (\Exception $e) {
            error_log("Erro ao criar contato: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao criar contato."];
        }
    }

    /**
     * Show a specific contact and its interaction history.
     * Requires authentication.
     *
     * @param array $headers Request headers.
     * @param int $id Contact ID.
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
            $contact = Contact::findById($id);
            if ($contact) {
                $history = Contact::getInteractionHistory($id);
                http_response_code(200);
                return ["contact" => $contact, "history" => $history];
            } else {
                http_response_code(404);
                return ["error" => "Contato não encontrado."];
            }
        } catch (\Exception $e) {
            error_log("Erro ao buscar contato ID {$id}: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao buscar contato."];
        }
    }

    /**
     * Update a contact.
     * Requires authentication.
     *
     * @param array $headers Request headers.
     * @param int $id Contact ID.
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

        // Basic Validation
        if (isset($requestData["email"]) && !empty($requestData["email"]) && !filter_var($requestData["email"], FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            return ["error" => "Formato de email inválido."];
        }
        // Validate if empresa_id exists if provided
        if (isset($requestData["empresa_id"]) && !empty($requestData["empresa_id"])) {
            if (!Company::findById((int)$requestData["empresa_id"])) {
                http_response_code(400);
                return ["error" => "Empresa associada não encontrada."];
            }
        }

        try {
            $contactExists = Contact::findById($id);
            if (!$contactExists) {
                http_response_code(404);
                return ["error" => "Contato não encontrado para atualização."];
            }

            // Add logic here to check if the user ($userData->userId) is allowed to update this contact

            // Transform Portuguese field names to English for database
            $transformedData = DataTransformer::transformContactToEnglish($requestData);

            if (Contact::update($id, $transformedData)) {
                // Add history entry for update
                Contact::addInteractionHistory($id, $userData->userId, "nota", "Contato atualizado.");

                http_response_code(200);
                $updatedContact = Contact::findById($id);
                return ["message" => "Contato atualizado com sucesso.", "contact" => $updatedContact];
            } else {
                http_response_code(500); // Or 304?
                return ["error" => "Não foi possível atualizar o contato."];
            }
        } catch (\Exception $e) {
            error_log("Erro ao atualizar contato ID {$id}: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao atualizar contato."];
        }
    }

    /**
     * Delete a contact.
     * Requires authentication (e.g., Admin role).
     *
     * @param array $headers Request headers.
     * @param int $id Contact ID.
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
            $contactExists = Contact::findById($id);
            if (!$contactExists) {
                http_response_code(404);
                return ["error" => "Contato não encontrado para exclusão."];
            }

            // Consider adding checks here: e.g., cannot delete if associated with active proposals?

            if (Contact::delete($id)) {
                http_response_code(200); // Or 204 No Content
                return ["message" => "Contato excluído com sucesso."];
            } else {
                http_response_code(500);
                return ["error" => "Não foi possível excluir o contato."];
            }
        } catch (\Exception $e) {
            error_log("Erro ao excluir contato ID {$id}: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao excluir contato."];
        }
    }

    /**
     * Add interaction history to a contact.
     *
     * @param array $headers
     * @param int $id Contact ID
     * @param array $requestData Expected keys: type, description
     * @return array
     */
    public function addInteraction(array $headers, int $id, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária."];
        }

        $tipo = $requestData["type"] ?? null;
        $descricao = $requestData["description"] ?? null;

        if (!$tipo || !$descricao) {
            http_response_code(400);
            return ["error" => "Tipo e descrição da interação são obrigatórios."];
        }
        // Validate interaction type if needed

        try {
            $contactExists = Contact::findById($id);
            if (!$contactExists) {
                http_response_code(404);
                return ["error" => "Contato não encontrado para adicionar interação."];
            }

            if (Contact::addInteractionHistory($id, $userData->userId, $tipo, $descricao)) {
                http_response_code(201);
                return ["message" => "Interação adicionada com sucesso."];
            } else {
                http_response_code(500);
                return ["error" => "Não foi possível adicionar a interação."];
            }
        } catch (\Exception $e) {
            error_log("Erro ao adicionar interação ao contato ID {$id}: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao adicionar interação."];
        }
    }
}
