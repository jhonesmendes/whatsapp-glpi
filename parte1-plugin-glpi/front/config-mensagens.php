<?php
/**
 * Aba "Mensagens do Bot" do plugin WhatsApp Bot.
 * Mensagem de boas-vindas e ação pós-resolução do chamado.
 */

include('../../../inc/includes.php');
include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/config.class.php');

$isAjaxAction = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save']);

if (!$isAjaxAction) {
    Session::checkRight('config', UPDATE);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    PluginWhatsappbotConfig::sendJson(PluginWhatsappbotConfig::handleAjaxSave($_POST));
}

$config = PluginWhatsappbotConfig::getConfig();

Html::header('WhatsApp Bot — Mensagens', $_SERVER['PHP_SELF'], 'config', 'PluginWhatsappbotConfig');

PluginWhatsappbotConfig::renderStyles();
?>

<div class="wa-config-wrap">

  <form id="wa-config-form" onsubmit="return false;">
  <?php echo Html::hidden('_whatsappbot_token', ['value' => PluginWhatsappbotConfig::generateFormToken()]); ?>

  <div class="wa-section">
    <div class="wa-section-head"><span class="ico">💬</span> Mensagens do Bot</div>
    <div class="wa-section-body">
      <div class="wa-grid full">
        <div class="wa-field">
          <label>Mensagem de boas-vindas (enviada na primeira interação)</label>
          <textarea name="welcome_message" style="min-height:110px"><?= htmlspecialchars($config['welcome_message'] ?? '') ?></textarea>
          <span class="wa-hint">Use *texto* para negrito e _texto_ para itálico (formatação WhatsApp)</span>
        </div>
        <div class="wa-field">
          <label>Ação após chamado resolvido</label>
          <select name="post_resolve_action">
            <option value="ask_rating" <?= ($config['post_resolve_action'] ?? '') === 'ask_rating' ? 'selected' : '' ?>>Pedir avaliação (1-5) e transferir se &lt; 3</option>
            <option value="notify_only" <?= ($config['post_resolve_action'] ?? '') === 'notify_only' ? 'selected' : '' ?>>Apenas notificar resolução</option>
            <option value="human" <?= ($config['post_resolve_action'] ?? '') === 'human' ? 'selected' : '' ?>>Transferir para humano</option>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="wa-footer">
    <span id="save-status" style="font-size:12px;margin-right:auto"></span>
    <button type="button" class="submit" onclick="saveConfig()">💾 Salvar configurações</button>
  </div>

  </form>
</div>

<?php
PluginWhatsappbotConfig::renderSaveScript();
Html::footer();
