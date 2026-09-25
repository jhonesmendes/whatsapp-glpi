<?php
/**
 * Lista de conversas ativas do WhatsApp Bot
 * GLPI → Plugins → WhatsApp Bot → Conversas
 */

include('../../../inc/includes.php');
Session::checkRight('config', READ);

include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/config.class.php');
include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/whatsapp.class.php');

global $DB;

/**
 * Registra o canal (bot/atendente) do ÚLTIMO chamado da sessão informada.
 * Guardado por chamado, não por sessão — sem isso, telas externas (ex:
 * dashboard de acompanhamento) só conseguem ver o status do chamado mais
 * recente de cada número, perdendo o histórico de chamados anteriores.
 */
function wabot_sync_ticket_channel($DB, string $waNumber, int $isHuman): void {
    $session = $DB->request([
        'FROM'  => 'glpi_plugin_whatsappbot_sessions',
        'WHERE' => ['wa_number' => $waNumber],
        'LIMIT' => 1
    ])->current();

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

// Ação: assumir conversa como humano
//
// OBS: wa_number pode ser um JID completo (ex: "27762752512242@lid",
// identificador de privacidade do WhatsApp), não só dígitos — por isso
// não usamos is_numeric() aqui, só confirmamos que o parâmetro veio.
if (!empty($_GET['take'])) {
    $wa_number = $_GET['take'];
    $DB->update('glpi_plugin_whatsappbot_sessions', [
        'is_human'    => 1,
        'human_agent' => Session::getLoginUserID(true)
    ], ['wa_number' => $wa_number]);
    wabot_sync_ticket_channel($DB, $wa_number, 1);
    Session::addMessageAfterRedirect("Conversa assumida. Responda pelo seu WhatsApp.", true, INFO);
    Html::back();
    exit;
}

// Ação: devolver ao bot (finaliza o atendimento humano)
if (!empty($_GET['release'])) {
    $wa_number = $_GET['release'];
    wabot_sync_ticket_channel($DB, $wa_number, 0);
    $DB->update('glpi_plugin_whatsappbot_sessions', [
        'is_human'    => 0,
        'human_agent' => '',
        'state'       => 'menu',
        'context'     => null,
    ], ['wa_number' => $wa_number]);

    // Antes isso só mudava o estado no banco, em silêncio — o usuário
    // continuava achando que estava esperando um humano, sem saber que o
    // atendimento tinha sido encerrado, até mandar mensagem de novo.
    $config = PluginWhatsappbotConfig::getConfig();
    $wa     = new PluginWhatsappbotWhatsapp($config);
    $wa->send($wa_number, "✅ Atendimento encerrado pelo técnico.\n\n_Digite *menu* para novas opções_");

    Session::addMessageAfterRedirect("Conversa devolvida ao bot.", true, INFO);
    Html::back();
    exit;
}

$sessions = $DB->request([
    'FROM'  => 'glpi_plugin_whatsappbot_sessions',
    'ORDER' => 'date_last_msg DESC',
    'LIMIT' => 100
]);

Html::header('WhatsApp Bot — Conversas', $_SERVER['PHP_SELF'], 'config', 'PluginWhatsappbotConfig');
?>
<style>
.wa-conv-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.wa-conv-table th { background: #f0f0f0; padding: 8px 12px; text-align: left; border-bottom: 2px solid #ddd; }
.wa-conv-table td { padding: 8px 12px; border-bottom: 1px solid #eee; vertical-align: middle; }
.wa-conv-table tr:hover td { background: #fafafa; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.badge-green { background: #d4edda; color: #155724; }
.badge-orange { background: #fff3cd; color: #856404; }
.badge-blue  { background: #cce5ff; color: #004085; }
.badge-gray  { background: #e2e3e5; color: #383d41; }
</style>

<h2 style="margin: 10px 0 16px; font-size: 18px;">💬 Conversas WhatsApp</h2>

<?php if (!iterator_count($sessions)): ?>
  <p style="color:#888;">Nenhuma conversa registrada ainda.</p>
<?php else: $sessions->rewind(); ?>
<table class="wa-conv-table">
  <thead>
    <tr>
      <th>Número WA</th>
      <th>Nome</th>
      <th>Estado</th>
      <th>Último chamado</th>
      <th>Última mensagem</th>
      <th>Ações</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($sessions as $s): ?>
    <?php
      $isHuman = (bool)$s['is_human'];
      $state   = $s['state'] ?? 'menu';
      $stateMap = [
        'menu'             => ['Bot — Menu', 'badge-green'],
        'agent'            => ['Bot — Agente IA', 'badge-green'],
        'open_ticket_desc'   => ['Abrindo chamado', 'badge-orange'],
        'open_ticket_attach' => ['Abrindo chamado (anexo)', 'badge-orange'],
        'open_ticket_cat'  => ['Escolhendo categoria', 'badge-orange'],
        'consult_ticket'   => ['Consultando', 'badge-blue'],
        'human'            => ['Atendimento humano', 'badge-blue'],
        'rating'           => ['Avaliando', 'badge-gray'],
      ];
      [$stateLabel, $badgeClass] = $stateMap[$state] ?? [$state, 'badge-gray'];
    ?>
    <tr>
      <td><?= htmlspecialchars($s['wa_number']) ?></td>
      <td><?= htmlspecialchars($s['wa_name'] ?: '—') ?></td>
      <td><span class="badge <?= $badgeClass ?>"><?= $stateLabel ?></span>
        <?php if ($isHuman && $s['human_agent']): ?>
          <br><small style="color:#888">Agente: <?= htmlspecialchars($s['human_agent']) ?></small>
        <?php endif; ?>
      </td>
      <td><?= $s['last_ticket_id'] ? '<a href="/glpi/front/ticket.form.php?id=' . (int)$s['last_ticket_id'] . '">#' . (int)$s['last_ticket_id'] . '</a>' : '—' ?></td>
      <td><?= htmlspecialchars($s['date_last_msg'] ?? '—') ?></td>
      <td>
        <?php if ($isHuman): ?>
          <a href="?release=<?= urlencode($s['wa_number']) ?>" class="vsubmit" style="font-size:11px;">Devolver ao bot</a>
        <?php else: ?>
          <a href="?take=<?= urlencode($s['wa_number']) ?>" class="submit" style="font-size:11px;">Assumir</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php Html::footer(); ?>
