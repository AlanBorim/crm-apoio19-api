<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Database;
use PDO;
use PDOException;

class DashboardController extends BaseController
{
    /**
     * Get all dashboard metrics
     * 
     * @param array $headers HTTP headers
     * @return array Response with metrics
     */
    public function getMetrics(array $headers): array
    {
        try {
            $pdo = Database::getInstance();

            // Get active proposals metrics
            $activeProposals = $this->getActiveProposals($pdo);

            // Get monthly performance data (last 6 months)
            $monthlyPerformance = $this->getMonthlyPerformance($pdo);

            // Get sales funnel data
            $salesFunnel = $this->getSalesFunnel($pdo);

            // Get monthly revenue data
            $monthlyRevenue = $this->getMonthlyRevenue($pdo);

            http_response_code(200);
            return [
                "success" => true,
                "data" => [
                    "activeProposals" => $activeProposals,
                    "monthlyPerformance" => $monthlyPerformance,
                    "salesFunnel" => $salesFunnel,
                    "monthlyRevenue" => $monthlyRevenue
                ]
            ];
        } catch (PDOException $e) {
            error_log("Erro ao buscar métricas do dashboard: " . $e->getMessage());
            http_response_code(500);
            return [
                "success" => false,
                "message" => "Erro ao carregar métricas do dashboard"
            ];
        }
    }

    /**
     * Get active proposals count and total value
     * 
     * @param PDO $pdo
     * @return array
     */
    private function getActiveProposals(PDO $pdo): array
    {
        $sql = "SELECT 
                    COUNT(*) as count,
                    COALESCE(SUM(valor_total), 0) as total_value
                FROM proposals 
                WHERE status IN ('enviada', 'em_negociacao')";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                "count" => (int)$result['count'],
                "totalValue" => (float)$result['total_value']
            ];
        } catch (PDOException $e) {
            error_log("Erro ao buscar propostas ativas: " . $e->getMessage());
            return [
                "count" => 0,
                "totalValue" => 0
            ];
        }
    }

    /**
     * Get monthly performance data for the last 6 months
     * 
     * @param PDO $pdo
     * @return array
     */
    private function getMonthlyPerformance(PDO $pdo): array
    {
        // Get last 6 months
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = date('Y-m', strtotime("-$i months"));
            $months[] = $date;
        }

        $performance = [];

        foreach ($months as $month) {
            $monthName = $this->getMonthName($month);

            // Count leads created in this month
            $leadsCount = $this->countLeadsByMonth($pdo, $month);

            // Count proposals created in this month
            $proposalsCount = $this->countProposalsByMonth($pdo, $month);

            $performance[] = [
                "name" => $monthName,
                "leads" => $leadsCount,
                "propostas" => $proposalsCount,
                "meta" => 50 // Fixed goal for now
            ];
        }

        return $performance;
    }

    /**
     * Count leads created in a specific month
     * 
     * @param PDO $pdo
     * @param string $month Format: Y-m
     * @return int
     */
    private function countLeadsByMonth(PDO $pdo, string $month): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM leads 
                WHERE DATE_FORMAT(created_at, '%Y-%m') = :month";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':month', $month);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['count'];
        } catch (PDOException $e) {
            error_log("Erro ao contar leads do mês {$month}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Count proposals created in a specific month
     * 
     * @param PDO $pdo
     * @param string $month Format: Y-m
     * @return int
     */
    private function countProposalsByMonth(PDO $pdo, string $month): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM proposals 
                WHERE DATE_FORMAT(criado_em, '%Y-%m') = :month";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':month', $month);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['count'];
        } catch (PDOException $e) {
            error_log("Erro ao contar propostas do mês {$month}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get sales funnel data (leads grouped by stage)
     * 
     * @param PDO $pdo
     * @return array
     */
    private function getSalesFunnel(PDO $pdo): array
    {
        $sql = "SELECT 
                    stage,
                    COUNT(*) as count
                FROM leads
                WHERE active = '1' 
                GROUP BY stage
                ORDER BY 
                    CASE stage
                        WHEN 'novo' THEN 1
                        WHEN 'qualificado' THEN 2
                        WHEN 'reuniao' THEN 3
                        WHEN 'proposta' THEN 4
                        WHEN 'fechado' THEN 5
                        ELSE 6
                    END";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Map stage names to Portuguese and assign colors
            $stageMap = [
                'novo' => ['name' => 'Novos Leads', 'color' => '#FF6B00'],
                'qualificado' => ['name' => 'Qualificados', 'color' => '#0073EA'],
                'reuniao' => ['name' => 'Reunião', 'color' => '#00C875'],
                'proposta' => ['name' => 'Proposta', 'color' => '#FFCB00'],
                'fechado' => ['name' => 'Fechados', 'color' => '#6E6E6E']
            ];

            $funnel = [];
            foreach ($results as $row) {
                $stage = $row['stage'];
                if (isset($stageMap[$stage])) {
                    $funnel[] = [
                        "name" => $stageMap[$stage]['name'],
                        "value" => (int)$row['count'],
                        "color" => $stageMap[$stage]['color']
                    ];
                }
            }

            return $funnel;
        } catch (PDOException $e) {
            error_log("Erro ao buscar funil de vendas: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get monthly revenue from accepted proposals
     * 
     * @param PDO $pdo
     * @return array
     */
    private function getMonthlyRevenue(PDO $pdo): array
    {
        $currentMonth = date('Y-m');

        $sql = "SELECT 
                    COALESCE(SUM(valor_total), 0) as revenue
                FROM proposals 
                WHERE status = 'aceita' 
                AND DATE_FORMAT(criado_em, '%Y-%m') = :month";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':month', $currentMonth);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $revenue = (float)$result['revenue'];
            $goal = 200000.00; // Fixed goal of R$ 200,000.00
            $percentage = $goal > 0 ? ($revenue / $goal) * 100 : 0;

            return [
                "revenue" => $revenue,
                "goal" => $goal,
                "percentage" => round($percentage, 2)
            ];
        } catch (PDOException $e) {
            error_log("Erro ao buscar faturamento mensal: " . $e->getMessage());
            return [
                "revenue" => 0,
                "goal" => 200000.00,
                "percentage" => 0
            ];
        }
    }

    /**
     * Get month name in Portuguese abbreviation
     * 
     * @param string $month Format: Y-m
     * @return string
     */
    private function getMonthName(string $month): string
    {
        $monthNames = [
            '01' => 'Jan',
            '02' => 'Fev',
            '03' => 'Mar',
            '04' => 'Abr',
            '05' => 'Mai',
            '06' => 'Jun',
            '07' => 'Jul',
            '08' => 'Ago',
            '09' => 'Set',
            '10' => 'Out',
            '11' => 'Nov',
            '12' => 'Dez'
        ];

        $monthNumber = substr($month, 5, 2);
        return $monthNames[$monthNumber] ?? $monthNumber;
    }
}
