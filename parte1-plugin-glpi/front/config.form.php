<?php
/**
 * Tela de configuração do plugin no GLPI
 * Acessível em: GLPI → Configuração → Plugins → WhatsApp Bot
 */

include('../../../inc/includes.php');
Session::checkRight('config', UPDATE);

include_once(GLPI_ROOT . '/plugins/whatsappbot/inc/config.class.php');

// --- DIAGNÓSTICO TEMPORÁRIO (v3) ---
// Colocado logo após o checkRight, incondicional, para não depender de
// nenhuma outra lógica do arquivo. Funciona tanto em GET quanto em POST.
if (isset($_GET['debug_csrf'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "== DEBUG v3 ==\n";
    echo "Chegou ate aqui = Session::checkRight passou.\n";
    echo "Metodo: " . $_SERVER['REQUEST_METHOD'] . "\n";
    echo "Campos GET: " . implode(', ', array_keys($_GET)) . "\n";
    echo "Campos POST: " . implode(', ', array_keys($_POST)) . "\n";
    if (isset($_POST['_whatsappbot_token'])) {
        $token = $_POST['_whatsappbot_token'];
        echo "\nToken recebido: $token\n";
        echo "Validou? " . (PluginWhatsappbotConfig::validateFormToken($token) ? 'SIM' : 'NAO') . "\n";
        foreach (PluginWhatsappbotConfig::debugFormToken($token) as $k => $v) {
            echo "$k: $v\n";
        }
    } else {
        echo "\n(nenhum token '_whatsappbot_token' no POST -- normal se foi so um GET de teste)\n";
    }
    exit;
}
// --- FIM DIAGNÓSTICO TEMPORÁRIO ---

// Processa salvamento
//
// OBS: usamos um token anti-CSRF próprio (PluginWhatsappbotConfig::*FormToken)
// em vez do Session::checkCSRF() nativo. Diagnóstico confirmou que, nesta
// instância, a lista de tokens CSRF da sessão ($_SESSION['glpicsrftokens'])
// é sobrescrita por chamadas AJAX concorrentes disparadas pelo próprio
// layout do GLPI (menu, sino de notificação, busca) enquanto esta tela —
// mais pesada em JS — está aberta, fazendo o token gerado no carregamento
// da página nunca bater com o exigido no envio (falso positivo de "ação
// não permitida"). O token próprio é um HMAC (sessão + janela de tempo)
// que não escreve nada na sessão, então não sofre essa corrida.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!PluginWhatsappbotConfig::validateFormToken($_POST['_whatsappbot_token'] ?? null)) {
        Html::displayErrorAndDie('Token de formulário inválido ou expirado. Recarregue a página e tente novamente.');
    }
    PluginWhatsappbotConfig::saveConfig($_POST);
    Session::addMessageAfterRedirect('Configurações salvas com sucesso!', true, INFO);
    Html::back();
    exit;
}

// Processa teste de conexão (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_connection'])) {
    // Descarta qualquer saída acidental (avisos do PHP, BOM, etc.)
    // para garantir que a resposta seja JSON puro.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    header('Content-Type: application/json');

    try {
        $result = PluginWhatsappbotConfig::testBaileysConnection(
            $_POST['baileys_url']   ?? null,
            $_POST['baileys_token'] ?? null
        );
    } catch (\Throwable $e) {
        $result = ['ok' => false, 'message' => 'Erro interno: ' . $e->getMessage()];
    }

    ob_end_clean();
    echo json_encode($result);
    exit;
}

$config = PluginWhatsappbotConfig::getConfig();

Html::header('WhatsApp Bot — Configuração', $_SERVER['PHP_SELF'], 'config', 'PluginWhatsappbotConfig');

$webhookUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . '/plugins/whatsappbot/webhook.php';
?>

<style>
.wa-config-wrap { max-width: 860px; margin: 0 auto; font-family: sans-serif; }
.wa-section { background: #fff; border: 1px solid #ddd; border-radius: 6px; margin-bottom: 18px; overflow: hidden; }
.wa-section-head { background: #f5f5f5; border-bottom: 1px solid #ddd; padding: 10px 18px; font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px; }
.wa-section-head .ico { font-size: 18px; }
.wa-section-body { padding: 16px 18px; }
.wa-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; }
.wa-grid.full { grid-template-columns: 1fr; }
.wa-field { display: flex; flex-direction: column; gap: 4px; }
.wa-field label { font-size: 12px; color: #555; font-weight: 500; }
.wa-field input[type=text],
.wa-field input[type=password],
.wa-field select,
.wa-field textarea { width: 100%; padding: 7px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; box-sizing: border-box; }
.wa-field textarea { min-height: 80px; resize: vertical; }
.wa-hint { font-size: 11px; color: #888; margin-top: 2px; }
.wa-webhook-box { background: #f0f7ff; border: 1px solid #b3d4f7; border-radius: 4px; padding: 8px 12px; font-family: monospace; font-size: 12px; color: #1a5fa8; word-break: break-all; }
.wa-status-bar { display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 14px; }
.wa-dot { width: 10px; height: 10px; border-radius: 50%; }
.wa-dot.green { background: #25d366; }
.wa-dot.red   { background: #e74c3c; }
.wa-dot.gray  { background: #bbb; }
.btn-test { padding: 6px 14px; background: #25d366; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; }
.btn-test:hover { background: #1da851; }
.wa-footer { display: flex; justify-content: flex-end; gap: 10px; padding-top: 10px; border-top: 1px solid #eee; margin-top: 4px; }
.wa-toggle { display: flex; align-items: center; gap: 8px; }
.wa-toggle input[type=checkbox] { width: 18px; height: 18px; cursor: pointer; }
</style>

<div class="wa-config-wrap">

  <form method="POST" action="?debug_csrf=1">
  <?php echo Html::hidden('_whatsappbot_token', ['value' => PluginWhatsappbotConfig::generateFormToken()]); ?>

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

  <!-- API GLPI -->
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

  <!-- IA / GPT -->
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

  <!-- Menu do Bot -->
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

  <!-- Notificação de Técnicos -->
  <div class="wa-section">
    <div class="wa-section-head"><span class="ico">👨‍💻</span> Notificação de Técnicos — Novo Chamado</div>
    <div class="wa-section-body">

      <p style="font-size:12px;color:#555;margin-bottom:14px;line-height:1.5">
        Quando um usuário abrir um chamado via WhatsApp, o bot dispara automaticamente uma mensagem
        para os técnicos cadastrados avisando sobre o novo registro.
        A busca usa o <strong>Grupo de atribuição padrão</strong> configurado acima (campo <em>celular</em> do usuário no GLPI).
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

  <!-- Botões -->
  <div class="wa-footer">
    <a href="conversations.php" class="vsubmit">Ver Conversas</a>
    <input type="submit" name="save" value="💾 Salvar configurações" class="submit">
  </div>

  </form>
</div>

<script>
function testConnection() {
  const bar  = document.getElementById('status-bar');
  const dot  = document.getElementById('status-dot');
  const text = document.getElementById('status-text');
  text.textContent = 'Testando...';
  dot.className = 'wa-dot gray';

  const fd = new FormData();
  fd.append('test_connection', '1');
  fd.append('baileys_url', document.querySelector('[name=baileys_url]').value);
  fd.append('baileys_token', document.querySelector('[name=baileys_token]').value);

  fetch(location.href, { method: 'POST', body: fd })
    .then(async r => {
      const raw = await r.text();
      let data;
      try {
        data = JSON.parse(raw);
      } catch (e) {
        throw new Error('Resposta inválida do servidor (HTTP ' + r.status + '): ' + raw.substring(0, 200));
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
