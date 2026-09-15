<?php
/**
 * webhook.php — Endpoint público
 * Recebe mensagens do servidor Baileys (Node.js)
 *
 * URL pública: https://SEU_GLPI/plugins/whatsappbot/webhook.php
 * Método: POST
 * Content-Type: application/json
 */

// Bootstrap do GLPI
// webhook.php fica em plugins/whatsappbot/ (não em front/ ou ajax/),
// então a raiz do GLPI está 2 níveis acima, não 3.
define('GLPI_ROOT', dirname(dirname(__DIR__)));
include(GLPI_ROOT . "/inc/includes.php");

// Carrega classes do plugin
include_once(dirname(__FILE__) . '/inc/config.class.php');
include_once(dirname(__FILE__) . '/inc/bot.class.php');
include_once(dirname(__FILE__) . '/inc/whatsapp.class.php');
include_once(dirname(__FILE__) . '/inc/glpiapi.class.php');
include_once(dirname(__FILE__) . '/inc/ai.class.php');

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Lê o corpo da requisição
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Valida token de segurança (enviado pelo Baileys no header)
$config = PluginWhatsappbotConfig::getConfig();
$sentToken = $_SERVER['HTTP_X_BOT_TOKEN'] ?? '';

if (!empty($config['baileys_token']) && $sentToken !== $config['baileys_token']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Log da mensagem recebida
$logFile = GLPI_LOG_DIR . '/whatsappbot.log';
$logLine = date('Y-m-d H:i:s') . ' IN  [' . ($data['from'] ?? '?') . '] ' . substr($data['body'] ?? '', 0, 100) . PHP_EOL;
file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

// Processa a mensagem
try {
    $bot = new PluginWhatsappbotBot();
    $bot->processIncoming($data);

    http_response_code(200);
    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    $errLine = date('Y-m-d H:i:s') . ' ERR ' . $e->getMessage() . PHP_EOL;
    file_put_contents($logFile, $errLine, FILE_APPEND | LOCK_EX);

    http_response_code(500);
    echo json_encode(['error' => 'Internal error', 'message' => $e->getMessage()]);
}
