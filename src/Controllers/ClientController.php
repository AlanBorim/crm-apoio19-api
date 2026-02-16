<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Client;
use Apoio19\Crm\Models\Lead;
use Apoio19\Crm\Middleware\AuthMiddleware;

class ClientController extends BaseController
{
    private AuthMiddleware $authMiddleware;

    public function __construct()
    {
        parent::__construct();
        $this->authMiddleware = new AuthMiddleware();
    }

    /**
     * List all clients.
     * 
     * @param array $headers
     * @param array $queryParams
     * @return array
     */
    public function index(array $headers, array $queryParams = []): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        // Check permission (assuming 'clients' resource exists, otherwise fallback to 'leads' or generic)
        // If 'clients' resource is not yet defined in permissions, we might need to add it or use a default.
        // For now, let's assume 'clients' resource.
        $this->requirePermission($userData, 'clients', 'view');

        $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 25;
        $offset = isset($queryParams['offset']) ? (int)$queryParams['offset'] : 0;

        $clients = Client::findAll($limit, $offset);
        return $this->successResponse($clients);
    }

    /**
     * Get a specific client.
     *
     * @param array $headers
     * @param int $id
     * @return array
     */
    public function show(array $headers, int $id): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        $this->requirePermission($userData, 'clients', 'view');

        $client = Client::findById((int)$id);
        if ($client) {
            return $this->successResponse($client);
        } else {
            return $this->errorResponse(404, 'Client not found');
        }
    }

    /**
     * Create a new client.
     * 
     * @param array $headers
     * @param array $requestData
     * @return array
     */
    public function store(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        $this->requirePermission($userData, 'clients', 'create');

        // Basic validation
        if (empty($requestData['lead_id']) && empty($requestData['company_id']) && empty($requestData['contact_id'])) {
            return $this->errorResponse(400, 'Client must be linked to a Lead, Company, or Contact');
        }

        // Check if lead is already a client
        if (!empty($requestData['lead_id'])) {
            $existing = Client::findByLeadId((int)$requestData['lead_id']);
            if ($existing) {
                return $this->errorResponse(409, 'Lead is already a client', 'CONFLICT', null, ['client_id' => $existing->id]);
            }
        }

        $id = Client::create($requestData);
        if ($id) {
            // Audit log
            $this->logAudit($userData->id, 'create', 'clients', $id, null, $requestData);
            return $this->successResponse(['id' => $id], 'Client created successfully', 201);
        } else {
            return $this->errorResponse(500, 'Failed to create client');
        }
    }

    /**
     * Update a client.
     *
     * @param array $headers
     * @param int $id
     * @param array $requestData
     * @return array
     */
    public function update(array $headers, int $id, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        $this->requirePermission($userData, 'clients', 'edit');

        if (Client::update((int)$id, $requestData)) {
            // Audit log
            $this->logAudit($userData->id, 'update', 'clients', $id, null, $requestData);
            return $this->successResponse(null, 'Client updated successfully');
        } else {
            return $this->errorResponse(500, 'Failed to update client');
        }
    }

    /**
     * Promote a Lead to a Client.
     * Endpoint: POST /clients/promote/{leadId}
     *
     * @param array $headers
     * @param int $leadId
     * @return array
     */
    public function promoteLead(array $headers, int $leadId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        // Permission to promote might be specific or just create client
        $this->requirePermission($userData, 'clients', 'create');

        $lead = Lead::findById((int)$leadId);
        if (!$lead) {
            return $this->errorResponse(404, 'Lead not found');
        }

        // Check if already exists
        $existing = Client::findByLeadId((int)$leadId);
        if ($existing) {
            return $this->successResponse(['client_id' => $existing->id], 'Lead is already a client');
        }

        // Create client data from Lead
        // Assuming properties match what Lead model has (empresa_id, contato_id were seen in Lead.php)
        $clientData = [
            'lead_id' => $lead->id,
            'company_id' => $lead->empresa_id ?? null,
            'contact_id' => $lead->contato_id ?? null,
            'status' => 'active',
            'start_date' => date('Y-m-d'),
            'notes' => 'Promoted from Lead #' . $lead->id
        ];

        $id = Client::create($clientData);
        if ($id) {
            // Audit log
            $this->logAudit($userData->id, 'promote', 'clients', $id, null, $clientData);
            return $this->successResponse(['id' => $id], 'Lead promoted to Client successfully', 201);
        } else {
            return $this->errorResponse(500, 'Failed to promote lead');
        }
    }
}
