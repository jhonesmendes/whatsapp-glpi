<?php
/**
 * Endpoint AJAX: desconecta a sessão WhatsApp no servidor Baileys
 * (forçando um logout, para depois escanear um novo QR code).
 * Ver comentário em ajax/save.php sobre por que isso fica em ajax/.
 */

include('../../../inc/includes.php');
include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/config.class.php');
include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/whatsapp.class.php');

if (!Session::haveRight('config', UPDATE)) {
    PluginWhatsappbotConfig::sendJson(['ok' => false, 'message' => 'Sem permissão (direito config/UPDATE ausente ou sessão expirada).']);
}

try {
    $config = PluginWhatsappbotConfig::getConfig();
    $wa     = new PluginWhatsappbotWhatsapp($config);
    $ok     = $wa->logout();
    $result = $ok
        ? ['ok' => true, 'message' => 'WhatsApp desconectado. Clique em "Ver QR code" para conectar um número (pode levar alguns segundos para o novo QR aparecer).']
        : ['ok' => false, 'message' => 'Não foi possível desconectar — verifique se o servidor Baileys está acessível.'];
} catch (\Throwable $e) {
    $result = ['ok' => false, 'message' => 'Erro interno: ' . $e->getMessage()];
}

PluginWhatsappbotConfig::sendJson($result);
