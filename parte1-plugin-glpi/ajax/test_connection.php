<?php
/**
 * Endpoint AJAX: testa a conexão com o servidor Baileys.
 * Ver comentário em ajax/save.php sobre por que isso fica em ajax/.
 */

include('../../../inc/includes.php');
include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/config.class.php');

if (!Session::haveRight('config', UPDATE)) {
    PluginWhatsappbotConfig::sendJson(['ok' => false, 'message' => 'Sem permissão (direito config/UPDATE ausente ou sessão expirada).']);
}

try {
    $result = PluginWhatsappbotConfig::testBaileysConnection(
        $_POST['baileys_url']   ?? null,
        $_POST['baileys_token'] ?? null
    );
} catch (\Throwable $e) {
    $result = ['ok' => false, 'message' => 'Erro interno: ' . $e->getMessage()];
}

PluginWhatsappbotConfig::sendJson($result);
