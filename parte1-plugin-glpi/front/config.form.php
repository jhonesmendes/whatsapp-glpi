<?php
/**
 * Aba "Conexão" do plugin WhatsApp Bot.
 * Status WhatsApp/Baileys, testar conexão, QR code.
 * Acessível em: GLPI → Configuração → Plugins → WhatsApp Bot
 */

include('../../../inc/includes.php');
include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/config.class.php');

$isAjaxAction = $_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['save']) || isset($_POST['test_connection']));

// Nas rotas AJAX (save/test_connection) verificamos o direito manualmente
// e sempre respondemos em JSON — ver PluginWhatsappbotConfig::handleAjaxSave().
if (!$isAjaxAction) {
    Session::checkRight('config', UPDATE);
}

// Processa salvamento (via fetch/AJAX — ver PluginWhatsappbotConfig::renderSaveScript())
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    PluginWhatsappbotConfig::sendJson(PluginWhatsappbotConfig::handleAjaxSave($_POST));
}

// Processa teste de conexão (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_connection'])) {
    try {
        if (!Session::haveRight('config', UPDATE)) {
            $result = ['ok' => false, 'message' => 'Sem permissão (direito config/UPDATE ausente ou sessão expirada).'];
        } else {
            $result = PluginWhatsappbotConfig::testBaileysConnection(
                $_POST['baileys_url']   ?? null,
                $_POST['baileys_token'] ?? null
            );
        }
    } catch (\Throwable $e) {
        $result = ['ok' => false, 'message' => 'Erro interno: ' . $e->getMessage()];
    }

    PluginWhatsappbotConfig::sendJson($result);
}

$config = PluginWhatsappbotConfig::getConfig();

Html::header('WhatsApp Bot — Conexão', $_SERVER['PHP_SELF'], 'config', 'PluginWhatsappbotConfig');

$webhookUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . '/plugins/whatsappbot/webhook.php';

PluginWhatsappbotConfig::renderStyles();
?>

<div class="wa-config-wrap">

  <form id="wa-config-form" onsubmit="return false;">
  <?php echo Html::hidden('_whatsappbot_token', ['value' => PluginWhatsappbotConfig::generateFormToken()]); ?>
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
function testConnection() {
  const dot  = document.getElementById('status-dot');
  const text = document.getElementById('status-text');
  text.textContent = 'Testando...';
  dot.className = 'wa-dot gray';

  const fd = new FormData();
  fd.append('test_connection', '1');
  fd.append('baileys_url', document.querySelector('[name=baileys_url]').value);
  fd.append('baileys_token', document.querySelector('[name=baileys_token]').value);

  fetch(WA_SELF_URL, { method: 'POST', body: fd })
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
