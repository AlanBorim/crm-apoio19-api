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

        // Permite acesso para admin, gestor ou diretoria
        $allowedRoles = ['admin', 'gestor', 'diretoria'];
        if (!in_array($userData->funcao, $allowedRoles)) {
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

            // Campaigns
            $stmt = $pdo->query("SELECT id, name AS title, 'campaign' AS type, deleted_at FROM whatsapp_campaigns WHERE deleted_at IS NOT NULL");
            $deletedItems = array_merge($deletedItems, $stmt->fetchAll(PDO::FETCH_ASSOC));

            // Users
            $stmt = $pdo->query("SELECT id, name AS title, 'user' AS type, deleted_at FROM users WHERE deleted_at IS NOT NULL");
            $deletedItems = array_merge($deletedItems, $stmt->fetchAll(PDO::FETCH_ASSOC));

            // Kanban Columns
            $stmt = $pdo->query("SELECT id, nome AS title, 'kanban_column' AS type, deleted_at FROM kanban_colunas WHERE deleted_at IS NOT NULL");
            $deletedItems = array_merge($deletedItems, $stmt->fetchAll(PDO::FETCH_ASSOC));

            // User Tasks
            $stmt = $pdo->query("SELECT id, titulo AS title, 'user_task' AS type, deleted_at FROM tarefas_usuario WHERE deleted_at IS NOT NULL");
            $deletedItems = array_merge($deletedItems, $stmt->fetchAll(PDO::FETCH_ASSOC));

            // Client Projects
            $stmt = $pdo->query("SELECT id, name AS title, 'client_project' AS type, deleted_at FROM client_projects WHERE deleted_at IS NOT NULL");
            $deletedItems = array_merge($deletedItems, $stmt->fetchAll(PDO::FETCH_ASSOC));

            // Clients
            $stmt = $pdo->query("SELECT c.id, COALESCE(c.fantasy_name, c.corporate_name, l.name, comp.name, 'Cliente sem nome') AS title, 'client' AS type, c.deleted_at FROM clients c LEFT JOIN leads l ON c.lead_id = l.id LEFT JOIN companies comp ON c.company_id = comp.id WHERE c.deleted_at IS NOT NULL");
            $deletedItems = array_merge($deletedItems, $stmt->fetchAll(PDO::FETCH_ASSOC));

            // Whatsapp Templates
            $stmt = $pdo->query("SELECT id, name AS title, 'whatsapp_template' AS type, deleted_at FROM whatsapp_templates WHERE deleted_at IS NOT NULL");
            $deletedItems = array_merge($deletedItems, $stmt->fetchAll(PDO::FETCH_ASSOC));

            // Whatsapp Contacts
            $stmt = $pdo->query("SELECT id, name AS title, 'whatsapp_contact' AS type, deleted_at FROM whatsapp_contacts WHERE deleted_at IS NOT NULL");
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

        // Check permissions
        $allowedRoles = ['admin', 'gestor', 'diretoria'];
        if (!in_array($userData->funcao, $allowedRoles)) {
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
            case 'campaign':
                // Uses explicit namespace because it might not be imported at the top
                $success = \Apoio19\Crm\Models\WhatsappCampaign::restore($id);
                break;
            case 'user':
                $success = \Apoio19\Crm\Models\User::restore($id);
                break;
            case 'kanban_column':
                $success = \Apoio19\Crm\Models\KanbanColuna::restore($id);
                break;
            case 'user_task':
                $success = \Apoio19\Crm\Models\TarefaUsuario::restore($id);
                break;
            case 'client':
                $success = \Apoio19\Crm\Models\Client::restore($id);
                break;
            case 'client_project':
                $success = \Apoio19\Crm\Models\ClientProject::restore($id);
                break;
            case 'whatsapp_template':
                $success = \Apoio19\Crm\Models\WhatsappTemplate::restore($id);
                break;
            case 'whatsapp_contact':
                $success = \Apoio19\Crm\Models\WhatsappContact::restore($id);
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
