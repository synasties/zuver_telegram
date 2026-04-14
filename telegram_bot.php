<?php

// =============================================================================
// CONFIGURATION — edit these values before deploying
// =============================================================================

define('TELEGRAM_BOT_TOKEN', '');   // e.g. 123456:ABC-DEF...
define('ZUVER_API_ENDPOINT',  '');   // base URL, no trailing slash
define('ZUVER_API_KEY',       '');     // API key token
define('ZUVER_AGENT_ID',      '');        // agent ID from your Zuver dashboard

// Maximum file size accepted from Telegram for upload to Zuver (bytes, default 20 MB)
define('MAX_FILE_SIZE', 20 * 1024 * 1024);

// =============================================================================
// BOOTSTRAP
// =============================================================================

// Only accept POST requests (Telegram webhook delivery)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(400);
    exit('Empty body');
}

$update = json_decode($rawBody, true);
if (!$update) {
    http_response_code(400);
    exit('Invalid JSON');
}

// Always respond 200 to Telegram immediately so it does not retry
http_response_code(200);
header('Content-Type: application/json');

// =============================================================================
// MAIN HANDLER
// =============================================================================

handle_update($update);

// =============================================================================
// CORE FUNCTIONS
// =============================================================================

/**
 * Dispatches an incoming Telegram update.
 */
function handle_update(array $update): void
{
    // Only handle regular messages (ignore edited messages, channel posts, etc.)
    if (!isset($update['message'])) {
        return;
    }

    $message = $update['message'];
    $chatId  = $message['chat']['id'];
    $text    = $message['text'] ?? '';

    // Determine if the message contains a file/image attachment
    $filePath   = null;   // server-side path after upload to Zuver
    $inputType  = 'Text'; // Zuver input_type: Text | File | Image

    if (isset($message['photo'])) {
        // photo array — grab the largest resolution
        $photoSizes = $message['photo'];
        $photo      = end($photoSizes);
        $fileId     = $photo['file_id'];
        $filePath   = upload_telegram_file_to_zuver($fileId, $chatId);
        $inputType  = 'Image';
        if (empty($text)) {
            $text = 'Please analyse this image.';
        }

    } elseif (isset($message['document'])) {
        $fileId   = $message['document']['file_id'];
        $fileSize = $message['document']['file_size'] ?? 0;
        if ($fileSize > MAX_FILE_SIZE) {
            send_telegram_message($chatId, 'Sorry, that file is too large to process (max ' . (MAX_FILE_SIZE / 1024 / 1024) . ' MB).');
            return;
        }
        $filePath  = upload_telegram_file_to_zuver($fileId, $chatId);
        $inputType = 'File';
        if (empty($text)) {
            $text = 'Please analyse the attached file.';
        }

    } elseif (isset($message['video'])) {
        $fileId   = $message['video']['file_id'];
        $fileSize = $message['video']['file_size'] ?? 0;
        if ($fileSize > MAX_FILE_SIZE) {
            send_telegram_message($chatId, 'Sorry, that video is too large to process (max ' . (MAX_FILE_SIZE / 1024 / 1024) . ' MB).');
            return;
        }
        $filePath  = upload_telegram_file_to_zuver($fileId, $chatId);
        $inputType = 'File';
        if (empty($text)) {
            $text = 'Please analyse the attached video.';
        }

    } elseif (isset($message['audio'])) {
        $fileId   = $message['audio']['file_id'];
        $filePath = upload_telegram_file_to_zuver($fileId, $chatId);
        $inputType = 'File';
        if (empty($text)) {
            $text = 'Please analyse the attached audio file.';
        }

    } elseif (isset($message['voice'])) {
        $fileId   = $message['voice']['file_id'];
        $filePath = upload_telegram_file_to_zuver($fileId, $chatId);
        $inputType = 'File';
        if (empty($text)) {
            $text = 'Please transcribe or describe this voice message.';
        }

    } elseif (isset($message['sticker'])) {
        // Stickers are images
        $fileId   = $message['sticker']['file_id'];
        $filePath = upload_telegram_file_to_zuver($fileId, $chatId);
        $inputType = 'Image';
        if (empty($text)) {
            $text = 'Describe this sticker.';
        }
    }

    // If there is still no text after all the above, bail out silently
    if (empty($text)) {
        send_telegram_message($chatId, 'I can only process text, images, and files. Please send one of those.');
        return;
    }

    // Call Zuver /chat using the configured agent ID directly
    $reply = call_zuver_chat(ZUVER_AGENT_ID, $text, $inputType, $filePath);

    // Send the reply back to Telegram
    send_telegram_message($chatId, $reply);
}

// =============================================================================
// ZUVER HELPERS
// =============================================================================

/**
 * Posts a message to the Zuver /chat endpoint and returns the agent reply.
 *
 * @param string      $agentId   Zuver agent ID
 * @param string      $message   User message text
 * @param string      $inputType "Text" | "File" | "Image"
 * @param string|null $filePath  Server-side file path returned by /upload (if any)
 */
function call_zuver_chat(string $agentId, string $message, string $inputType = 'Text', ?string $filePath = null): string
{
    $body = [
        'agent_id'   => $agentId,
        'message'    => $message,
        'stream'     => false,
        'input_type' => $inputType,
    ];

    if ($filePath !== null) {
        $body['file_paths'] = [$filePath];
    }

    $response = zuver_request('POST', '/api/v1/chat', $body);

    if (isset($response['reply'])) {
        return $response['reply'];
    }

    if (isset($response['error'])) {
        return 'Zuver error: ' . $response['error'];
    }

    return 'Sorry, I could not process your request right now.';
}

/**
 * Uploads a file (downloaded from Telegram) to Zuver's /upload endpoint and
 * returns the server-side path to be used in subsequent /chat calls.
 *
 * @param string $telegramFileId  Telegram file_id
 * @param int    $chatId          Telegram chat ID (used for contextual logging only)
 */
function upload_telegram_file_to_zuver(string $telegramFileId, int $chatId): ?string
{
    // Step 1: ask Telegram for the file path
    $fileInfo = telegram_request('getFile', ['file_id' => $telegramFileId]);
    if (!isset($fileInfo['result']['file_path'])) {
        return null;
    }

    $tgFilePath = $fileInfo['result']['file_path'];
    $fileUrl    = 'https://api.telegram.org/file/bot' . TELEGRAM_BOT_TOKEN . '/' . $tgFilePath;

    // Step 2: download the file into memory
    $fileData = @file_get_contents($fileUrl);
    if ($fileData === false) {
        return null;
    }

    // Step 3: upload to Zuver via multipart/form-data
    $boundary  = '----ZuverBoundary' . md5(uniqid('', true));
    $filename  = basename($tgFilePath);

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
    $body .= "Content-Type: application/octet-stream\r\n\r\n";
    $body .= $fileData . "\r\n";
    $body .= "--{$boundary}--\r\n";

    $ch = curl_init(ZUVER_API_ENDPOINT . '/api/v1/upload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . ZUVER_API_KEY,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$raw) {
        return null;
    }

    $decoded = json_decode($raw, true);
    return $decoded['path'] ?? null;
}

/**
 * Generic Zuver API request helper.
 *
 * @param string     $method  HTTP method (GET, POST, PUT, DELETE)
 * @param string     $path    API path, e.g. "/api/v1/agents"
 * @param array|null $body    Request body (will be JSON-encoded)
 * @return mixed Decoded JSON response or null on failure
 */
function zuver_request(string $method, string $path, ?array $body = null)
{
    $url = ZUVER_API_ENDPOINT . $path;

    $headers = [
        'Authorization: Bearer ' . ZUVER_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$raw) {
        return null;
    }

    $decoded = json_decode($raw, true);
    return $decoded;
}

// =============================================================================
// TELEGRAM HELPERS
// =============================================================================

/**
 * Sends a text message to a Telegram chat.
 *
 * @param int|string $chatId  Telegram chat ID
 * @param string     $text    Message text (Markdown is supported)
 */
function send_telegram_message($chatId, string $text): void
{
    // Telegram has a 4096-character limit per message; split if necessary
    $chunks = mb_str_split($text, 4096);
    foreach ($chunks as $chunk) {
        telegram_request('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => $chunk,
            'parse_mode' => 'Markdown',
        ]);
    }
}

/**
 * Makes a call to the Telegram Bot API.
 *
 * @param string $method Telegram API method name, e.g. "sendMessage"
 * @param array  $params Parameters for the method
 * @return array|null Decoded JSON response
 */
function telegram_request(string $method, array $params = []): ?array
{
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $raw = curl_exec($ch);
    curl_close($ch);

    if (!$raw) {
        return null;
    }

    return json_decode($raw, true);
}
