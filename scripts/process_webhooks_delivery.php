<?php

/**
 * Script to process webhook files and populate delivery tracking fields
 * in whatsapp_campaign_messages table
 * 
 * Usage: php scripts/process_webhooks_delivery.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Apoio19\Crm\Models\Database;

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$db = Database::getInstance();

// Directory containing webhook files
$hookDir = __DIR__ . '/../api/hook';

// Statistics
$stats = [
    'total_files' => 0,
    'processed_webhooks' => 0,
    'delivered_updated' => 0,
    'read_updated' => 0,
    'failed_updated' => 0,
    'errors' => 0
];

echo "=== Processing Webhook Files ===\n";
echo "Directory: $hookDir\n\n";

// Function to process a single JSON file
function processWebhookFile($filePath, $db, &$stats)
{
    $stats['total_files']++;

    try {
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if (!$data || !isset($data['body']['entry'])) {
            return; // Not a valid webhook format
        }

        foreach ($data['body']['entry'] as $entry) {
            foreach ($entry['changes'] as $change) {
                if (!isset($change['value']['statuses'])) {
                    continue;
                }

                foreach ($change['value']['statuses'] as $status) {
                    $stats['processed_webhooks']++;

                    $wamid = $status['id'] ?? null;
                    $statusType = $status['status'] ?? null;
                    $timestamp = $status['timestamp'] ?? null;

                    if (!$wamid || !$statusType || !$timestamp) {
                        continue;
                    }

                    // Convert Unix timestamp to MySQL datetime
                    $datetime = date('Y-m-d H:i:s', (int)$timestamp);

                    // Prepare update based on status type
                    $updateFields = [];
                    $params = [];

                    switch ($statusType) {
                        case 'delivered':
                            $updateFields[] = 'delivered_at = ?';
                            $params[] = $datetime;
                            $updateType = 'delivered_updated';
                            break;

                        case 'read':
                            $updateFields[] = 'read_at = ?';
                            $params[] = $datetime;
                            $updateType = 'read_updated';
                            break;

                        case 'failed':
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

                                $updateFields[] = 'failure_message = ?';
                                $params[] = $errorMsg;
                            }
                            $updateType = 'failed_updated';
                            break;

                        default:
                            continue 2; // Skip this status
                    }

                    // Add wamid to params
                    $params[] = $wamid;

                    // Update database
                    $sql = "UPDATE whatsapp_campaign_messages 
                            SET " . implode(', ', $updateFields) . "
                            WHERE message_id = ?";

                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);

                    if ($stmt->rowCount() > 0) {
                        $stats[$updateType]++;
                    }
                }
            }
        }
    } catch (Exception $e) {
        $stats['errors']++;
        echo "Error processing file " . basename($filePath) . ": " . $e->getMessage() . "\n";
    }
}

// Process all JSON files in hook directory
$files = glob($hookDir . '/*.json');
$totalFiles = count($files);

echo "Found $totalFiles JSON files\n";
echo "Processing...\n\n";

$progressInterval = max(1, floor($totalFiles / 20)); // Show progress every 5%

foreach ($files as $index => $file) {
    processWebhookFile($file, $db, $stats);

    // Show progress
    if ((($index + 1) % $progressInterval) === 0 || ($index + 1) === $totalFiles) {
        $percent = round((($index + 1) / $totalFiles) * 100);
        $current = $index + 1;
        echo "\rProgress: $percent% ($current/$totalFiles files)";
    }
}

// Process subdirectories
$subdirs = glob($hookDir . '/*', GLOB_ONLYDIR);
foreach ($subdirs as $subdir) {
    $files = glob($subdir . '/*.json');
    foreach ($files as $file) {
        processWebhookFile($file, $db, $stats);
    }
}

echo "\n\n=== Processing Complete ===\n";
echo "Total files scanned: {$stats['total_files']}\n";
echo "Webhook events processed: {$stats['processed_webhooks']}\n";
echo "\nUpdates applied:\n";
echo "  - Delivered: {$stats['delivered_updated']}\n";
echo "  - Read: {$stats['read_updated']}\n";
echo "  - Failed: {$stats['failed_updated']}\n";
echo "\nErrors: {$stats['errors']}\n";

echo "\n[OK] Done!\n";
