<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Services\ReportService;
use Apoio19\Crm\Middleware\AuthMiddleware;

// Placeholder for Request/Response handling
class ReportController extends BaseController
{
    private AuthMiddleware $authMiddleware;
    private ReportService $reportService;

    public function __construct()
    {
        $this->authMiddleware = new AuthMiddleware();
        $this->reportService = new ReportService();
    }

    /**
     * Get a summary dashboard report (combining multiple metrics).
     *
     * @param array $headers Request headers.
     * @return array JSON response.
     */
    public function getDashboardSummary(array $headers): array
    {
        // Define roles allowed to see the full dashboard
        $allowedRoles = ["Admin", "Comercial", "Financeiro"];
        $userData = $this->authMiddleware->handle($headers, $allowedRoles);
        if (!$userData) {
            http_response_code(401); // Or 403 Forbidden
            return ["error" => "Autenticação necessária ou permissão insuficiente para acessar o dashboard."];
        }

        try {
            $summary = [
                "lead_status_counts" => $this->reportService->getLeadStatusCounts(),
                "proposal_status_counts" => $this->reportService->getProposalStatusCounts(),
                "sales_performance_by_user" => $this->reportService->getSalesPerformanceByUser(),
                // Add more reports as needed for the dashboard
                "lead_source_counts" => $this->reportService->getLeadSourceCounts(),
                "task_status_by_user" => $this->reportService->getTaskStatusByUser(),
            ];

            http_response_code(200);
            return ["data" => $summary];
        } catch (\Exception $e) {
            error_log("Erro ao gerar sumário do dashboard: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Erro interno ao gerar sumário do dashboard."];
        }
    }

    /**
     * Get Lead Status Counts Report.
     *
     * @param array $headers Request headers.
     * @return array JSON response.
     */
    public function getLeadStatusReport(array $headers): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin", "Comercial"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária ou permissão insuficiente."];
        }
        $data = $this->reportService->getLeadStatusCounts();
        http_response_code(200);
        return ["data" => $data];
    }

    /**
     * Get Lead Source Counts Report.
     *
     * @param array $headers Request headers.
     * @return array JSON response.
     */
    public function getLeadSourceReport(array $headers): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin", "Comercial"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária ou permissão insuficiente."];
        }
        $data = $this->reportService->getLeadSourceCounts();
        http_response_code(200);
        return ["data" => $data];
    }

    /**
     * Get Proposal Status Counts Report.
     *
     * @param array $headers Request headers.
     * @return array JSON response.
     */
    public function getProposalStatusReport(array $headers): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin", "Comercial", "Financeiro"]);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária ou permissão insuficiente."];
        }
        $data = $this->reportService->getProposalStatusCounts();
        http_response_code(200);
        return ["data" => $data];
    }

    /**
     * Get Sales Performance by User Report.
     *
     * @param array $headers Request headers.
     * @return array JSON response.
     */
    public function getSalesPerformanceReport(array $headers): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin", "Financeiro"]); // Admins and Finance can see overall sales
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária ou permissão insuficiente."];
        }
        $data = $this->reportService->getSalesPerformanceByUser();
        http_response_code(200);
        return ["data" => $data];
    }

    /**
     * Get Task Status by User Report.
     *
     * @param array $headers Request headers.
     * @return array JSON response.
     */
    public function getTaskStatusReport(array $headers): array
    {
        $userData = $this->authMiddleware->handle($headers, ["Admin", "Comercial"]); // Admins and Sales managers might see this
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação necessária ou permissão insuficiente."];
        }
        $data = $this->reportService->getTaskStatusByUser();
        http_response_code(200);
        return ["data" => $data];
    }

    // Add endpoints for other specific reports if needed

}
