<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Define o cabeçalho de resposta como JSON
header("Content-Type: application/json");
// Define o diretório base da aplicação
define("BASE_PATH", dirname(__DIR__));

// --- Roteamento Simples ---
use Apoio19\Crm\Controllers\AuthController;
use Apoio19\Crm\Controllers\LeadController;
use Apoio19\Crm\Controllers\NotificationController;
use Apoio19\Crm\Controllers\HistoryController;

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

// --- Adicione outras rotas aqui ---
// Exemplo:
// if ($requestPath === '/leads' && $requestMethod === 'GET') { ... }

// --- Fim do Roteamento ---

// Se nenhuma rota corresponder após verificar todas as rotas definidas
http_response_code(404);
echo json_encode(["error" => "Endpoint não encontrado: " . $requestMethod . " " . $requestPath]);
exit;
