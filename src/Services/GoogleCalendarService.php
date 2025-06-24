<?php

namespace Apoio19\Crm\Services;

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use \Exception;

class GoogleCalendarService
{
    private GoogleAuthService $authService;

    public function __construct()
    {
        $this->authService = new GoogleAuthService();
    }

    /**
     * Get the Google Calendar service instance for a user.
     *
     * @param int $userId CRM User ID.
     * @return Calendar|null Calendar service instance or null if authentication fails.
     */
    private function getCalendarService(int $userId): ?Calendar
    {
        $client = $this->authService->getAuthenticatedClientForUser($userId);
        if ($client) {
            return new Calendar($client);
        }
        return null;
    }

    /**
     * List events from the user's primary Google Calendar.
     *
     * @param int $userId CRM User ID.
     * @param array $options Optional parameters (timeMin, timeMax, maxResults, etc.).
     * @return array|null List of events or null on failure.
     */
    public function listEvents(int $userId, array $options = []): ?array
    {
        $service = $this->getCalendarService($userId);
        if (!$service) {
            error_log("Falha ao obter serviço de calendário autenticado para usuário ID {$userId}");
            return null;
        }

        try {
            $calendarId = 'primary'; // Use the primary calendar
            $results = $service->events->listEvents($calendarId, $options);
            return $results->getItems();
        } catch (Exception $e) {
            error_log("Erro ao listar eventos do Google Calendar para usuário ID {$userId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a new event in the user's primary Google Calendar.
     *
     * @param int $userId CRM User ID.
     * @param array $eventData Event details (summary, description, startDateTime, endDateTime, attendees, etc.).
     * @return Event|null The created event object or null on failure.
     */
    public function createEvent(int $userId, array $eventData): ?Event
    {
        $service = $this->getCalendarService($userId);
        if (!$service) {
            error_log("Falha ao obter serviço de calendário autenticado para criar evento (Usuário ID: {$userId})");
            return null;
        }

        try {
            $calendarId = 'primary';
            $event = new Event([
                'summary' => $eventData['summary'] ?? 'Novo Evento CRM',
                'description' => $eventData['description'] ?? '',
                'start' => new EventDateTime([
                    'dateTime' => $eventData['startDateTime'], // Expects RFC3339 format e.g., '2025-06-05T10:00:00-03:00'
                    'timeZone' => $eventData['timeZone'] ?? 'America/Sao_Paulo', // Or get from user settings
                ]),
                'end' => new EventDateTime([
                    'dateTime' => $eventData['endDateTime'], // Expects RFC3339 format e.g., '2025-06-05T11:00:00-03:00'
                    'timeZone' => $eventData['timeZone'] ?? 'America/Sao_Paulo',
                ]),
                // Add attendees if provided
                // 'attendees' => [
                //     ['email' => 'attendee1@example.com'],
                //     ['email' => 'attendee2@example.com'],
                // ],
                // Add reminders if needed
                // 'reminders' => [
                //     'useDefault' => false,
                //     'overrides' => [
                //         ['method' => 'email', 'minutes' => 24 * 60],
                //         ['method' => 'popup', 'minutes' => 10],
                //     ],
                // ],
            ]);
            
            if (!empty($eventData['attendees']) && is_array($eventData['attendees'])) {
                 $attendees = [];
                 foreach ($eventData['attendees'] as $email) {
                     if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                         $attendees[] = ['email' => $email];
                     }
                 }
                 if (!empty($attendees)) {
                     $event->setAttendees($attendees);
                 }
            }

            $createdEvent = $service->events->insert($calendarId, $event);
            return $createdEvent;
        } catch (Exception $e) {
            error_log("Erro ao criar evento no Google Calendar para usuário ID {$userId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get a specific event by ID.
     *
     * @param int $userId CRM User ID.
     * @param string $eventId Google Calendar Event ID.
     * @return Event|null
     */
    public function getEvent(int $userId, string $eventId): ?Event
    {
        $service = $this->getCalendarService($userId);
        if (!$service) {
            error_log("Falha ao obter serviço de calendário autenticado para buscar evento (Usuário ID: {$userId})");
            return null;
        }

        try {
            $calendarId = 'primary';
            return $service->events->get($calendarId, $eventId);
        } catch (Exception $e) {
            // Handle not found exception specifically if needed (e.g., 404 error from API)
            error_log("Erro ao buscar evento '{$eventId}' do Google Calendar para usuário ID {$userId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update an existing event in the user's primary Google Calendar.
     *
     * @param int $userId CRM User ID.
     * @param string $eventId Google Calendar Event ID.
     * @param array $eventData Data to update.
     * @return Event|null The updated event object or null on failure.
     */
    public function updateEvent(int $userId, string $eventId, array $eventData): ?Event
    {
        $service = $this->getCalendarService($userId);
        if (!$service) {
            error_log("Falha ao obter serviço de calendário autenticado para atualizar evento (Usuário ID: {$userId})");
            return null;
        }

        try {
            $calendarId = 'primary';
            // First, get the existing event
            $event = $service->events->get($calendarId, $eventId);
            if (!$event) {
                 error_log("Evento '{$eventId}' não encontrado para atualização (Usuário ID: {$userId})");
                 return null; // Or throw specific exception
            }

            // Update fields based on $eventData
            if (isset($eventData['summary'])) {
                $event->setSummary($eventData['summary']);
            }
            if (isset($eventData['description'])) {
                $event->setDescription($eventData['description']);
            }
            if (isset($eventData['startDateTime'])) {
                $start = new EventDateTime([
                    'dateTime' => $eventData['startDateTime'],
                    'timeZone' => $eventData['timeZone'] ?? $event->start->timeZone ?? 'America/Sao_Paulo',
                ]);
                $event->setStart($start);
            }
            if (isset($eventData['endDateTime'])) {
                 $end = new EventDateTime([
                    'dateTime' => $eventData['endDateTime'],
                    'timeZone' => $eventData['timeZone'] ?? $event->end->timeZone ?? 'America/Sao_Paulo',
                ]);
                $event->setEnd($end);
            }
             if (isset($eventData['attendees']) && is_array($eventData['attendees'])) {
                 $attendees = [];
                 foreach ($eventData['attendees'] as $email) {
                     if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                         $attendees[] = ['email' => $email];
                     }
                 }
                 $event->setAttendees($attendees); // Overwrites existing attendees
            }
            // Add more fields to update as needed

            $updatedEvent = $service->events->update($calendarId, $eventId, $event);
            return $updatedEvent;
        } catch (Exception $e) {
            error_log("Erro ao atualizar evento '{$eventId}' no Google Calendar para usuário ID {$userId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete an event from the user's primary Google Calendar.
     *
     * @param int $userId CRM User ID.
     * @param string $eventId Google Calendar Event ID.
     * @return bool True on success, false on failure.
     */
    public function deleteEvent(int $userId, string $eventId): bool
    {
        $service = $this->getCalendarService($userId);
        if (!$service) {
            error_log("Falha ao obter serviço de calendário autenticado para deletar evento (Usuário ID: {$userId})");
            return false;
        }

        try {
            $calendarId = 'primary';
            $service->events->delete($calendarId, $eventId);
            return true;
        } catch (Exception $e) {
            error_log("Erro ao deletar evento '{$eventId}' do Google Calendar para usuário ID {$userId}: " . $e->getMessage());
            return false;
        }
    }
}

