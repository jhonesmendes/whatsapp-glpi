<?php
/**
 * PluginWhatsappbotWhatsapp
 * Cliente HTTP que faz chamadas ao servidor Baileys (Node.js) no servidor B.
 * O GLPI (servidor A) chama este cliente para enviar mensagens.
 */
class PluginWhatsappbotWhatsapp {

    private string $baseUrl;
    private string $token;
    private string $logFile;

    public function __construct(array $config) {
        $this->baseUrl = rtrim($config['baileys_url'] ?? 'http://localhost:3333', '/');
        $this->token   = $config['baileys_token'] ?? '';
        $this->logFile = GLPI_LOG_DIR . '/whatsappbot.log';
    }

    /**
     * Completa o JID com "@s.whatsapp.net" apenas se ainda não tiver um
     * domínio. Alguns contatos chegam com sufixo diferente (ex: "@lid",
     * o identificador pseudônimo de privacidade do WhatsApp) — nesses
     * casos usar "@s.whatsapp.net" manda a mensagem para um destino que
     * não existe, então a resposta nunca chega.
     */
    private function toJid(string $to): string {
        return str_contains($to, '@') ? $to : $to . '@s.whatsapp.net';
    }

    /**
     * Envia mensagem de texto para um número
     *
     * @param string $to     JID destino, com sufixo (@s.whatsapp.net, @lid, etc.)
     *                       ou só dígitos com DDI (assume @s.whatsapp.net nesse caso)
     * @param string $text   Texto da mensagem (suporta markdown WA: *negrito*, _itálico_)
     */
    public function send(string $to, string $text): bool {
        return $this->post('/send', [
            'to'   => $this->toJid($to),
            'text' => $text,
        ]);
    }

    /**
     * Envia imagem com legenda opcional
     */
    public function sendImage(string $to, string $imageUrl, string $caption = ''): bool {
        return $this->post('/send-image', [
            'to'      => $this->toJid($to),
            'url'     => $imageUrl,
            'caption' => $caption,
        ]);
    }

    /**
     * Obtém status da conexão WhatsApp no servidor Baileys
     */
    public function getStatus(): array {
        $resp = $this->get('/status');
        return $resp ?: ['status' => 'unknown'];
    }

    /**
     * Solicita novo QR code para reconexão
     */
    public function requestQr(): array {
        $resp = $this->get('/qr');
        return $resp ?: ['error' => 'Failed to get QR'];
    }

    /**
     * Desconecta a sessão WhatsApp
     */
    public function logout(): bool {
        return $this->post('/logout', []);
    }

    // ---------------------------------------------------------------
    // Métodos HTTP internos
    // ---------------------------------------------------------------

    private function post(string $path, array $body): bool {
        $url  = $this->baseUrl . $path;
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-bot-token: ' . $this->token,
            ],
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $this->log("POST $path ERROR: $err");
            return false;
        }

        $ok = $code >= 200 && $code < 300;
        if (!$ok) {
            $this->log("POST $path HTTP $code: $resp");
        }
        return $ok;
    }

    private function get(string $path): ?array {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => [
                'x-bot-token: ' . $this->token,
            ],
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err || $code !== 200) return null;
        return json_decode($resp, true);
    }

    private function log(string $msg): void {
        $line = date('Y-m-d H:i:s') . ' WA  ' . $msg . PHP_EOL;
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
