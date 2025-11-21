<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\WhatsappTemplate;
use Apoio19\Crm\Middleware\AuthMiddleware;

class WhatsappTemplateController
{
    private WhatsappTemplate $templateModel;
    private AuthMiddleware $authMiddleware;

    public function __construct()
    {
        $this->templateModel = new WhatsappTemplate();
        $this->authMiddleware = new AuthMiddleware();
    }

    public function index(array $headers): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária"];
        }

        try {
            $filters = [];
            if (isset($_GET['status'])) $filters['status'] = $_GET['status'];
            if (isset($_GET['category'])) $filters['category'] = $_GET['category'];
            
            $templates = $this->templateModel->getAll($filters);
            
            http_response_code(200);
            return ["success" => true, "data" => $templates];
        } catch (\Exception $e) {
            http_response_code(500);
            return ["error" => "Erro ao listar templates"];
        }
    }

    public function show(array $headers, int $id): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária"];
        }

        try {
            $template = $this->templateModel->findById($id);
            
            if (!$template) {
                http_response_code(404);
                return ["error" => "Template não encontrado"];
            }
            
            http_response_code(200);
            return ["success" => true, "data" => $template];
        } catch (\Exception $e) {
            http_response_code(500);
            return ["error" => "Erro ao buscar template"];
        }
    }

    public function store(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers, ["admin", "gerente"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária"];
        }

        try {
            if (empty($requestData['name']) || empty($requestData['template_id'])) {
                http_response_code(400);
                return ["error" => "Dados obrigatórios ausentes"];
            }
            
            $id = $this->templateModel->create($requestData);
            
            http_response_code(201);
            return ["success" => true, "data" => ["id" => $id], "message" => "Template criado"];
        } catch (\Exception $e) {
            http_response_code(500);
            return ["error" => "Erro ao criar template"];
        }
    }

    public function update(array $headers, int $id, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers, ["admin", "gerente"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária"];
        }

        try {
            $template = $this->templateModel->findById($id);
            if (!$template) {
                http_response_code(404);
                return ["error" => "Template não encontrado"];
            }
            
            $this->templateModel->update($id, $requestData);
            
            http_response_code(200);
            return ["success" => true, "message" => "Template atualizado"];
        } catch (\Exception $e) {
            http_response_code(500);
            return ["error" => "Erro ao atualizar template"];
        }
    }

    public function delete(array $headers, int $id): array
    {
        $userData = $this->authMiddleware->handle($headers, ["admin"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Apenas administradores podem deletar templates"];
        }

        try {
            $this->templateModel->delete($id);
            http_response_code(200);
            return ["success" => true, "message" => "Template deletado"];
        } catch (\Exception $e) {
            http_response_code(500);
            return ["error" => "Erro ao deletar template"];
        }
    }
}
