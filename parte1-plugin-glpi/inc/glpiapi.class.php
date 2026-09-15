<?php
/**
 * PluginWhatsappbotGlpiApi
 * Integração com a API REST do GLPI.
 * Cria chamados, consulta chamados, busca usuários e categorias.
 *
 * Documentação da API GLPI: https://glpi-project.org/DOC/en/api/
 */
class PluginWhatsappbotGlpiApi {

    private string $baseUrl;
    private string $appToken;
    private string $userToken;
    private string $sessionToken = '';
    private string $logFile;

    public function __construct(array $config) {
        $this->baseUrl   = rtrim($config['glpi_api_url']   ?? '', '/');
        $this->appToken  = $config['glpi_app_token']  ?? '';
        $this->userToken = $config['glpi_user_token'] ?? '';
        $this->logFile   = GLPI_LOG_DIR . '/whatsappbot.log';
    }

    // ---------------------------------------------------------------
    // Sessão na API GLPI
    // ---------------------------------------------------------------

    private function initSession(): bool {
        if ($this->sessionToken) return true;

        // Não repete "App-Token" aqui — request() já inclui esse header em
        // todas as chamadas (defaultHeaders). Repeti-lo faz o Apache/PHP
        // combinar os dois valores num único header, o que o GLPI rejeita
        // como app_token inválido mesmo com o token correto configurado.
        $resp = $this->request('GET', '/initSession', [], [
            'Authorization: user_token ' . $this->userToken,
        ]);

        if (isset($resp['session_token'])) {
            $this->sessionToken = $resp['session_token'];
            return true;
        }
        $this->log('initSession falhou: ' . json_encode($resp));
        return false;
    }

    private function killSession(): void {
        if (!$this->sessionToken) return;
        $this->request('GET', '/killSession');
        $this->sessionToken = '';
    }

    // ---------------------------------------------------------------
    // Chamados (Tickets)
    // ---------------------------------------------------------------

    /**
     * Cria um novo chamado
     */
    public function createTicket(array $data): ?array {
        if (!$this->initSession()) return null;

        $payload = [
            'input' => [
                'name'              => $data['name']    ?? 'Chamado via WhatsApp',
                'content'           => $data['content'] ?? '',
                'status'            => 1, // Novo
                'type'              => 1, // Incidente
                'urgency'           => 3, // Médio
                'impact'            => 3,
                'priority'          => 3,
                'itilcategories_id' => $data['itilcategories_id'] ?? 0,
                'requesttypes_id'   => 1, // Helpdesk
            ]
        ];

        // Associa usuário solicitante se existir
        if (!empty($data['users_id'])) {
            $payload['input']['_users_id_requester'] = $data['users_id'];
        }

        // Associa grupo se configurado
        if (!empty($data['groups_id_assign'])) {
            $payload['input']['_groups_id_assign'] = $data['groups_id_assign'];
        }

        $result = $this->request('POST', '/Ticket', $payload);
        $this->killSession();
        return $result;
    }

    /**
     * Busca chamados do usuário (últimos N)
     */
    public function getUserTickets(int $userId, int $limit = 5): array {
        if (!$this->initSession()) return [];

        $resp = $this->request('GET', '/Ticket', [], [], [
            'searchText[_users_id_requester]' => $userId,
            'sort'                            => 'date_mod',
            'order'                           => 'DESC',
            'range'                           => "0-" . ($limit - 1),
            'forcedisplay[0]'                 => 'id',
            'forcedisplay[1]'                 => 'name',
            'forcedisplay[2]'                 => 'status',
            'forcedisplay[3]'                 => 'date_mod',
            'forcedisplay[4]'                 => 'date',
            'forcedisplay[5]'                 => '_users_id_assign',
        ]);

        $this->killSession();

        if (isset($resp['data'])) return $resp['data'];
        if (is_array($resp) && isset($resp[0])) return $resp;
        return [];
    }

    /**
     * Busca um chamado específico com todos os detalhes
     */
    public function getTicket(int $ticketId): ?array {
        if (!$this->initSession()) return null;

        $ticket = $this->request('GET', "/Ticket/$ticketId");
        if (!$ticket || isset($ticket['ERROR'])) {
            $this->killSession();
            return null;
        }

        // Busca acompanhamentos
        $followups = $this->request('GET', "/Ticket/$ticketId/ITILFollowup", [], [], [
            'sort'  => 'date',
            'order' => 'DESC',
            'range' => '0-4',
        ]);

        $ticket['followups'] = $followups ?: [];
        $this->killSession();
        return $ticket;
    }

    /**
     * Adiciona acompanhamento a um chamado
     */
    public function addFollowup(int $ticketId, string $content, bool $isPrivate = false): bool {
        if (!$this->initSession()) return false;

        $result = $this->request('POST', "/Ticket/$ticketId/ITILFollowup", [
            'input' => [
                'items_id'    => $ticketId,
                'itemtype'    => 'Ticket',
                'content'     => $content,
                'is_private'  => $isPrivate ? 1 : 0,
                'requesttypes_id' => 1,
            ]
        ]);

        $this->killSession();
        return isset($result['id']);
    }

    // ---------------------------------------------------------------
    // Categorias
    // ---------------------------------------------------------------

    /**
     * Retorna categorias de chamado disponíveis
     */
    public function getCategories(): array {
        if (!$this->initSession()) return [];

        $resp = $this->request('GET', '/ITILCategory', [], [], [
            'range'           => '0-19',
            'forcedisplay[0]' => 'id',
            'forcedisplay[1]' => 'name',
            'forcedisplay[2]' => 'completename',
            'is_helpdeskvisible' => 1,
        ]);

        $this->killSession();

        if (isset($resp['data'])) return $resp['data'];
        if (is_array($resp) && isset($resp[0]['id'])) return $resp;
        return [];
    }

    // ---------------------------------------------------------------
    // Usuários
    // ---------------------------------------------------------------

    /**
     * Busca usuário pelo número de telefone cadastrado no GLPI
     */
    public function findUserByPhone(string $phone): int {
        if (!$this->initSession()) return 0;

        // Remove DDI se necessário para busca
        $phoneVariants = [
            $phone,
            preg_replace('/^55/', '', $phone),   // remove DDI 55
            preg_replace('/^55(\d{2})9/', '55$1', $phone), // remove 9 dígito
        ];

        foreach ($phoneVariants as $variant) {
            if (empty($variant)) continue;

            $resp = $this->request('GET', '/User', [], [], [
                'searchText[mobile]' => $variant,
                'range'              => '0-1',
                'forcedisplay[0]'    => 'id',
                'forcedisplay[1]'    => 'name',
                'forcedisplay[2]'    => 'mobile',
            ]);

            $users = $resp['data'] ?? (is_array($resp) ? $resp : []);
            if (!empty($users)) {
                $this->killSession();
                return (int)($users[0]['id'] ?? 0);
            }
        }

        $this->killSession();
        return 0;
    }

    // ---------------------------------------------------------------
    // Técnicos — busca para notificação de novo chamado
    // ---------------------------------------------------------------

    /**
     * Retorna lista de técnicos de um grupo com número de celular cadastrado.
     * Se groupId = 0, busca todos os usuários com perfil de técnico/helpdesk.
     *
     * Retorna: [ ['name' => '...', 'mobile' => '55119...'], ... ]
     */
    public function getTechniciansByGroup(int $groupId = 0): array {
        if (!$this->initSession()) return [];

        $technicians = [];

        if ($groupId > 0) {
            // Busca membros do grupo no GLPI
            $resp = $this->request('GET', "/Group_User", [], [], [
                'searchText[groups_id]' => $groupId,
                'range'                 => '0-99',
                'forcedisplay[0]'       => 'users_id',
            ]);

            $members = $resp['data'] ?? (is_array($resp) ? $resp : []);

            foreach ($members as $member) {
                $uid = (int)($member['users_id'] ?? 0);
                if (!$uid) continue;

                $user = $this->request('GET', "/User/$uid", [], [], [
                    'forcedisplay[0]' => 'id',
                    'forcedisplay[1]' => 'name',
                    'forcedisplay[2]' => 'realname',
                    'forcedisplay[3]' => 'firstname',
                    'forcedisplay[4]' => 'mobile',
                ]);

                if (!empty($user['mobile'])) {
                    $technicians[] = [
                        'id'     => $uid,
                        'name'   => trim(($user['firstname'] ?? '') . ' ' . ($user['realname'] ?? $user['name'] ?? '')),
                        'mobile' => $this->normalizePhone($user['mobile']),
                    ];
                }
            }
        } else {
            // Busca todos os usuários que têm celular cadastrado e perfil técnico
            // (Perfil 6 = Technician no GLPI padrão — ajuste conforme seu ambiente)
            $resp = $this->request('GET', '/User', [], [], [
                'range'              => '0-49',
                'forcedisplay[0]'    => 'id',
                'forcedisplay[1]'    => 'name',
                'forcedisplay[2]'    => 'realname',
                'forcedisplay[3]'    => 'firstname',
                'forcedisplay[4]'    => 'mobile',
                'forcedisplay[5]'    => 'profiles_id',
                'searchText[mobile]' => '%',   // qualquer celular preenchido
                'is_active'          => 1,
            ]);

            $users = $resp['data'] ?? (is_array($resp) ? $resp : []);

            foreach ($users as $u) {
                if (empty($u['mobile'])) continue;
                $technicians[] = [
                    'id'     => (int)($u['id'] ?? 0),
                    'name'   => trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? $u['name'] ?? '')),
                    'mobile' => $this->normalizePhone($u['mobile']),
                ];
            }
        }

        $this->killSession();

        // Remove entradas sem número válido
        return array_values(array_filter($technicians, fn($t) => !empty($t['mobile'])));
    }

    /**
     * Normaliza número de telefone para formato E.164 sem +
     * Ex: (11) 99999-0000 → 5511999990000
     */
    private function normalizePhone(string $phone): string {
        $digits = preg_replace('/\D/', '', $phone);
        // Se não começa com 55 (Brasil) e tem 10-11 dígitos, adiciona DDI
        if (strlen($digits) <= 11 && !str_starts_with($digits, '55')) {
            $digits = '55' . $digits;
        }
        return $digits;
    }

    // ---------------------------------------------------------------
    // Requisição HTTP genérica
    // ---------------------------------------------------------------

    private function request(
        string $method,
        string $path,
        array  $body    = [],
        array  $headers = [],
        array  $params  = []
    ): mixed {
        $url = $this->baseUrl . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $defaultHeaders = [
            'Content-Type: application/json',
            'App-Token: ' . $this->appToken,
        ];
        if ($this->sessionToken) {
            $defaultHeaders[] = 'Session-Token: ' . $this->sessionToken;
        }
        $allHeaders = array_merge($defaultHeaders, $headers);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $allHeaders,
        ]);

        if (!empty($body)) {
            $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);
            if ($path === '/Ticket') {
                // DIAGNÓSTICO TEMPORÁRIO — bug de acentuação em chamados criados via API
                $this->log('DEBUG /Ticket payload enviado: ' . $jsonBody);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $this->log("GLPI API $method $path CURL ERROR: $err");
            return null;
        }

        $decoded = json_decode($resp, true);
        if ($code >= 400) {
            $this->log("GLPI API $method $path HTTP $code: " . substr($resp, 0, 200));
        }

        return $decoded;
    }

    private function log(string $msg): void {
        $line = date('Y-m-d H:i:s') . ' API ' . $msg . PHP_EOL;
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
