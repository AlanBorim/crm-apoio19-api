<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Models\SystemConfig;
use Apoio19\Crm\Middleware\AuthMiddleware;

class ConfiguracoesController extends BaseController
{

    private $authMiddleware;

    public function __construct()
    {
        parent::__construct();
        $this->authMiddleware = new AuthMiddleware();
    }

    /**
     * GET /settings/layout
     * Get layout settings
     */
    public function getLayoutConfig(array $headers): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        $this->requirePermission($userData, 'configuracoes', 'view');

        try {
            $config = SystemConfig::getLayoutConfig();

            // Garantir que logoIcon existe na resposta
            if (!isset($config['logoIcon'])) {
                $config['logoIcon'] = SystemConfig::get('layout.logo_icon', '/logo-icon.png');
            }

            return $this->successResponse(['config' => $config], "Configurações de layout recuperadas com sucesso.", 200, $traceId);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao recuperar configurações.", "INTERNAL_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * PUT /settings/layout
     * Update layout settings
     */
    public function updateLayoutConfig(array $headers, array $requestData): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        $this->requirePermission($userData, 'configuracoes', 'edit');

        // Validação
        if (empty($requestData)) {
            return $this->errorResponse(400, "Dados de configuração não fornecidos.", "VALIDATION_ERROR", $traceId);
        }

        try {
            $success = SystemConfig::setLayoutConfig($requestData, $userData->id);

            if ($success) {
                $config = SystemConfig::getLayoutConfig();

                // Audit log
                $this->logAudit($userData->id, 'update', 'system_configs', null, null, $config);

                return $this->successResponse(['config' => $config], "Configurações salvas com sucesso.", 200, $traceId);
            } else {
                return $this->errorResponse(500, "Erro ao salvar configurações.", "SAVE_ERROR", $traceId);
            }
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao salvar configurações.", "INTERNAL_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * POST /settings/upload-logo
     * Upload logo
     */
    public function uploadLogo(array $headers, array $files): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        $this->requirePermission($userData, 'configuracoes', 'edit');

        if (empty($files) || !isset($files['logo'])) {
            return $this->errorResponse(400, "Nenhum arquivo enviado.", "VALIDATION_ERROR", $traceId);
        }

        $file = $files['logo'];

        // Validar tipo de arquivo
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
        if (!in_array($file['type'], $allowedTypes)) {
            return $this->errorResponse(400, "Tipo de arquivo não permitido. Use: JPG, PNG, GIF, WebP ou SVG.", "INVALID_FILE_TYPE", $traceId);
        }

        // Validar tamanho (máx 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            return $this->errorResponse(400, "Arquivo muito grande. Tamanho máximo: 5MB.", "FILE_TOO_LARGE", $traceId);
        }

        try {
            // Criar diretório se não existir - usar caminho absoluto do frontend
            $uploadDir = '/var/www/html/crm-frontend/public/uploads/logos/';

            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0775, true)) {
                    error_log("Falha ao criar diretório: $uploadDir");
                    return $this->errorResponse(500, "Erro ao criar diretório de upload.", "UPLOAD_DIR_ERROR", $traceId);
                }
            }

            // Verificar se o diretório é gravável
            if (!is_writable($uploadDir)) {
                error_log("Diretório não gravável: $uploadDir");
                return $this->errorResponse(500, "Diretório de upload não tem permissão de escrita.", "UPLOAD_DIR_PERMISSION", $traceId);
            }

            // Gerar nome único
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
            $filepath = $uploadDir . $filename;

            // Mover arquivo
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                error_log("Falha ao mover arquivo de {$file['tmp_name']} para $filepath");
                return $this->errorResponse(500, "Erro ao fazer upload do arquivo.", "UPLOAD_ERROR", $traceId);
            }

            // Salvar caminho no banco
            $logoPath = '/uploads/logos/' . $filename;
            SystemConfig::set('layout.logo', $logoPath, 'string', $userData->id);

            // Audit log
            $this->logAudit($userData->id, 'update', 'system_configs', null, null, ['logo' => $logoPath]);

            return $this->successResponse(['logoPath' => $logoPath], "Logo enviado com sucesso.", 200, $traceId);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao processar upload.", "INTERNAL_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * POST /settings/upload-logo-icon
     * Upload logo icon (collapsed menu)
     */
    public function uploadLogoIcon(array $headers, array $files): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        $this->requirePermission($userData, 'configuracoes', 'edit');

        // Verificar se o arquivo foi enviado
        if (!isset($files['logoIcon'])) {
            return $this->errorResponse(400, "Arquivo de logo ícone não enviado.", "FILE_REQUIRED", $traceId);
        }

        $file = $files['logoIcon'];

        // Validar arquivo
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml'];
        if (!in_array($file['type'], $allowedTypes)) {
            return $this->errorResponse(400, "Tipo de arquivo não permitido.", "INVALID_FILE_TYPE", $traceId);
        }

        try {
            // Usar mesmo diretório do logo completo
            $uploadDir = '/var/www/html/crm-frontend/public/uploads/logos/';

            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0775, true)) {
                    error_log("Falha ao criar diretório: $uploadDir");
                    return $this->errorResponse(500, "Erro ao criar diretório de upload.", "UPLOAD_DIR_ERROR", $traceId);
                }
            }

            if (!is_writable($uploadDir)) {
                error_log("Diretório não gravável: $uploadDir");
                return $this->errorResponse(500, "Diretório de upload não tem permissão de escrita.", "UPLOAD_DIR_PERMISSION", $traceId);
            }

            // Gerar nome único com prefixo 'icon'
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'logo_icon_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
            $filepath = $uploadDir . $filename;

            // Mover arquivo
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                error_log("Falha ao mover arquivo de {$file['tmp_name']} para $filepath");
                return $this->errorResponse(500, "Erro ao fazer upload do arquivo.", "UPLOAD_ERROR", $traceId);
            }

            // Salvar caminho no banco
            $logoIconPath = '/uploads/logos/' . $filename;
            SystemConfig::set('layout.logo_icon', $logoIconPath, 'string', $userData->id);

            // Audit log
            $this->logAudit($userData->id, 'update', 'system_configs', null, null, ['logo_icon' => $logoIconPath]);

            return $this->successResponse(['logoIconPath' => $logoIconPath], "Logo ícone enviado com sucesso.", 200, $traceId);
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao processar upload.", "INTERNAL_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * GET /settings/security
     * Retorna as configurações de segurança do sistema
     */
    public function getSecurityConfig(array $headers): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        $this->requirePermission($userData, 'configuracoes', 'view');

        try {
            $twofaConfig = \Apoio19\Crm\Models\SystemConfig::get('security.twofa_enabled');
            $twofaEnabled = $twofaConfig && ($twofaConfig['config_value'] === '1' || $twofaConfig['config_value'] === 'true');

            return $this->successResponse(
                ['twofa_enabled' => $twofaEnabled],
                "Configurações de segurança recuperadas.",
                200,
                $traceId
            );
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao recuperar configurações de segurança.", "INTERNAL_ERROR", $traceId, $this->debugDetails($e));
        }
    }

    /**
     * PUT /settings/security
     * Atualiza as configurações de segurança do sistema
     */
    public function updateSecurityConfig(array $headers, array $requestData): array
    {
        $traceId = bin2hex(random_bytes(8));
        $userData = $this->authMiddleware->handle($headers);

        if (!$userData) {
            return $this->errorResponse(401, "Autenticação necessária.", "UNAUTHENTICATED", $traceId);
        }

        $this->requirePermission($userData, 'configuracoes', 'edit');

        try {
            if (isset($requestData['twofa_enabled'])) {
                $value = $requestData['twofa_enabled'] ? '1' : '0';
                \Apoio19\Crm\Models\SystemConfig::set('security.twofa_enabled', $value, 'string', $userData->id);
            }

            $this->logAudit($userData->id, 'update', 'system_configs', null, null, $requestData);

            return $this->successResponse(
                ['twofa_enabled' => ($value ?? '0') === '1'],
                "Configurações de segurança salvas.",
                200,
                $traceId
            );
        } catch (\Exception $e) {
            return $this->errorResponse(500, "Erro ao salvar configurações de segurança.", "INTERNAL_ERROR", $traceId, $this->debugDetails($e));
        }
    }
}
