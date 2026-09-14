<?php
/**
 * Aba "Conexão" do plugin WhatsApp Bot.
 * Status WhatsApp/Baileys, testar conexão, QR code.
 * Acessível em: GLPI → Configuração → Plugins → WhatsApp Bot
 *
 * Salvar e testar conexão são feitos via fetch para plugins/whatsappbot/ajax/
 * (ver PluginWhatsappbotConfig::renderSaveScript()) — este arquivo só
 * renderiza a página (GET).
 */

include('../../../inc/includes.php');
Session::checkRight('config', UPDATE);

include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/config.class.php');

$config = PluginWhatsappbotConfig::getConfig();

Html::header('WhatsApp Bot — Conexão', $_SERVER['PHP_SELF'], 'config', 'PluginWhatsappbotConfig');

global $CFG_GLPI;
$webhookUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . $CFG_GLPI['root_doc'] . '/plugins/whatsappbot/webhook.php';

PluginWhatsappbotConfig::renderStyles();
?>

<div class="wa-config-wrap">

  <form id="wa-config-form" onsubmit="return false;">
  <input type="hidden" name="_has_is_active" value="1">

  <!-- Status da conexão -->
  <div class="wa-section">
    <div class="wa-section-head"><span class="ico">📡</span> Status da Conexão</div>
    <div class="wa-section-body">
      <div class="wa-status-bar" id="status-bar">
        <div class="wa-dot gray" id="status-dot"></div>
        <span id="status-text">Clique em "Testar conexão" para verificar</span>
        <button type="button" class="btn-test" onclick="testConnection()">Testar conexão</button>
        <button type="button" class="btn-test" style="background:#128c7e" onclick="showQrCode()">📷 Ver QR code</button>
      </div>

      <div class="wa-grid">
        <div class="wa-field">
          <label>URL do servidor Baileys (Node.js)</label>
          <input type="text" name="baileys_url"
            value="<?= htmlspecialchars($config['baileys_url'] ?? 'http://SEU_SERVIDOR:3333') ?>">
          <span class="wa-hint">Exemplo: http://192.168.1.100:3333 ou https://bot.suaempresa.com</span>
        </div>
        <div class="wa-field">
          <label>Token de autenticação (mesmo configurado no servidor Baileys)</label>
          <input type="password" name="baileys_token"
            value="<?= htmlspecialchars($config['baileys_token'] ?? '') ?>"
            placeholder="Token secreto">
        </div>
        <div class="wa-field">
          <label>Número WhatsApp (conectado no servidor Baileys)</label>
          <input type="text" name="whatsapp_number"
            value="<?= htmlspecialchars($config['whatsapp_number'] ?? '') ?>"
            placeholder="+55 11 99999-0000">
        </div>
        <div class="wa-field">
          <label>Timeout sem resposta (minutos)</label>
          <input type="text" name="timeout_minutes"
            value="<?= (int)($config['timeout_minutes'] ?? 15) ?>">
        </div>
      </div>

      <div style="margin-top: 14px;">
        <label style="font-size:12px;color:#555;font-weight:500;display:block;margin-bottom:4px;">
          URL do Webhook — copie e configure no servidor Baileys:
        </label>
        <div class="wa-webhook-box"><?= htmlspecialchars($webhookUrl) ?></div>
      </div>

      <div style="margin-top:12px" class="wa-toggle">
        <input type="checkbox" name="is_active" id="is_active" value="1"
          <?= !empty($config['is_active']) ? 'checked' : '' ?>>
        <label for="is_active" style="font-size:13px;cursor:pointer">Bot ativo (processa mensagens recebidas)</label>
      </div>
    </div>
  </div>

  <!-- Botões -->
  <div class="wa-footer">
    <span id="save-status" style="font-size:12px;margin-right:auto"></span>
    <button type="button" class="submit" onclick="saveConfig()">💾 Salvar configurações</button>
  </div>

  </form>
</div>

<?php PluginWhatsappbotConfig::renderSaveScript(); ?>

<script>
const WA_TEST_CONNECTION_URL = <?= json_encode(PluginWhatsappbotConfig::ajaxUrl('test_connection.php')) ?>;

function testConnection() {
  const dot  = document.getElementById('status-dot');
  const text = document.getElementById('status-text');
  text.textContent = 'Testando...';
  dot.className = 'wa-dot gray';

  const fd = new FormData();
  fd.append('baileys_url', document.querySelector('[name=baileys_url]').value);
  fd.append('baileys_token', document.querySelector('[name=baileys_token]').value);

  fetch(WA_TEST_CONNECTION_URL, {
    method: 'POST',
    body: fd,
    headers: { 'X-Glpi-Csrf-Token': WA_CSRF_TOKEN }
  })
    .then(async r => {
      const raw = await r.text();
      let data;
      try {
        data = JSON.parse(raw);
      } catch (e) {
        let readable = raw;
        try {
          const doc = new DOMParser().parseFromString(raw, 'text/html');
          readable = doc.body.innerText.replace(/\s+/g, ' ').trim();
        } catch (_) {}
        throw new Error('Resposta inválida do servidor (HTTP ' + r.status + '): ' + readable.substring(0, 1500));
      }
      return data;
    })
    .then(data => {
      if (data.ok) {
        dot.className = 'wa-dot green';
        text.textContent = '✅ Conectado — WhatsApp ' + (data.status || '') + (data.number ? ' | Número: ' + data.number : '');
      } else {
        dot.className = 'wa-dot red';
        text.textContent = '❌ Erro: ' + (data.message || 'Não foi possível conectar');
      }
    })
    .catch(e => {
      dot.className = 'wa-dot red';
      text.textContent = '❌ Erro de rede: ' + e.message;
      console.error('Teste de conexão falhou:', e);
    });
}

function showQrCode() {
  const baileysUrl = document.querySelector('[name=baileys_url]').value.replace(/\/$/, '');
  const token = document.querySelector('[name=baileys_token]').value;
  if (!baileysUrl) {
    alert('Preencha a URL do servidor Baileys primeiro.');
    return;
  }
  window.open(baileysUrl + '/qr-view?token=' + encodeURIComponent(token), 'wa-qr', 'width=400,height=480');
}
</script>

<?php Html::footer(); ?>
