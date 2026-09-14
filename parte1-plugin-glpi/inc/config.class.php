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
     * Salva configurações
     */
    public static function saveConfig(array $data): bool {
        global $DB;
        $config = self::getConfig();

        $fields = [
            'baileys_url'          => trim($data['baileys_url']   ?? ''),
            'baileys_token'        => trim($data['baileys_token']  ?? ''),
            'whatsapp_number'      => trim($data['whatsapp_number'] ?? ''),
            'openai_api_key'       => trim($data['openai_api_key'] ?? ''),
            'openai_model'         => $data['openai_model']        ?? 'gpt-4.1',
            'openai_system_prompt' => trim($data['openai_system_prompt'] ?? ''),
            'welcome_message'      => trim($data['welcome_message'] ?? ''),
            'timeout_minutes'      => (int)($data['timeout_minutes'] ?? 15),
            'glpi_api_url'         => trim($data['glpi_api_url']   ?? ''),
            'glpi_app_token'       => trim($data['glpi_app_token'] ?? ''),
            'glpi_user_token'      => trim($data['glpi_user_token'] ?? ''),
            'default_category_id'  => (int)($data['default_category_id'] ?? 0),
            'default_group_id'     => (int)($data['default_group_id']    ?? 0),
            'unknown_user_action'  => $data['unknown_user_action']  ?? 'visitor',
            'post_resolve_action'  => $data['post_resolve_action']  ?? 'ask_rating',
            'tech_notify_numbers'  => trim($data['tech_notify_numbers'] ?? ''),
            'is_active'            => isset($data['is_active']) ? 1 : 0,
            'date_mod'             => date('Y-m-d H:i:s'),
        ];

        if ($config) {
            $DB->update('glpi_plugin_whatsappbot_configs', $fields, ['id' => $config['id']]);
        } else {
            $DB->insert('glpi_plugin_whatsappbot_configs', $fields);
        }
        return true;
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
        $menu['links']['config'] = '/plugins/whatsappbot/front/config.form.php';
        $menu['links']['conversations'] = '/plugins/whatsappbot/front/conversations.php';
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
}
