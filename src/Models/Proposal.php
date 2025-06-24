<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class Proposal
{
    public int $id;
    public string $titulo;
    public ?int $lead_id;
    public ?int $contato_id;
    public ?int $empresa_id;
    public ?int $responsavel_id;
    public ?string $descricao;
    public ?string $condicoes;
    public float $valor_total;
    public string $status;
    public ?string $data_envio;
    public ?string $data_validade;
    public ?string $pdf_path;
    public ?int $modelo_id;
    public string $criado_em;
    public string $atualizado_em;

    // Constants for proposal status
    const STATUS_RASCUNHO = 'rascunho';
    const STATUS_ENVIADA = 'enviada';
    const STATUS_ACEITA = 'aceita';
    const STATUS_REJEITADA = 'rejeitada';
    const STATUS_EM_NEGOCIACAO = 'em_negociacao';
    const STATUS_CANCELADA = 'cancelada';

    /**
     * Find a proposal by ID.
     *
     * @param int $id
     * @return Proposal|null
     */
    public static function findById(int $id): ?Proposal
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT * FROM propostas WHERE id = :id LIMIT 1");
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
            $proposalData = $stmt->fetch();

            if ($proposalData) {
                return self::hydrate($proposalData);
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar proposta por ID: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Get all proposals (with basic pagination and optional filters).
     *
     * @param array $filters (e.g., ["status" => "enviada", "responsavel_id" => 5])
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function findAll(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $proposals = [];
        $sql = "SELECT p.*, l.nome as lead_nome, c.nome as contato_nome, e.nome as empresa_nome, u.nome as responsavel_nome 
               FROM propostas p
               LEFT JOIN leads l ON p.lead_id = l.id
               LEFT JOIN contatos c ON p.contato_id = c.id
               LEFT JOIN empresas e ON p.empresa_id = e.id
               LEFT JOIN usuarios u ON p.responsavel_id = u.id";
        $whereClauses = [];
        $params = [];

        if (!empty($filters['status'])) {
            $whereClauses[] = "p.status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['responsavel_id'])) {
            $whereClauses[] = "p.responsavel_id = :responsavel_id";
            $params[':responsavel_id'] = (int)$filters['responsavel_id'];
        }
        // Add more filters as needed (e.g., date range, lead_id)

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        $sql .= " ORDER BY p.criado_em DESC LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            
            // Bind parameters dynamically
            foreach ($params as $key => &$val) {
                 // Determine the PDO type based on the key or value type
                 $paramType = PDO::PARAM_STR;
                 if ($key === ':limit' || $key === ':offset' || $key === ':responsavel_id') {
                     $paramType = PDO::PARAM_INT;
                 }
                 $stmt->bindParam($key, $val, $paramType);
            }
            unset($val); // Unset reference

            $stmt->execute();
            $results = $stmt->fetchAll();

            foreach ($results as $proposalData) {
                // We don't use hydrate here because we have joined data
                $proposals[] = $proposalData; 
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar todas as propostas: " . $e->getMessage());
        }
        return $proposals;
    }

    /**
     * Get total count of proposals (with optional filters).
     *
     * @param array $filters
     * @return int
     */
    public static function countAll(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) FROM propostas p";
        $whereClauses = [];
        $params = [];

        if (!empty($filters['status'])) {
            $whereClauses[] = "p.status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['responsavel_id'])) {
            $whereClauses[] = "p.responsavel_id = :responsavel_id";
            $params[':responsavel_id'] = (int)$filters['responsavel_id'];
        }
        // Add more filters matching findAll

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Erro ao contar propostas: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Create a new proposal.
     *
     * @param array $data Associative array of proposal data.
     * @param array $items Array of proposal items.
     * @return int|false The ID of the new proposal or false on failure.
     */
    public static function create(array $data, array $items = []): int|false
    {
        $sql = "INSERT INTO propostas (titulo, lead_id, contato_id, empresa_id, responsavel_id, descricao, condicoes, valor_total, status, data_validade, modelo_id) 
                VALUES (:titulo, :lead_id, :contato_id, :empresa_id, :responsavel_id, :descricao, :condicoes, :valor_total, :status, :data_validade, :modelo_id)";
        
        $pdo = Database::getInstance();
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare($sql);

            $valorTotal = self::calculateTotalValue($items);
            $status = $data['status'] ?? self::STATUS_RASCUNHO;

            // Bind parameters
            $stmt->bindParam(":titulo", $data['titulo']);
            $stmt->bindParam(":lead_id", $data['lead_id'], PDO::PARAM_INT);
            $stmt->bindParam(":contato_id", $data['contato_id'], PDO::PARAM_INT);
            $stmt->bindParam(":empresa_id", $data['empresa_id'], PDO::PARAM_INT);
            $stmt->bindParam(":responsavel_id", $data['responsavel_id'], PDO::PARAM_INT);
            $stmt->bindParam(":descricao", $data['descricao']);
            $stmt->bindParam(":condicoes", $data['condicoes']);
            $stmt->bindParam(":valor_total", $valorTotal);
            $stmt->bindParam(":status", $status);
            $stmt->bindParam(":data_validade", $data['data_validade']); // Ensure YYYY-MM-DD
            $stmt->bindParam(":modelo_id", $data['modelo_id'], PDO::PARAM_INT);

            if ($stmt->execute()) {
                $proposalId = (int)$pdo->lastInsertId();
                
                // Insert items
                if (!self::syncItems($proposalId, $items)) {
                    throw new PDOException("Falha ao inserir itens da proposta.");
                }

                // Add history
                self::addHistory($proposalId, $data['responsavel_id'], 'Criada', 'Proposta criada.');

                $pdo->commit();
                return $proposalId;
            } else {
                $pdo->rollBack();
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Erro ao criar proposta: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Update an existing proposal.
     *
     * @param int $id Proposal ID.
     * @param array $data Associative array of data to update.
     * @param array $items Array of proposal items (will replace existing items).
     * @param int|null $userId User performing the update (for history).
     * @return bool True on success, false on failure.
     */
    public static function update(int $id, array $data, array $items = [], ?int $userId = null): bool
    {
        $fields = [];
        $params = [":id" => $id];
        $allowedFields = ['titulo', 'lead_id', 'contato_id', 'empresa_id', 'responsavel_id', 'descricao', 'condicoes', 'status', 'data_envio', 'data_validade', 'pdf_path', 'modelo_id'];
        $statusChanged = false;
        $oldStatus = null;

        // Get old status if status is being updated
        if (isset($data['status'])) {
             $currentProposal = self::findById($id);
             if ($currentProposal) {
                 $oldStatus = $currentProposal->status;
                 if ($oldStatus !== $data['status']) {
                     $statusChanged = true;
                 }
             }
        }

        // Calculate new total value based on provided items
        $data['valor_total'] = self::calculateTotalValue($items);
        $allowedFields[] = 'valor_total'; // Allow updating calculated total value

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "`{$key}` = :{$key}";
                $paramType = PDO::PARAM_STR;
                if (in_array($key, ['lead_id', 'contato_id', 'empresa_id', 'responsavel_id', 'modelo_id'])) {
                    $paramType = PDO::PARAM_INT;
                    $value = empty($value) ? null : (int)$value;
                }
                $params[":{$key}"] = $value;
            }
        }

        if (empty($fields)) {
            // If only items are updated, still proceed to syncItems
            if (!empty($items)) {
                 // Need to ensure transaction wraps this logic if only items change
                 $pdo = Database::getInstance();
                 try {
                     $pdo->beginTransaction();
                     if (!self::syncItems($id, $items)) {
                         throw new PDOException("Falha ao atualizar itens da proposta.");
                     }
                     // Update valor_total based on new items even if no other fields changed
                     $newTotal = self::calculateTotalValue($items);
                     $stmtTotal = $pdo->prepare("UPDATE propostas SET valor_total = :valor_total WHERE id = :id");
                     $stmtTotal->bindParam(':valor_total', $newTotal);
                     $stmtTotal->bindParam(':id', $id, PDO::PARAM_INT);
                     $stmtTotal->execute();
                     
                     self::addHistory($id, $userId, 'Itens Atualizados', 'Itens da proposta foram atualizados.');
                     $pdo->commit();
                     return true;
                 } catch (PDOException $e) {
                     $pdo->rollBack();
                     error_log("Erro ao atualizar itens da proposta ID {$id}: " . $e->getMessage());
                     return false;
                 }
            }
            return false; // No fields or items to update
        }

        $sql = "UPDATE propostas SET " . implode(", ", $fields) . " WHERE id = :id";
        $pdo = Database::getInstance();

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare($sql);
            
            if ($stmt->execute($params)) {
                // Sync items (delete old, insert new)
                if (!self::syncItems($id, $items)) {
                    throw new PDOException("Falha ao atualizar itens da proposta.");
                }

                // Add history
                $historyAction = 'Atualizada';
                $historyDetails = 'Proposta atualizada.';
                if ($statusChanged) {
                    $historyAction = 'Status Alterado';
                    $historyDetails = "Status alterado de '{$oldStatus}' para '{$data['status']}'.";
                }
                self::addHistory($id, $userId, $historyAction, $historyDetails);

                $pdo->commit();
                return true;
            } else {
                 $pdo->rollBack();
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Erro ao atualizar proposta ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Delete a proposal and its items.
     *
     * @param int $id Proposal ID.
     * @return bool True on success, false on failure.
     */
    public static function delete(int $id): bool
    {
        // Consider soft deletes
        $sql = "DELETE FROM propostas WHERE id = :id";
        $pdo = Database::getInstance();
        try {
            // Deletion cascades to items and history due to FK constraints ON DELETE CASCADE
            $pdo->beginTransaction();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            $deleted = $stmt->execute();
            $pdo->commit();
            return $deleted;
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Erro ao deletar proposta ID {$id}: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Get items for a specific proposal.
     *
     * @param int $proposalId
     * @return array
     */
    public static function getItems(int $proposalId): array
    {
        $items = [];
        $sql = "SELECT * FROM proposta_itens WHERE proposta_id = :proposal_id ORDER BY id ASC";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":proposal_id", $proposalId, PDO::PARAM_INT);
            $stmt->execute();
            $items = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Erro ao buscar itens da proposta ID {$proposalId}: " . $e->getMessage());
        }
        return $items;
    }

    /**
     * Get history for a specific proposal.
     *
     * @param int $proposalId
     * @return array
     */
    public static function getHistory(int $proposalId): array
    {
        $history = [];
        $sql = "SELECT hp.*, u.nome as usuario_nome 
                FROM historico_propostas hp
                LEFT JOIN usuarios u ON hp.usuario_id = u.id
                WHERE hp.proposta_id = :proposal_id 
                ORDER BY hp.data_acao DESC";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":proposal_id", $proposalId, PDO::PARAM_INT);
            $stmt->execute();
            $history = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Erro ao buscar histórico da proposta ID {$proposalId}: " . $e->getMessage());
        }
        return $history;
    }

    /**
     * Add a history record for a proposal.
     *
     * @param int $proposalId
     * @param int|null $userId User performing the action.
     * @param string $action Description of the action.
     * @param string|null $details Additional details.
     * @return bool
     */
    public static function addHistory(int $proposalId, ?int $userId, string $action, ?string $details = null): bool
    {
        $sql = "INSERT INTO historico_propostas (proposta_id, usuario_id, acao, detalhes, data_acao) 
                VALUES (:proposta_id, :usuario_id, :acao, :detalhes, NOW())";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":proposta_id", $proposalId, PDO::PARAM_INT);
            $stmt->bindParam(":usuario_id", $userId, PDO::PARAM_INT);
            $stmt->bindParam(":acao", $action);
            $stmt->bindParam(":detalhes", $details);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao adicionar histórico para proposta ID {$proposalId}: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Sync proposal items (delete existing, insert new).
     *
     * @param int $proposalId
     * @param array $items
     * @return bool
     */
    private static function syncItems(int $proposalId, array $items): bool
    {
        $pdo = Database::getInstance();
        try {
            // Delete existing items
            $stmtDelete = $pdo->prepare("DELETE FROM proposta_itens WHERE proposta_id = :proposal_id");
            $stmtDelete->bindParam(":proposal_id", $proposalId, PDO::PARAM_INT);
            $stmtDelete->execute();

            // Insert new items
            if (!empty($items)) {
                $sqlInsert = "INSERT INTO proposta_itens (proposta_id, descricao, quantidade, valor_unitario, valor_total_item) VALUES (:proposta_id, :descricao, :quantidade, :valor_unitario, :valor_total_item)";
                $stmtInsert = $pdo->prepare($sqlInsert);

                foreach ($items as $item) {
                    if (empty($item['descricao']) || !isset($item['valor_unitario'])) continue; // Skip invalid items
                    
                    $quantidade = $item['quantidade'] ?? 1.00;
                    $valorUnitario = $item['valor_unitario'] ?? 0.00;
                    $valorTotalItem = (float)$quantidade * (float)$valorUnitario;

                    $stmtInsert->bindParam(":proposta_id", $proposalId, PDO::PARAM_INT);
                    $stmtInsert->bindParam(":descricao", $item['descricao']);
                    $stmtInsert->bindParam(":quantidade", $quantidade);
                    $stmtInsert->bindParam(":valor_unitario", $valorUnitario);
                    $stmtInsert->bindParam(":valor_total_item", $valorTotalItem);
                    
                    if (!$stmtInsert->execute()) {
                        // Log error for specific item insertion failure
                        error_log("Falha ao inserir item para proposta ID {$proposalId}: " . $item['descricao']);
                        // Depending on requirements, you might want to continue or fail the whole sync
                        // For now, let's consider it a failure for the sync operation
                        return false; 
                    }
                }
            }
            return true;
        } catch (PDOException $e) {
            error_log("Erro ao sincronizar itens para proposta ID {$proposalId}: " . $e->getMessage());
            // Ensure transaction is rolled back in the calling method
            throw $e; // Re-throw to be caught by transaction handler
        }
    }

    /**
     * Calculate the total value based on items.
     *
     * @param array $items
     * @return float
     */
    private static function calculateTotalValue(array $items): float
    {
        $total = 0.00;
        foreach ($items as $item) {
             $quantidade = $item['quantidade'] ?? 1.00;
             $valorUnitario = $item['valor_unitario'] ?? 0.00;
             $total += (float)$quantidade * (float)$valorUnitario;
        }
        return $total;
    }

    /**
     * Hydrate a Proposal object from database data.
     *
     * @param array $data
     * @return Proposal
     */
    private static function hydrate(array $data): Proposal
    {
        $proposal = new self();
        $proposal->id = (int)$data["id"];
        $proposal->titulo = $data["titulo"];
        $proposal->lead_id = $data["lead_id"] ? (int)$data["lead_id"] : null;
        $proposal->contato_id = $data["contato_id"] ? (int)$data["contato_id"] : null;
        $proposal->empresa_id = $data["empresa_id"] ? (int)$data["empresa_id"] : null;
        $proposal->responsavel_id = $data["responsavel_id"] ? (int)$data["responsavel_id"] : null;
        $proposal->descricao = $data["descricao"];
        $proposal->condicoes = $data["condicoes"];
        $proposal->valor_total = (float)$data["valor_total"];
        $proposal->status = $data["status"];
        $proposal->data_envio = $data["data_envio"];
        $proposal->data_validade = $data["data_validade"];
        $proposal->pdf_path = $data["pdf_path"];
        $proposal->modelo_id = $data["modelo_id"] ? (int)$data["modelo_id"] : null;
        $proposal->criado_em = $data["criado_em"];
        $proposal->atualizado_em = $data["atualizado_em"];
        return $proposal;
    }
}

