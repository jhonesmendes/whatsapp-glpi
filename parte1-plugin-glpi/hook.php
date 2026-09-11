<?php
/**
 * hook.php
 * Define as funções de hook chamadas pelo GLPI nos eventos configurados em setup.php
 */

/**
 * Chamado quando um Ticket é atualizado.
 * Notifica o usuário no WhatsApp quando o chamado é resolvido ou fechado.
 */
function plugin_whatsappbot_item_update_Ticket(Ticket $ticket): void {
    try {
        include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/config.class.php');
        $config = PluginWhatsappbotConfig::getConfig();
        if (empty($config['is_active'])) return;

        include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/whatsapp.class.php');
        include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/glpiapi.class.php');
        include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/ai.class.php');
        include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/bot.class.php');

        $status = (int)($ticket->fields['status'] ?? 0);
        if (in_array($status, [Ticket::SOLVED, Ticket::CLOSED])) {
            $bot = new PluginWhatsappbotBot();
            $bot->notifyTicketResolved($ticket);
        }
    } catch (Throwable $e) {
        Toolbox::logError('WhatsApp Bot hook error: ' . $e->getMessage());
    }
}

/**
 * Chamado quando um acompanhamento (ITILFollowup) é criado.
 * Notifica o usuário no WhatsApp sobre novos comentários do técnico.
 */
function plugin_whatsappbot_post_item_form_ITILFollowup(ITILFollowup $followup): void {
    try {
        include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/config.class.php');
        $config = PluginWhatsappbotConfig::getConfig();
        if (empty($config['is_active'])) return;
        if ($followup->fields['is_private'] ?? false) return;

        include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/whatsapp.class.php');
        include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/glpiapi.class.php');
        include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/ai.class.php');
        include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/bot.class.php');

        $bot = new PluginWhatsappbotBot();
        $bot->notifyFollowup($followup);
    } catch (Throwable $e) {
        Toolbox::logError('WhatsApp Bot hook error: ' . $e->getMessage());
    }
}
