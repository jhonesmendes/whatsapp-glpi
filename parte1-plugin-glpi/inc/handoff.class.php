<?php
/**
 * PluginWhatsappbotHandoff
 * Assumir / devolver uma conversa entre o bot e um atendente humano.
 *
 * Compartilhado entre a tela "Conversas" do GLPI e a extensão do WhatsApp
 * Web (extension.php) — as duas portas fazem exatamente a mesma coisa.
 */
class PluginWhatsappbotHandoff {

    /**
     * Acha a sessão da conversa aberta no WhatsApp Web.
     *
     * O WhatsApp Web identifica contatos por "123@lid" ou "5565...@c.us";
     * o Baileys grava "123@lid" ou "5565...@s.whatsapp.net" — então além
     * da comparação exata, troca @c.us por @s.whatsapp.net. Se nenhum JID
     * bater (ex: conversa ainda sem mensagens carregadas na tela), tenta
     * pelo nome, mas só aceita se existir exatamente uma sessão com ele.
     *
     * @param string[] $jids JIDs encontrados na conversa aberta
     */
    public static function findSession(array $jids, string $name = ''): ?array {
        global $DB;

        $candidates = [];
        foreach ($jids as $jid) {
            $jid = trim((string)$jid);
            if ($jid === '' || str_ends_with($jid, '@g.us')) continue;
            $candidates[] = $jid;
            if (str_ends_with($jid, '@c.us')) {
                $digits = substr($jid, 0, -strlen('@c.us'));
                $candidates[] = $digits . '@s.whatsapp.net';
                $candidates[] = $digits;
            }
        }

        if ($candidates) {
            $row = $DB->request([
                'FROM'  => 'glpi_plugin_whatsappbot_sessions',
                'WHERE' => ['wa_number' => array_values(array_unique($candidates))],
                'ORDER' => 'date_last_msg DESC',
                'LIMIT' => 1
            ])->current();
            if ($row) return $row;
        }

        $name = trim($name);
        if ($name !== '') {
            $rows = iterator_to_array($DB->request([
                'FROM'  => 'glpi_plugin_whatsappbot_sessions',
                'WHERE' => ['wa_name' => $name],
                'LIMIT' => 2
            ]), false);
            if (count($rows) === 1) return $rows[0];
        }

        return null;
    }

    public static function getSession(string $waNumber): ?array {
        global $DB;
        $row = $DB->request([
            'FROM'  => 'glpi_plugin_whatsappbot_sessions',
            'WHERE' => ['wa_number' => $waNumber],
            'LIMIT' => 1
        ])->current();
        return $row ?: null;
    }

    /**
     * Para o bot nessa conversa: daqui em diante o atendente responde.
     */
    public static function take(string $waNumber, string $agent): void {
        global $DB;
        $DB->update('glpi_plugin_whatsappbot_sessions', [
            'is_human'    => 1,
            'human_agent' => mb_substr($agent, 0, 100),
        ], ['wa_number' => $waNumber]);
        self::syncTicketChannel($waNumber, 1);
    }

    /**
     * Devolve ao bot (finaliza o atendimento humano) e avisa o usuário.
     *
     * Antes isso só mudava o estado no banco, em silêncio — o usuário
     * continuava achando que estava esperando um humano, sem saber que o
     * atendimento tinha sido encerrado, até mandar mensagem de novo.
     */
    public static function release(string $waNumber): void {
        global $DB;
        self::syncTicketChannel($waNumber, 0);
        $DB->update('glpi_plugin_whatsappbot_sessions', [
            'is_human'    => 0,
            'human_agent' => '',
            'state'       => 'menu',
            'context'     => null,
        ], ['wa_number' => $waNumber]);

        $wa = new PluginWhatsappbotWhatsapp(PluginWhatsappbotConfig::getConfig());
        $wa->send($waNumber, "✅ Atendimento encerrado pelo técnico.\n\n_Digite *menu* para novas opções_");
    }

    /**
     * Registra o canal (bot/atendente) do ÚLTIMO chamado da sessão informada.
     * Guardado por chamado, não por sessão — sem isso, telas externas (ex:
     * dashboard de acompanhamento) só conseguem ver o status do chamado mais
     * recente de cada número, perdendo o histórico de chamados anteriores.
     */
    private static function syncTicketChannel(string $waNumber, int $isHuman): void {
        global $DB;
        $session  = self::getSession($waNumber);
        $ticketId = (int)($session['last_ticket_id'] ?? 0);
        if ($ticketId <= 0) return;

        $exists = $DB->request([
            'FROM'  => 'glpi_plugin_whatsappbot_ticket_channel',
            'WHERE' => ['tickets_id' => $ticketId],
            'LIMIT' => 1
        ])->current();

        $fields = ['wa_number' => $waNumber, 'is_human' => $isHuman, 'date_mod' => date('Y-m-d H:i:s')];
        if ($exists) {
            $DB->update('glpi_plugin_whatsappbot_ticket_channel', $fields, ['tickets_id' => $ticketId]);
        } else {
            $fields['tickets_id'] = $ticketId;
            $DB->insert('glpi_plugin_whatsappbot_ticket_channel', $fields);
        }
    }
}
