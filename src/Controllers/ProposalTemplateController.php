<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Database;
use PDO;
use PDOException;

class ProposalTemplateController
{
    /**
     * Get all active proposal templates
     */
    public function index(): void
    {
        try {
            $pdo = Database::getInstance();

            $sql = "SELECT id, nome, descricao, conteudo_padrao, condicoes_padrao, created_at, updated_at 
                    FROM proposal_templates 
                    WHERE ativo = 1 
                    ORDER BY nome ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute();

            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => $templates
            ]);
        } catch (PDOException $e) {
            error_log("Erro ao buscar templates: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao buscar templates de propostas'
            ]);
        }
    }

    /**
     * Get template by ID
     */
    public function show(int $id): void
    {
        try {
            $pdo = Database::getInstance();

            $sql = "SELECT id, nome, descricao, conteudo_padrao, condicoes_padrao, observacoes, created_at, updated_at 
                    FROM proposal_templates 
                    WHERE id = :id AND ativo = 1";

            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            $template = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($template) {
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'data' => $template
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Template não encontrado'
                ]);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar template: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao buscar template'
            ]);
        }
    }

    /**
     * Create new template
     */
    public function store(): void
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true);

            if (empty($data['nome'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Nome do template é obrigatório'
                ]);
                return;
            }

            $pdo = Database::getInstance();

            $sql = "INSERT INTO proposal_templates (nome, descricao, conteudo_padrao, condicoes_padrao, observacoes, ativo) 
                    VALUES (:nome, :descricao, :conteudo_padrao, :condicoes_padrao, :observacoes, :ativo)";

            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':nome', $data['nome']);
            $stmt->bindParam(':descricao', $data['descricao'] ?? null);
            $stmt->bindParam(':conteudo_padrao', $data['conteudo_padrao'] ?? null);
            $stmt->bindParam(':condicoes_padrao', $data['condicoes_padrao'] ?? null);
            $stmt->bindParam(':observacoes', $data['observacoes'] ?? null);
            $ativo = $data['ativo'] ?? 1;
            $stmt->bindParam(':ativo', $ativo, PDO::PARAM_INT);

            $stmt->execute();
            $templateId = (int)$pdo->lastInsertId();

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Template criado com sucesso',
                'data' => ['id' => $templateId]
            ]);
        } catch (PDOException $e) {
            error_log("Erro ao criar template: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao criar template'
            ]);
        }
    }

    /**
     * Update template
     */
    public function update(int $id): void
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true);

            $pdo = Database::getInstance();

            $fields = [];
            $params = [':id' => $id];

            if (isset($data['nome'])) {
                $fields[] = "nome = :nome";
                $params[':nome'] = $data['nome'];
            }
            if (isset($data['descricao'])) {
                $fields[] = "descricao = :descricao";
                $params[':descricao'] = $data['descricao'];
            }
            if (isset($data['conteudo_padrao'])) {
                $fields[] = "conteudo_padrao = :conteudo_padrao";
                $params[':conteudo_padrao'] = $data['conteudo_padrao'];
            }
            if (isset($data['condicoes_padrao'])) {
                $fields[] = "condicoes_padrao = :condicoes_padrao";
                $params[':condicoes_padrao'] = $data['condicoes_padrao'];
            }
            if (isset($data['observacoes'])) {
                $fields[] = "observacoes = :observacoes";
                $params[':observacoes'] = $data['observacoes'];
            }
            if (isset($data['ativo'])) {
                $fields[] = "ativo = :ativo";
                $params[':ativo'] = $data['ativo'];
            }

            if (empty($fields)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Nenhum campo para atualizar'
                ]);
                return;
            }

            $sql = "UPDATE proposal_templates SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Template atualizado com sucesso'
            ]);
        } catch (PDOException $e) {
            error_log("Erro ao atualizar template: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao atualizar template'
            ]);
        }
    }

    /**
     * Delete (deactivate) template
     */
    public function destroy(int $id): void
    {
        try {
            $pdo = Database::getInstance();

            // Soft delete - apenas marca como inativo
            $sql = "UPDATE proposal_templates SET ativo = 0 WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Template desativado com sucesso'
            ]);
        } catch (PDOException $e) {
            error_log("Erro ao desativar template: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao desativar template'
            ]);
        }
    }
}
