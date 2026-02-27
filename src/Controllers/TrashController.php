<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Database;
use Apoio19\Crm\Models\Lead;
use Apoio19\Crm\Models\Proposal;
use Apoio19\Crm\Models\Company;
use Apoio19\Crm\Models\Contact;
use Apoio19\Crm\Models\Tarefa;
use Apoio19\Crm\Middleware\AuthMiddleware;
use \PDO;

class TrashController extends BaseController
{
    private AuthMiddleware $authMiddleware;

    public function __construct()
    {
        parent::__construct();
        $this->authMiddleware = new AuthMiddleware();
    }

    /**
     * Get all soft-deleted items across different tables.
     *
     * @param array $headers Request headers.
     * @return array JSON response.
     */
    public function index(array $headers): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        // Only admins can access the trash
        if ($userData->role !== 'admin') {
            http_response_code(403);
            return ["error" => "Acesso negado."];
        }

        try {
            $pdo = Database::getInstance();
            $deletedItems = [];

            // Leads
            $stmt = $pdo->query("SELECT id, name AS title, 'lead' AS type, deleted_at FROM leads WHERE deleted_at IS NOT NULL");
            $deletedItems = array_merge($deletedItems, $stmt->fetchAll(PDO::FETCH_ASSOC));

            // Proposals
            $stmt = $pdo->query("SELECT id, titulo AS title, 'proposal' AS type, deleted_at FROM proposals WHERE deleted_at IS NOT NULL");
            $deletedItems = array_merge($deletedItems, $stmt->fetchAll(PDO::FETCH_ASSOC));

            // Companies
            $stmt = $pdo->query("SELECT id, name AS title, 'company' AS type, deleted_at FROM companies WHERE deleted_at IS NOT NULL");
            $deletedItems = array_merge($deletedItems, $stmt->fetchAll(PDO::FETCH_ASSOC));

            // Contacts
            $stmt = $pdo->query("SELECT id, name AS title, 'contact' AS type, deleted_at FROM contacts WHERE deleted_at IS NOT NULL");
            $deletedItems = array_merge($deletedItems, $stmt->fetchAll(PDO::FETCH_ASSOC));

            // Tasks
            $stmt = $pdo->query("SELECT id, titulo AS title, 'task' AS type, deleted_at FROM tarefas WHERE deleted_at IS NOT NULL");
            $deletedItems = array_merge($deletedItems, $stmt->fetchAll(PDO::FETCH_ASSOC));

            // Sort items by deleted_at descending
            usort($deletedItems, function ($a, $b) {
                return strtotime($b['deleted_at']) - strtotime($a['deleted_at']);
            });

            http_response_code(200);
            return ["data" => $deletedItems];
        } catch (\PDOException $e) {
            error_log("Erro ao buscar lixeira: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro ao buscar itens da lixeira."];
        }
    }

    /**
     * Restore a specific soft-deleted item.
     *
     * @param array $headers Request headers.
     * @param string $type The type of item (lead, proposal, company, contact, task).
     * @param int $id The ID of the item.
     * @return array JSON response.
     */
    public function restore(array $headers, string $type, int $id): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        // Only admins can restore items
        if ($userData->role !== 'admin') {
            http_response_code(403);
            return ["error" => "Acesso negado."];
        }

        $success = false;

        switch ($type) {
            case 'lead':
                $success = Lead::restore($id);
                break;
            case 'proposal':
                $success = Proposal::restore($id);
                break;
            case 'company':
                $success = Company::restore($id);
                break;
            case 'contact':
                $success = Contact::restore($id);
                break;
            case 'task':
                $success = Tarefa::restore($id);
                break;
            default:
                http_response_code(400);
                return ["error" => "Tipo de item inválido."];
        }

        if ($success) {
            // Optional: Log restoration audit using BaseController's method
            $this->logAudit($userData->id, 'restore', $type, $id, null, null);

            http_response_code(200);
            return ["message" => "Item restaurado com sucesso."];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao restaurar o item."];
        }
    }
}
