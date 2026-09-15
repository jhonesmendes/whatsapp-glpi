<?php
/**
 * PluginWhatsappbotAi
 * Integração com a API da OpenAI (GPT-4.1).
 * Formata dados de chamados em mensagens amigáveis para WhatsApp.
 */
class PluginWhatsappbotAi {

    private string $apiKey;
    private string $model;
    private string $systemPrompt;
    private string $logFile;

    // Custo em tokens — limite para não gerar respostas gigantes no WA
    const MAX_TOKENS = 400;

    public function __construct(array $config) {
        $this->apiKey       = $config['openai_api_key']       ?? '';
        $this->model        = $config['openai_model']         ?? 'gpt-4.1';
        $this->systemPrompt = $config['openai_system_prompt'] ?? 'Você é um assistente de TI. Responda em português, de forma clara e concisa.';
        $this->logFile      = GLPI_LOG_DIR . '/whatsappbot.log';
    }

    /**
     * Envia uma mensagem para o GPT e retorna a resposta em texto
     *
     * @param string $userPrompt  Prompt com os dados a formatar
     * @param string|null $system  System prompt override (opcional)
     * @return string|null        Texto da resposta, ou null em caso de erro
     */
    public function complete(string $userPrompt, ?string $system = null): ?string {
        if (empty($this->apiKey)) {
            $this->log('API Key não configurada');
            return null;
        }

        $payload = [
            'model'      => $this->model,
            'max_tokens' => self::MAX_TOKENS,
            'messages'   => [
                [
                    'role'    => 'system',
                    'content' => $system ?? $this->systemPrompt
                ],
                [
                    'role'    => 'user',
                    'content' => $userPrompt
                ]
            ]
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $this->log("OpenAI CURL error: $err");
            return null;
        }

        $data = json_decode($resp, true);

        if ($code !== 200) {
            $errMsg = $data['error']['message'] ?? $resp;
            $this->log("OpenAI HTTP $code: $errMsg");
            return null;
        }

        $text = $data['choices'][0]['message']['content'] ?? null;
        return $text ? trim($text) : null;
    }

    /**
     * Formata lista de tickets para WhatsApp
     */
    public function formatTicketList(array $tickets): ?string {
        $json = json_encode($tickets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $prompt = <<<PROMPT
Formate a lista de chamados abaixo para mensagem de WhatsApp.
Use emojis de status (🆕 Novo, ⏳ Em atendimento, ✅ Resolvido, 🔒 Fechado).
Seja conciso. Máximo 5 chamados. Não inclua IDs técnicos, apenas o número do chamado.

Dados dos chamados (JSON):
$json
PROMPT;
        return $this->complete($prompt);
    }

    /**
     * Formata detalhes de um chamado específico
     */
    public function formatTicketDetail(array $ticket): ?string {
        $json = json_encode($ticket, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $prompt = <<<PROMPT
Formate os detalhes deste chamado para mensagem de WhatsApp.
Inclua: número do chamado, assunto, status, técnico responsável (se houver), data de abertura, e resumo dos últimos comentários (se houver).
Seja claro e direto. Use emojis moderadamente.

Dados do chamado (JSON):
$json
PROMPT;
        return $this->complete($prompt);
    }

    /**
     * Gera título automático para chamado a partir da descrição do usuário
     */
    public function generateTicketTitle(string $description): ?string {
        $prompt = "Crie um título curto e objetivo (máximo 60 caracteres) para um chamado de TI com base nesta descrição: \"$description\". Responda apenas com o título, sem aspas.";
        return $this->complete($prompt, 'Você é um assistente que cria títulos concisos para chamados de TI.');
    }

    /**
     * Escolhe a categoria de chamado mais adequada com base na descrição do
     * problema, dentre as categorias existentes no GLPI.
     *
     * @param string $description  Descrição do problema, escrita pelo usuário
     * @param array  $categories   Lista de categorias: [['id' => .., 'name' => ..], ...]
     * @return int  ID da categoria escolhida, ou 0 se nenhuma se encaixar / IA indisponível
     */
    public function detectCategory(string $description, array $categories): int {
        if (empty($categories)) return 0;

        $list = '';
        foreach ($categories as $cat) {
            $list .= "{$cat['id']} — {$cat['name']}\n";
        }

        $prompt = <<<PROMPT
Categorias de chamado de TI disponíveis (ID — Nome):
$list

Descrição do problema relatado pelo usuário:
"$description"

Qual categoria da lista acima melhor se encaixa nesse problema?
Responda APENAS com o número do ID da categoria escolhida, nada mais.
Se nenhuma categoria da lista fizer sentido, responda apenas: 0
PROMPT;

        $resp = $this->complete(
            $prompt,
            'Você classifica chamados de TI em categorias. Responda sempre apenas com um número.'
        );

        if ($resp === null) return 0;

        preg_match('/\d+/', $resp, $matches);
        $catId = isset($matches[0]) ? (int)$matches[0] : 0;

        // Só aceita o ID se ele realmente existir na lista recebida
        $validIds = array_column($categories, 'id');
        return in_array($catId, array_map('intval', $validIds), true) ? $catId : 0;
    }

    private function log(string $msg): void {
        $line = date('Y-m-d H:i:s') . ' AI  ' . $msg . PHP_EOL;
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
