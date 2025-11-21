<?php

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// Define o cabeçalho de resposta como JSON
header("Content-Type: application/json");
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
use Apoio19\Crm\Controllers\WhatsappCampaignController;
use Apoio19\Crm\Controllers\WhatsappTemplateController;

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

// Endpoint de tratativas de usuários
if ($requestPath === '/users' && $requestMethod === 'GET') {
    try {
        $headers = getallheaders();
        $users = new UserController();

        // Capturar parâmetro type se fornecido
        $queryParams = [];

        // Listar usuários
        $users = $users->index($headers, $queryParams);

        echo json_encode($users);
        exit;
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode([
            "error" => "Erro interno no servidor.",
            "detalhes" => $e->getMessage()
        ]);
        error_log("Erro ao listar usuários: " . $e->getMessage());
    }
    http_response_code(501); // Not Implemented
    echo json_encode(["error" => "Endpoint de usuários não implementado."]);
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
if ($requestPath === '/users/create' && $requestMethod === 'POST') {
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
if (preg_match('#^/users/update/(\d+)$#', $requestPath, $matches) && $requestMethod === 'PUT') {
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

// POST /api/kanban/tasks - Criar tarefa
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

// ============================================================================
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

// --- Adicione outras rotas aqui ---
// Exemplo:
// if ($requestPath === '/leads' && $requestMethod === 'GET') { ... }

// --- Fim do Roteamento ---

// Se nenhuma rota corresponder após verificar todas as rotas definidas
http_response_code(404);
echo json_encode(["error" => "Endpoint não encontrado: " . $requestMethod . " " . $requestPath]);
exit;
