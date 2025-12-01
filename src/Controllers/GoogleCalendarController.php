<?php

namespace Apoio19\Crm\Controllers;

use Apoio19\Crm\Services\GoogleAuthService;
use Apoio19\Crm\Services\GoogleCalendarService;
use Apoio19\Crm\Middleware\AuthMiddleware;

// Placeholder for Request/Response handling & Session management
// In a real framework, use Request/Response objects, session handling, and proper routing.
class GoogleCalendarController extends BaseController
{
    private AuthMiddleware $authMiddleware;
    private GoogleAuthService $googleAuthService;
    private GoogleCalendarService $googleCalendarService;

    public function __construct()
    {
        $this->authMiddleware = new AuthMiddleware();
        $this->googleAuthService = new GoogleAuthService();
        $this->googleCalendarService = new GoogleCalendarService();

        // Session start might be needed here or in a bootstrap file
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Redirects the user to Google for OAuth 2.0 authentication.
     * Requires CRM user authentication.
     *
     * @param array $headers Request headers.
     * @return void Redirects the user or returns error.
     */
    public function redirectToGoogleAuth(array $headers): void
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            echo json_encode(["error" => "Autenticação do CRM necessária para iniciar a integração com Google."]);
            return;
        }

        // Store user ID in session to retrieve it in the callback
        $_SESSION["google_oauth_user_id"] = $userData->userId;

        $authUrl = $this->googleAuthService->createAuthUrl();
        header("Location: " . $authUrl);
        exit(); // Important to stop script execution after redirect
    }

    /**
     * Handles the callback from Google after user authorization.
     * Exchanges the authorization code for tokens and stores them.
     *
     * @param array $queryParams Query parameters from Google (code, state, etc.).
     * @return array JSON response (success or error).
     */
    public function handleGoogleCallback(array $queryParams): array
    {
        // Retrieve user ID from session
        $userId = $_SESSION["google_oauth_user_id"] ?? null;
        unset($_SESSION["google_oauth_user_id"]); // Clean up session variable

        if (!$userId) {
            http_response_code(400);
            return ["error" => "Sessão de usuário inválida ou expirada durante o callback do Google."];
        }

        if (isset($queryParams["error"])) {
            http_response_code(400);
            return ["error" => "Erro retornado pelo Google: " . htmlspecialchars($queryParams["error"])];
        }

        if (!isset($queryParams["code"])) {
            http_response_code(400);
            return ["error" => "Código de autorização do Google não encontrado."];
        }

        $authCode = $queryParams["code"];

        if ($this->googleAuthService->handleCallback($authCode, $userId)) {
            http_response_code(200);
            // Redirect user back to a CRM page (e.g., settings or calendar view)
            // header("Location: /crm/calendar?google_auth=success"); exit();
            return ["message" => "Autenticação com Google concluída e tokens armazenados com sucesso."];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao processar o callback do Google e armazenar os tokens."];
        }
    }

    /**
     * List Google Calendar events for the authenticated CRM user.
     *
     * @param array $headers Request headers.
     * @param array $queryParams Optional query parameters for filtering (timeMin, timeMax, etc.).
     * @return array JSON response.
     */
    public function listEvents(array $headers, array $queryParams = []): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        $events = $this->googleCalendarService->listEvents($userData->userId, $queryParams);

        if ($events === null) {
            // Check if the reason is lack of authentication
            if (!$this->googleAuthService->getAuthenticatedClientForUser($userData->userId)) {
                http_response_code(401); // Or a custom code indicating Google Auth needed
                return ["error" => "Integração com Google Calendar não autorizada ou token inválido. Por favor, autorize o acesso.", "needs_google_auth" => true];
            }
            http_response_code(500);
            return ["error" => "Falha ao buscar eventos do Google Calendar."];
        }

        http_response_code(200);
        return ["data" => $events];
    }

    /**
     * Create a Google Calendar event for the authenticated CRM user.
     *
     * @param array $headers Request headers.
     * @param array $requestData Event data.
     * @return array JSON response.
     */
    public function createEvent(array $headers, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        // Basic validation for required fields
        if (empty($requestData["summary"]) || empty($requestData["startDateTime"]) || empty($requestData["endDateTime"])) {
            http_response_code(400);
            return ["error" => "Campos obrigatórios (summary, startDateTime, endDateTime) não fornecidos."];
        }
        // Add more validation (date format RFC3339, etc.)

        $createdEvent = $this->googleCalendarService->createEvent($userData->userId, $requestData);

        if ($createdEvent) {
            http_response_code(201);
            return ["message" => "Evento criado com sucesso no Google Calendar.", "event" => $createdEvent];
        } else {
            if (!$this->googleAuthService->getAuthenticatedClientForUser($userData->userId)) {
                http_response_code(401);
                return ["error" => "Integração com Google Calendar não autorizada ou token inválido. Por favor, autorize o acesso.", "needs_google_auth" => true];
            }
            http_response_code(500);
            return ["error" => "Falha ao criar evento no Google Calendar."];
        }
    }

    /**
     * Get a specific Google Calendar event.
     *
     * @param array $headers Request headers.
     * @param string $eventId Google Calendar Event ID.
     * @return array JSON response.
     */
    public function getEvent(array $headers, string $eventId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        $event = $this->googleCalendarService->getEvent($userData->userId, $eventId);

        if ($event) {
            http_response_code(200);
            return ["data" => $event];
        } else {
            if (!$this->googleAuthService->getAuthenticatedClientForUser($userData->userId)) {
                http_response_code(401);
                return ["error" => "Integração com Google Calendar não autorizada ou token inválido. Por favor, autorize o acesso.", "needs_google_auth" => true];
            }
            // Could be 404 Not Found or 500 Internal Error
            http_response_code(404); // Assuming not found is more likely if service didn't throw other errors
            return ["error" => "Evento não encontrado no Google Calendar ou falha ao buscar."];
        }
    }

    /**
     * Update a Google Calendar event.
     *
     * @param array $headers Request headers.
     * @param string $eventId Google Calendar Event ID.
     * @param array $requestData Data to update.
     * @return array JSON response.
     */
    public function updateEvent(array $headers, string $eventId, array $requestData): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        if (empty($requestData)) {
            http_response_code(400);
            return ["error" => "Nenhum dado fornecido para atualização."];
        }

        $updatedEvent = $this->googleCalendarService->updateEvent($userData->userId, $eventId, $requestData);

        if ($updatedEvent) {
            http_response_code(200);
            return ["message" => "Evento atualizado com sucesso no Google Calendar.", "event" => $updatedEvent];
        } else {
            if (!$this->googleAuthService->getAuthenticatedClientForUser($userData->userId)) {
                http_response_code(401);
                return ["error" => "Integração com Google Calendar não autorizada ou token inválido. Por favor, autorize o acesso.", "needs_google_auth" => true];
            }
            http_response_code(500); // Or 404 if event not found during update
            return ["error" => "Falha ao atualizar evento no Google Calendar."];
        }
    }

    /**
     * Delete a Google Calendar event.
     *
     * @param array $headers Request headers.
     * @param string $eventId Google Calendar Event ID.
     * @return array JSON response.
     */
    public function deleteEvent(array $headers, string $eventId): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        if ($this->googleCalendarService->deleteEvent($userData->userId, $eventId)) {
            http_response_code(200); // Or 204 No Content
            return ["message" => "Evento excluído com sucesso do Google Calendar."];
        } else {
            if (!$this->googleAuthService->getAuthenticatedClientForUser($userData->userId)) {
                http_response_code(401);
                return ["error" => "Integração com Google Calendar não autorizada ou token inválido. Por favor, autorize o acesso.", "needs_google_auth" => true];
            }
            http_response_code(500); // Or 404 if event not found
            return ["error" => "Falha ao excluir evento do Google Calendar."];
        }
    }

    /**
     * Disconnect Google Calendar integration for the user (delete tokens).
     *
     * @param array $headers Request headers.
     * @return array JSON response.
     */
    public function disconnectGoogle(array $headers): array
    {
        $userData = $this->authMiddleware->handle($headers);
        if (!$userData) {
            http_response_code(401);
            return ["error" => "Autenticação do CRM necessária."];
        }

        if ($this->googleAuthService->deleteTokens($userData->userId)) {
            // Optionally, try to revoke the token on Google's side as well
            // $client = $this->googleAuthService->getClient();
            // $client->revokeToken(); // Requires the token to be set first

            http_response_code(200);
            return ["message" => "Integração com Google Calendar desconectada com sucesso."];
        } else {
            http_response_code(500);
            return ["error" => "Falha ao desconectar a integração com Google Calendar."];
        }
    }
}
