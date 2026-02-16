<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\ClientProject;
use Apoio19\Crm\Models\Client;
use Apoio19\Crm\Middleware\AuthMiddleware;

class ClientProjectController extends BaseController
{
    private AuthMiddleware $authMiddleware;

    public function __construct()
    {
        parent::__construct();
        $this->authMiddleware = new AuthMiddleware();
    }

    /**
     * List projects for a specific client.
     * URL: /clients/{clientId}/projects
     * 
     * @param array $headers
     * @param int $clientId
     * @return array
     */
    public function index(array $headers, int $clientId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        $this->requirePermission($userData, 'clients', 'view'); // Assuming projects view perm is tied to client view

        $projects = ClientProject::findByClientId((int)$clientId);
        return $this->successResponse($projects);
    }

    /**
     * Get a specific project.
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

        $project = ClientProject::findById((int)$id);
        if ($project) {
            return $this->successResponse($project);
        } else {
            return $this->errorResponse(404, 'Project not found');
        }
    }

    /**
     * Create a new project.
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

        $this->requirePermission($userData, 'clients', 'edit'); // Creating project likely requires edit on client? Or create rights.
        // Let's use 'edit' on clients for now as adding a project modifies client state roughly.

        if (empty($requestData['client_id']) || empty($requestData['name'])) {
            return $this->errorResponse(400, 'Client ID and Name are required');
        }

        // Verify client exists
        if (!Client::findById((int)$requestData['client_id'])) {
            return $this->errorResponse(404, 'Client not found');
        }

        $id = ClientProject::create($requestData);
        if ($id) {
            $this->logAudit($userData->id, 'create', 'client_projects', $id, null, $requestData);
            return $this->successResponse(['id' => $id], 'Project created successfully', 201);
        } else {
            return $this->errorResponse(500, 'Failed to create project');
        }
    }

    /**
     * Update a project.
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

        if (ClientProject::update((int)$id, $requestData)) {
            $this->logAudit($userData->id, 'update', 'client_projects', $id, null, $requestData);
            return $this->successResponse(null, 'Project updated successfully');
        } else {
            return $this->errorResponse(500, 'Failed to update project');
        }
    }

    /**
     * Delete a project.
     *
     * @param array $headers
     * @param int $id
     * @return array
     */
    public function destroy(array $headers, int $id): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.");
        }

        $this->requirePermission($userData, 'clients', 'edit');

        if (ClientProject::delete((int)$id)) {
            $this->logAudit($userData->id, 'delete', 'client_projects', $id, null, null);
            return $this->successResponse(null, 'Project deleted successfully');
        } else {
            return $this->errorResponse(500, 'Failed to delete project');
        }
    }
}
