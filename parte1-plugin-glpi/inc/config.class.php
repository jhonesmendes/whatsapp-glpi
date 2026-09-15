<?php
/**
 * PluginWhatsappbotConfig
 * Gerencia configurações e exibe a tela de admin dentro do GLPI
 */
class PluginWhatsappbotConfig extends CommonGLPI {

    static $rightname = 'config';

    // ---------------------------------------------------------------
    // Helpers estáticos
    // ---------------------------------------------------------------

    /**
     * Retorna todas as configurações como array
     */
    public static function getConfig(): array {
        global $DB;
        $row = $DB->request([
            'FROM'  => 'glpi_plugin_whatsappbot_configs',
            'LIMIT' => 1
        ])->current();
        return $row ?: [];
    }

    /**
     * Campos que cada aba pode enviar, com a função de normalização de cada um.
     * Usado por saveConfig() para atualizar só os campos que a aba realmente
     * enviou — assim salvar uma aba nunca apaga o que está nas outras.
     */
    private static function fieldCasters(): array {
        return [
            'baileys_url'          => fn($v) => trim($v),
            'baileys_token'        => fn($v) => trim($v),
            'whatsapp_number'      => fn($v) => trim($v),
            'timeout_minutes'      => fn($v) => (int)$v,
            'openai_api_key'       => fn($v) => trim($v),
            'openai_model'         => fn($v) => $v,
            'openai_system_prompt' => fn($v) => trim($v),
            'welcome_message'      => fn($v) => trim($v),
            'ask_description_message' => fn($v) => trim($v),
            'ask_name_message'     => fn($v) => trim($v),
            'ask_location_message' => fn($v) => trim($v),
            'glpi_api_url'         => fn($v) => trim($v),
            'glpi_app_token'       => fn($v) => trim($v),
            'glpi_user_token'      => fn($v) => trim($v),
            'default_category_id'  => fn($v) => (int)$v,
            'default_group_id'     => fn($v) => (int)$v,
            'unknown_user_action'  => fn($v) => $v,
            'post_resolve_action'  => fn($v) => $v,
            'tech_notify_numbers'  => fn($v) => trim($v),
        ];
    }

    /**
     * Salva configurações — só atualiza os campos presentes em $data.
     * Cada aba da tela de configuração envia apenas os campos que ela
     * mesma mostra, então isso evita que salvar uma aba zere as outras.
     *
     * Checkboxes (ex: is_active) não aparecem em $data quando desmarcados,
     * então cada formulário deve enviar um marcador oculto correspondente
     * (ex: "_has_is_active") sempre que o checkbox estiver presente na tela,
     * para diferenciar "desmarcado" de "campo nem existe nesta aba".
     */
    public static function saveConfig(array $data): bool {
        global $DB;
        $config = self::getConfig();

        $fields = [];
        foreach (self::fieldCasters() as $key => $cast) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = $cast($data[$key]);
            }
        }
        if (array_key_exists('_has_is_active', $data)) {
            $fields['is_active'] = isset($data['is_active']) ? 1 : 0;
        }
        $fields['date_mod'] = date('Y-m-d H:i:s');

        if ($config) {
            $DB->update('glpi_plugin_whatsappbot_configs', $fields, ['id' => $config['id']]);
        } else {
            // Registro inicial: preenche o que não veio com os padrões da tabela.
            $DB->insert('glpi_plugin_whatsappbot_configs', $fields);
        }
        return true;
    }

    /**
     * Envia uma resposta JSON e encerra o script, descartando qualquer
     * saída acidental (avisos do PHP, BOM, etc.) que corromperia o JSON.
     */
    public static function sendJson(array $result): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    /**
     * Handler compartilhado para o "Salvar" de qualquer aba via AJAX.
     * Sempre retorna um array pronto para json_encode — nunca lança nem
     * deixa o GLPI renderizar uma página de erro em HTML no meio do fetch.
     *
     * A validação de CSRF acontece automaticamente pelo próprio núcleo do
     * GLPI (inc/includes.php) antes deste código rodar, desde que a
     * requisição seja feita para uma URL sob .../ajax/ com o header
     * "X-Glpi-Csrf-Token" — ver PluginWhatsappbotConfig::ajaxUrl() e
     * renderSaveScript(). Não precisamos (nem devemos) checar de novo aqui.
     */
    public static function handleAjaxSave(array $data): array {
        try {
            if (!Session::haveRight('config', UPDATE)) {
                return ['ok' => false, 'message' => 'Sem permissão para alterar esta configuração (direito config/UPDATE ausente ou sessão expirada).'];
            }
            self::saveConfig($data);
            return ['ok' => true, 'message' => 'Configurações salvas com sucesso!'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Erro interno: ' . $e->getMessage()];
        }
    }

    // ---------------------------------------------------------------
    // Menu GLPI
    // ---------------------------------------------------------------

    static function getMenuName()    { return 'WhatsApp Bot'; }
    static function getMenuContent() {
        $menu = [];
        $menu['title'] = self::getMenuName();
        $menu['page']  = '/plugins/whatsappbot/front/config.form.php';
        $menu['links']['Conexão']                    = '/plugins/whatsappbot/front/config.form.php';
        $menu['links']['Integração GLPI']             = '/plugins/whatsappbot/front/config-glpi.php';
        $menu['links']['Inteligência Artificial']     = '/plugins/whatsappbot/front/config-ia.php';
        $menu['links']['Mensagens do Bot']            = '/plugins/whatsappbot/front/config-mensagens.php';
        $menu['links']['Notificação de Técnicos']     = '/plugins/whatsappbot/front/config-notificacoes.php';
        $menu['links']['Conversas']                   = '/plugins/whatsappbot/front/conversations.php';
        return $menu;
    }

    // ---------------------------------------------------------------
    // Verifica conexão com Baileys
    // ---------------------------------------------------------------

    public static function testBaileysConnection(?string $baileysUrl = null, ?string $baileysToken = null): array {
        $config = self::getConfig();

        // Usa os valores recém-digitados no formulário (ainda não salvos), se enviados;
        // caso contrário, cai para o que já está gravado no banco.
        $baileysUrl   = $baileysUrl   !== null && $baileysUrl   !== '' ? $baileysUrl   : ($config['baileys_url']   ?? '');
        $baileysToken = $baileysToken !== null                        ? $baileysToken : ($config['baileys_token'] ?? '');

        if (empty($baileysUrl)) {
            return ['ok' => false, 'message' => 'URL do servidor Baileys não configurada'];
        }

        $url = rtrim($baileysUrl, '/') . '/status';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => [
                'x-bot-token: ' . $baileysToken,
                'Content-Type: application/json'
            ]
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['ok' => false, 'message' => "Erro de conexão: $err"];
        }
        if ($code !== 200) {
            return ['ok' => false, 'message' => "Servidor respondeu HTTP $code"];
        }

        $data = json_decode($resp, true);
        return [
            'ok'      => true,
            'message' => 'Conectado',
            'status'  => $data['status'] ?? 'unknown',
            'number'  => $data['number'] ?? '',
        ];
    }

    // ---------------------------------------------------------------
    // UI compartilhada entre as abas de configuração
    // ---------------------------------------------------------------

    /**
     * URL fixa da página atual, calculada no servidor. Não usar
     * location.href/pathname no JS: a navegação por abas do GLPI
     * reescreve a barra de endereço (ex: .../config.form.php/conversations.php)
     * sem recarregar a página, o que faria o POST ir para o caminho errado.
     */
    public static function selfUrl(): string {
        return (isset($_SERVER['HTTPS']) ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST']
            . $_SERVER['PHP_SELF'];
    }

    /**
     * URL de um endpoint AJAX do plugin (plugins/whatsappbot/ajax/$file).
     * Requisições POST para caminhos sob .../ajax/ são tratadas de forma
     * especial pelo núcleo do GLPI: o token CSRF é lido do header
     * "X-Glpi-Csrf-Token" (não do corpo do POST) e não é invalidado a
     * cada chamada — pensado exatamente para múltiplas chamadas AJAX
     * concorrentes, como as desta tela. Ver inc/includes.php do GLPI.
     */
    public static function ajaxUrl(string $file): string {
        global $CFG_GLPI;
        return (isset($_SERVER['HTTPS']) ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST']
            . $CFG_GLPI['root_doc'] . '/plugins/whatsappbot/ajax/' . $file;
    }

    public static function renderStyles(): void {
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
        .wa-footer { display: flex; justify-content: flex-end; align-items: center; gap: 10px; padding-top: 10px; border-top: 1px solid #eee; margin-top: 4px; }
        .wa-toggle { display: flex; align-items: center; gap: 8px; }
        .wa-toggle input[type=checkbox] { width: 18px; height: 18px; cursor: pointer; }
        </style>
        <?php
    }

    /**
     * Script comum de salvamento — cada aba só precisa de um
     * <form id="wa-config-form"> com os campos dela e um botão chamando
     * saveConfig(). O envio é sempre via fetch/AJAX (nunca submit
     * tradicional de página inteira).
     */
    public static function renderSaveScript(): void {
        $saveUrl = self::ajaxUrl('save.php');
        $csrf    = Session::getNewCSRFToken();
        ?>
        <script>
        const WA_SAVE_URL  = <?= json_encode($saveUrl) ?>;
        const WA_CSRF_TOKEN = <?= json_encode($csrf) ?>;

        function saveConfig() {
          const statusEl = document.getElementById('save-status');
          statusEl.textContent = 'Salvando...';
          statusEl.style.color = '#888';

          const form = document.getElementById('wa-config-form');
          const fd   = new FormData(form);

          fetch(WA_SAVE_URL, {
            method: 'POST',
            body: fd,
            headers: { 'X-Glpi-Csrf-Token': WA_CSRF_TOKEN }
          })
            .then(async r => {
              const raw = await r.text();
              try {
                return JSON.parse(raw);
              } catch (e) {
                let readable = raw;
                try {
                  const doc = new DOMParser().parseFromString(raw, 'text/html');
                  readable = doc.body.innerText.replace(/\s+/g, ' ').trim();
                } catch (_) {}
                throw new Error('Resposta inválida do servidor (HTTP ' + r.status + '): ' + readable.substring(0, 1500));
              }
            })
            .then(data => {
              statusEl.style.color = data.ok ? '#25d366' : '#e74c3c';
              statusEl.textContent = (data.ok ? '✅ ' : '❌ ') + data.message;
            })
            .catch(e => {
              statusEl.style.color = '#e74c3c';
              statusEl.textContent = '❌ Erro: ' + e.message;
              console.error('Salvar falhou:', e);
            });
        }
        </script>
        <?php
    }
}
