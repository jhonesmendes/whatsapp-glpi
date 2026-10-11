<?php
/**
 * extension.php — Endpoint da extensão "Fibra Forte Carimbo" (WhatsApp Web)
 * Permite assumir / devolver ao bot a conversa aberta, sem entrar no GLPI.
 *
 * URL: https://SEU_GLPI/plugins/whatsappbot/extension.php
 * Método: POST, Content-Type: text/plain (corpo em JSON)
 * Header: X-Ext-Token: <token configurado na aba Conexão>
 *
 * Corpo: { "action": "status" | "take" | "release" | "ping",
 *          "jids": ["123@lid", ...], "name": "Nome no cabeçalho",
 *          "wa_number": "123@lid" (take/release), "agent": "Nome do atendente" }
 *
 * Fica em plugins/whatsappbot/ (como o webhook.php) e, como ele, precisa
 * receber o corpo com Content-Type "text/plain": com "application/json" o
 * núcleo do GLPI decodifica o corpo para $_POST e barra a requisição
 * ("Acesso negado") por falta de token CSRF de sessão, que a extensão não
 * teria como enviar. O JSON é lido manualmente de php://input.
 */

define('GLPI_ROOT', dirname(dirname(__DIR__)));
include(GLPI_ROOT . "/inc/includes.php");

include_once(dirname(__FILE__) . '/inc/config.class.php');
include_once(dirname(__FILE__) . '/inc/whatsapp.class.php');
include_once(dirname(__FILE__) . '/inc/handoff.class.php');

function wabot_ext_reply(int $code, array $body): void {
    http_response_code($code);
    PluginWhatsappbotConfig::sendJson($body);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wabot_ext_reply(405, ['ok' => false, 'message' => 'Method not allowed']);
}

// Sem token configurado o endpoint fica desligado — diferente do webhook,
// aqui não faz sentido aceitar chamadas anônimas.
$config   = PluginWhatsappbotConfig::getConfig();
$expected = (string)($config['extension_token'] ?? '');
$sent     = (string)($_SERVER['HTTP_X_EXT_TOKEN'] ?? '');
if ($expected === '') {
    wabot_ext_reply(403, ['ok' => false, 'message' => 'Token da extensão não configurado no GLPI (WhatsApp Bot → Conexão).']);
}
if (!hash_equals($expected, $sent)) {
    wabot_ext_reply(401, ['ok' => false, 'message' => 'Token da extensão inválido.']);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    wabot_ext_reply(400, ['ok' => false, 'message' => 'Invalid JSON']);
}

$action = $data['action'] ?? '';

function wabot_ext_session_json(?array $s): array {
    if (!$s) return ['ok' => true, 'found' => false];
    return [
        'ok'             => true,
        'found'          => true,
        'wa_number'      => $s['wa_number'],
        'wa_name'        => $s['wa_name'] ?? '',
        'is_human'       => (bool)$s['is_human'],
        'human_agent'    => $s['human_agent'] ?? '',
        'state'          => $s['state'] ?? '',
        'last_ticket_id' => (int)($s['last_ticket_id'] ?? 0),
    ];
}

try {
    switch ($action) {
        case 'ping':
            wabot_ext_reply(200, ['ok' => true, 'message' => 'Conectado ao GLPI']);

        case 'status':
            $jids = array_filter((array)($data['jids'] ?? []), 'is_string');
            $session = PluginWhatsappbotHandoff::findSession($jids, (string)($data['name'] ?? ''));
            wabot_ext_reply(200, wabot_ext_session_json($session));

        case 'take':
        case 'release':
            $waNumber = (string)($data['wa_number'] ?? '');
            if ($waNumber === '' || !PluginWhatsappbotHandoff::getSession($waNumber)) {
                wabot_ext_reply(404, ['ok' => false, 'message' => 'Conversa não encontrada no bot.']);
            }
            if ($action === 'take') {
                $agent = trim((string)($data['agent'] ?? '')) ?: 'WhatsApp Web';
                PluginWhatsappbotHandoff::take($waNumber, $agent);
            } else {
                PluginWhatsappbotHandoff::release($waNumber);
            }
            file_put_contents(
                GLPI_LOG_DIR . '/whatsappbot.log',
                date('Y-m-d H:i:s') . " EXT [$waNumber] $action por " . ($data['agent'] ?? '?') . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
            wabot_ext_reply(200, wabot_ext_session_json(PluginWhatsappbotHandoff::getSession($waNumber)));

        default:
            wabot_ext_reply(400, ['ok' => false, 'message' => 'Ação desconhecida']);
    }
} catch (Throwable $e) {
    wabot_ext_reply(500, ['ok' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
}
