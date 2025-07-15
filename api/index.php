<?php

// Define o diretório base da aplicação
define("BASE_PATH", dirname(__DIR__));

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

// Define o cabeçalho de resposta como JSON
header("Content-Type: application/json");

// --- Lógica de Extração de Caminho Corrigida ---
$requestUri = $_SERVER['REQUEST_URI']; // ex: /api/login?param=1
$basePath = '/api';

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

// --- Roteamento Simples ---
use Apoio19\Crm\Controllers\AuthController;
use Apoio19\Crm\Controllers\LeadController;


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

// Rota de Leads GET
if ($requestPath === '/leads' && $requestMethod === 'GET') {

    try {

        $headers = getallheaders();
        $leadsController = new LeadController();

        // Executa o método e valida a resposta
        $response = $leadsController->index($headers);

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


// Rotas POST
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

// --- Adicione outras rotas aqui ---
// Exemplo:
// if ($requestPath === '/leads' && $requestMethod === 'GET') { ... }

// --- Fim do Roteamento ---

// Se nenhuma rota corresponder após verificar todas as rotas definidas
http_response_code(404);
echo json_encode(["error" => "Endpoint não encontrado: " . $requestMethod . " " . $requestPath]);
exit;
