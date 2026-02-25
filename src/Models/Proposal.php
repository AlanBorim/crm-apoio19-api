<?php

namespace Apoio19\Crm\Models;

use Apoio19\Crm\Models\Database;
use Apoio19\Crm\Models\Lead;
use Apoio19\Crm\Models\HistoricoInteracoes;
use InvalidArgumentException;
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
    public ?string $uploaded_pdf_path;
    public ?int $modelo_id;
    public string $criado_em;
    public string $atualizado_em;
    public ?string $lead_nome = null;
    public ?string $observacoes;

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
            $sql = "SELECT p.*, l.name as lead_nome 
                    FROM proposals p 
                    LEFT JOIN leads l ON p.lead_id = l.id 
                    WHERE p.id = :id LIMIT 1";
            $stmt = $pdo->prepare($sql);
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
        $sql = "SELECT 
                        p.*, 
                        l.name as lead_nome, 
                        c.name as contato_nome, 
                        e.name as empresa_nome, 
                        u.name as responsavel_nome 
                    FROM proposals p
                    LEFT JOIN leads l ON p.lead_id = l.id
                    LEFT JOIN contacts c ON p.contact_id = c.id
                    LEFT JOIN companies e ON p.company_id = e.id
                    LEFT JOIN users u ON p.responsavel_id = u.id";
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
        $sql = "SELECT COUNT(*) FROM proposals p";
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
        $pdo = Database::getInstance();
        try {
            $pdo->beginTransaction();

            // Log detalhado dos dados recebidos
            error_log("Dados recebidos para criar proposta: " . json_encode([
                'lead_id' => $data['lead_id'] ?? 'not_set',
                'lead_name' => $data['lead_name'] ?? 'not_set',
                'titulo' => $data['titulo'] ?? 'not_set'
            ]));

            // Se lead_id não foi fornecido, tentar criar um novo lead com os dados fornecidos
            // Considerar "", null, 0 como vazios
            $leadId = $data['lead_id'] ?? null;
            if ($leadId === '' || $leadId === 0 || $leadId === '0') {
                $leadId = null;
            }

            if (empty($leadId) && !empty($data['lead_name'])) {
                // Validar dados mínimos do lead (seguindo padrão do LeadController)
                $leadName = trim($data['lead_name']);
                if (empty($leadName)) {
                    error_log("Lead name vazio após trim");
                    $pdo->rollBack();
                    return false;
                }

                // Validar email se fornecido
                $leadEmail = $data['lead_email'] ?? null;
                if (!empty($leadEmail) && !filter_var($leadEmail, FILTER_VALIDATE_EMAIL)) {
                    error_log("Email inválido fornecido: {$leadEmail}");
                    $pdo->rollBack();
                    return false;
                }

                // Preparar dados do lead (seguindo padrão do LeadController)
                $leadData = [
                    'name' => $leadName,
                    'email' => $leadEmail,
                    'phone' => $data['lead_phone'] ?? null,
                    'company' => $data['lead_company'] ?? null,
                    'stage' => 'novo',
                    'assigned_to' => $data['responsavel_id'] ?? null,
                    'created_at' => date('Y-m-d H:i:s')
                ];

                // Criar o lead usando o método do modelo
                $leadId = Lead::create($leadData);

                if (!$leadId) {
                    error_log("Erro ao criar lead automaticamente para proposta");
                    $pdo->rollBack();
                    return false;
                }

                error_log("Lead criado automaticamente com ID: {$leadId} para proposta");

                // Registrar no histórico do lead (seguindo padrão do Lead Controller)
                try {
                    HistoricoInteracoes::logAction(
                        $leadId,
                        null,
                        $data['responsavel_id'] ?? null,
                        "Lead Criado",
                        "Lead criado automaticamente a partir de proposta."
                    );
                } catch (\Exception $e) {
                    error_log("Erro ao registrar histórico do lead: " . $e->getMessage());
                    // Não falhar a criação da proposta por isso
                }
            }

            // Se ainda não temos lead_id, falhar
            if (empty($leadId)) {
                error_log("Erro ao criar proposta: lead_id é obrigatório ou dados de lead devem ser fornecidos. Recebido lead_id: " . json_encode($data['lead_id'] ?? 'not_set') . ", lead_name: " . json_encode($data['lead_name'] ?? 'not_set'));
                $pdo->rollBack();
                return false;
            }

            $sql = "INSERT INTO proposals (titulo, lead_id, contact_id, company_id, responsavel_id, descricao, condicoes, observacoes, valor_total, status, data_validade, modelo_id) 
                    VALUES (:titulo, :lead_id, :contact_id, :company_id, :responsavel_id, :descricao, :condicoes, :observacoes, :valor_total, :status, :data_validade, :modelo_id)";

            $stmt = $pdo->prepare($sql);

            $valorTotal = self::calculateTotalValue($items);
            $status = $data['status'] ?? self::STATUS_RASCUNHO;

            // Valores obrigatórios
            $titulo = $data['titulo'] ?? 'Proposta sem título';
            $descricao = $data['descricao'] ?? '';
            $condicoes = $data['condicoes'] ?? '';
            $observacoes = $data['observacoes'] ?? null;
            $responsavelId = $data['responsavel_id'] ?? null;

            // Valores opcionais (contact_id e company_id não são mais necessários)
            $contactId = $data['contact_id'] ?? $data['contato_id'] ?? null;
            $companyId = $data['company_id'] ?? $data['empresa_id'] ?? null;
            $modeloId = $data['modelo_id'] ?? null;

            // Converter string vazia em NULL para data_validade
            $dataValidade = $data['data_validade'] ?? null;
            if ($dataValidade === '' || $dataValidade === '0000-00-00') {
                $dataValidade = null;
            }

            // Log para debug
            error_log("Creating proposal with data: " . json_encode([
                'titulo' => $titulo,
                'lead_id' => $leadId,
                'contact_id' => $contactId,
                'company_id' => $companyId,
                'responsavel_id' => $responsavelId,
                'valor_total' => $valorTotal,
                'status' => $status,
                'data_validade' => $dataValidade,
                'modelo_id' => $modeloId,
                'items_count' => count($items)
            ]));

            // Bind parameters
            $stmt->bindParam(":titulo", $titulo);
            $stmt->bindParam(":lead_id", $leadId, PDO::PARAM_INT);
            $stmt->bindParam(":contact_id", $contactId, $contactId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindParam(":company_id", $companyId, $companyId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindParam(":responsavel_id", $responsavelId, $responsavelId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindParam(":descricao", $descricao);
            $stmt->bindParam(":condicoes", $condicoes);
            $stmt->bindParam(":observacoes", $observacoes);
            $stmt->bindParam(":valor_total", $valorTotal);
            $stmt->bindParam(":status", $status);
            $stmt->bindParam(":data_validade", $dataValidade, $dataValidade !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindParam(":modelo_id", $modeloId, $modeloId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);

            if ($stmt->execute()) {
                $proposalId = (int)$pdo->lastInsertId();
                error_log("Proposal created successfully with ID: {$proposalId}");

                // Insert items
                if (!empty($items)) {
                    error_log("Syncing " . count($items) . " items for proposal ID: {$proposalId}");
                    if (!self::syncItems($proposalId, $items)) {
                        error_log("Failed to sync items for proposal ID: {$proposalId}");
                        throw new PDOException("Falha ao inserir itens da proposta.");
                    }
                    error_log("Items synced successfully for proposal ID: {$proposalId}");
                } else {
                    error_log("No items to sync for proposal ID: {$proposalId}");
                }

                // Add history
                self::addHistory($proposalId, $responsavelId, 'Criada', 'Proposta criada.');

                $pdo->commit();
                error_log("Transaction committed successfully for proposal ID: {$proposalId}");
                return $proposalId;
            } else {
                $pdo->rollBack();
                $errorInfo = $stmt->errorInfo();
                error_log("Failed to execute INSERT: SQLSTATE[{$errorInfo[0]}] [{$errorInfo[1]}] {$errorInfo[2]}");
                return false;
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("PDOException ao criar proposta: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return false;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Exception ao criar proposta: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return false;
        }
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
        $allowedFields = ['titulo', 'lead_id', 'contato_id', 'empresa_id', 'responsavel_id', 'descricao', 'condicoes', 'observacoes', 'status', 'data_envio', 'data_validade', 'pdf_path', 'uploaded_pdf_path', 'modelo_id'];
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

        // Only calculate new total value if items are provided
        // This prevents overwriting valor_total to 0 when only updating status/observacoes
        if (!empty($items)) {
            $data['valor_total'] = self::calculateTotalValue($items);
            $allowedFields[] = 'valor_total'; // Allow updating calculated total value
        }

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "`{$key}` = :{$key}";
                $paramType = PDO::PARAM_STR;

                // Handle integer fields
                if (in_array($key, ['lead_id', 'contato_id', 'empresa_id', 'responsavel_id', 'modelo_id'])) {
                    $paramType = PDO::PARAM_INT;
                    $value = empty($value) ? null : (int)$value;
                }

                // Handle date fields - convert empty strings to NULL
                if (in_array($key, ['data_envio', 'data_validade'])) {
                    $value = (empty($value) || $value === '') ? null : $value;
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
                    $stmtTotal = $pdo->prepare("UPDATE proposals SET valor_total = :valor_total WHERE id = :id");
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

        $sql = "UPDATE proposals SET " . implode(", ", $fields) . " WHERE id = :id";
        $pdo = Database::getInstance();

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare($sql);

            if ($stmt->execute($params)) {
                // Only sync items if items are provided
                // This prevents deleting all items when only updating status/observacoes
                if (!empty($items)) {
                    if (!self::syncItems($id, $items)) {
                        throw new PDOException("Falha ao atualizar itens da proposta.");
                    }
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
        $sql = "DELETE FROM proposals WHERE id = :id";
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
        $sql = "SELECT * FROM proposta_itens WHERE proposal_id = :proposal_id ORDER BY id ASC";
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
        $sql = "SELECT hp.*, u.name as usuario_nome 
                FROM historico_propostas hp
                LEFT JOIN users u ON hp.usuario_id = u.id
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
            $stmtDelete = $pdo->prepare("DELETE FROM proposta_itens WHERE proposal_id = :proposal_id");
            $stmtDelete->bindParam(":proposal_id", $proposalId, PDO::PARAM_INT);
            $stmtDelete->execute();

            // Insert new items
            if (!empty($items)) {
                $sqlInsert = "INSERT INTO proposta_itens (proposal_id, description, quantity, unit_price, total_price) 
                              VALUES (:proposal_id, :description, :quantity, :unit_price, :total_price)";
                $stmtInsert = $pdo->prepare($sqlInsert);

                foreach ($items as $item) {
                    // Aceitar tanto 'descricao' quanto 'description'
                    $description = $item['description'] ?? $item['descricao'] ?? '';
                    $unitPrice = $item['unit_price'] ?? $item['valor_unitario'] ?? 0.00;

                    if (empty($description)) continue; // Skip invalid items

                    $quantity = $item['quantity'] ?? $item['quantidade'] ?? 1.00;
                    $totalPrice = (float)$quantity * (float)$unitPrice;

                    $stmtInsert->bindParam(":proposal_id", $proposalId, PDO::PARAM_INT);
                    $stmtInsert->bindParam(":description", $description);
                    $stmtInsert->bindParam(":quantity", $quantity);
                    $stmtInsert->bindParam(":unit_price", $unitPrice);
                    $stmtInsert->bindParam(":total_price", $totalPrice);

                    if (!$stmtInsert->execute()) {
                        // Log error for specific item insertion failure
                        error_log("Falha ao inserir item para proposta ID {$proposalId}: " . $description);
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

    private static function intOrNull(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data)) return null;
        $v = $data[$key];
        if ($v === '' || $v === null) return null;
        $int = filter_var($v, FILTER_VALIDATE_INT);
        return $int === false ? null : (int)$int;
    }

    private static function hydrate(array $data): Proposal
    {
        $p = new self();

        // Defina aqui quais são realmente obrigatórios no seu domínio
        foreach (['id', 'titulo', 'valor_total', 'status'] as $req) {
            if (!array_key_exists($req, $data)) {
                throw new InvalidArgumentException("Campo obrigatório ausente: {$req}");
            }
        }

        // Obrigatórios
        $p->id          = (int)$data['id'];
        $p->titulo      = (string)$data['titulo'];
        $p->valor_total = isset($data['valor_total']) && $data['valor_total'] !== '' ? (float)$data['valor_total'] : 0.0;
        $p->status      = (string)$data['status'];

        // Inteiros opcionais (preservando 0)
        $p->lead_id        = self::intOrNull($data, 'lead_id');
        $p->contato_id     = self::intOrNull($data, 'contato_id');
        $p->empresa_id     = self::intOrNull($data, 'empresa_id');
        $p->responsavel_id = self::intOrNull($data, 'responsavel_id');
        $p->modelo_id      = self::intOrNull($data, 'modelo_id');

        // Opcionais string/datetime
        $p->descricao     = $data['descricao']     ?? null;
        $p->condicoes     = $data['condicoes']     ?? null;
        $p->observacoes   = $data['observacoes']   ?? null;
        $p->data_envio    = $data['data_envio']    ?? null;
        $p->data_validade = $data['data_validade'] ?? null;
        $p->pdf_path      = $data['pdf_path']      ?? null;
        $p->uploaded_pdf_path = $data['uploaded_pdf_path'] ?? null;
        $p->criado_em     = $data['criado_em']     ?? null;
        $p->atualizado_em = $data['atualizado_em'] ?? null;
        $p->lead_nome     = $data['lead_nome']     ?? null;

        return $p;
    }
}
