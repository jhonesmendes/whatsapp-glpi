<?php
/**
 * Aba "Notificação de Técnicos" do plugin WhatsApp Bot.
 * Números fixos adicionais para avisar sobre novos chamados.
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

Html::header('WhatsApp Bot — Notificação de Técnicos', $_SERVER['PHP_SELF'], 'config', 'PluginWhatsappbotConfig');

PluginWhatsappbotConfig::renderStyles();
?>

<div class="wa-config-wrap">

  <form id="wa-config-form" onsubmit="return false;">
  <?php echo Html::hidden('_whatsappbot_token', ['value' => PluginWhatsappbotConfig::generateFormToken()]); ?>

  <div class="wa-section">
    <div class="wa-section-head"><span class="ico">👨‍💻</span> Notificação de Técnicos — Novo Chamado</div>
    <div class="wa-section-body">

      <p style="font-size:12px;color:#555;margin-bottom:14px;line-height:1.5">
        Quando um usuário abrir um chamado via WhatsApp, o bot dispara automaticamente uma mensagem
        para os técnicos cadastrados avisando sobre o novo registro.
        A busca usa o <strong>Grupo de atribuição padrão</strong> (aba Integração GLPI, campo <em>celular</em> do usuário no GLPI).
        Você também pode adicionar números fixos abaixo.
      </p>

      <div class="wa-grid">
        <div class="wa-field" style="grid-column:1/-1">
          <label>Números fixos adicionais para notificação (além do grupo)</label>
          <textarea name="tech_notify_numbers" style="min-height:70px"
            placeholder="Um número por linha com DDI:&#10;5511999990001&#10;5511999990002"><?= htmlspecialchars($config['tech_notify_numbers'] ?? '') ?></textarea>
          <span class="wa-hint">
            Formato: somente dígitos com DDI. Ex: <code>5511999990001</code>
            — útil para supervisores ou técnicos de plantão que não estão no grupo padrão.
          </span>
        </div>
      </div>

      <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:4px;padding:10px 14px;font-size:12px;color:#5d4037;margin-top:10px">
        <strong>Como funciona:</strong><br>
        1. Usuário abre chamado → GLPI registra → bot confirma para o usuário<br>
        2. Bot busca membros do <em>Grupo de atribuição padrão</em> com celular cadastrado no GLPI<br>
        3. Soma os <em>Números fixos adicionais</em> acima<br>
        4. Envia para cada técnico: número do chamado, assunto, solicitante, prévia da descrição e link direto no GLPI
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
