<?php

namespace Apoio19\Crm\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Apoio19\Crm\Models\Database;
use \PDO;
use \PDOException;
use \Exception;

class WhatsappService
{
    private Client $httpClient;
    private string $apiUrl;
    private ?string $apiKey;

    public function __construct()
    {
        // Load environment variables (simple approach)
        $envPath = __DIR__ . '/../../.env';
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                list($name, $value) = explode('=', $line, 2);
                $_ENV[trim($name)] = trim($value);
            }
        }

        $apiUrl = $_ENV['WHATSAPP_API_URL'] ?? '';
        $apiKey = $_ENV['WHATSAPP_API_KEY'] ?? null;

        // Sanitize values to remove quotes and whitespace that might be left by manual parsing
        $this->apiUrl = trim($apiUrl, " \t\n\r\0\x0B\"'");
        if ($apiKey) {
            $this->apiKey = trim($apiKey, " \t\n\r\0\x0B\"'");
        } else {
            $this->apiKey = null;
        }

        if (empty($this->apiUrl)) {
            // Log or handle the error appropriately - API cannot function without URL
            error_log("URL da API do WhatsApp (WHATSAPP_API_URL) não configurada no .env");
            // Potentially throw an exception or set a state indicating misconfiguration
        }

        $this->httpClient = new Client([
            'base_uri' => $this->apiUrl,
            'timeout'  => 10.0, // Request timeout
        ]);
    }

    /**
     * Sends a text message using the configured WhatsApp API (ZDG).
     *
     * @param string $phoneNumber Target phone number (e.g., 55119XXXXXXXX@c.us).
     * @param string $message Text message content.
     * @param int|null $crmUserId ID of the CRM user sending the message.
     * @param int|null $leadId Associated Lead ID.
     * @param int|null $contactId Associated Contact ID.
     * @return array ['success' => bool, 'message' => string, 'external_id' => string|null]
     */
    public function sendMessage(string $phoneNumber, string $message, ?int $crmUserId = null, ?int $leadId = null, ?int $contactId = null): array
    {
        if (empty($this->apiUrl)) {
            return ['success' => false, 'message' => 'API URL não configurada.', 'external_id' => null];
        }

        // Adapt the endpoint and payload based on the ZDG API documentation
        // This is a *guess* based on common patterns for whatsapp-web.js APIs
        $endpoint = '/send-message'; // Example endpoint
        $payload = [
            'number' => $phoneNumber,
            'message' => $message
        ];
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if ($this->apiKey) {
            // Add API key header if configured (e.g., 'Authorization: Bearer YOUR_KEY' or 'X-API-Key: YOUR_KEY')
            // Adjust header name based on ZDG API requirements
            $headers['X-API-Key'] = $this->apiKey;
        }

        try {
            $response = $this->httpClient->post($endpoint, [
                'headers' => $headers,
                'json' => $payload
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            if ($statusCode >= 200 && $statusCode < 300 && ($body['success'] ?? true)) { // Check ZDG API success indicator
                $externalId = $body['id'] ?? $body['messageId'] ?? null; // Get the message ID from ZDG API response

                // Save to history
                $this->saveHistoryRecord([
                    'mensagem_id_externo' => $externalId,
                    'lead_id' => $leadId,
                    'contato_id' => $contactId,
                    'usuario_id' => $crmUserId,
                    'numero_destino' => $phoneNumber,
                    'mensagem' => $message,
                    'tipo' => 'enviada',
                    'status_envio' => 'sent', // Initial status, might be updated by webhook
                ]);

                return ['success' => true, 'message' => 'Mensagem enviada com sucesso.', 'external_id' => $externalId];
            } else {
                $errorMessage = $body['error'] ?? $body['message'] ?? 'Erro desconhecido da API ZDG.';
                $this->saveHistoryRecord([
                    'lead_id' => $leadId,
                    'contato_id' => $contactId,
                    'usuario_id' => $crmUserId,
                    'numero_destino' => $phoneNumber,
                    'mensagem' => $message,
                    'tipo' => 'erro',
                    'status_envio' => 'failed',
                    'detalhes_erro' => "API ZDG Error: {$statusCode} - {$errorMessage}" // Add error details if table supports it
                ]);
                return ['success' => false, 'message' => "Falha ao enviar mensagem via API ZDG: {$errorMessage}", 'external_id' => null];
            }
        } catch (RequestException $e) {
            $errorMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $errorMessage .= " | Response: " . $e->getResponse()->getBody()->getContents();
            }
            error_log("Erro de requisição ao enviar mensagem WhatsApp: " . $errorMessage);
            $this->saveHistoryRecord([
                'lead_id' => $leadId,
                'contato_id' => $contactId,
                'usuario_id' => $crmUserId,
                'numero_destino' => $phoneNumber,
                'mensagem' => $message,
                'tipo' => 'erro',
                'status_envio' => 'failed',
                'detalhes_erro' => "Request Error: {$e->getMessage()}" // Add error details if table supports it
            ]);
            return ['success' => false, 'message' => "Erro de comunicação com a API ZDG: {$e->getMessage()}", 'external_id' => null];
        } catch (Exception $e) {
            error_log("Erro inesperado ao enviar mensagem WhatsApp: " . $e->getMessage());
            $this->saveHistoryRecord([
                'lead_id' => $leadId,
                'contato_id' => $contactId,
                'usuario_id' => $crmUserId,
                'numero_destino' => $phoneNumber,
                'mensagem' => $message,
                'tipo' => 'erro',
                'status_envio' => 'failed',
                'detalhes_erro' => "General Error: {$e->getMessage()}" // Add error details if table supports it
            ]);
            return ['success' => false, 'message' => "Erro inesperado: {$e->getMessage()}", 'external_id' => null];
        }
    }

    /**
     * Saves a WhatsApp message interaction to the database history.
     *
     * @param array $data Associative array matching columns in `whatsapp_historico`.
     * @return bool Success status.
     */
    public function saveHistoryRecord(array $data): bool
    {
        // Basic validation
        if (empty($data['numero_destino']) || empty($data['mensagem']) || empty($data['tipo'])) {
            error_log("Tentativa de salvar registro de histórico do WhatsApp com dados incompletos.");
            return false;
        }

        $sql = "INSERT INTO whatsapp_historico 
                    (mensagem_id_externo, lead_id, contato_id, usuario_id, numero_destino, numero_origem, mensagem, tipo, status_envio, timestamp_api) 
                VALUES 
                    (:mensagem_id_externo, :lead_id, :contato_id, :usuario_id, :numero_destino, :numero_origem, :mensagem, :tipo, :status_envio, :timestamp_api)";

        // Add handling for error details if the column exists
        // if (isset($data['detalhes_erro'])) { ... }

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);

            $stmt->bindValue(":mensagem_id_externo", $data['mensagem_id_externo'] ?? null);
            $stmt->bindValue(":lead_id", $data['lead_id'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":contato_id", $data['contato_id'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(":usuario_id", $data['usuario_id'] ?? null, PDO::PARAM_INT);
            $stmt->bindParam(":numero_destino", $data['numero_destino']);
            $stmt->bindValue(":numero_origem", $data['numero_origem'] ?? null);
            $stmt->bindParam(":mensagem", $data['mensagem']);
            $stmt->bindParam(":tipo", $data['tipo']);
            $stmt->bindValue(":status_envio", $data['status_envio'] ?? null);
            $stmt->bindValue(":timestamp_api", $data['timestamp_api'] ?? null, PDO::PARAM_INT);
            // Bind error details if applicable

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao salvar histórico do WhatsApp no banco de dados: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Updates the status of a previously sent message based on webhook data.
     *
     * @param string $externalId The external message ID provided by the API.
     * @param string $status The new status (e.g., delivered, read, failed).
     * @return bool Success status.
     */
    public function updateMessageStatus(string $externalId, string $status): bool
    {
        $sql = "UPDATE whatsapp_historico SET status_envio = :status, atualizado_em = NOW() 
                 WHERE mensagem_id_externo = :external_id AND tipo = 'enviada'";
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(":status", $status);
            $stmt->bindParam(":external_id", $externalId);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao atualizar status da mensagem WhatsApp (ID Externo: {$externalId}): " . $e->getMessage());
            return false;
        }
    }

    // TODO: Add methods for sending media if required and supported by ZDG API.
    // TODO: Add method to check API connection status if ZDG API provides an endpoint for it.

    /**
     * Test the connection to the WhatsApp API.
     * Returns an associative array indicating success and optional data.
     */
    public function testConnection(): array
    {
        try {
            // Get configuration from database
            $config = \Apoio19\Crm\Models\Whatsapp::getConfig();

            if (!$config) {
                return ['connected' => false, 'error' => 'WhatsApp configuration not found in database'];
            }

            $businessAccountId = $config['business_account_id'] ?? null;
            // Use access_token for API calls, not webhook_verify_token
            $accessToken = $config['access_token'] ?? null;

            if (empty($businessAccountId)) {
                return ['connected' => false, 'error' => 'Business Account ID not configured'];
            }

            if (empty($accessToken)) {
                return ['connected' => false, 'error' => 'Access token not configured'];
            }

            // Construct the endpoint with business_account_id
            $endpoint = "/{$businessAccountId}/phone_numbers";

            $headers = [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json'
            ];

            $response = $this->httpClient->get($endpoint, [
                'headers' => $headers
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            // Verify we got phone numbers data
            $connected = $statusCode === 200 && isset($body['data']) && is_array($body['data']);

            return ['connected' => $connected, 'data' => $body];
        } catch (RequestException $e) {
            $msg = $e->getMessage();
            if ($e->hasResponse()) {
                $msg .= ' | Response: ' . $e->getResponse()->getBody()->getContents();
            }
            return ['connected' => false, 'error' => $msg];
        } catch (Exception $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process incoming webhook from Meta API
     */
    public function processIncomingWebhook(array $webhookData): bool
    {
        try {
            if (!isset($webhookData['entry'])) {
                error_log("Invalid webhook structure");
                return false;
            }

            foreach ($webhookData['entry'] as $entry) {
                if (!isset($entry['changes'])) continue;

                foreach ($entry['changes'] as $change) {
                    if ($change['field'] !== 'messages') continue;

                    $value = $change['value'];

                    // Extract phone_number_id from webhook metadata
                    $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                    if (isset($value['messages'])) {
                        foreach ($value['messages'] as $message) {
                            $this->processIncomingMessage($message, $phoneNumberId);
                        }
                    }

                    if (isset($value['statuses'])) {
                        foreach ($value['statuses'] as $status) {
                            $this->processMessageStatus($status);
                        }
                    }
                }
            }
            return true;
        } catch (\Exception $e) {
            error_log("Webhook error: " . $e->getMessage());
            return false;
        }
    }

    private function processIncomingMessage(array $message, ?string $phoneNumberId = null): void
    {
        try {
            $phoneNumber = $message['from'] ?? null;
            if (!$phoneNumber) return;

            $whatsappMessageId = $message['id'] ?? null;

            error_log("Processando mensagem recebida - from: {$phoneNumber}, phone_number_id: {$phoneNumberId}, message_id: {$whatsappMessageId}");

            // Evitar inserção duplicada em caso de retry do webhook
            if ($whatsappMessageId) {
                $whatsappMessageModel = new \Apoio19\Crm\Models\WhatsappChatMessage();
                $existing = $whatsappMessageModel->findByWhatsappMessageId($whatsappMessageId);
                if ($existing) {
                    error_log("Mensagem duplicada ignorada: {$whatsappMessageId}");
                    return;
                }
            }

            $whatsappContact = new \Apoio19\Crm\Models\WhatsappContact();
            $contact = $whatsappContact->findByPhoneNumber($phoneNumber);

            if (!$contact) {
                // Tenta extrair o nome do perfil do remetente, se disponível no payload
                $profileName = $message['profile']['name'] ?? $phoneNumber;
                $contactId = $whatsappContact->create([
                    'phone_number' => $phoneNumber,
                    'name' => $profileName
                ]);
                error_log("Novo contato criado: {$phoneNumber} (ID: {$contactId})");
            } else {
                $contactId = $contact['id'];
            }

            $messageType = $message['type'] ?? 'text';

            // Extrair conteúdo conforme o tipo da mensagem
            switch ($messageType) {
                case 'text':
                    $messageContent = $message['text']['body'] ?? '';
                    break;
                case 'image':
                    $messageContent = $message['image']['caption'] ?? '[Imagem]';
                    $mediaUrl = $message['image']['id'] ?? null;
                    break;
                case 'audio':
                    $messageContent = '[Áudio]';
                    $mediaUrl = $message['audio']['id'] ?? null;
                    break;
                case 'video':
                    $messageContent = $message['video']['caption'] ?? '[Vídeo]';
                    $mediaUrl = $message['video']['id'] ?? null;
                    break;
                case 'document':
                    $messageContent = $message['document']['filename'] ?? '[Documento]';
                    $mediaUrl = $message['document']['id'] ?? null;
                    break;
                case 'sticker':
                    $messageContent = '[Sticker]';
                    $mediaUrl = $message['sticker']['id'] ?? null;
                    break;
                case 'location':
                    $lat = $message['location']['latitude'] ?? '';
                    $lng = $message['location']['longitude'] ?? '';
                    $messageContent = "[Localização: {$lat}, {$lng}]";
                    break;
                case 'button':
                    $messageContent = $message['button']['text'] ?? '[Botão]';
                    break;
                case 'interactive':
                    $interactiveType = $message['interactive']['type'] ?? '';
                    if ($interactiveType === 'button_reply') {
                        $messageContent = $message['interactive']['button_reply']['title'] ?? '[Botão]';
                    } elseif ($interactiveType === 'list_reply') {
                        $messageContent = $message['interactive']['list_reply']['title'] ?? '[Lista]';
                    } else {
                        $messageContent = '[Interativo]';
                    }
                    break;
                case 'reaction':
                    $reactedMessageId = $message['reaction']['message_id'] ?? null;
                    $emoji = $message['reaction']['emoji'] ?? ''; // Can be empty if the reaction was removed

                    if ($reactedMessageId) {
                        $whatsappMessageModel = new \Apoio19\Crm\Models\WhatsappChatMessage();
                        $success = $whatsappMessageModel->updateReaction($reactedMessageId, $emoji);
                        error_log("Reação atualizada para a mensagem {$reactedMessageId} com emoji: {$emoji}. Sucesso: " . ($success ? 'Sim' : 'Não'));
                    }

                    // As reações não são novas mensagens na lista, então podemos encerrar o processamento aqui
                    return;
                default:
                    $messageContent = "[{$messageType}]";
            }

            // NOVO: Fazer download da mídia (se houver) antes de salvar
            if ($mediaUrl) {
                // Determine a subpasta baseada no tipo da mensagem para organizar os uploads
                $subfolder = 'others';
                if ($messageType === 'image' || $messageType === 'sticker') $subfolder = 'images';
                elseif ($messageType === 'video') $subfolder = 'videos';
                elseif ($messageType === 'audio') $subfolder = 'audios';
                elseif ($messageType === 'document') $subfolder = 'documents';

                $downloadedPath = $this->downloadMedia($mediaUrl, $phoneNumberId, $subfolder);
                if ($downloadedPath) {
                    $mediaUrl = $downloadedPath; // Substitui o ID da mídia do Meta pelo path local
                }
            }

            $whatsappMessage = new \Apoio19\Crm\Models\WhatsappChatMessage();
            $created = $whatsappMessage->create([
                'contact_id'          => $contactId,
                'user_id'             => null, // null = mensagem recebida (sem usuário CRM)
                'phone_number_id'     => $phoneNumberId, // ID do número da Meta que recebeu a mensagem
                'direction'           => 'incoming',
                'message_type'        => $messageType,
                'message_content'     => $messageContent,
                'media_url'           => $mediaUrl ?? null,
                'whatsapp_message_id' => $whatsappMessageId,
                'status'              => 'delivered'
            ]);

            error_log("Mensagem armazenada - contact_id: {$contactId}, phone_number_id: {$phoneNumberId}, db_id: {$created}");

            // NOVO: Verificar se é resposta a uma campanha
            $replyToWamid = $message['context']['id'] ?? null;
            if ($replyToWamid) {
                $db = \Apoio19\Crm\Models\Database::getInstance();
                $stmt = $db->prepare('SELECT id, campaign_id FROM whatsapp_campaign_messages WHERE message_id = ? LIMIT 1');
                $stmt->execute([$replyToWamid]);
                $campaignMsg = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($campaignMsg) {
                    // Registrar a resposta na tabela de respostas de campanha
                    $stmtResponse = $db->prepare('
                        INSERT INTO whatsapp_message_responses 
                        (message_id, from_number, response_text, response_type, media_url, received_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ');

                    $stmtResponse->execute([
                        $campaignMsg['id'],
                        $phoneNumber,
                        $messageContent,
                        $messageType,
                        $mediaUrl ?? null
                    ]);
                    error_log("Resposta à campanha {$campaignMsg['campaign_id']} registrada na tabela whatsapp_message_responses");

                    // Executar ação configurada (ex: envio de template automático)
                    $this->processCampaignResponseAction($campaignMsg['campaign_id'], $contactId, $messageContent, $phoneNumber, $phoneNumberId);
                }
            }
        } catch (\Exception $e) {
            error_log("Erro ao processar mensagem recebida: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }

    /**
     * Download media from WhatsApp API and save locally
     * 
     * @param string $mediaId ID from WhatsApp Meta API
     * @param string|null $phoneNumberId Sender phone ID (to get access token)
     * @param string $subfolder Directory to save (images, videos, etc)
     * @return string|null Local path to the file or null if failed
     */
    private function downloadMedia(string $mediaId, ?string $phoneNumberId, string $subfolder = 'others'): ?string
    {
        try {
            $config = \Apoio19\Crm\Models\Whatsapp::getConfig();
            if (!$config) {
                error_log("downloadMedia: WhatsApp não configurado.");
                return null;
            }

            // Descobrir Token
            $accessToken = $config['access_token'];
            if ($phoneNumberId) {
                $db = \Apoio19\Crm\Models\Database::getInstance();
                $stmt = $db->prepare('SELECT access_token FROM whatsapp_phone_numbers WHERE phone_number_id = ? AND status = "active" LIMIT 1');
                $stmt->execute([$phoneNumberId]);
                $numberConfig = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($numberConfig && !empty($numberConfig['access_token'])) {
                    $accessToken = $numberConfig['access_token'];
                }
            }

            // Primeiro: Buscar a URL da mídia pelo ID
            // GET https://graph.facebook.com/vXX.X/{media-id}
            $mediaInfoEndpoint = "/{$mediaId}";
            $infoResponse = $this->httpClient->get($mediaInfoEndpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken
                ]
            ]);

            $infoStatusCode = $infoResponse->getStatusCode();
            if ($infoStatusCode !== 200) {
                error_log("downloadMedia: Falha ao obter informações da mídia {$mediaId}. Status: {$infoStatusCode}");
                return null;
            }

            $mediaInfo = json_decode($infoResponse->getBody()->getContents(), true);
            $mediaUrl = $mediaInfo['url'] ?? null;
            $mimeType = $mediaInfo['mime_type'] ?? 'application/octet-stream';

            if (!$mediaUrl) {
                error_log("downloadMedia: URL da mídia vazia na resposta da Meta.");
                return null;
            }

            // Segundo: Realizar o download do arquivo de fato
            // Necessário Header de Auth
            $downloadResponse = $this->httpClient->get($mediaUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken
                ]
            ]);

            $downloadStatusCode = $downloadResponse->getStatusCode();
            if ($downloadStatusCode !== 200) {
                error_log("downloadMedia: Erro HTTP {$downloadStatusCode} ao baixar a mídia da URL: {$mediaUrl}");
                return null;
            }

            // Resolver extensão baseada no Mimetype ou usar dat
            $extension = 'dat';
            $mimeToExt = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp', // Stickers
                'audio/ogg' => 'ogg',
                'audio/ogg; codecs=opus' => 'ogg',
                'audio/mpeg' => 'mp3',
                'audio/amr' => 'amr',
                'video/mp4' => 'mp4',
                'application/pdf' => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/vnd.ms-excel' => 'xls',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            ];

            // Normalize mime_type (sometimes Meta returns codecs in audio)
            $cleanMime = explode(';', $mimeType)[0];
            if (isset($mimeToExt[$cleanMime])) {
                $extension = $mimeToExt[$cleanMime];
            } else if (isset($mimeToExt[$mimeType])) {
                $extension = $mimeToExt[$mimeType];
            }

            // Define caminhos e diretórios
            // Caminho público que vai ser exibido no navegador
            $publicPath = "/uploads/whatsapp/{$subfolder}";

            // Ajustar o diretório de destino baseado na estrutura (salvando na pasta public do crm-frontend)
            $frontendPublicDir = dirname(BASE_PATH) . '/crm-frontend/public';
            if (!is_dir(dirname(BASE_PATH) . '/crm-frontend')) {
                // Fallback para o ambiente de desenvolvimento local
                $frontendPublicDir = dirname(BASE_PATH) . '/crm-apoio19/public';
            }
            $targetDir = $frontendPublicDir . $publicPath;

            error_log("downloadMedia -> Salvando midia em: " . $targetDir);

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }

            // Nome do arquivo
            $fileName = uniqid('wa_media_') . '_' . time() . '.' . $extension;
            $fullFilePath = $targetDir . '/' . $fileName;

            // Salva o conteúdo binário
            $fileContent = $downloadResponse->getBody()->getContents();
            if (file_put_contents($fullFilePath, $fileContent) === false) {
                error_log("downloadMedia: Falha ao escrever arquivo no disco: {$fullFilePath}");
                return null;
            }

            error_log("downloadMedia: Mídia {$mediaId} salva com sucesso em {$fullFilePath}");

            // Retorna o URI base
            return "{$publicPath}/{$fileName}";
        } catch (RequestException $e) {
            $error = $e->getMessage();
            if ($e->hasResponse()) {
                $error .= ' | Response: ' . $e->getResponse()->getBody()->getContents();
            }
            error_log("downloadMedia: Erro de requisição. Detalhes: " . $error);
            return null;
        } catch (\Exception $e) {
            error_log("downloadMedia: Erro desconhecido durante o download da mídia. Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Processa a ação (flow_auto_reply, etc) baseada na resposta de campanha recebida
     */
    private function processCampaignResponseAction(int $campaignId, int $contactId, string $responseText, string $phoneNumber, ?string $phoneNumberId): void
    {
        try {
            $db = \Apoio19\Crm\Models\Database::getInstance();
            $stmt = $db->prepare('SELECT settings, user_id, phone_number_id FROM whatsapp_campaigns WHERE id = ? LIMIT 1');
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$campaign || empty($campaign['settings'])) {
                return;
            }

            $settings = json_decode($campaign['settings'], true);
            if (!isset($settings['responses_config'][$responseText])) {
                return;
            }

            $config = $settings['responses_config'][$responseText];
            $action = $config['action'] ?? null;

            if ($action === 'flow_auto_reply') {
                $templateId = $config['template_id'] ?? null;
                if ($templateId) {
                    $templateModel = new \Apoio19\Crm\Models\WhatsappTemplate();
                    $template = $templateModel->findById($templateId);

                    if ($template && $template['status'] === 'APPROVED') {
                        error_log("Enviando auto-reply para a campanha {$campaignId} usando o template {$template['name']}");

                        // Para templates simples, assumimos components vazios
                        $result = $this->sendTemplateMessage(
                            $phoneNumber,
                            $template['name'],
                            $template['language'] ?? 'pt_BR',
                            [],
                            $campaign['user_id'],
                            $campaign['phone_number_id'] ?? $phoneNumberId
                        );

                        if ($result['success'] && isset($result['message_id'])) {
                            // Extract body component if parsing is possible
                            $bodyText = "[Resposta Automática: {$template['name']}]";
                            if (!empty($template['components'])) {
                                $componentsInfo = is_string($template['components']) ? json_decode($template['components'], true) : $template['components'];
                                if (is_array($componentsInfo)) {
                                    foreach ($componentsInfo as $comp) {
                                        if (isset($comp['type']) && $comp['type'] === 'BODY') {
                                            $bodyText = $comp['text'];
                                            break;
                                        }
                                    }
                                }
                            }

                            $chatMsgModel = new \Apoio19\Crm\Models\WhatsappChatMessage();
                            $chatMsgModel->create([
                                'contact_id'          => $contactId,
                                'user_id'             => $campaign['user_id'],
                                'phone_number_id'     => $phoneNumberId, // Meta phone number ID
                                'direction'           => 'outgoing',
                                'message_type'        => 'template',
                                'message_content'     => $bodyText,
                                'whatsapp_message_id' => $result['message_id'],
                                'status'              => 'sent'
                            ]);
                            error_log("Auto-reply template saved to whatsapp_chat_messages for contact $contactId");
                        }
                    }
                }
            } elseif ($action === 'status_interessado' || $action === 'status_perdido') {
                error_log("Alteração de status do lead planejada: Contato {$contactId} para {$action}");
                $stmtContact = $db->prepare('SELECT lead_id FROM whatsapp_contacts WHERE id = ? LIMIT 1');
                $stmtContact->execute([$contactId]);
                $contact = $stmtContact->fetch(\PDO::FETCH_ASSOC);

                if (!empty($contact['lead_id'])) {
                    $statusId = ($action === 'status_interessado') ? 2 : 4; // Exemplo de IDs de interessado e perdido
                    $stmtUpdate = $db->prepare('UPDATE leads SET status_id = ? WHERE id = ?');
                    $stmtUpdate->execute([$statusId, $contact['lead_id']]);
                    error_log("Status do lead {$contact['lead_id']} atualizado para {$statusId} ({$action})");
                }
            }
        } catch (\Exception $e) {
            error_log("Erro ao processar ação de resposta de campanha: " . $e->getMessage());
        }
    }


    private function processMessageStatus(array $status): void
    {
        try {
            $whatsappMessageId = $status['id'] ?? null;
            $statusValue = $status['status'] ?? null;

            if ($whatsappMessageId && $statusValue) {
                // Update chat messages (existing functionality)
                $whatsappMessage = new \Apoio19\Crm\Models\WhatsappChatMessage();
                $whatsappMessage->updateStatus($whatsappMessageId, $statusValue);

                // Update campaign messages (new functionality)
                $this->updateCampaignMessageStatus($status);
            }
        } catch (\Exception $e) {
            error_log("Error updating status: " . $e->getMessage());
        }
    }

    /**
     * Update campaign message status based on webhook data
     * 
     * @param array $status Status data from webhook
     * @return void
     */
    private function updateCampaignMessageStatus(array $status): void
    {
        try {
            $wamid = $status['id'] ?? null;
            $statusType = $status['status'] ?? null;
            $timestamp = $status['timestamp'] ?? null;

            if (!$wamid || !$statusType || !$timestamp) {
                error_log("Campaign message status update: Missing required fields (wamid, status, or timestamp)");
                return;
            }

            // Convert Unix timestamp to MySQL datetime
            $datetime = date('Y-m-d H:i:s', (int)$timestamp);

            $db = Database::getInstance();

            // Prepare update based on status type
            $updateFields = [];
            $params = [];

            switch ($statusType) {
                case 'sent':
                    $updateFields[] = 'status = ?';
                    $params[] = 'sent';
                    $updateFields[] = 'sent_at = ?';
                    $params[] = $datetime;
                    break;

                case 'delivered':
                    $updateFields[] = 'status = ?';
                    $params[] = 'delivered';
                    $updateFields[] = 'delivered_at = ?';
                    $params[] = $datetime;
                    break;

                case 'read':
                    $updateFields[] = 'status = ?';
                    $params[] = 'read';
                    $updateFields[] = 'read_at = ?';
                    $params[] = $datetime;
                    break;

                case 'failed':
                    $updateFields[] = 'status = ?';
                    $params[] = 'failed';
                    $updateFields[] = 'failed_at = ?';
                    $params[] = $datetime;

                    // Extract error message
                    if (isset($status['errors'][0])) {
                        $error = $status['errors'][0];
                        $errorMsg = sprintf(
                            "[%d] %s: %s",
                            $error['code'] ?? 0,
                            $error['title'] ?? 'Error',
                            $error['message'] ?? ''
                        );
                        if (isset($error['error_data']['details'])) {
                            $errorMsg .= " - " . $error['error_data']['details'];
                        }

                        $updateFields[] = 'error_message = ?';
                        $params[] = $errorMsg;
                    }
                    break;

                default:
                    error_log("Campaign message status update: Unknown status type: {$statusType}");
                    return;
            }

            // Add updated_at timestamp
            $updateFields[] = 'updated_at = NOW()';

            // Add wamid to params for WHERE clause
            $params[] = $wamid;

            // Update database
            $sql = "UPDATE whatsapp_campaign_messages 
                    SET " . implode(', ', $updateFields) . "
                    WHERE message_id = ?";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $rowCount = $stmt->rowCount();
            if ($rowCount > 0) {
                error_log("Campaign message status updated: wamid={$wamid}, status={$statusType}, rows_affected={$rowCount}");
            } else {
                error_log("Campaign message status update: No rows affected for wamid={$wamid}");
            }
        } catch (\PDOException $e) {
            error_log("Database error updating campaign message status: " . $e->getMessage());
        } catch (\Exception $e) {
            error_log("Error updating campaign message status: " . $e->getMessage());
        }
    }


    public function sendTextMessage(string $phoneNumber, string $message, int $userId, int $contactId, ?string $phoneNumberId = null): array
    {
        try {
            $config = \Apoio19\Crm\Models\Whatsapp::getConfig();
            if (!$config) {
                return ['success' => false, 'error' => 'WhatsApp não configurado'];
            }

            // Usar phone_number_id passado explicitamente ou cair no padrão do config
            if ($phoneNumberId) {
                // Buscar o access_token específico do número selecionado
                $db = \Apoio19\Crm\Models\Database::getInstance();
                $stmt = $db->prepare('SELECT * FROM whatsapp_phone_numbers WHERE phone_number_id = ? AND status = "active" LIMIT 1');
                $stmt->execute([$phoneNumberId]);
                $numberConfig = $stmt->fetch(\PDO::FETCH_ASSOC);

                if (!$numberConfig) {
                    error_log("sendTextMessage: phone_number_id {$phoneNumberId} não encontrado, usando config padrão");
                    $phoneNumberId = $config['phone_number_id'];
                    $accessToken = $config['access_token'];
                } else {
                    $accessToken = $numberConfig['access_token'];
                }
            } else {
                $phoneNumberId = $config['phone_number_id'];
                $accessToken = $config['access_token'];
            }

            $endpoint = "/{$phoneNumberId}/messages";
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phoneNumber,
                'type' => 'text',
                'text' => ['body' => $message]
            ];

            $response = $this->httpClient->post($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => $payload
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['messages'][0]['id'])) {
                $whatsappMessageId = $body['messages'][0]['id'];

                $whatsappMessage = new \Apoio19\Crm\Models\WhatsappChatMessage();
                $whatsappMessage->create([
                    'contact_id'          => $contactId,
                    'user_id'             => $userId,
                    'phone_number_id'     => $phoneNumberId,
                    'direction'           => 'outgoing',
                    'message_type'        => 'text',
                    'message_content'     => $message,
                    'whatsapp_message_id' => $whatsappMessageId,
                    'status'              => 'sent'
                ]);

                error_log("Mensagem enviada - contact_id: {$contactId}, phone_number_id: {$phoneNumberId}, wamid: {$whatsappMessageId}");

                return ['success' => true, 'message_id' => $whatsappMessageId];
            }

            return ['success' => false, 'error' => 'Failed to send'];
        } catch (RequestException $e) {
            $error = $e->getMessage();
            if ($e->hasResponse()) {
                $body = json_decode($e->getResponse()->getBody()->getContents(), true);
                $error = $body['error']['message'] ?? $error;
            }
            return ['success' => false, 'error' => $error];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }


    /**
     * Send a template message
     */
    public function sendTemplateMessage(string $phoneNumber, string $templateName, string $language, array $components, int $userId, ?string $phoneNumberId = null): array
    {
        try {
            $config = \Apoio19\Crm\Models\Whatsapp::getConfig();
            if (!$config) {
                return ['success' => false, 'error' => 'WhatsApp não configurado'];
            }

            if ($phoneNumberId) {
                $db = \Apoio19\Crm\Models\Database::getInstance();
                $stmt = $db->prepare('SELECT * FROM whatsapp_phone_numbers WHERE phone_number_id = ? AND status = "active" LIMIT 1');
                $stmt->execute([$phoneNumberId]);
                $numberConfig = $stmt->fetch(\PDO::FETCH_ASSOC);

                if (!$numberConfig) {
                    $phoneNumberId = $config['phone_number_id'];
                    $accessToken = $config['access_token'];
                } else {
                    $accessToken = $numberConfig['access_token'];
                }
            } else {
                $phoneNumberId = $config['phone_number_id'];
                $accessToken = $config['access_token'];
            }

            $endpoint = "/{$phoneNumberId}/messages";
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phoneNumber,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => $language],
                    'components' => $components
                ]
            ];

            $response = $this->httpClient->post($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => $payload
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['messages'][0]['id'])) {
                $whatsappMessageId = $body['messages'][0]['id'];

                // For a test message, we might not have a contact_id, 
                // but if we do later, we could also save it to whatsapp_chat_messages here.

                return ['success' => true, 'message_id' => $whatsappMessageId, 'phone_number_id' => $phoneNumberId];
            }

            return ['success' => false, 'error' => 'Failed to send template'];
        } catch (RequestException $e) {
            $error = $e->getMessage();
            if ($e->hasResponse()) {
                $body = json_decode($e->getResponse()->getBody()->getContents(), true);
                $error = $body['error']['message'] ?? $error;
                error_log("Meta API Template Error: " . json_encode($body));
            }
            return ['success' => false, 'error' => $error];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get phone numbers from Meta API
     */
    public function getPhoneNumbersFromMeta(): array
    {
        try {
            // Get configuration from database
            $config = \Apoio19\Crm\Models\Whatsapp::getConfig();

            if (!$config) {
                return ['success' => false, 'error' => 'WhatsApp configuration not found in database'];
            }

            $businessAccountId = $config['business_account_id'] ?? null;
            $accessToken = $config['access_token'] ?? null;

            if (empty($businessAccountId) || empty($accessToken)) {
                return ['success' => false, 'error' => 'Business Account ID or Access Token not configured'];
            }

            // Get phone numbers from Meta API
            $endpoint = "/{$businessAccountId}/phone_numbers";

            $headers = [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json'
            ];

            $response = $this->httpClient->get($endpoint, [
                'headers' => $headers
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            if ($statusCode === 200 && isset($body['data'])) {
                return ['success' => true, 'data' => $body['data']];
            }

            return ['success' => false, 'error' => 'Failed to fetch phone numbers'];
        } catch (RequestException $e) {
            $error = $e->getMessage();
            if ($e->hasResponse()) {
                $body = json_decode($e->getResponse()->getBody()->getContents(), true);
                $error = $body['error']['message'] ?? $error;
            }
            return ['success' => false, 'error' => $error];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Sync templates from Meta API
     */
    public function syncTemplates(): array
    {
        try {
            // Get configuration from database
            $config = \Apoio19\Crm\Models\Whatsapp::getConfig();

            if (!$config) {
                return ['success' => false, 'error' => 'WhatsApp configuration not found in database'];
            }

            $businessAccountId = $config['business_account_id'] ?? null;
            $accessToken = $config['access_token'] ?? null;

            if (empty($businessAccountId) || empty($accessToken)) {
                return ['success' => false, 'error' => 'Business Account ID or Access Token not configured'];
            }

            // Get templates from Meta API
            $endpoint = "/{$businessAccountId}/message_templates";

            $headers = [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json'
            ];

            $response = $this->httpClient->get($endpoint, [
                'headers' => $headers,
                'query' => ['limit' => 100] // Get up to 100 templates
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            if ($statusCode === 200 && isset($body['data'])) {
                $templates = $body['data'];
                $syncedCount = 0;
                $db = Database::getInstance();

                foreach ($templates as $template) {
                    // Check if template already exists
                    $stmt = $db->prepare("
                        SELECT id FROM whatsapp_templates 
                        WHERE template_id = ?
                    ");
                    $stmt->execute([$template['id']]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                    $componentsJson = json_encode($template['components'] ?? []);

                    if ($existing) {
                        // Update existing template
                        $stmt = $db->prepare("
                            UPDATE whatsapp_templates 
                            SET name = ?,
                                language = ?,
                                category = ?,
                                status = ?,
                                components = ?,
                                updated_at = NOW()
                            WHERE template_id = ?
                        ");
                        $stmt->execute([
                            $template['name'],
                            $template['language'],
                            $template['category'] ?? 'UTILITY',
                            $template['status'],
                            $componentsJson,
                            $template['id']
                        ]);
                    } else {
                        // Insert new template
                        $stmt = $db->prepare("
                            INSERT INTO whatsapp_templates 
                            (template_id, name, language, category, status, components, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ");
                        $stmt->execute([
                            $template['id'],
                            $template['name'],
                            $template['language'],
                            $template['category'] ?? 'UTILITY',
                            $template['status'],
                            $componentsJson
                        ]);
                    }
                    $syncedCount++;
                }

                return [
                    'success' => true,
                    'message' => "Synchronized {$syncedCount} templates",
                    'count' => $syncedCount
                ];
            }

            return ['success' => false, 'error' => 'Failed to fetch templates'];
        } catch (RequestException $e) {
            $error = $e->getMessage();
            if ($e->hasResponse()) {
                $body = json_decode($e->getResponse()->getBody()->getContents(), true);
                $error = $body['error']['message'] ?? $error;
            }
            return ['success' => false, 'error' => $error];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
