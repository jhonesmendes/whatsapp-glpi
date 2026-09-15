<?php
/**
 * PluginWhatsappbotBot
 * Núcleo do bot: processa mensagens recebidas, gerencia estados de conversa
 * e roteia para as ações corretas (criar chamado, consultar, transferir).
 */
class PluginWhatsappbotBot {

    private array  $config;
    private PluginWhatsappbotWhatsapp $wa;
    private PluginWhatsappbotGlpiApi  $glpiApi;
    private PluginWhatsappbotAi       $ai;

    // Estados da conversa
    const STATE_MENU                 = 'menu';
    const STATE_OPEN_TICKET_DESC     = 'open_ticket_desc';
    const STATE_OPEN_TICKET_LOCATION = 'open_ticket_location';
    const STATE_CONSULT_TICKET       = 'consult_ticket';
    const STATE_HUMAN                = 'human';
    const STATE_RATING               = 'rating';

    public function __construct() {
        $this->config  = PluginWhatsappbotConfig::getConfig();
        $this->wa      = new PluginWhatsappbotWhatsapp($this->config);
        $this->glpiApi = new PluginWhatsappbotGlpiApi($this->config);
        $this->ai      = new PluginWhatsappbotAi($this->config);
    }

    // ---------------------------------------------------------------
    // Ponto de entrada: mensagem recebida do Baileys
    // ---------------------------------------------------------------

    public function processIncoming(array $data): void {
        $from     = $this->normalizeNumber($data['from']   ?? '');
        $body     = trim($data['body'] ?? '');
        $waName   = $data['pushName'] ?? '';
        $msgId    = $data['msgId'] ?? null;
        // Número de telefone real, quando o Baileys conseguir resolvê-lo
        // (contatos com privacidade "@lid" ativada só expõem um ID
        // pseudônimo em $from — nem sempre dá pra saber o número real).
        $fromReal = $data['fromReal'] ?? null;

        if (empty($from) || empty($body)) return;
        if (!($this->config['is_active'] ?? false)) return;

        // Carrega ou cria sessão
        $session = $this->getOrCreateSession($from, $waName);

        // Evita processar a mesma mensagem duas vezes. O servidor Baileys
        // reenvia a mensagem ao GLPI se a chamada anterior parecer ter
        // falhado (timeout de rede) — mas às vezes a primeira tentativa
        // já tinha funcionado, só demorou a responder. Sem essa checagem,
        // a segunda tentativa processa a mesma descrição de novo como se
        // fosse uma resposta nova, criando um chamado duplicado ou caindo
        // no menu por engano.
        if ($msgId && $msgId === ($session['last_msg_id'] ?? '')) {
            return;
        }
        if ($msgId) {
            $this->updateSession($from, ['last_msg_id' => $msgId]);
        }

        // Salva mensagem no histórico
        $this->saveMessage($from, 'in', $body);

        // Contato novo: getOrCreateSession() já mandou o menu de
        // boas-vindas como resposta a esta primeira mensagem. Não
        // continuar processando o mesmo texto como se fosse uma escolha
        // de menu — isso mandava "Opção não reconhecida" + o menu de novo
        // em seguida, sempre que a primeira mensagem não fosse "1"/"2"/"3".
        if (!empty($session['_is_new_session'])) {
            return;
        }

        // Se está em atendimento humano, não processa
        if ($session['is_human']) {
            // Apenas registra — humano responde manualmente via painel
            return;
        }

        // Detecta "cancelar" / "menu" / "0" em qualquer estado
        if (in_array(strtolower($body), ['cancelar', 'menu', '0', 'voltar'])) {
            $this->sendMenu($from, $waName);
            $this->updateSession($from, ['state' => self::STATE_MENU, 'context' => null]);
            return;
        }

        // Roteia pelo estado atual
        switch ($session['state']) {
            case self::STATE_MENU:
                $this->handleMenu($from, $body, $session);
                break;

            case self::STATE_OPEN_TICKET_DESC:
                $this->handleOpenTicketDesc($from, $body, $session, $fromReal);
                break;

            case self::STATE_OPEN_TICKET_LOCATION:
                $this->handleOpenTicketLocation($from, $body, $session);
                break;

            case self::STATE_CONSULT_TICKET:
                $this->handleConsultTicket($from, $body, $session);
                break;

            case self::STATE_RATING:
                $this->handleRating($from, $body, $session);
                break;

            default:
                $this->sendMenu($from, $waName);
                $this->updateSession($from, ['state' => self::STATE_MENU]);
        }
    }

    // ---------------------------------------------------------------
    // Handlers de estado
    // ---------------------------------------------------------------

    /**
     * Menu principal
     */
    private function handleMenu(string $from, string $body, array $session): void {
        switch (trim($body)) {
            case '1':
                $askDesc = $this->config['ask_description_message']
                    ?: "📋 *Abrir chamado*\n\nDescreva o problema que está tendo.\n\nDigite uma descrição clara (mínimo 10 caracteres):\n\n_Digite *0* para voltar ao menu_";
                $this->wa->send($from, $askDesc);
                $this->updateSession($from, ['state' => self::STATE_OPEN_TICKET_DESC]);
                break;

            case '2':
                $this->handleListTickets($from, $session);
                break;

            case '3':
                $this->transferToHuman($from, $session);
                break;

            default:
                // Mensagem não reconhecida → reexibe menu
                $this->wa->send($from, "Opção não reconhecida. Por favor, escolha uma das opções abaixo:");
                $this->sendMenu($from, $session['wa_name'] ?? '');
        }
    }

    /**
     * Aguarda descrição do problema (passo 1 de abrir chamado)
     */
    private function handleOpenTicketDesc(string $from, string $body, array $session, ?string $fromReal = null): void {
        if (strlen($body) < 10) {
            $this->wa->send($from, "⚠️ A descrição é muito curta. Por favor, descreva melhor o problema (mínimo 10 caracteres).");
            return;
        }

        // JSON_UNESCAPED_UNICODE: sem isso, acentos são gravados como
        // sequências "\uXXXX" no banco. O GLPI parece remover barras
        // invertidas de strings ao buscar do banco (compatibilidade antiga
        // com magic quotes), o que corrompe exatamente esse escape (ex:
        // "ã" vira "u00e3" — "não" aparece como "nu00e3o"). Gravando
        // o acento como caractere UTF-8 literal (sem barra invertida),
        // não tem o que corromper.
        $context = ['description' => $body, 'fromReal' => $fromReal];

        // Busca localizações cadastradas no GLPI para o usuário escolher
        // numa lista (em vez de digitar texto livre) — assim o chamado usa
        // o campo nativo "Localização" do GLPI, não um texto solto.
        $locations = $this->glpiApi->getLocations();

        if (!empty($locations)) {
            $header = $this->config['ask_location_message'] ?: "📍 Informe sua filial/localização:";
            $msg = "{$header}\n\n";
            foreach ($locations as $i => $loc) {
                $num = $i + 1;
                $msg .= "*{$num}* — {$loc['name']}\n";
            }
            $context['locations'] = $locations;

            $this->wa->send($from, $msg);
            $this->updateSession($from, [
                'state'   => self::STATE_OPEN_TICKET_LOCATION,
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE)
            ]);
        } else {
            // Sem localizações cadastradas no GLPI — cria o chamado sem
            // vincular ao campo de localização.
            $categories = $this->glpiApi->getCategories();
            $catId      = $this->ai->detectCategory($body, $categories);
            $this->createTicket($from, $body, 0, $catId, $session, $fromReal);
        }
    }

    /**
     * Aguarda escolha da localização/filial (passo 2 de abrir chamado) —
     * obrigatório. Depois disso, a categoria é escolhida automaticamente
     * pela IA com base na descrição, e o chamado é criado.
     */
    private function handleOpenTicketLocation(string $from, string $body, array $session): void {
        $context     = json_decode($session['context'] ?? '{}', true);
        $description = $context['description'] ?? 'Sem descrição';
        $fromReal    = $context['fromReal'] ?? null;
        $locations   = $context['locations'] ?? [];

        $idx = (int)trim($body) - 1;
        if (!is_numeric(trim($body)) || !isset($locations[$idx])) {
            $this->wa->send($from, "⚠️ Por favor, responda apenas com o número da localização na lista acima.");
            return;
        }
        $locationsId = (int)$locations[$idx]['id'];

        // Categoriza automaticamente com base na descrição, usando a IA
        $categories = $this->glpiApi->getCategories();
        $catId      = $this->ai->detectCategory($description, $categories);

        $this->createTicket($from, $description, $locationsId, $catId, $session, $fromReal);
    }

    /**
     * Cria o chamado no GLPI e confirma
     */
    private function createTicket(string $from, string $description, int $locationsId, int $catId, array $session, ?string $fromReal = null): void {
        $userId = (int)($session['users_id'] ?? 0);
        $name   = trim($session['wa_name'] ?? '') ?: 'Contato WhatsApp';

        // Mostra o número real quando o Baileys conseguiu resolvê-lo (contatos
        // com privacidade "@lid" só expõem um ID pseudônimo, não o número).
        $displayNumber = $fromReal ?: preg_replace('/@.*/', '', $from);

        $fullContent = "{$description}\n\n" .
            "👤 Solicitante: {$name}\n\n" .
            "[Chamado aberto via WhatsApp: {$displayNumber}]";

        $ticketData = [
            'name'              => $this->summarizeTitle($description),
            'content'           => $fullContent,
            'users_id'          => $userId,
            'itilcategories_id' => $catId ?: ($this->config['default_category_id'] ?? 0),
            'groups_id_assign'  => $this->config['default_group_id'] ?? 0,
        ];
        if ($locationsId > 0) {
            $ticketData['locations_id'] = $locationsId;
        }

        $result = $this->glpiApi->createTicket($ticketData);

        if ($result && isset($result['id'])) {
            $ticketId = $result['id'];
            $this->updateSession($from, [
                'state'          => self::STATE_MENU,
                'context'        => null,
                'last_ticket_id' => $ticketId
            ]);
            $this->saveMessage($from, 'out', "Chamado #{$ticketId} criado");

            // 1. Confirma para o usuário que abriu o chamado
            $this->wa->send($from,
                "✅ *Chamado aberto com sucesso!*\n\n" .
                "🔢 Número: *#{$ticketId}*\n" .
                "📝 Assunto: {$result['name']}\n" .
                "⏳ Status: Aguardando atendimento\n\n" .
                "Você receberá atualizações aqui quando houver novidades.\n\n" .
                "_Responda *menu* a qualquer momento para voltar ao início_"
            );

            // 2. Notifica técnicos do grupo configurado
            $this->notifyTechnicians($ticketId, $result['name'] ?? 'Sem título', $description, $session);

        } else {
            $this->wa->send($from,
                "❌ Não foi possível abrir o chamado no momento. Tente novamente ou escolha a opção *3* para falar com um técnico."
            );
            $this->updateSession($from, ['state' => self::STATE_MENU, 'context' => null]);
        }
    }

    /**
     * Lista chamados do usuário
     */
    private function handleListTickets(string $from, array $session): void {
        $userId = (int)($session['users_id'] ?? 0);

        if ($userId === 0) {
            // Sem conta GLPI vinculada a este número — não dá pra listar
            // "os chamados do usuário", mas se ele abriu algum chamado por
            // esta própria conversa, mostra o andamento dele mesmo assim.
            $lastTicketId = (int)($session['last_ticket_id'] ?? 0);
            if ($lastTicketId > 0) {
                $this->handleConsultTicket($from, (string)$lastTicketId, $session);
                return;
            }
            $this->wa->send($from,
                "⚠️ Não encontrei sua conta no sistema.\n\nPor favor, escolha outra opção ou fale com um técnico (*opção 3*)."
            );
            return;
        }

        $tickets = $this->glpiApi->getUserTickets($userId, 5);

        if (empty($tickets)) {
            $this->wa->send($from, "📭 Você não possui chamados abertos no momento.\n\nDigite *1* para abrir um novo chamado.");
            return;
        }

        // Formata com IA
        $rawData = json_encode($tickets, JSON_UNESCAPED_UNICODE);
        $prompt  = "Formate esta lista de chamados de suporte de forma clara e amigável para WhatsApp. Use emojis de status. Dados: $rawData";
        $formatted = $this->ai->complete($prompt);

        if (!$formatted) {
            // Fallback sem IA
            $msg = "📋 *Seus chamados:*\n\n";
            foreach ($tickets as $t) {
                $statusLabel = $this->getStatusLabel($t['status']);
                $msg .= "• *#{$t['id']}* — {$t['name']}\n  {$statusLabel}\n\n";
            }
            $formatted = $msg;
        }

        $formatted .= "\n\n_Digite *menu* para voltar_";
        $this->wa->send($from, $formatted);
        $this->updateSession($from, ['state' => self::STATE_MENU]);
    }

    /**
     * Consulta chamado específico (estado intermediário se necessário)
     */
    private function handleConsultTicket(string $from, string $body, array $session): void {
        if (!is_numeric($body)) {
            $this->wa->send($from, "Por favor, informe apenas o número do chamado.");
            return;
        }

        $ticket = $this->glpiApi->getTicket((int)$body);
        if (!$ticket) {
            $this->wa->send($from, "❌ Chamado #$body não encontrado ou você não tem permissão para consultá-lo.");
            $this->updateSession($from, ['state' => self::STATE_MENU]);
            return;
        }

        $prompt = "Formate os detalhes deste chamado de forma clara para WhatsApp, incluindo status, técnico responsável e últimas atualizações: " . json_encode($ticket, JSON_UNESCAPED_UNICODE);
        $formatted = $this->ai->complete($prompt) ?? "Chamado #{$ticket['id']}: {$ticket['name']} — Status: {$this->getStatusLabel($ticket['status'])}";

        $this->wa->send($from, $formatted . "\n\n_Digite *menu* para voltar_");
        $this->updateSession($from, ['state' => self::STATE_MENU]);
    }

    /**
     * Avaliação pós-atendimento
     */
    private function handleRating(string $from, string $body, array $session): void {
        if (!is_numeric($body) || (int)$body < 1 || (int)$body > 5) {
            $this->wa->send($from, "Por favor, responda com um número de 1 a 5.");
            return;
        }

        $nota = (int)$body;
        $this->wa->send($from, $nota >= 4
            ? "🙏 Obrigado pela avaliação! Ficamos felizes em ajudar. Qualquer dúvida, estamos aqui."
            : "😔 Obrigado pelo feedback. Vou transferir para um técnico para entender melhor."
        );

        if ($nota < 3) {
            $this->transferToHuman($from, $session);
        } else {
            $this->updateSession($from, ['state' => self::STATE_MENU]);
        }
    }

    /**
     * Transfere para atendimento humano
     */
    private function transferToHuman(string $from, array $session): void {
        $this->wa->send($from,
            "👨‍💻 Transferindo para atendimento humano...\n\nUm técnico irá assumir essa conversa em breve. Por favor, aguarde.\n\n_Tempo médio de espera: alguns minutos_"
        );
        $this->updateSession($from, [
            'state'    => self::STATE_HUMAN,
            'is_human' => 1
        ]);

        // Notifica no GLPI via comentário interno (se tiver ticket ativo)
        if (!empty($session['last_ticket_id'])) {
            $this->glpiApi->addFollowup(
                (int)$session['last_ticket_id'],
                "⚠️ Usuário WhatsApp ({$from}) solicitou atendimento humano.",
                true // is_private
            );
        }
    }

    // ---------------------------------------------------------------
    // Notificações proativas (chamadas pelos hooks do GLPI)
    // ---------------------------------------------------------------

    /**
     * Notifica usuário quando chamado é resolvido
     */
    public function notifyTicketResolved(Ticket $ticket): void {
        $ticketId = $ticket->getID();
        $session  = $this->getSessionByTicketId($ticketId);
        if (!$session) return;

        $from = $session['wa_number'];
        $action = $this->config['post_resolve_action'] ?? 'ask_rating';

        $this->wa->send($from,
            "✅ *Chamado #{$ticketId} resolvido!*\n\n" .
            "Assunto: {$ticket->fields['name']}\n\n" .
            "O seu chamado foi marcado como resolvido."
        );

        if ($action === 'ask_rating') {
            sleep(1);
            $this->wa->send($from,
                "⭐ Como você avalia o atendimento?\n\nResponda com um número de *1* a *5*:\n\n1 — Péssimo\n2 — Ruim\n3 — Regular\n4 — Bom\n5 — Excelente"
            );
            $this->updateSession($from, ['state' => self::STATE_RATING, 'is_human' => 0]);
        } else {
            $this->updateSession($from, ['state' => self::STATE_MENU, 'is_human' => 0]);
        }
    }

    /**
     * Notifica usuário quando técnico adiciona acompanhamento público
     */
    public function notifyFollowup(ITILFollowup $followup): void {
        if ($followup->fields['is_private']) return; // só mensagens públicas

        $ticketId = (int)$followup->fields['items_id'];
        $session  = $this->getSessionByTicketId($ticketId);
        if (!$session) return;

        $from    = $session['wa_number'];
        $content = strip_tags($followup->fields['content'] ?? '');
        if (empty($content)) return;

        $this->wa->send($from,
            "💬 *Atualização no chamado #{$ticketId}:*\n\n{$content}\n\n_Responda *menu* para ver suas opções_"
        );
    }

    // ---------------------------------------------------------------
    // Notificação de técnicos — novo chamado aberto via WhatsApp
    // ---------------------------------------------------------------

    /**
     * Dispara mensagem WhatsApp para todos os técnicos do grupo configurado
     * informando que um novo chamado foi registrado.
     *
     * Executa de forma assíncrona (fork) para não bloquear a resposta ao usuário.
     */
    private function notifyTechnicians(
        int    $ticketId,
        string $ticketName,
        string $description,
        array  $session
    ): void {
        $groupId    = (int)($this->config['default_group_id'] ?? 0);
        $glpiUrl    = rtrim($this->config['glpi_api_url'] ?? '', '/');

        // URL direta do chamado no GLPI para o técnico acessar
        $glpiBase   = preg_replace('/\/apirest\.php.*$/', '', $glpiUrl);
        $ticketUrl  = "{$glpiBase}/front/ticket.form.php?id={$ticketId}";

        // Nome do solicitante (nome WA ou usuário GLPI)
        $requesterName = trim($session['wa_name'] ?? '') ?: "WhatsApp {$session['wa_number']}";

        // Trunca descrição para a mensagem
        $descPreview = mb_strlen($description) > 200
            ? mb_substr($description, 0, 200) . '...'
            : $description;

        $msg = "🔔 *Novo chamado registrado via WhatsApp*\n\n" .
               "🔢 Chamado: *#{$ticketId}*\n" .
               "📋 Assunto: {$ticketName}\n" .
               "👤 Solicitante: {$requesterName}\n" .
               "📝 Descrição:\n_{$descPreview}_\n\n" .
               "🔗 Acesse: {$ticketUrl}";

        // Busca técnicos do grupo com celular cadastrado no GLPI
        $technicians = $this->glpiApi->getTechniciansByGroup($groupId);

        // Adiciona números fixos configurados manualmente (ex: supervisores de plantão)
        $fixedNumbers = $this->config['tech_notify_numbers'] ?? '';
        foreach (array_filter(array_map('trim', explode("\n", $fixedNumbers))) as $num) {
            $num = preg_replace('/\D/', '', $num);
            if (strlen($num) >= 10) {
                // Adiciona DDI 55 se não tiver
                if (strlen($num) <= 11 && !str_starts_with($num, '55')) {
                    $num = '55' . $num;
                }
                // Evita duplicatas com os do grupo
                $exists = array_filter($technicians, fn($t) => $t['mobile'] === $num);
                if (empty($exists)) {
                    $technicians[] = ['id' => 0, 'name' => "Número fixo", 'mobile' => $num];
                }
            }
        }

        if (empty($technicians)) {
            $this->log("notifyTechnicians: nenhum técnico encontrado para notificar (groupId={$groupId})");
            return;
        }

        $sent  = 0;
        $fails = 0;
        foreach ($technicians as $tech) {
            $techNumber = $tech['mobile'] ?? '';
            if (empty($techNumber)) continue;

            // Não notifica o próprio solicitante se ele for técnico
            if (isset($session['users_id']) && (int)$session['users_id'] === (int)$tech['id']) continue;

            $ok = $this->wa->send($techNumber, $msg);
            if ($ok) {
                $sent++;
                $this->log("Técnico notificado: {$tech['name']} ({$techNumber}) — Chamado #{$ticketId}");
            } else {
                $fails++;
                $this->log("Falha ao notificar técnico: {$tech['name']} ({$techNumber})");
            }

            // Pequena pausa entre envios para não sobrecarregar o Baileys
            if ($sent + $fails < count($technicians)) {
                usleep(600000); // 0.6s entre cada mensagem
            }
        }

        $this->log("notifyTechnicians: chamado #{$ticketId} — {$sent} notificados, {$fails} falhas");
    }

    /**
     * Log interno rápido
     */
    private function log(string $msg): void {
        $line = date('Y-m-d H:i:s') . ' BOT ' . $msg . PHP_EOL;
        file_put_contents(GLPI_LOG_DIR . '/whatsappbot.log', $line, FILE_APPEND | LOCK_EX);
    }

    // ---------------------------------------------------------------
    // Helpers internos
    // ---------------------------------------------------------------

    private function sendMenu(string $from, string $name): void {
        $firstName = explode(' ', $name)[0] ?? 'usuário';
        $welcome   = $this->config['welcome_message'] ?? "Olá! Como posso ajudar?\n\n*1* — Abrir chamado\n*2* — Consultar andamento\n*3* — Falar com humano";
        $this->wa->send($from, $welcome);
        $this->saveMessage($from, 'out', $welcome);
    }

    private function summarizeTitle(string $description): string {
        $title = mb_substr(trim($description), 0, 80);
        if (mb_strlen($description) > 80) $title .= '...';
        return $title;
    }

    private function getStatusLabel(int $status): string {
        $labels = [
            1 => '🆕 Novo',
            2 => '⏳ Em atendimento',
            3 => '⏳ Em atendimento',
            4 => '⏸️ Pendente',
            5 => '✅ Resolvido',
            6 => '🔒 Fechado',
        ];
        return $labels[$status] ?? "Status $status";
    }

    /**
     * Normaliza o JID recebido, mantendo o domínio original (ex: "@s.whatsapp.net",
     * "@lid" — identificador pseudônimo de privacidade do WhatsApp).
     *
     * Antes isso removia TUDO que não fosse dígito, descartando o domínio —
     * então ao responder, sempre se assumia "@s.whatsapp.net". Para contatos
     * com privacidade "@lid" ativada, isso manda a resposta para um número
     * que não existe (o ID do @lid não é o número de telefone real da
     * pessoa), e o bot fica "mudo" mesmo processando a mensagem certinho.
     */
    private function normalizeNumber(string $number): string {
        [$id, $domain] = array_pad(explode('@', trim($number), 2), 2, null);
        $id = preg_replace('/[^0-9]/', '', $id ?? '');
        return $domain ? "$id@$domain" : $id;
    }

    // ---------------------------------------------------------------
    // Sessões no banco
    // ---------------------------------------------------------------

    private function getOrCreateSession(string $from, string $name): array {
        global $DB;

        $row = $DB->request([
            'FROM'  => 'glpi_plugin_whatsappbot_sessions',
            'WHERE' => ['wa_number' => $from],
            'LIMIT' => 1
        ])->current();

        if ($row) {
            // Atualiza nome se tiver
            if ($name && $row['wa_name'] !== $name) {
                $DB->update('glpi_plugin_whatsappbot_sessions', ['wa_name' => $name], ['wa_number' => $from]);
                $row['wa_name'] = $name;
            }
            return $row;
        }

        // Tenta encontrar usuário pelo número no GLPI
        $userId = $this->glpiApi->findUserByPhone($from);

        $DB->insert('glpi_plugin_whatsappbot_sessions', [
            'wa_number'     => $from,
            'wa_name'       => $name,
            'users_id'      => $userId,
            'state'         => self::STATE_MENU,
            'is_human'      => 0,
            'date_start'    => date('Y-m-d H:i:s'),
            'date_last_msg' => date('Y-m-d H:i:s'),
        ]);

        // Envia menu de boas-vindas na primeira mensagem
        $this->sendMenu($from, $name);

        $session = $DB->request([
            'FROM'  => 'glpi_plugin_whatsappbot_sessions',
            'WHERE' => ['wa_number' => $from],
            'LIMIT' => 1
        ])->current();

        // Sinaliza pro chamador (processIncoming) que o menu já foi enviado
        // agora mesmo — não é um valor de fato salvo no banco, só uma
        // marcação em memória para esta chamada.
        $session['_is_new_session'] = true;
        return $session;
    }

    private function updateSession(string $from, array $fields): void {
        global $DB;
        $fields['date_last_msg'] = date('Y-m-d H:i:s');
        $DB->update('glpi_plugin_whatsappbot_sessions', $fields, ['wa_number' => $from]);
    }

    private function getSessionByTicketId(int $ticketId): ?array {
        global $DB;
        $row = $DB->request([
            'FROM'  => 'glpi_plugin_whatsappbot_sessions',
            'WHERE' => ['last_ticket_id' => $ticketId],
            'LIMIT' => 1
        ])->current();
        return $row ?: null;
    }

    private function saveMessage(string $from, string $direction, string $body): void {
        global $DB;
        $DB->insert('glpi_plugin_whatsappbot_messages', [
            'wa_number'  => $from,
            'direction'  => $direction,
            'message'    => mb_substr($body, 0, 4000),
            'date_sent'  => date('Y-m-d H:i:s'),
        ]);
    }
}
