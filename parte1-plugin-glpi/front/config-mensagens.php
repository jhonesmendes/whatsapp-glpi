<?php
/**
 * Aba "Mensagens do Bot" do plugin WhatsApp Bot.
 * Mensagem de boas-vindas e ação pós-resolução do chamado.
 */

include('../../../inc/includes.php');
Session::checkRight('config', UPDATE);

include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/config.class.php');

$config = PluginWhatsappbotConfig::getConfig();

Html::header('WhatsApp Bot — Mensagens', $_SERVER['PHP_SELF'], 'config', 'PluginWhatsappbotConfig');

PluginWhatsappbotConfig::renderStyles();
?>

<div class="wa-config-wrap">

  <form id="wa-config-form" onsubmit="return false;">

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
          <label>Mensagem pedindo a descrição do problema (opção "1 — Abrir chamado")</label>
          <textarea name="ask_description_message" style="min-height:90px"><?= htmlspecialchars($config['ask_description_message'] ?? '') ?></textarea>
          <span class="wa-hint">Enviada logo após o usuário escolher "Abrir chamado"</span>
        </div>
        <div class="wa-field">
          <label>Mensagem perguntando se quer anexar foto/documento</label>
          <textarea name="ask_attachment_message" style="min-height:70px"><?= htmlspecialchars($config['ask_attachment_message'] ?? '') ?></textarea>
          <span class="wa-hint">Enviada depois da descrição, só quando a pessoa não mandou nenhuma imagem/documento junto com ela — qualquer resposta que não seja um anexo é tratada como "não quero anexar" e segue o fluxo normalmente</span>
        </div>
        <div class="wa-field">
          <label>Mensagem pedindo o nome de quem está solicitando</label>
          <textarea name="ask_name_message" style="min-height:70px"><?= htmlspecialchars($config['ask_name_message'] ?? '') ?></textarea>
          <span class="wa-hint">Enviada logo após a descrição — a resposta é obrigatória e é usada como "Solicitante" no chamado, em vez do nome de contato do WhatsApp</span>
        </div>
        <div class="wa-field">
          <label>Mensagem pedindo o e-mail de quem está solicitando</label>
          <textarea name="ask_email_message" style="min-height:70px"><?= htmlspecialchars($config['ask_email_message'] ?? '') ?></textarea>
          <span class="wa-hint">Enviada logo após o nome — usada para tentar vincular o solicitante a uma conta já existente no GLPI (a maioria dos usuários não tem celular cadastrado, mas quase todos têm e-mail)</span>
        </div>
        <div class="wa-field">
          <label>Mensagem pedindo a localização/filial</label>
          <textarea name="ask_location_message" style="min-height:70px"><?= htmlspecialchars($config['ask_location_message'] ?? '') ?></textarea>
          <span class="wa-hint">Enviada depois do nome — vira uma lista com as localizações cadastradas no GLPI, a resposta é obrigatória</span>
        </div>
        <div class="wa-field">
          <label>Mensagem de cobrança — 1ª tentativa errada no menu</label>
          <textarea name="menu_reminder_message" style="min-height:70px"><?= htmlspecialchars($config['menu_reminder_message'] ?? '') ?></textarea>
          <span class="wa-hint">Enviada quando a pessoa manda algo que não é 1/2/3 no menu (nome, telefone, áudio, etc) pela primeira vez</span>
        </div>
        <div class="wa-field">
          <label>Mensagem de cobrança — 2ª tentativa errada no menu</label>
          <textarea name="menu_reminder_message_2" style="min-height:70px"><?= htmlspecialchars($config['menu_reminder_message_2'] ?? '') ?></textarea>
          <span class="wa-hint">Enviada na segunda vez seguida que a pessoa não escolhe uma opção válida</span>
        </div>
        <div class="wa-field">
          <label>Mensagem final — 3ª tentativa errada (bot para de insistir)</label>
          <textarea name="menu_blocked_message" style="min-height:70px"><?= htmlspecialchars($config['menu_blocked_message'] ?? '') ?></textarea>
          <span class="wa-hint">Enviada uma vez só na 3ª tentativa errada — depois o bot fica em silêncio até a pessoa digitar *chamado* (ou "menu"/"0"/"voltar"/"cancelar") para retomar</span>
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
