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
     */
    public static function handleAjaxSave(array $data): array {
        try {
            if (!Session::haveRight('config', UPDATE)) {
                return ['ok' => false, 'message' => 'Sem permissão para alterar esta configuração (direito config/UPDATE ausente ou sessão expirada).'];
            }
            if (!self::validateFormToken($data['_whatsappbot_token'] ?? null)) {
                return ['ok' => false, 'message' => 'Token de formulário inválido ou expirado. Recarregue a página e tente novamente.'];
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

    // ---------------------------------------------------------------
    // Token anti-CSRF próprio (sem depender de $_SESSION['glpicsrftokens'])
    // ---------------------------------------------------------------
    //
    // Session::checkCSRF() nativo do GLPI guarda os tokens válidos num
    // array dentro de $_SESSION. Em telas com bastante atividade AJAX de
    // fundo (menu, notificações, busca), pedidos concorrentes podem gravar
    // a sessão de volta com uma cópia desatualizada e apagar o token que
    // acabou de ser gerado — falso positivo de "ação não permitida".
    //
    // Para evitar essa corrida, geramos um token que não escreve nada na
    // sessão: é um HMAC de (id da sessão + janela de tempo) usando um
    // segredo mantido só no servidor. A validação recalcula o HMAC e
    // compara — sem precisar consultar nenhum estado mutável.

    const CSRF_WINDOW_SECONDS = 1800; // 30 minutos por janela (token válido por até ~1h)

    private static function getCsrfSecretPath(): string {
        return GLPI_ROOT . '/plugins/whatsappbot/config/csrf_secret.php';
    }

    private static function getCsrfSecret(): string {
        $path = self::getCsrfSecretPath();

        if (is_file($path)) {
            $secret = include $path;
            if (is_string($secret) && strlen($secret) >= 32) {
                return $secret;
            }
        }

        $secret = bin2hex(random_bytes(32));
        $dir    = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($path, "<?php\nreturn " . var_export($secret, true) . ";\n");
        return $secret;
    }

    public static function generateFormToken(): string {
        $window = (int) floor(time() / self::CSRF_WINDOW_SECONDS);
        return hash_hmac('sha256', session_id() . '|' . $window, self::getCsrfSecret());
    }

    /**
     * Utilitário de diagnóstico — retorna detalhes internos do cálculo do
     * token, útil para investigar falhas de validação caso reapareçam.
     */
    public static function debugFormToken(?string $token): array {
        $path      = self::getCsrfSecretPath();
        $dir       = dirname($path);
        $fileExisted = is_file($path);
        $secret    = self::getCsrfSecret(); // pode criar o arquivo agora, se ainda não existir
        $nowWindow = (int) floor(time() / self::CSRF_WINDOW_SECONDS);

        return [
            'session_id'            => session_id(),
            'config_dir'            => $dir,
            'config_dir_existe'     => is_dir($dir) ? 'sim' : 'nao',
            'config_dir_gravavel'   => is_writable($dir) ? 'sim' : (is_dir($dir) ? 'nao' : 'n/a (dir nao existe)'),
            'secret_arquivo_existia_antes' => $fileExisted ? 'sim' : 'nao',
            'secret_arquivo_existe_agora'  => is_file($path) ? 'sim' : 'nao',
            'secret_arquivo_gravavel'      => is_file($path) ? (is_writable($path) ? 'sim' : 'nao') : 'n/a',
            'secret_primeiros_8_chars'     => substr($secret, 0, 8),
            'janela_atual'          => $nowWindow,
            'token_esperado_janela_atual'    => hash_hmac('sha256', session_id() . '|' . $nowWindow, $secret),
            'token_esperado_janela_anterior' => hash_hmac('sha256', session_id() . '|' . ($nowWindow - 1), $secret),
        ];
    }

    public static function validateFormToken(?string $token): bool {
        if (empty($token)) {
            return false;
        }
        $secret = self::getCsrfSecret();
        $nowWindow = (int) floor(time() / self::CSRF_WINDOW_SECONDS);
        // Aceita a janela atual e a anterior (evita falha na borda do intervalo)
        foreach ([$nowWindow, $nowWindow - 1] as $window) {
            $expected = hash_hmac('sha256', session_id() . '|' . $window, $secret);
            if (hash_equals($expected, $token)) {
                return true;
            }
        }
        return false;
    }

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
        $selfUrl = self::selfUrl();
        ?>
        <script>
        const WA_SELF_URL = <?= json_encode($selfUrl) ?>;

        function saveConfig() {
          const statusEl = document.getElementById('save-status');
          statusEl.textContent = 'Salvando...';
          statusEl.style.color = '#888';

          const form = document.getElementById('wa-config-form');
          const fd   = new FormData(form);
          fd.append('save', '1');

          fetch(WA_SELF_URL, { method: 'POST', body: fd })
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
