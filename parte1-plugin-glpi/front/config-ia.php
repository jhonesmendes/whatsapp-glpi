<?php
/**
 * Aba "Inteligência Artificial" do plugin WhatsApp Bot.
 * Configuração da OpenAI (GPT).
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

Html::header('WhatsApp Bot — Inteligência Artificial', $_SERVER['PHP_SELF'], 'config', 'PluginWhatsappbotConfig');

PluginWhatsappbotConfig::renderStyles();
?>

<div class="wa-config-wrap">

  <form id="wa-config-form" onsubmit="return false;">
  <?php echo Html::hidden('_whatsappbot_token', ['value' => PluginWhatsappbotConfig::generateFormToken()]); ?>

  <div class="wa-section">
    <div class="wa-section-head"><span class="ico">🤖</span> Inteligência Artificial (GPT)</div>
    <div class="wa-section-body">
      <div class="wa-grid">
        <div class="wa-field">
          <label>OpenAI API Key</label>
          <input type="password" name="openai_api_key"
            value="<?= htmlspecialchars($config['openai_api_key'] ?? '') ?>"
            placeholder="sk-proj-...">
        </div>
        <div class="wa-field">
          <label>Modelo</label>
          <select name="openai_model">
            <?php foreach (['gpt-4.1', 'gpt-4.1-mini', 'gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo'] as $m): ?>
              <option value="<?= $m ?>" <?= ($config['openai_model'] ?? 'gpt-4.1') === $m ? 'selected' : '' ?>><?= $m ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="wa-field" style="grid-column:1/-1">
          <label>System prompt (instrução base da IA)</label>
          <textarea name="openai_system_prompt"><?= htmlspecialchars($config['openai_system_prompt'] ?? '') ?></textarea>
          <span class="wa-hint">Defina o comportamento da IA ao formatar respostas de chamados</span>
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
