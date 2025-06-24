<?php

namespace Apoio19\Crm\Services;

use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;

class ReportService
{
    /**
     * Get lead count by status.
     *
     * @return array [["status" => string, "count" => int]]
     */
    public function getLeadStatusCounts(): array
    {
        $sql = "SELECT status, COUNT(*) as count 
                FROM leads 
                WHERE status IS NOT NULL 
                GROUP BY status 
                ORDER BY status";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao gerar relatório de status de leads: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get lead count by source (origem).
     *
     * @return array [["origem" => string, "count" => int]]
     */
    public function getLeadSourceCounts(): array
    {
        $sql = "SELECT origem, COUNT(*) as count 
                FROM leads 
                WHERE origem IS NOT NULL AND origem != ''
                GROUP BY origem 
                ORDER BY count DESC";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao gerar relatório de origem de leads: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get proposal count by status.
     *
     * @return array [["status" => string, "count" => int]]
     */
    public function getProposalStatusCounts(): array
    {
        $sql = "SELECT status, COUNT(*) as count 
                FROM propostas 
                GROUP BY status 
                ORDER BY FIELD(status, 'rascunho', 'enviada', 'em_negociacao', 'aceita', 'rejeitada', 'cancelada')"; // Order logically
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao gerar relatório de status de propostas: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get total value of accepted proposals per user (sales performance).
     *
     * @return array [["responsavel_id" => int, "responsavel_nome" => string, "total_valor" => float, "count" => int]]
     */
    public function getSalesPerformanceByUser(): array
    {
        $sql = "SELECT p.responsavel_id, u.nome as responsavel_nome, SUM(p.valor_total) as total_valor, COUNT(p.id) as count
                FROM propostas p
                JOIN usuarios u ON p.responsavel_id = u.id
                WHERE p.status = 'aceita' AND p.responsavel_id IS NOT NULL
                GROUP BY p.responsavel_id, u.nome
                ORDER BY total_valor DESC";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao gerar relatório de desempenho de vendas por usuário: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get task counts by status (concluida) per user.
     *
     * @return array [["responsavel_id" => int, "responsavel_nome" => string, "status" => string, "count" => int]]
     */
    public function getTaskStatusByUser(): array
    {
        $sql = "SELECT 
                    t.responsavel_id, 
                    u.nome as responsavel_nome, 
                    CASE WHEN t.concluida = TRUE THEN 'Concluída' ELSE 'Pendente' END as status, 
                    COUNT(t.id) as count
                FROM tarefas t
                JOIN usuarios u ON t.responsavel_id = u.id
                WHERE t.responsavel_id IS NOT NULL
                GROUP BY t.responsavel_id, u.nome, t.concluida
                ORDER BY u.nome, t.concluida";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao gerar relatório de status de tarefas por usuário: " . $e->getMessage());
            return [];
        }
    }

    // Add more report methods as needed:
    // - Lead conversion funnel (e.g., Novo -> Contatado -> Qualificado -> Proposta -> Aceita)
    // - Activity report (interactions per user)
    // - Revenue forecast (based on proposals in negotiation/sent)
}

