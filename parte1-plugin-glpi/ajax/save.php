<?php
/**
 * Endpoint AJAX: salva configurações de qualquer aba do plugin.
 *
 * Fica em plugins/whatsappbot/ajax/ (não em front/) de propósito: o
 * núcleo do GLPI (inc/includes.php) trata POSTs para caminhos sob
 * .../ajax/ de forma especial — o token CSRF vem do header
 * "X-Glpi-Csrf-Token" em vez do corpo do POST, e não é invalidado a
 * cada chamada (pensado para múltiplas requisições AJAX concorrentes).
 * Um POST comum para plugins/whatsappbot/front/*.php exige o campo
 * "_glpi_csrf_token" no corpo e falha com "ação não permitida" se
 * ausente — foi exatamente isso que quebrava o salvamento antes.
 */

include('../../../inc/includes.php');
include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/config.class.php');

PluginWhatsappbotConfig::sendJson(PluginWhatsappbotConfig::handleAjaxSave($_POST));
