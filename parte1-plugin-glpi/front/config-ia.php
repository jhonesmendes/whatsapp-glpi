<?php
/**
 * Aba "Inteligência Artificial" do plugin WhatsApp Bot.
 * Configuração da OpenAI (GPT).
 */

include('../../../inc/includes.php');
Session::checkRight('config', UPDATE);

include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/config.class.php');

$config = PluginWhatsappbotConfig::getConfig();

Html::header('WhatsApp Bot — Inteligência Artificial', $_SERVER['PHP_SELF'], 'config', 'PluginWhatsappbotConfig');

PluginWhatsappbotConfig::renderStyles();
?>

<div class="wa-config-wrap">

  <form id="wa-config-form" onsubmit="return false;">

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
          <span class="wa-hint">No modo clássico, define o tom da IA ao formatar respostas de chamados. No modo agente, é anexado como instruções específicas da empresa, além das regras padrão do agente.</span>
        </div>
      </div>
    </div>
  </div>

  <div class="wa-section">
    <div class="wa-section-head"><span class="ico">🧪</span> Modo agente (experimental)</div>
    <div class="wa-section-body">
      <input type="hidden" name="_has_agent_mode" value="1">
      <div class="wa-toggle">
        <input type="checkbox" name="agent_mode" id="agent_mode" value="1"
          <?= !empty($config['agent_mode']) ? 'checked' : '' ?>>
        <label for="agent_mode" style="font-size:13px;cursor:pointer">Ativar modo agente (IA decide o fluxo da conversa)</label>
      </div>
      <span class="wa-hint" style="display:block;margin-top:8px;">
        Substitui o menu numerado (1/2/3) e o passo-a-passo fixo por uma conversa livre: a própria IA decide o que perguntar e quando abrir o chamado, consultar ou transferir para um atendente, usando as mesmas ações de sempre por trás. As mensagens configuradas na aba "Mensagens do Bot" deixam de ser usadas nesse modo — quem escreve tudo é a IA, seguindo o "System prompt" acima. Recomendado testar antes de ativar em produção.
      </span>
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
