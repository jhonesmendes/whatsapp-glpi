<?php
/**
 * Aba "Integração GLPI" do plugin WhatsApp Bot.
 * API REST, tokens, categoria/grupo padrão.
 */

include('../../../inc/includes.php');
Session::checkRight('config', UPDATE);

include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/config.class.php');

$config = PluginWhatsappbotConfig::getConfig();

Html::header('WhatsApp Bot — Integração GLPI', $_SERVER['PHP_SELF'], 'config', 'PluginWhatsappbotConfig');

PluginWhatsappbotConfig::renderStyles();
?>

<div class="wa-config-wrap">

  <form id="wa-config-form" onsubmit="return false;">

  <div class="wa-section">
    <div class="wa-section-head"><span class="ico">🔗</span> Integração API GLPI</div>
    <div class="wa-section-body">
      <div class="wa-grid">
        <div class="wa-field" style="grid-column:1/-1">
          <label>URL da API REST do GLPI</label>
          <input type="text" name="glpi_api_url"
            value="<?= htmlspecialchars($config['glpi_api_url'] ?? '') ?>"
            placeholder="https://seuglpi.com/apirest.php">
          <span class="wa-hint">Habilite em GLPI → Configuração → Geral → API → Habilitar API REST</span>
        </div>
        <div class="wa-field">
          <label>App Token GLPI</label>
          <input type="password" name="glpi_app_token"
            value="<?= htmlspecialchars($config['glpi_app_token'] ?? '') ?>">
          <span class="wa-hint">Gerado em GLPI → Configuração → Geral → API</span>
        </div>
        <div class="wa-field">
          <label>User Token (usuário para criar chamados)</label>
          <input type="password" name="glpi_user_token"
            value="<?= htmlspecialchars($config['glpi_user_token'] ?? '') ?>">
          <span class="wa-hint">Perfil do usuário GLPI → API token</span>
        </div>
        <div class="wa-field">
          <label>Categoria padrão dos chamados</label>
          <input type="text" name="default_category_id"
            value="<?= (int)($config['default_category_id'] ?? 0) ?>"
            placeholder="ID da categoria (0 = sem categoria)">
        </div>
        <div class="wa-field">
          <label>Grupo de atribuição padrão</label>
          <input type="text" name="default_group_id"
            value="<?= (int)($config['default_group_id'] ?? 0) ?>"
            placeholder="ID do grupo (0 = sem grupo)">
        </div>
        <div class="wa-field">
          <label>Se usuário não for encontrado pelo número</label>
          <select name="unknown_user_action">
            <option value="visitor" <?= ($config['unknown_user_action'] ?? '') === 'visitor' ? 'selected' : '' ?>>Criar como visitante</option>
            <option value="deny"    <?= ($config['unknown_user_action'] ?? '') === 'deny'    ? 'selected' : '' ?>>Não permitir abertura de chamado</option>
            <option value="human"   <?= ($config['unknown_user_action'] ?? '') === 'human'   ? 'selected' : '' ?>>Transferir para humano</option>
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
