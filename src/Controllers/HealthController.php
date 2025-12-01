<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\Database;
use Apoio19\Crm\Models\User;

/**
 * Controlador para verificação de saúde da API
 */
class HealthController extends BaseController
{
    /**
     * Verificação básica de saúde da API
     *
     * @param array $headers Cabeçalhos da requisição
     * @return array Resposta JSON
     */
    public function check(array $headers = []): array
    {
        try {
            $startTime = microtime(true);

            // Verificar conexão com banco de dados
            $dbStatus = $this->checkDatabase();

            // Verificar sistema de arquivos
            $filesystemStatus = $this->checkFilesystem();

            // Verificar memória disponível
            $memoryStatus = $this->checkMemory();

            // Calcular tempo de resposta
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            // Status geral
            $overallStatus = $dbStatus['status'] && $filesystemStatus['status'] && $memoryStatus['status'];

            $healthData = [
                'status' => $overallStatus ? 'healthy' : 'unhealthy',
                'timestamp' => date('Y-m-d H:i:s'),
                'version' => $this->getApiVersion(),
                'uptime' => $this->getUptime(),
                'response_time_ms' => $responseTime,
                'checks' => [
                    'database' => $dbStatus,
                    'filesystem' => $filesystemStatus,
                    'memory' => $memoryStatus
                ],
                'system_info' => [
                    'php_version' => PHP_VERSION,
                    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time'),
                    'timezone' => date_default_timezone_get()
                ]
            ];

            return $this->successResponse($healthData, "API está funcionando corretamente.", $overallStatus ? 200 : 503);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro interno na verificação de saúde.", $e->getMessage());
        }
    }

    /**
     * Verificação detalhada de saúde da API
     *
     * @param array $headers Cabeçalhos da requisição
     * @return array Resposta JSON
     */
    public function detailed(array $headers = []): array
    {
        try {
            $startTime = microtime(true);

            // Verificações básicas
            $basicChecks = $this->check($headers);

            // Verificações adicionais
            $additionalChecks = [
                'users_count' => $this->checkUsersCount(),
                'disk_space' => $this->checkDiskSpace(),
                'configuration' => $this->checkConfiguration(),
                'dependencies' => $this->checkDependencies()
            ];

            // Combinar resultados
            $healthData = $basicChecks['data'];
            $healthData['checks'] = array_merge($healthData['checks'], $additionalChecks);
            $healthData['response_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);

            // Recalcular status geral
            $allChecksHealthy = true;
            foreach ($healthData['checks'] as $check) {
                if (!$check['status']) {
                    $allChecksHealthy = false;
                    break;
                }
            }

            $healthData['status'] = $allChecksHealthy ? 'healthy' : 'unhealthy';

            return $this->successResponse($healthData, "Verificação detalhada concluída.", $allChecksHealthy ? 200 : 503);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro na verificação detalhada.", $e->getMessage());
        }
    }

    /**
     * Verificação rápida (apenas status)
     *
     * @param array $headers Cabeçalhos da requisição
     * @return array Resposta JSON
     */
    public function ping(array $headers = []): array
    {
        try {
            $startTime = microtime(true);

            // Verificação mínima - apenas conexão com banco
            $dbConnected = $this->quickDatabaseCheck();

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            $pingData = [
                'status' => $dbConnected ? 'ok' : 'error',
                'timestamp' => date('Y-m-d H:i:s'),
                'response_time_ms' => $responseTime,
                'message' => $dbConnected ? 'API respondendo' : 'Problemas de conectividade'
            ];

            return $this->successResponse($pingData, "Ping realizado.", $dbConnected ? 200 : 503);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro no ping.", $e->getMessage());
        }
    }

    /**
     * Informações sobre a API
     *
     * @param array $headers Cabeçalhos da requisição
     * @return array Resposta JSON
     */
    public function info(array $headers = []): array
    {
        try {
            $infoData = [
                'api_name' => 'CRM API',
                'version' => $this->getApiVersion(),
                'description' => 'API para gerenciamento de CRM',
                'documentation' => '/api/docs',
                'support_email' => 'suporte@crm.com',
                'build_date' => $this->getBuildDate(),
                'environment' => $this->getEnvironment(),
                'endpoints' => [
                    'health' => '/api/health',
                    'users' => '/api/users',
                    'auth' => '/api/auth'
                ],
                'features' => [
                    'user_management' => true,
                    'authentication' => true,
                    'permissions' => true,
                    'bulk_operations' => true
                ]
            ];

            return $this->successResponse($infoData, "Informações da API.");
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao obter informações.", $e->getMessage());
        }
    }

    // Métodos auxiliares privados

    /**
     * Verificar conexão com banco de dados
     */
    private function checkDatabase(): array
    {
        try {
            $pdo = Database::getInstance();

            $inicio = microtime(true);

            $stmt = $pdo->prepare("SELECT 1 as test");
            $stmt->execute();
            $result = $stmt->fetch();

            $fim = microtime(true);

            $tempoMs = ($fim - $inicio) * 1000; // converte para milissegundos

            return [
                'status' => $result && $result['test'] == 1,
                'message' => $result ? 'Conexão com banco OK' : 'Falha na query de teste',
                'response_time_ms' => $tempoMs
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Erro de conexão: ' . $e->getMessage(),
                'response_time_ms' => 0
            ];
        }
    }

    /**
     * Verificação rápida do banco
     */
    private function quickDatabaseCheck(): bool
    {
        try {
            $pdo = Database::getInstance();
            return $pdo !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Verificar sistema de arquivos
     */
    private function checkFilesystem(): array
    {
        try {
            $tempFile = sys_get_temp_dir() . '/crm_health_check_' . time();
            $testData = 'health_check_test';

            // Tentar escrever arquivo
            $writeSuccess = file_put_contents($tempFile, $testData) !== false;

            // Tentar ler arquivo
            $readSuccess = false;
            if ($writeSuccess) {
                $readData = file_get_contents($tempFile);
                $readSuccess = $readData === $testData;

                // Limpar arquivo de teste
                @unlink($tempFile);
            }

            return [
                'status' => $writeSuccess && $readSuccess,
                'message' => $writeSuccess && $readSuccess ?
                    'Sistema de arquivos OK' :
                    'Problemas de escrita/leitura',
                'writable' => $writeSuccess,
                'readable' => $readSuccess
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Erro no sistema de arquivos: ' . $e->getMessage(),
                'writable' => false,
                'readable' => false
            ];
        }
    }

    /**
     * Verificar memória disponível
     */
    private function checkMemory(): array
    {
        try {
            $memoryUsage = memory_get_usage(true);
            $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
            $memoryPercent = $memoryLimit > 0 ? ($memoryUsage / $memoryLimit) * 100 : 0;

            return [
                'status' => $memoryPercent < 90, // Alerta se usar mais de 90%
                'message' => $memoryPercent < 90 ? 'Memória OK' : 'Uso alto de memória',
                'usage_bytes' => $memoryUsage,
                'usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                'limit_mb' => round($memoryLimit / 1024 / 1024, 2),
                'usage_percent' => round($memoryPercent, 2)
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Erro ao verificar memória: ' . $e->getMessage(),
                'usage_bytes' => 0,
                'usage_mb' => 0,
                'limit_mb' => 0,
                'usage_percent' => 0
            ];
        }
    }

    /**
     * Verificar contagem de usuários
     */
    private function checkUsersCount(): array
    {
        try {
            $totalUsers = User::countWithWhere("WHERE ativo = '1' OR ativo = '0'");
            $activeUsers = User::countWithWhere("WHERE ativo = '1'");

            return [
                'status' => true,
                'message' => 'Contagem de usuários OK',
                'total_users' => $totalUsers,
                'active_users' => $activeUsers,
                'inactive_users' => $totalUsers - $activeUsers
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Erro ao contar usuários: ' . $e->getMessage(),
                'total_users' => 0,
                'active_users' => 0,
                'inactive_users' => 0
            ];
        }
    }

    /**
     * Verificar espaço em disco
     */
    private function checkDiskSpace(): array
    {
        try {
            $freeBytes = disk_free_space('.');
            $totalBytes = disk_total_space('.');
            $usedBytes = $totalBytes - $freeBytes;
            $usedPercent = $totalBytes > 0 ? ($usedBytes / $totalBytes) * 100 : 0;

            return [
                'status' => $usedPercent < 90, // Alerta se usar mais de 90%
                'message' => $usedPercent < 90 ? 'Espaço em disco OK' : 'Pouco espaço disponível',
                'free_gb' => round($freeBytes / 1024 / 1024 / 1024, 2),
                'total_gb' => round($totalBytes / 1024 / 1024 / 1024, 2),
                'used_percent' => round($usedPercent, 2)
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Erro ao verificar disco: ' . $e->getMessage(),
                'free_gb' => 0,
                'total_gb' => 0,
                'used_percent' => 0
            ];
        }
    }

    /**
     * Verificar configurações importantes
     */
    private function checkConfiguration(): array
    {
        try {
            $configs = [
                'display_errors' => ini_get('display_errors'),
                'log_errors' => ini_get('log_errors'),
                'error_reporting' => error_reporting(),
                'post_max_size' => ini_get('post_max_size'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'max_input_vars' => ini_get('max_input_vars')
            ];

            // Verificar se configurações estão adequadas para produção
            $productionReady = (
                ini_get('display_errors') == '0' &&
                ini_get('log_errors') == '1' &&
                ini_get('expose_php') == '0'
            );

            return [
                'status' => true, // Sempre OK, apenas informativo
                'message' => $productionReady ? 'Configuração adequada' : 'Revisar configurações',
                'production_ready' => $productionReady,
                'settings' => $configs
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Erro ao verificar configurações: ' . $e->getMessage(),
                'production_ready' => false,
                'settings' => []
            ];
        }
    }

    /**
     * Verificar dependências
     */
    private function checkDependencies(): array
    {
        try {
            $extensions = [
                'pdo' => extension_loaded('pdo'),
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'json' => extension_loaded('json'),
                'mbstring' => extension_loaded('mbstring'),
                'openssl' => extension_loaded('openssl'),
                'curl' => extension_loaded('curl')
            ];

            $allLoaded = !in_array(false, $extensions);

            return [
                'status' => $allLoaded,
                'message' => $allLoaded ? 'Todas as extensões OK' : 'Extensões faltando',
                'extensions' => $extensions,
                'missing' => array_keys(array_filter($extensions, function ($loaded) {
                    return !$loaded;
                }))
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Erro ao verificar dependências: ' . $e->getMessage(),
                'extensions' => [],
                'missing' => []
            ];
        }
    }

    /**
     * Obter versão da API
     */
    private function getApiVersion(): string
    {
        // Pode ler de um arquivo de versão ou definir manualmente
        return '1.0.0';
    }

    /**
     * Obter data de build
     */
    private function getBuildDate(): string
    {
        // Pode ler de um arquivo de build ou usar data atual
        return date('Y-m-d H:i:s');
    }

    /**
     * Obter ambiente
     */
    private function getEnvironment(): string
    {
        return $_ENV['APP_ENV'] ?? 'production';
    }

    /**
     * Obter uptime do servidor
     */
    private function getUptime(): string
    {
        try {
            if (PHP_OS_FAMILY === 'Linux') {
                $uptime = file_get_contents('/proc/uptime');
                $uptimeSeconds = (int) explode(' ', $uptime)[0];

                $days = floor($uptimeSeconds / 86400);
                $hours = floor(($uptimeSeconds % 86400) / 3600);
                $minutes = floor(($uptimeSeconds % 3600) / 60);

                return "{$days}d {$hours}h {$minutes}m";
            }

            return 'N/A';
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    /**
     * Converter limite de memória para bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);

        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int) $limit;
        }
    }
}
