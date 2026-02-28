<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Define o cabeçalho de resposta como JSON (exceto para upload de arquivos)
$requestUriForHeader = $_SERVER['REQUEST_URI'] ?? '';
if (!preg_match('#/upload-pdf$#', $requestUriForHeader)) {
    header("Content-Type: application/json");
}
// Define o diretório base da aplicação
define("BASE_PATH", dirname(__DIR__));

// --- Roteamento Simples ---
use Apoio19\Crm\Controllers\AuthController;
use Apoio19\Crm\Controllers\EmailController;
use Apoio19\Crm\Controllers\HealthController;
use Apoio19\Crm\Controllers\LeadController;
use Apoio19\Crm\Controllers\NotificationController;
use Apoio19\Crm\Controllers\HistoryController;
use Apoio19\Crm\Controllers\UserController;
use Apoio19\Crm\Controllers\KanbanController;
use Apoio19\Crm\Controllers\TarefaController;
use Apoio19\Crm\Controllers\TarefaUsuarioController;
use Apoio19\Crm\Controllers\WhatsappCampaignController;
use Apoio19\Crm\Controllers\WhatsappTemplateController;
use Apoio19\Crm\Controllers\WhatsappController;
use Apoio19\Crm\Controllers\ProposalController;
use Apoio19\Crm\Controllers\DashboardController;
use Apoio19\Crm\Controllers\ClientController;
use Apoio19\Crm\Controllers\ClientProjectController;
use Apoio19\Crm\Controllers\ProposalTemplateController;
use Apoio19\Crm\Controllers\ConfiguracoesController;
use Apoio19\Crm\Controllers\TrashController;

// --- Lógica de Extração de Caminho Corrigida ---
$requestUri = $_SERVER['REQUEST_URI']; // ex: /api/login?param=1
$basePath = '/api';


// Carrega o autoload do Composer
// Certifique-se de que o caminho para vendor/autoload.php está correto
$autoloadPath = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    echo json_encode(["error" => "Erro interno do servidor: Autoload não encontrado em " . $autoloadPath]);
    exit;
}
require $autoloadPath;

// Carrega variáveis de ambiente (ajuste o caminho se necessário)
try {
    // Assumindo que .env está na raiz do projeto (BASE_PATH)
    if (file_exists(BASE_PATH . '/.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
        $dotenv->load();
    } else {
        // Log ou aviso de que .env não foi encontrado, se necessário
        error_log("Arquivo .env não encontrado em " . BASE_PATH);
    }
} catch (\Exception $e) {
    error_log("Erro ao carregar .env: " . $e->getMessage());
    // Não interrompa a execução, mas logue o erro
}

// Remove query string se presente
if (($pos = strpos($requestUri, '?')) !== false) {
    $requestUri = substr($requestUri, 0, $pos);
}

$requestPath = null;
// Verifica se a URI começa com o caminho base da API
if (strpos($requestUri, $basePath) === 0) {
    // Obtém a parte após o caminho base
    $requestPath = substr($requestUri, strlen($basePath));
    // Garante que comece com uma barra ou seja apenas a barra (raiz da API)
    if (empty($requestPath)) {
        $requestPath = '/';
    } elseif ($requestPath[0] !== '/') {
        $requestPath = '/' . $requestPath; // ex: /login
    }
} else {
    // Não deve acontecer com a regra do Apache, mas é bom ter um fallback
    http_response_code(400); // Bad Request, a URL não corresponde ao esperado
    echo json_encode(["error" => "Requisição mal formatada."]);
    exit;
}
// -----------------------------------------------

$requestMethod = $_SERVER["REQUEST_METHOD"];

// Route to serve internal API uploads dynamically since frontend no longer manages it
if (strpos($requestPath, '/uploads/') === 0 && $requestMethod === 'GET') {
    $filePath = BASE_PATH . $requestPath;

    if (file_exists($filePath) && !is_dir($filePath)) {
        $mime_type = mime_content_type($filePath);
        if ($mime_type) {
            header("Content-Type: " . $mime_type);
        }

        // Cache control para melhorar performance no cliente
        header("Cache-Control: public, max-age=31536000"); // 1 ano cache

        readfile($filePath);
        exit;
    } else {
        http_response_code(404);
        echo json_encode(["error" => "Arquivo não encontrado."]);
        exit;
    }
}

// Route to serve proposal PDFs from dedicated storage directory
if (strpos($requestPath, '/storage/proposals/') === 0 && $requestMethod === 'GET') {
    $filePath = '/var/www/html/crm/storage' . substr($requestPath, strlen('/storage'));

    if (file_exists($filePath) && !is_dir($filePath)) {
        $mime_type = mime_content_type($filePath);
        if ($mime_type) {
            header("Content-Type: " . $mime_type);
        }
        header("Cache-Control: private, max-age=3600");
        header("Content-Disposition: inline; filename=\"" . basename($filePath) . "\"");
        readfile($filePath);
        exit;
    } else {
        http_response_code(404);
        echo json_encode(["error" => "Arquivo PDF não encontrado."]);
        exit;
    }
}

if ($requestPath === '/process_campaign' && $requestMethod === 'GET') {
    require BASE_PATH . '/scripts/process_althaia_campaign.php';
    exit;
}

// Rota de Login
if ($requestPath === '/login' && $requestMethod === 'POST') {

    try {
        // Ler o corpo da requisição JSON
        $input = json_decode(file_get_contents('php://input'), true);

        // Verificar se o JSON foi decodificado corretamente
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400); // Bad Request
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $authController = new AuthController();
        // Passar os dados de entrada para o método login
        $response = $authController->login($input);

        // Garante que o controller retorne um array
        if (is_array($response)) {
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno. Resposta inesperada do servidor."]);
        }

        exit;
    } catch (\Throwable $th) {
        error_log("Erro no AuthController->login: " . $th->getMessage() . "\n" . $th->getTraceAsString());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao processar o login.", "status" => $th->getMessage() . "\n" . $th->getTraceAsString()]);
        exit;
    }
}

// Rota de Refresh Token
if ($requestPath === '/refresh' && $requestMethod === 'POST') {

    $controller = new AuthController();
    echo json_encode($controller->refresh());
    exit;
}

if ($requestPath === '/forgot-password' && $requestMethod === 'POST') {

    $input = json_decode(file_get_contents('php://input'), true);

    // Verificar se o JSON foi decodificado corretamente
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
        exit;
    }

    $controller = new AuthController();
    echo json_encode($controller->requestPasswordReset($input));

    exit;
}

if ($requestPath === '/reset-password' && $requestMethod === 'POST') {

    $input = json_decode(file_get_contents('php://input'), true);

    // Verificar se o JSON foi decodificado corretamente
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
        exit;
    }

    $controller = new AuthController();
    echo json_encode($controller->resetPassword($input));

    exit;
}

// Rota de logout
if ($requestPath === '/logout' && $requestMethod === 'POST') {

    try {
        // Ler o corpo da requisição JSON
        $input = json_decode(file_get_contents('php://input'), true);

        // Verificar se o JSON foi decodificado corretamente
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400); // Bad Request
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }
        $controller = new AuthController();
        $controller->logout($input);
        exit;
    } catch (\Throwable $th) {
        error_log("Erro no AuthController->logout: " . $th->getMessage() . "\n" . $th->getTraceAsString());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao processar o logout.", "status" => $th->getMessage() . "\n" . $th->getTraceAsString()]);
        exit;
    }
}

// Rota de verificação de 2FA
if ($requestPath === '/2fa/verify' && $requestMethod === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }
        $controller = new AuthController();
        $response = $controller->verify2FA($input);
        echo json_encode($response);
    } catch (\Throwable $th) {
        error_log("Erro no AuthController->verify2FA: " . $th->getMessage() . "\n" . $th->getTraceAsString());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao verificar código 2FA."]);
    }
    exit;
}

// GET - Configurações de segurança (2FA)
if ($requestPath === '/settings/security' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new ConfiguracoesController();
        $response = $controller->getSecurityConfig($headers);
        if (is_array($response)) {
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno."]);
        }
    } catch (\Throwable $th) {
        error_log("Erro em getSecurityConfig: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao recuperar configurações de segurança."]);
    }
    exit;
}

// PUT - Atualizar configurações de segurança (2FA)
if ($requestPath === '/settings/security' && $requestMethod === 'PUT') {
    try {
        $headers = getallheaders();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $controller = new ConfiguracoesController();
        $response = $controller->updateSecurityConfig($headers, $input);
        if (is_array($response)) {
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno."]);
        }
    } catch (\Throwable $th) {
        error_log("Erro em updateSecurityConfig: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao salvar configurações de segurança."]);
    }
    exit;
}

// Rotas de Leads 
if ($requestPath === '/leads' && $requestMethod === 'GET') {

    try {

        $headers = getallheaders();
        $leadsController = new LeadController();

        // Captura todos os parâmetros da query string
        $queryParams = $_GET;

        // Executa o método com filtros
        $response = $leadsController->index($headers, $queryParams);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\InvalidArgumentException $e) {
        http_response_code(400); // Bad Request
        echo json_encode([
            "error" => "Parâmetros inválidos.",
            "detalhes" => $e->getMessage()
        ]);
    } catch (\PDOException $e) {
        http_response_code(500); // Erro de banco de dados
        echo json_encode([
            "error" => "Erro ao acessar o banco de dados.",
            "detalhes" => $e->getMessage()
        ]);
    } catch (\Exception $e) {
        http_response_code(500); // Erro genérico
        echo json_encode([
            "error" => "Erro interno no servidor.",
            "detalhes" => $e->getMessage()
        ]);
    }

    exit;
}
// Rota de Estatísticas de Leads
if ($requestPath === '/leads/stats' && $requestMethod === 'GET') {

    try {
        $headers = getallheaders();
        $leadsController = new LeadController();

        $response = $leadsController->stats($headers);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode([
            "error" => "Parâmetros inválidos.",
            "detalhes" => $e->getMessage()
        ]);
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode([
            "error" => "Erro ao acessar o banco de dados.",
            "detalhes" => $e->getMessage()
        ]);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode([
            "error" => "Erro interno no servidor.",
            "detalhes" => $e->getMessage()
        ]);
    }

    exit;
}
// Rota de inserção de lead
if ($requestPath === '/leads' && $requestMethod === 'POST') {

    try {
        // Ler o corpo da requisição JSON
        $input = json_decode(file_get_contents('php://input'), true);

        // Verificar se o JSON foi decodificado corretamente
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400); // Bad Request
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $headers = getallheaders();
        $leadsController = new LeadController();

        // Passar os dados de entrada para o método store
        $response = $leadsController->store($headers, $input);

        // Garante que o controller retorne um array
        if (is_array($response)) {
            http_response_code(201); // Created
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno. Resposta inesperada do servidor."]);
        }
    } catch (\Throwable $th) {
        error_log("Erro no LeadController->store: " . $th->getMessage() . "\n" . $th->getTraceAsString());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao processar a criação do lead.", "status" => $th->getMessage() . "\n" . $th->getTraceAsString()]);
    }
    exit;
}
// Rota de obtenção de um lead específico
if (preg_match('#^/leads/(\d+)$#', $requestPath, $matches) && $requestMethod === 'GET') {
    $leadId = $matches[1];

    try {
        $headers = getallheaders();
        $leadsController = new LeadController();

        // Executa o método e valida a resposta
        $response = $leadsController->show($headers, $leadId);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            // Resposta inesperada
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\InvalidArgumentException $e) {
        http_response_code(400); // Bad Request
        echo json_encode([
            "error" => "Parâmetros inválidos.",
            "detalhes" => $e->getMessage()
        ]);
    } catch (\PDOException $e) {
        http_response_code(500); // Erro de banco de dados
        echo json_encode([
            "error" => "Erro ao acessar o banco de dados.",
            "detalhes" => $e->getMessage()
        ]);
    } catch (\Exception $e) {
        http_response_code(500); // Erro genérico
        echo json_encode([
            "error" => "Erro interno no servidor.",
            "detalhes" => $e->getMessage()
        ]);
    }

    exit;
}
// Rota de atualização de um lead específico
if (preg_match('#^/leads/(\d+)$#', $requestPath, $matches) && $requestMethod === 'PUT') {
    $leadId = $matches[1];

    // Ler o corpo da requisição JSON
    $input = json_decode(file_get_contents('php://input'), true);

    // Verificar se o JSON foi decodificado corretamente
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
        exit;
    }

    try {
        $headers = getallheaders();
        $leadsController = new LeadController();

        // Executa o método e valida a resposta
        $response = $leadsController->update($headers, $leadId, $input);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            // Resposta inesperada
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\InvalidArgumentException $e) {
        http_response_code(400); // Bad Request
        echo json_encode([
            "error" => "Parâmetros inválidos.",
            "detalhes" => $e->getMessage()
        ]);
    } catch (\PDOException $e) {
        http_response_code(500); // Erro de banco de dados
        echo json_encode([
            "error" => "Erro ao acessar o banco de dados.",
            "detalhes" => $e->getMessage()
        ]);
    } catch (\Exception $e) {
        http_response_code(500); // Erro genérico
        echo json_encode([
            "error" => "Erro interno no servidor.",
            "detalhes" => $e->getMessage()
        ]);
    }

    exit;
}
// Rota de exclusão de um lead específico
if (preg_match('#^/leads/(\d+)$#', $requestPath, $matches) && $requestMethod === 'DELETE') {
    $leadId = $matches[1];

    try {
        $headers = getallheaders();
        $leadsController = new LeadController();

        // Executa o método e valida a resposta
        $response = $leadsController->destroy($headers, $leadId);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            // Resposta inesperada
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\InvalidArgumentException $e) {
        http_response_code(400); // Bad Request
        echo json_encode([
            "error" => "Parâmetros inválidos.",
            "detalhes" => $e->getMessage()
        ]);
    } catch (\PDOException $e) {
        http_response_code(500); // Erro de banco de dados
        echo json_encode([
            "error" => "Erro ao acessar o banco de dados.",
            "detalhes" => $e->getMessage()
        ]);
    } catch (\Exception $e) {
        http_response_code(500); // Erro genérico
        echo json_encode([
            "error" => "Erro interno no servidor.",
            "detalhes" => $e->getMessage()
        ]);
    }
    exit;
}

// Rotas de Histórico de Interações
if ($requestPath === '/history' && $requestMethod === 'POST') {

    try {
        // Ler o corpo da requisição JSON
        $input = json_decode(file_get_contents('php://input'), true);

        // Verificar se o JSON foi decodificado corretamente
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400); // Bad Request
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $headers = getallheaders();
        $historyController = new HistoryController();

        // Passar os dados de entrada para o método logAction
        $response = $historyController->logAction($headers, $input);

        // Garante que o controller retorne um array
        if (is_array($response)) {
            http_response_code(201); // Created
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno. Resposta inesperada do servidor."]);
        }
    } catch (\Throwable $th) {
        error_log("Erro no LeadController->logAction: " . $th->getMessage() . "\n" . $th->getTraceAsString());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao processar o histórico de interações.", "status" => $th->getMessage() . "\n" . $th->getTraceAsString()]);
    }
    exit;
}
// Rota de obtenção do histórico de interações de um lead específico
if (preg_match('#^/history/(\d+)$#', $requestPath, $matches) && $requestMethod === 'GET') {
    $leadId = $matches[1];

    try {
        $headers = getallheaders();
        $historyController = new HistoryController();

        // Executa o método e valida a resposta
        $response = $historyController->getHistory($headers, $leadId);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            // Resposta inesperada
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\InvalidArgumentException $e) {
        http_response_code(400); // Bad Request
        echo json_encode([
            "error" => "Parâmetros inválidos.",
            "detalhes" => $e->getMessage()
        ]);
    } catch (\PDOException $e) {
        http_response_code(500); // Erro de banco de dados
        echo json_encode([
            "error" => "Erro ao acessar o banco de dados.",
            "detalhes" => $e->getMessage()
        ]);
    } catch (\Exception $e) {
        http_response_code(500); // Erro genérico
        echo json_encode([
            "error" => "Erro interno no servidor.",
            "detalhes" => $e->getMessage()
        ]);
    }

    exit;
}

// Rotas de Notificações
if ($requestPath === '/notifications' && $requestMethod === 'POST') {

    try {
        // Ler o corpo da requisição JSON
        $input = json_decode(file_get_contents('php://input'), true);

        // Verificar se o JSON foi decodificado corretamente
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400); // Bad Request
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $headers = getallheaders();
        $notificationController = new NotificationController();
        // Passar os dados de entrada para o método store
        $response = $notificationController->store($headers, $input);
        // Garante que o controller retorne um array
        if (is_array($response)) {
            http_response_code(201); // Created
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno. Resposta inesperada do servidor."]);
        }
        exit;
    } catch (\Throwable $th) {
        error_log("Erro ao decodificar JSON: " . $th->getMessage());
        http_response_code(400);
        echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
        exit;
    }
}

if ($requestPath === '/notifications' && $requestMethod === 'GET') {
    try {

        $headers = getallheaders();
        $notificationController = new NotificationController();

        // Executa o método e valida a resposta
        $response = $notificationController->index($headers);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            // Resposta inesperada
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\InvalidArgumentException $e) {
        http_response_code(400); // Bad Request
        echo json_encode([
            "error" => "Parâmetros inválidos.",
            "detalhes" => $e->getMessage()
        ]);
    } catch (\PDOException $e) {
        http_response_code(500); // Erro de banco de dados
        echo json_encode([
            "error" => "Erro ao acessar o banco de dados.",
            "detalhes" => $e->getMessage()
        ]);
    } catch (\Exception $e) {
        http_response_code(500); // Erro genérico
        echo json_encode([
            "error" => "Erro interno no servidor.",
            "detalhes" => $e->getMessage()
        ]);
    }

    exit;
}
//rota de leitura de todas as notificações
if ($requestPath === '/notifications/mark-all-read' && $requestMethod === 'PATCH') {
    try {
        // Lógica para atualizar notificações
        $headers = getallheaders();
        $notificationController = new NotificationController();

        // Executa o método e valida a resposta
        $response = $notificationController->markAllAsRead($headers);
        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            // Resposta inesperada
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\Throwable $th) {
        error_log("Erro ao marcar todas as notificações como lidas: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao marcar notificações como lidas"]);
    }
    exit;
}

if (preg_match('#^/notifications/(\d+)/read$#', $requestPath, $matches) && $requestMethod === 'PATCH') {
    $leadId = $matches[1];

    try {
        // Lógica para atualizar uma notificação específica
        $headers = getallheaders();
        $notificationController = new NotificationController();

        // Executa o método e valida a resposta
        $response = $notificationController->markAsRead($headers, $leadId);
        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            // Resposta inesperada
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\Throwable $th) {
        error_log("Erro ao marcar notificação como lida: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao marcar notificação como lida"]);
    }
    exit;
}

if ($requestPath === '/notifications' && $requestMethod === 'DELETE') {

    try {
        // Lógica para excluir todas as notificações
        $headers = getallheaders();
        $notificationController = new NotificationController();

        // Executa o método e valida a resposta
        $response = $notificationController->deleteAll($headers);
        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            // Resposta inesperada
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\Throwable $th) {
        error_log("Erro ao excluir notificações: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao excluir notificações"]);
    }
    exit;
}

// Rotas da Lixeira (Trash)
if ($requestPath === '/trash' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new TrashController();
        $response = $controller->index($headers);
        if (is_array($response)) {
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno. Resposta inesperada do servidor."]);
        }
    } catch (\Throwable $th) {
        error_log("Erro no TrashController->index: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao listar itens da lixeira."]);
    }
    exit;
}

if (preg_match('#^/trash/([^/]+)/(\d+)/restore$#', $requestPath, $matches) && $requestMethod === 'POST') {
    $type = $matches[1];
    $id = (int)$matches[2];

    try {
        $headers = getallheaders();
        $controller = new TrashController();
        $response = $controller->restore($headers, $type, $id);
        if (is_array($response)) {
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno. Resposta inesperada do servidor."]);
        }
    } catch (\Throwable $th) {
        error_log("Erro no TrashController->restore: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao restaurar item."]);
    }
    exit;
}

if (preg_match('#^/notifications/(\d+)$#', $requestPath, $matches) && $requestMethod === 'DELETE') {
    $leadId = $matches[1];
    try {
        // Lógica para excluir uma notificação específica
        $headers = getallheaders();
        $notificationController = new NotificationController();

        // Executa o método e valida a resposta
        $response = $notificationController->delete($headers, $leadId);
        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            // Resposta inesperada
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\Throwable $th) {
        error_log("Erro ao excluir notificação: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao excluir notificação"]);
    }
    exit;
}

/* Endpoints de Configurações de Leads */
// GET /settings/leads - Obter configurações (já existe)
if ($requestPath === '/settings/leads' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $leadController = new LeadController();

        // Capturar parâmetro type se fornecido
        $type = $_GET['type'] ?? null;

        $response = $leadController->getSettings($headers, $type);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode([
            "error" => "Erro interno no servidor.",
            "detalhes" => $e->getMessage()
        ]);
    }
    exit;
}

// POST /settings/leads - Criar nova configuração
if ($requestPath === '/settings/leads' && $requestMethod === 'POST') {
    try {
        // Ler o corpo da requisição JSON
        $input = json_decode(file_get_contents('php://input'), true);

        // Verificar se o JSON foi decodificado corretamente
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $headers = getallheaders();
        $leadController = new LeadController();

        $response = $leadController->storeSettings($headers, $input);

        if (is_array($response)) {
            http_response_code(201); // Created
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno. Resposta inesperada do servidor."]);
        }
    } catch (\Throwable $th) {
        error_log("Erro no LeadController->storeSettings: " . $th->getMessage() . "\n" . $th->getTraceAsString());
        http_response_code(500);
        echo json_encode([
            "error" => "Erro interno ao criar configuração de lead.",
            "status" => $th->getMessage() . "\n" . $th->getTraceAsString()
        ]);
    }
    exit;
}

// PUT /settings/leads/{id} - Atualizar configuração existente
if (preg_match('#^/settings/leads/(\d+)$#', $requestPath, $matches) && $requestMethod === 'PUT') {
    $settingId = (int)$matches[1];

    try {
        // Ler o corpo da requisição JSON
        $input = json_decode(file_get_contents('php://input'), true);

        // Verificar se o JSON foi decodificado corretamente
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $headers = getallheaders();
        $leadController = new LeadController();

        $response = $leadController->updateSettings($headers, $settingId, $input);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode([
            "error" => "Erro interno no servidor.",
            "detalhes" => $e->getMessage()
        ]);
    }
    exit;
}

// DELETE /settings/leads/{id} - Excluir configuração
if (preg_match('#^/settings/leads/(\d+)$#', $requestPath, $matches) && $requestMethod === 'DELETE') {
    $settingId = (int)$matches[1];

    try {
        $headers = getallheaders();
        $leadController = new LeadController();

        $response = $leadController->deleteSettings($headers, $settingId);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode([
            "error" => "Erro interno no servidor.",
            "detalhes" => $e->getMessage()
        ]);
    }
    exit;
}

// --- Endpoints de Configurações de Layout ---

// GET /settings/layout - Obter configurações de layout
if ($requestPath === '/settings/layout' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new ConfiguracoesController();
        $response = $controller->getLayoutConfig($headers);

        if (is_array($response)) {
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno. Resposta inesperada do servidor."]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno.", "detalhes" => $e->getMessage()]);
    }
    exit;
}

// PUT /settings/layout - Atualizar configurações de layout
if ($requestPath === '/settings/layout' && $requestMethod === 'PUT') {
    try {
        $headers = getallheaders();
        $input = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido."]);
            exit;
        }

        $controller = new ConfiguracoesController();
        $response = $controller->updateLayoutConfig($headers, $input);

        if (is_array($response)) {
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno. Resposta inesperada do servidor."]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno.", "detalhes" => $e->getMessage()]);
    }
    exit;
}

// POST /settings/upload-logo - Fazer upload do logo
if ($requestPath === '/settings/upload-logo' && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $controller = new ConfiguracoesController();
        $response = $controller->uploadLogo($headers, $_FILES);

        if (is_array($response)) {
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno. Resposta inesperada do servidor."]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno.", "detalhes" => $e->getMessage()]);
    }
    exit;
}

// POST /settings/upload-logo-icon - Fazer upload do ícone (logo collapse)
if ($requestPath === '/settings/upload-logo-icon' && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $controller = new ConfiguracoesController();
        $response = $controller->uploadLogoIcon($headers, $_FILES);

        if (is_array($response)) {
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno. Resposta inesperada do servidor."]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno.", "detalhes" => $e->getMessage()]);
    }
    exit;
}

// Endpoint de tratativas de usuários
if ($requestPath === '/users' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $users = new UserController();

        // Capturar parâmetros da query string
        $queryParams = $_GET;

        // Listar usuários
        $response = $users->index($headers, $queryParams);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno. Resposta inesperada do servidor."]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode([
            "error" => "Erro interno no servidor.",
            "detalhes" => $e->getMessage()
        ]);
        error_log("Erro ao listar usuários: " . $e->getMessage());
    }
    exit;
}

// GET /users/profile - Obter perfil do usuário logado
if ($requestPath === '/users/profile' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $userController = new UserController();
        $response = $userController->profile($headers);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno."]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno.", "detalhes" => $e->getMessage()]);
    }
    exit;
}

// PUT /users/profile - Atualizar perfil do usuário logado
if ($requestPath === '/users/profile' && $requestMethod === 'PUT') {
    try {
        $headers = getallheaders();
        $input = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido."]);
            exit;
        }

        $userController = new UserController();
        $response = $userController->updateProfile($headers, $input);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno."]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno.", "detalhes" => $e->getMessage()]);
    }
    exit;
}

// GET /users/permissions - Obter permissões disponíveis
if ($requestPath === '/users/permissions' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $userController = new UserController();
        $response = $userController->getPermissions($headers);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno."]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno.", "detalhes" => $e->getMessage()]);
    }
    exit;
}

// GET /users/{id} - Obter usuário específico
if (preg_match('#^/users/(\d+)$#', $requestPath, $matches) && $requestMethod === 'GET') {
    $userId = (int)$matches[1];
    try {
        $headers = getallheaders();
        $userController = new UserController();
        $response = $userController->show($headers, $userId);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno."]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno.", "detalhes" => $e->getMessage()]);
    }
    exit;
}

// DELETE /users/{id} - Excluir usuário
if (preg_match('#^/users/(\d+)$#', $requestPath, $matches) && $requestMethod === 'DELETE') {
    $userId = (int)$matches[1];
    try {
        $headers = getallheaders();
        $userController = new UserController();
        $response = $userController->destroy($headers, $userId);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno."]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno.", "detalhes" => $e->getMessage()]);
    }
    exit;
}

// POST /users/bulk-action - Ações em lote
if ($requestPath === '/users/bulk-action' && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $input = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido."]);
            exit;
        }

        $userController = new UserController();
        $response = $userController->bulkAction($headers, $input);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno."]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno.", "detalhes" => $e->getMessage()]);
    }
    exit;
}

if (preg_match('#^/users/activate/(\d+)$#', $requestPath, $matches) && $requestMethod === 'PATCH') {
    $userId = (int)$matches[1];

    try {
        $headers = getallheaders();
        $userController = new UserController();

        // Ativar usuário
        $response = $userController->activate($headers, $userId);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode([
            "error" => "Erro interno no servidor.",
            "detalhes" => $e->getMessage()
        ]);
        error_log("Erro ao ativar usuário: " . $e->getMessage());
    }
    exit;
}

if (preg_match('#^/users/deactivate/(\d+)$#', $requestPath, $matches) && $requestMethod === 'PATCH') {
    $userId = (int)$matches[1];

    try {
        $headers = getallheaders();
        $userController = new UserController();

        // Desativar usuário
        $response = $userController->deactivate($headers, $userId);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode([
                "error" => "Erro interno. Resposta inesperada do servidor.",
                "detalhes" => $response
            ]);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode([
            "error" => "Erro interno no servidor.",
            "detalhes" => $e->getMessage()
        ]);
        error_log("Erro ao desativar usuário: " . $e->getMessage());
    }
    exit;
}

// criar usuário
if ($requestPath === '/users' && $requestMethod === 'POST') {
    try {
        // Ler o corpo da requisição JSON
        $input = json_decode(file_get_contents('php://input'), true);

        // Verificar se o JSON foi decodificado corretamente
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $headers = getallheaders();
        $userController = new UserController();

        // Passar os dados de entrada para o método store
        $response = $userController->store($headers, $input);

        // Garante que o controller retorne um array
        if (is_array($response)) {
            http_response_code(201); // Created
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno. Resposta inesperada do servidor."]);
        }
    } catch (\Exception $th) {
        error_log("Erro no UserController->store: " . $th->getMessage() . "\n" . $th->getTraceAsString());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao processar a criação do usuário.", "status" => $th->getMessage() . "\n" . $th->getTraceAsString()]);
    }
    exit;
}

// alterar usuários
if (preg_match('#^/users/(\d+)$#', $requestPath, $matches) && $requestMethod === 'PUT') {
    $userId = (int)$matches[1];

    try {
        // Ler o corpo da requisição JSON
        $input = json_decode(file_get_contents('php://input'), true);

        // Verificar se o JSON foi decodificado corretamente
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $headers = getallheaders();
        $userController = new UserController();

        // Passar os dados de entrada para o método update
        $response = $userController->update($headers, $userId, $input);

        // Garante que o controller retorne um array
        if (is_array($response)) {
            http_response_code(200); // OK
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno. Resposta inesperada do servidor."]);
        }
    } catch (\Exception $th) {
        error_log("Erro no UserController->update: " . $th->getMessage() . "\n" . $th->getTraceAsString());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao processar a atualização do usuário.", "status" => $th->getMessage() . "\n" . $th->getTraceAsString()]);
    }
    exit;
}

// Rota de envio de e-mail /api/email/send-welcome
if ($requestPath === '/email/send-welcome' && $requestMethod === 'POST') {
    $requestData = json_decode(file_get_contents('php://input'), true);

    if (!$requestData) {
        http_response_code(400);
        echo json_encode(["error" => "Corpo da requisição vazio ou inválido."]);
        exit;
    }

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
        exit;
    }

    $controller = new EmailController();
    $response = $controller->sendWelcomeEmail($requestData);

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Endpoint de Health Check
if ($requestPath === '/health' && $requestMethod === 'GET') {
    try {
        // Verificação de saúde da API
        $healthController = new HealthController();
        $response = $healthController->check();

        echo json_encode($response);
    } catch (\Exception $e) {
        //throw $e;
    }
    exit;
}

// ============================================
// ROTAS DO KANBAN
// ============================================

// GET /api/kanban/board - Obter quadro completo do Kanban
if ($requestPath === '/kanban/board' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $queryParams = $_GET;
        $controller = new KanbanController();
        $response = $controller->getBoard($headers, $queryParams);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em /kanban/board: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao buscar quadro Kanban."]);
    }
    exit;
}

// POST /api/kanban/columns - Criar nova coluna
if ($requestPath === '/kanban/columns' && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $requestData = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $controller = new KanbanController();
        $response = $controller->createColumn($headers, $requestData);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em POST /kanban/columns: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao criar coluna."]);
    }
    exit;
}

// PUT /api/kanban/columns/{id} - Atualizar coluna
if (preg_match('#^/kanban/columns/(\d+)$#', $requestPath, $matches) && $requestMethod === 'PUT') {
    try {
        $columnId = (int)$matches[1];
        $headers = getallheaders();
        $requestData = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $controller = new KanbanController();
        $response = $controller->updateColumn($headers, $columnId, $requestData);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em PUT /kanban/columns: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao atualizar coluna."]);
    }
    exit;
}

// DELETE /api/kanban/columns/{id} - Deletar coluna
if (preg_match('#^/kanban/columns/(\d+)$#', $requestPath, $matches) && $requestMethod === 'DELETE') {
    try {
        $columnId = (int)$matches[1];
        $headers = getallheaders();
        $controller = new KanbanController();
        $response = $controller->deleteColumn($headers, $columnId);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em DELETE /kanban/columns: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao deletar coluna."]);
    }
    exit;
}

// POST /api/kanban/tasks/order - Atualizar ordem das tarefas
if ($requestPath === '/kanban/tasks/order' && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $requestData = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $controller = new KanbanController();
        $response = $controller->updateTaskOrder($headers, $requestData);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em POST /kanban/tasks/order: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao atualizar ordem das tarefas."]);
    }
    exit;
}

// ============================================
// ROTAS DE TAREFAS
// ============================================

// GET /api/kanban/tasks - Listar tarefas
if ($requestPath === '/kanban/tasks' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $queryParams = $_GET;
        $controller = new TarefaController();
        $response = $controller->index($headers, $queryParams);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em GET /kanban/tasks: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao listar tarefas."]);
    }
    exit;
}

// GET /api/kanban/tasks/{id} - Obter tarefa específica
if (preg_match('#^/kanban/tasks/(\d+)$#', $requestPath, $matches) && $requestMethod === 'GET') {
    try {
        $taskId = (int)$matches[1];
        $headers = getallheaders();
        $controller = new TarefaController();
        $response = $controller->show($headers, $taskId);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em GET /kanban/tasks/{id}: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao buscar tarefa."]);
    }
    exit;
}

// POST /api/kanban - Criar tarefa (Endpoint simplificado solicitado)
if ($requestPath === '/kanban' && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $requestData = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $controller = new TarefaController();
        $response = $controller->store($headers, $requestData);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em POST /kanban: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao criar tarefa."]);
    }
    exit;
}

// POST /api/kanban/tasks - Criar tarefa (Mantendo compatibilidade ou removendo se necessário)
if ($requestPath === '/kanban/tasks' && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $requestData = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $controller = new TarefaController();
        $response = $controller->store($headers, $requestData);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em POST /kanban/tasks: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao criar tarefa."]);
    }
    exit;
}

// PUT /api/kanban/tasks/{id} - Atualizar tarefa
if (preg_match('#^/kanban/tasks/(\d+)$#', $requestPath, $matches) && $requestMethod === 'PUT') {
    try {
        $taskId = (int)$matches[1];
        $headers = getallheaders();
        $requestData = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $controller = new TarefaController();
        $response = $controller->update($headers, $taskId, $requestData);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em PUT /kanban/tasks/{id}: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao atualizar tarefa."]);
    }
    exit;
}

// DELETE /api/kanban/tasks/{id} - Deletar tarefa
if (preg_match('#^/kanban/tasks/(\d+)$#', $requestPath, $matches) && $requestMethod === 'DELETE') {
    try {
        $taskId = (int)$matches[1];
        $headers = getallheaders();
        $controller = new TarefaController();
        $response = $controller->destroy($headers, $taskId);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em DELETE /kanban/tasks/{id}: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao deletar tarefa."]);
    }
    exit;
}

// GET /api/kanban/tasks/{id}/comments - Obter comentários de uma tarefa
if (preg_match('#^/kanban/tasks/(\d+)/comments$#', $requestPath, $matches) && $requestMethod === 'GET') {
    try {
        $taskId = (int)$matches[1];
        $headers = getallheaders();
        $controller = new TarefaController();
        $response = $controller->getComments($headers, $taskId);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em GET /kanban/tasks/{id}/comments: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao buscar comentários."]);
    }
    exit;
}

// POST /api/kanban/tasks/{id}/comments - Criar comentário
if (preg_match('#^/kanban/tasks/(\d+)/comments$#', $requestPath, $matches) && $requestMethod === 'POST') {
    try {
        $taskId = (int)$matches[1];
        $headers = getallheaders();
        $requestData = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $controller = new TarefaController();
        $response = $controller->addComment($headers, $taskId, $requestData);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em POST /kanban/tasks/{id}/comments: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao criar comentário."]);
    }
    exit;
}

// DELETE /api/kanban/comments/{id} - Deletar comentário
if (preg_match('#^/kanban/comments/(\d+)$#', $requestPath, $matches) && $requestMethod === 'DELETE') {
    try {
        $commentId = (int)$matches[1];
        $headers = getallheaders();
        $controller = new TarefaController();
        $response = $controller->deleteComment($headers, 0, $commentId); // taskId não é usado
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em DELETE /kanban/comments/{id}: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao deletar comentário."]);
    }
    exit;
}

// GET /api/kanban/logs - Obter logs de atividade
if ($requestPath === '/kanban/logs' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $queryParams = $_GET;
        $controller = new TarefaController();
        $response = $controller->getLogs($headers, $queryParams);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em GET /kanban/logs: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao buscar logs."]);
    }
    exit;
}

// POST /api/kanban/logs - Criar novo log de atividade
if ($requestPath === '/kanban/logs' && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $requestData = json_decode(file_get_contents('php://input'), true);
        $controller = new TarefaController();
        $response = $controller->createLog($headers, $requestData);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("Erro em POST /kanban/logs: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno ao criar log."]);
    }
    exit;
}

// ============================================================================
// Rotas de Relatórios do WhatsApp
if ($requestPath === '/whatsapp/analytics' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappController();
        $response = $controller->getAnalytics($headers, $_GET);

        http_response_code(200);
        echo json_encode($response);
    } catch (\Throwable $th) {
        error_log("Erro em GET /whatsapp/analytics: " . $th->getMessage() . "\n" . $th->getTraceAsString());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => "Resposta inválida"]);
    }
    exit;
}

// ============================================================================
// Rotas de Configuração do WhatsApp
if ($requestPath === '/whatsapp/config' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappController();
        $response = $controller->getConfig($headers);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => "Resposta inválida"]);
        }
    } catch (\Throwable $th) {
        error_log("Erro em GET /whatsapp/config: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

if ($requestPath === '/whatsapp/config' && $requestMethod === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido"]);
            exit;
        }
        $headers = getallheaders();
        $controller = new WhatsappController();
        $response = $controller->saveConfig($headers, $input);
        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => "Resposta inválida"]);
        }
    } catch (\Throwable $th) {
        error_log("Erro em POST /whatsapp/config: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// Sync WhatsApp phone numbers from Meta API
if ($requestPath === '/whatsapp/phone-numbers/sync' && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappController();
        $response = $controller->syncPhoneNumbers($headers);
        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => "Resposta inválida"]);
        }
    } catch (\Throwable $th) {
        error_log("Erro em POST /whatsapp/phone-numbers/sync: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// Get stored WhatsApp phone numbers
if ($requestPath === '/whatsapp/phone-numbers' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappController();
        $response = $controller->getPhoneNumbers($headers);
        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => "Resposta inválida"]);
        }
    } catch (\Throwable $th) {
        error_log("Erro em GET /whatsapp/phone-numbers: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// As rotas de campanhas baseadas no `WhatsappController` foram removidas daqui para não conflitar com as rotas do `WhatsappCampaignController` que começam mais abaixo.

// Templates route
if ($requestPath === '/whatsapp/templates' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappController();
        $response = $controller->getTemplates($headers);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => "Resposta inválida"]);
        }
    } catch (\Throwable $th) {
        error_log("Erro em templates route: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// All raw contacts route
if ($requestPath === '/whatsapp/contacts/all' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappController();
        $queryParams = $_GET;
        $response = $controller->getContacts($headers, $queryParams);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => "Resposta inválida"]);
        }
    } catch (\Throwable $th) {
        error_log("Erro em contacts/all route: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

if ($requestPath === '/whatsapp/test-connection' && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappController();
        $response = $controller->testConnection($headers);
        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => "Resposta inválida"]);
        }
    } catch (\Throwable $th) {
        error_log("Erro em POST /whatsapp/test-connection: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

if ($requestPath === '/whatsapp/test-message' && $requestMethod === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido"]);
            exit;
        }
        $headers = getallheaders();
        $controller = new WhatsappController();
        $response = $controller->sendTestMessage($headers, $input);
        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => "Resposta inválida"]);
        }
    } catch (\Throwable $th) {
        error_log("Erro em POST /whatsapp/test-message: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// ROTAS DO WHATSAPP
// ============================================================================

// Campanhas WhatsApp - Listar todas
if ($requestPath === '/whatsapp/campaigns' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappCampaignController();
        $response = $controller->index($headers);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao listar campanhas"]);
    }
    exit;
}

// Campanhas WhatsApp - Criar nova
if ($requestPath === '/whatsapp/campaigns' && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $input = json_decode(file_get_contents('php://input'), true);
        $controller = new WhatsappCampaignController();
        $response = $controller->store($headers, $input);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao criar campanha"]);
    }
    exit;
}

// Campanhas WhatsApp - Buscar por ID
if (preg_match('#^/whatsapp/campaigns/(\d+)$#', $requestPath, $matches) && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappCampaignController();
        $response = $controller->show($headers, (int)$matches[1]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao buscar campanha"]);
    }
    exit;
}

// Campanhas WhatsApp - Atualizar
if (preg_match('#^/whatsapp/campaigns/(\d+)$#', $requestPath, $matches) && $requestMethod === 'PUT') {
    try {
        $headers = getallheaders();
        $input = json_decode(file_get_contents('php://input'), true);
        $controller = new WhatsappCampaignController();
        $response = $controller->update($headers, (int)$matches[1], $input);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao atualizar campanha"]);
    }
    exit;
}

// Campanhas WhatsApp - Iniciar
if (preg_match('#^/whatsapp/campaigns/(\d+)/start$#', $requestPath, $matches) && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappCampaignController();
        $response = $controller->start($headers, (int)$matches[1]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao iniciar campanha"]);
    }
    exit;
}

// Campanhas WhatsApp - Status (Play/Pause)
if (preg_match('#^/whatsapp/campaigns/(\d+)/status$#', $requestPath, $matches) && $requestMethod === 'PATCH') {
    try {
        $headers = getallheaders();
        $input = json_decode(file_get_contents('php://input'), true);
        $controller = new WhatsappCampaignController();
        if (isset($input['status']) && $input['status'] === 'paused') {
            $response = $controller->pause($headers, (int)$matches[1]);
        } else {
            // Tratar outro status se necessário ou usar um método genérico
            http_response_code(400);
            $response = ["error" => "Status não suportado por esta rota diretamente"];
        }
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao mudar status da campanha"]);
    }
    exit;
}

// Campanhas WhatsApp - Contatos (Resumo)
if (preg_match('#^/whatsapp/campaigns/(\d+)/contacts$#', $requestPath, $matches) && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappCampaignController();
        $response = $controller->getCampaignContacts($headers, (int)$matches[1]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao obter contatos da campanha"]);
    }
    exit;
}

// Campanhas WhatsApp - Mensagens
if (preg_match('#^/whatsapp/campaigns/(\d+)/messages$#', $requestPath, $matches) && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappCampaignController();
        $response = $controller->getMessages($headers, (int)$matches[1]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao obter mensagens da campanha"]);
    }
    exit;
}

// Campanhas WhatsApp - Reenviar Mensagem Específica
if (preg_match('#^/whatsapp/campaigns/(\d+)/contacts/(\d+)/resend$#', $requestPath, $matches) && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappCampaignController();
        // matches[1] = campaign_id, matches[2] = contact_id
        $response = $controller->resendMessage($headers, (int)$matches[1], (int)$matches[2]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao reenviar mensagem"]);
    }
    exit;
}

// Campanhas WhatsApp - Clonar Campanha
if (preg_match('#^/whatsapp/campaigns/(\d+)/clone$#', $requestPath, $matches) && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappCampaignController();
        $response = $controller->cloneCampaign($headers, (int)$matches[1]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao clonar campanha"]);
    }
    exit;
}

// Campanhas WhatsApp - Adicionar Contatos (via Wizard)
if (preg_match('#^/whatsapp/campaigns/(\d+)/contacts$#', $requestPath, $matches) && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $input = json_decode(file_get_contents('php://input'), true);
        $controller = new WhatsappCampaignController();
        $response = $controller->addContacts($headers, (int)$matches[1], $input);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao adicionar contatos à campanha"]);
    }
    exit;
}

// Campanhas WhatsApp - Atribuir Respostas (Flows)
if (preg_match('#^/whatsapp/campaigns/(\d+)/responses$#', $requestPath, $matches) && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $input = json_decode(file_get_contents('php://input'), true);
        $controller = new WhatsappCampaignController();
        $response = $controller->saveResponsesConfig($headers, (int)$matches[1], $input);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao configurar respostas da campanha"]);
    }
    exit;
}

// Campanhas WhatsApp - Pausar
if (preg_match('#^/whatsapp/campaigns/(\d+)/pause$#', $requestPath, $matches) && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappCampaignController();
        $response = $controller->pause($headers, (int)$matches[1]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao pausar campanha"]);
    }
    exit;
}

// Campanhas WhatsApp - Cancelar
if (preg_match('#^/whatsapp/campaigns/(\d+)/cancel$#', $requestPath, $matches) && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappCampaignController();
        $response = $controller->cancel($headers, (int)$matches[1]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao cancelar campanha"]);
    }
    exit;
}

// Campanhas WhatsApp - Deletar
if (preg_match('#^/whatsapp/campaigns/(\d+)$#', $requestPath, $matches) && $requestMethod === 'DELETE') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappCampaignController();
        $response = $controller->delete($headers, (int)$matches[1]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao deletar campanha"]);
    }
    exit;
}

// Templates WhatsApp - Listar todos
if ($requestPath === '/whatsapp/templates' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappTemplateController();
        $response = $controller->index($headers);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao listar templates"]);
    }
    exit;
}

// Templates WhatsApp - Criar novo
if ($requestPath === '/whatsapp/templates' && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $input = json_decode(file_get_contents('php://input'), true);
        $controller = new WhatsappTemplateController();
        $response = $controller->store($headers, $input);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao criar template"]);
    }
    exit;
}
// Templates WhatsApp - Webhook de recepção Meta
if ($requestPath === '/whatsapp/webhook' && $requestMethod === 'GET') {
    try {
        // Para GET, os parâmetros vêm da query string, não dos headers
        $queryParams = $_GET;

        // Log para debug
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => 'GET',
            'query_params' => $queryParams,
            'headers' => getallheaders()
        ];

        $filename = 'hook/webhook_verify_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.json';
        file_put_contents($filename, json_encode($logData, JSON_PRETTY_PRINT), LOCK_EX);

        $controller = new WhatsappController();
        $controller->verifyWebhook($queryParams);
    } catch (\Exception $e) {
        error_log("Erro na verificação do webhook: " . $e->getMessage());
        http_response_code(500);
    }
    exit;
}

// Webhook de recepção Meta — POST
if ($requestPath === '/whatsapp/webhook' && $requestMethod === 'POST') {
    try {
        $input = file_get_contents('php://input');

        // Log: raw input para diagnóstico
        error_log("[WEBHOOK] POST recebido. Tamanho: " . strlen($input) . " bytes");
        error_log("[WEBHOOK] Raw input: " . substr($input, 0, 2000)); // primeiros 2000 chars

        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[WEBHOOK] ERRO JSON parse: " . json_last_error_msg() . " | Raw: " . $input);
            http_response_code(200); // Sempre 200 para Meta não retentar
            echo json_encode(["success" => false, "error" => "JSON parse error"]);
            exit;
        }

        // Log: estrutura do payload
        error_log("[WEBHOOK] Payload decodificado: " . json_encode($data));

        // Salvar payload completo em arquivo para análise
        $hookDir = __DIR__ . '/hook/';
        if (!is_dir($hookDir)) {
            mkdir($hookDir, 0755, true);
        }
        $filename = $hookDir . 'webhook_data_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.json';
        $logData = [
            'timestamp'   => date('Y-m-d H:i:s'),
            'method'      => 'POST',
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'body'        => $data
        ];
        file_put_contents($filename, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        // Processar webhook
        $controller = new WhatsappController();
        $response = $controller->processWebhook($data);

        error_log("[WEBHOOK] Resultado processamento: " . json_encode($response));

        http_response_code(200);
        echo json_encode($response);
    } catch (\Exception $e) {
        error_log("[WEBHOOK] Exceção: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        http_response_code(200); // Sempre 200 para Meta não retentar
        echo json_encode(["success" => false]);
    }
    exit;
}

// Get all WhatsApp conversations
if ($requestPath === '/whatsapp/conversations' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappController();
        $response = $controller->getConversations($headers);
        http_response_code(200);
        echo json_encode($response);
    } catch (\Throwable $th) {
        error_log("Erro em GET /whatsapp/conversations: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// Get messages for a specific conversation
if (preg_match('#^/whatsapp/conversations/(\d+)/messages$#', $requestPath, $matches) && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $contactId = (int)$matches[1];
        $controller = new WhatsappController();
        $response = $controller->getMessages($headers, $contactId, $_GET);
        http_response_code(200);
        echo json_encode($response);
    } catch (\Throwable $th) {
        error_log("Erro em GET /whatsapp/conversations/{id}/messages: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// Send message to a conversation
if (preg_match('#^/whatsapp/conversations/(\d+)/messages$#', $requestPath, $matches) && $requestMethod === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido"]);
            exit;
        }

        $headers = getallheaders();
        $contactId = (int)$matches[1];
        $input['contact_id'] = $contactId;

        $controller = new WhatsappController();
        $response = $controller->sendMessage($headers, $input);
        http_response_code(200);
        echo json_encode($response);
    } catch (\Throwable $th) {
        error_log("Erro em POST /whatsapp/conversations/{id}/messages: " . $th->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}


// =================================================================================
// ROTAS DE TAREFAS DE USUÁRIO (NOVO MÓDULO)
// =================================================================================

// Listar tarefas do usuário
if ($requestPath === '/tarefas' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new TarefaUsuarioController();
        $response = $controller->index($headers, $_GET);
        echo json_encode($response);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno do servidor: " . $e->getMessage()]);
    }
    exit;
}

// Criar nova tarefa
if ($requestPath === '/tarefas' && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $requestData = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $controller = new TarefaUsuarioController();
        $response = $controller->store($headers, $requestData);
        echo json_encode($response);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno do servidor: " . $e->getMessage()]);
    }
    exit;
}

// Obter tarefa por ID
if (preg_match('/^\/tarefas\/(\d+)$/', $requestPath, $matches) && $requestMethod === 'GET') {
    try {
        $id = (int)$matches[1];
        $headers = getallheaders();
        $controller = new TarefaUsuarioController();
        $response = $controller->show($headers, $id);
        echo json_encode($response);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno do servidor: " . $e->getMessage()]);
    }
    exit;
}

// Atualizar tarefa
if (preg_match('/^\/tarefas\/(\d+)$/', $requestPath, $matches) && $requestMethod === 'PUT') {
    try {
        $id = (int)$matches[1];
        $headers = getallheaders();
        $requestData = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido no corpo da requisição."]);
            exit;
        }

        $controller = new TarefaUsuarioController();
        $response = $controller->update($headers, $id, $requestData);
        echo json_encode($response);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno do servidor: " . $e->getMessage()]);
    }
    exit;
}

// Excluir tarefa
if (preg_match('/^\/tarefas\/(\d+)$/', $requestPath, $matches) && $requestMethod === 'DELETE') {
    try {
        $id = (int)$matches[1];
        $headers = getallheaders();
        $controller = new TarefaUsuarioController();
        $response = $controller->destroy($headers, $id);
        echo json_encode($response);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno do servidor: " . $e->getMessage()]);
    }
    exit;
}

// Templates WhatsApp - Buscar por ID
if (preg_match('#^/whatsapp/templates/(\d+)$#', $requestPath, $matches) && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappTemplateController();
        $response = $controller->show($headers, (int)$matches[1]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao buscar template"]);
    }
    exit;
}

// Templates WhatsApp - Atualizar
if (preg_match('#^/whatsapp/templates/(\d+)$#', $requestPath, $matches) && $requestMethod === 'PUT') {
    try {
        $headers = getallheaders();
        $input = json_decode(file_get_contents('php://input'), true);
        $controller = new WhatsappTemplateController();
        $response = $controller->update($headers, (int)$matches[1], $input);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao atualizar template"]);
    }
    exit;
}

// Templates WhatsApp - Deletar
if (preg_match('#^/whatsapp/templates/(\d+)$#', $requestPath, $matches) && $requestMethod === 'DELETE') {
    try {
        $headers = getallheaders();
        $controller = new WhatsappTemplateController();
        $response = $controller->delete($headers, (int)$matches[1]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao deletar template"]);
    }
    exit;
}

// =================================================================================
// ROTAS DE PROPOSTAS
// =================================================================================

// Listar propostas
if ($requestPath === '/proposals' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new ProposalController();
        $response = $controller->index($headers, $_GET);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao listar propostas: " . $e->getMessage()]);
    }
    exit;
}

// Criar proposta
if ($requestPath === '/proposals' && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido"]);
            exit;
        }
        $controller = new ProposalController();
        $response = $controller->store($headers, $input);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao criar proposta: " . $e->getMessage()]);
    }
    exit;
}

// Obter proposta por ID
if (preg_match('#^/proposals/(\d+)$#', $requestPath, $matches) && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new ProposalController();
        $response = $controller->show($headers, (int)$matches[1]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao buscar proposta: " . $e->getMessage()]);
    }
    exit;
}

// Atualizar proposta
if (preg_match('#^/proposals/(\d+)$#', $requestPath, $matches) && $requestMethod === 'PUT') {
    try {
        $headers = getallheaders();
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "JSON inválido"]);
            exit;
        }
        $controller = new ProposalController();
        $response = $controller->update($headers, (int)$matches[1], $input);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao atualizar proposta: " . $e->getMessage()]);
    }
    exit;
}

// Excluir proposta
if (preg_match('#^/proposals/(\d+)$#', $requestPath, $matches) && $requestMethod === 'DELETE') {
    try {
        $headers = getallheaders();
        $controller = new ProposalController();
        $response = $controller->destroy($headers, (int)$matches[1]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao excluir proposta: " . $e->getMessage()]);
    }
    exit;
}

// Upload de PDF externo para proposta
if (preg_match('#^/proposals/(\d+)/upload-pdf$#', $requestPath, $matches) && $requestMethod === 'POST') {
    header("Content-Type: application/json"); // Ensure JSON response
    try {
        $headers = getallheaders();
        $controller = new ProposalController();
        $response = $controller->uploadPdf($headers, (int)$matches[1]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao fazer upload do PDF: " . $e->getMessage()]);
    }
    exit;
}

// Gerar PDF da proposta
if (preg_match('#^/proposals/(\d+)/pdf$#', $requestPath, $matches) && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $controller = new ProposalController();
        $response = $controller->generatePdf($headers, (int)$matches[1]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao gerar PDF: " . $e->getMessage()]);
    }
    exit;
}

// Enviar proposta por e-mail
if (preg_match('#^/proposals/(\d+)/send$#', $requestPath, $matches) && $requestMethod === 'POST') {
    try {
        $headers = getallheaders();
        $controller = new ProposalController();
        $response = $controller->sendProposal($headers, (int)$matches[1]);
        echo json_encode($response);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao enviar proposta: " . $e->getMessage()]);
    }
    exit;
}

// Rotas de Templates de Propostas
// GET /proposal-templates - Listar todos os templates ativos
if ($requestPath === '/proposal-templates' && $requestMethod === 'GET') {
    $controller = new ProposalTemplateController();
    $controller->index();
    exit;
}

// GET /propos al-templates/{id} - Buscar template por ID
if (preg_match('/^\/proposal-templates\/(\d+)$/', $requestPath, $matches) && $requestMethod === 'GET') {
    $controller = new ProposalTemplateController();
    $controller->show((int)$matches[1]);
    exit;
}

// POST /proposal-templates - Criar novo template
if ($requestPath === '/proposal-templates' && $requestMethod === 'POST') {
    $controller = new ProposalTemplateController();
    $controller->store();
    exit;
}

// PUT /proposal-templates/{id} - Atualizar template
if (preg_match('/^\/proposal-templates\/(\d+)$/', $requestPath, $matches) && $requestMethod === 'PUT') {
    $controller = new ProposalTemplateController();
    $controller->update((int)$matches[1]);
    exit;
}

// DELETE /proposal-templates/{id} - Desativar template
if (preg_match('/^\/proposal-templates\/(\d+)$/', $requestPath, $matches) && $requestMethod === 'DELETE') {
    $controller = new ProposalTemplateController();
    $controller->destroy((int)$matches[1]);
}

// Dashboard Routes
if ($requestPath === '/dashboard/metrics' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $dashboardController = new DashboardController();

        $response = $dashboardController->getMetrics($headers);

        if (is_array($response)) {
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno. Resposta inesperada do servidor."]);
        }
    } catch (\Throwable $th) {
        error_log("Erro no DashboardController->getMetrics: " . $th->getMessage() . "\n" . $th->getTraceAsString());
        http_response_code(500);
        echo json_encode([
            "error" => "Erro interno ao processar métricas do dashboard.",
            "details" => $th->getMessage()
        ]);
    }
    exit;
}

// --- Fim do Roteamento ---


// --- Rotas de Clientes ---

// Listar clientes
if ($requestPath === '/clients' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new ClientController();
        $queryParams = $_GET;
        $response = $controller->index($headers, $queryParams);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => $response]);
        }
    } catch (\Throwable $th) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// Criar cliente
if ($requestPath === '/clients' && $requestMethod === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $headers = getallheaders();
        $controller = new ClientController();
        $response = $controller->store($headers, $input);

        if (is_array($response)) {
            http_response_code(201);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => $response]);
        }
    } catch (\Throwable $th) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// Obter cliente específico
if (preg_match('#^/clients/(\d+)$#', $requestPath, $matches) && $requestMethod === 'GET') {
    try {
        $id = $matches[1];
        $headers = getallheaders();
        $controller = new ClientController();
        $response = $controller->show($headers, $id);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => $response]);
        }
    } catch (\Throwable $th) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// Atualizar cliente
if (preg_match('#^/clients/(\d+)$#', $requestPath, $matches) && $requestMethod === 'PUT') {
    try {
        $id = $matches[1];
        $input = json_decode(file_get_contents('php://input'), true);
        $headers = getallheaders();
        $controller = new ClientController();
        $response = $controller->update($headers, $id, $input);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => $response]);
        }
    } catch (\Throwable $th) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// Promover Lead a Cliente
if (preg_match('#^/clients/promote/(\d+)$#', $requestPath, $matches) && $requestMethod === 'POST') {
    try {
        $leadId = $matches[1];
        $headers = getallheaders();
        $controller = new ClientController();
        $response = $controller->promoteLead($headers, $leadId);

        if (is_array($response)) {
            http_response_code(201);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => $response]);
        }
    } catch (\Throwable $th) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}


// --- Rotas de Projetos de Clientes ---

// Listar projetos (filtrado por cliente)
if ($requestPath === '/client-projects' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $controller = new ClientProjectController();
        $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

        // Se não houver client_id, talvez implementar listagem geral?
        // O método index requer clientId. Vamos assumir que é obrigatório por enquanto ou retornar vazio.
        if ($clientId > 0) {
            $response = $controller->index($headers, $clientId);
        } else {
            // Fallback ou erro? Vamos retornar array vazio para evitar quebra
            // Ou erro 400 Bad Request
            http_response_code(400);
            echo json_encode(["error" => "client_id is required"]);
            exit;
        }

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => $response]);
        }
    } catch (\Throwable $th) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// Criar projeto
if ($requestPath === '/client-projects' && $requestMethod === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $headers = getallheaders();
        $controller = new ClientProjectController();
        $response = $controller->store($headers, $input);

        if (is_array($response)) {
            http_response_code(201);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => $response]);
        }
    } catch (\Throwable $th) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// Obter projeto específico
if (preg_match('#^/client-projects/(\d+)$#', $requestPath, $matches) && $requestMethod === 'GET') {
    try {
        $id = $matches[1];
        $headers = getallheaders();
        $controller = new ClientProjectController();
        $response = $controller->show($headers, $id);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => $response]);
        }
    } catch (\Throwable $th) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// Atualizar projeto
if (preg_match('#^/client-projects/(\d+)$#', $requestPath, $matches) && $requestMethod === 'PUT') {
    try {
        $id = $matches[1];
        $input = json_decode(file_get_contents('php://input'), true);
        $headers = getallheaders();
        $controller = new ClientProjectController();
        $response = $controller->update($headers, $id, $input);
        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => $response]);
        }
    } catch (\Throwable $th) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// Deletar projeto
if (preg_match('#^/client-projects/(\d+)$#', $requestPath, $matches) && $requestMethod === 'DELETE') {
    try {
        $id = $matches[1];
        $headers = getallheaders();
        $controller = new ClientProjectController();
        $response = $controller->destroy($headers, $id);

        if (is_array($response)) {
            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Erro interno", "detalhes" => $response]);
        }
    } catch (\Throwable $th) {
        http_response_code(500);
        echo json_encode(["error" => "Erro interno", "detalhes" => $th->getMessage()]);
    }
    exit;
}

// Se nenhuma rota corresponder ap�s verificar todas as rotas definidas
http_response_code(404);
echo json_encode(["error" => "Endpoint n�o encontrado: " . $requestMethod . " " . $requestPath]);
exit;
