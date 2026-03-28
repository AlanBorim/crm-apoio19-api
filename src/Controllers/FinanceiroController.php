<?php

namespace Apoio19\Crm\Controllers;

class FinanceiroController extends BaseController
{
    /**
     * Get basic finance data
     * 
     * @param array $headers HTTP headers
     * @return array Response payload
     */
    public function index(array $headers): array
    {
        try {
            http_response_code(200);
            return [
                "success" => true,
                "message" => "Financeiro module is under construction.",
                "data" => []
            ];
        } catch (\Exception $e) {
            error_log("Erro em FinanceiroController->index: " . $e->getMessage());
            http_response_code(500);
            return [
                "success" => false,
                "message" => "Erro interno no servidor."
            ];
        }
    }
}
