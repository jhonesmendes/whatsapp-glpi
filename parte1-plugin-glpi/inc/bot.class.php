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
    const STATE_MENU_BLOCKED         = 'menu_blocked';
    const STATE_OPEN_TICKET_DESC     = 'open_ticket_desc';
    const STATE_OPEN_TICKET_ATTACH   = 'open_ticket_attach';
    const STATE_OPEN_TICKET_NAME     = 'open_ticket_name';
    const STATE_OPEN_TICKET_EMAIL    = 'open_ticket_email';
    const STATE_OPEN_TICKET_LOCATION = 'open_ticket_location';
    const STATE_CONSULT_TICKET       = 'consult_ticket';
    const STATE_HUMAN                = 'human';
    const STATE_RATING               = 'rating';
    const STATE_AGENT                = 'agent';

    // Limite de rodadas IA↔ferramenta por mensagem recebida, no modo
    // agente — evita loop infinito/custo descontrolado se a IA insistir
    // em chamar ferramentas sem nunca dar uma resposta final ao usuário.
    const AGENT_MAX_TOOL_ROUNDS = 4;
    // Quantas mensagens (user+assistant) do histórico da conversa ficam
    // guardadas — mais que isso e a coluna "context" (limite de 64KB)
    // e o custo de tokens por mensagem cresceriam sem necessidade.
    const AGENT_HISTORY_LIMIT = 20;

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
        // Imagem/documento enviado junto (ver STATE_OPEN_TICKET_DESC) —
        // formato: ['base64' => ..., 'mimetype' => ..., 'filename' => ...],
        // ou ['tooLarge' => true] quando o Baileys descartou por passar do
        // limite configurado.
        $media    = $data['media'] ?? null;

        // Uma imagem/documento sem legenda chega com body vazio — só
        // descarta a mensagem se não tiver nem texto nem anexo.
        if (empty($from) || (empty($body) && empty($media))) return;
        if (!($this->config['is_active'] ?? false)) return;

        // Devolve ao menu qualquer conversa parada há mais tempo que o
        // "Timeout sem resposta" configurado — inclui quem ficou esperando
        // atendimento humano sem ninguém assumir. Roda antes de carregar a
        // sessão do remetente atual: se a dele mesma estiver estourada,
        // essa mensagem já processa a partir do menu, do zero.
        $this->releaseTimedOutSessions();

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

        // Detecta "cancelar" / "menu" / "0" / "voltar" em qualquer estado —
        // inclusive durante atendimento humano. Sem isso, quem foi
        // transferido pra um técnico ficava travado pra sempre esperando,
        // sem conseguir voltar ao atendimento automático sozinho caso
        // nenhum técnico assumisse a conversa pelo painel.
        if (in_array(strtolower($body), ['cancelar', 'menu', '0', 'voltar'])) {
            if (!empty($session['is_human']) && !empty($session['last_ticket_id'])) {
                $this->setTicketChannel((int)$session['last_ticket_id'], $from, 0);
            }
            if (!empty($this->config['agent_mode'])) {
                // Modo agente: "menu" funciona como um reset de conversa
                // (limpa o histórico) — continua valendo mesmo aqui, como
                // uma saída de emergência confiável caso a IA trave ou
                // entenda algo errado, mas sem voltar pro menu numerado
                // rígido do fluxo antigo.
                $this->wa->send($from, "🔄 Conversa reiniciada. Pode me dizer como posso ajudar.");
                $this->updateSession($from, ['state' => self::STATE_AGENT, 'context' => null, 'is_human' => 0]);
            } else {
                $this->sendMenu($from, $waName);
                $this->updateSession($from, ['state' => self::STATE_MENU, 'context' => null, 'is_human' => 0]);
            }
            return;
        }

        // Se está em atendimento humano, não processa
        if ($session['is_human']) {
            // Apenas registra — humano responde manualmente via painel
            return;
        }

        // Modo agente: a IA decide dinamicamente o que perguntar/fazer a
        // cada mensagem (via function calling), em vez de seguir a
        // máquina de estados fixa abaixo. Fica atrás de uma configuração
        // (desligado por padrão) por ser uma mudança grande no motor de
        // conversa de um bot já em produção — dá pra testar e comparar
        // antes de trocar de vez.
        if (!empty($this->config['agent_mode'])) {
            $this->handleAgentTurn($from, $body, $session, $fromReal, $media);
            return;
        }

        // Roteia pelo estado atual
        switch ($session['state']) {
            case self::STATE_MENU:
                $this->handleMenu($from, $body, $session);
                break;

            case self::STATE_MENU_BLOCKED:
                $this->handleMenuBlocked($from, $body, $session, $waName);
                break;

            case self::STATE_OPEN_TICKET_DESC:
                $this->handleOpenTicketDesc($from, $body, $session, $fromReal, $media);
                break;

            case self::STATE_OPEN_TICKET_ATTACH:
                $this->handleOpenTicketAttach($from, $body, $session, $media);
                break;

            case self::STATE_OPEN_TICKET_NAME:
                $this->handleOpenTicketName($from, $body, $session);
                break;

            case self::STATE_OPEN_TICKET_EMAIL:
                $this->handleOpenTicketEmail($from, $body, $session);
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
                    ?: "📋 *Abrir chamado*\n\nDescreva o problema que está tendo.\n\nDigite uma descrição clara (mínimo 10 caracteres):\n\n_Digite 0 para voltar ao menu_";
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
                $this->handleMenuNotRecognized($from, $session);
        }
    }

    /**
     * Usuário mandou algo que não é 1/2/3 enquanto está no menu (nome,
     * telefone, áudio, etc. — comum quando a pessoa não quer seguir o
     * fluxo). Cobra com duas mensagens diferentes (1ª e 2ª tentativa
     * erradas); na 3ª, para de reexibir o menu e entra em
     * STATE_MENU_BLOCKED — fica em silêncio até a pessoa digitar
     * "chamado" (ou "menu"/"0"/"voltar"/"cancelar") pra retomar.
     */
    private function handleMenuNotRecognized(string $from, array $session): void {
        $context = $this->decodeContext($session['context'] ?? null);
        $nudges  = (int)($context['menu_nudges'] ?? 0) + 1;
        $context['menu_nudges'] = $nudges;
        $waName = $session['wa_name'] ?? '';

        if ($nudges >= 3) {
            // 3ª tentativa errada: mensagem final, sem reexibir o menu, e
            // entra em estado de silêncio até a pessoa pedir pra voltar.
            $blocked = $this->config['menu_blocked_message']
                ?: "😕 Por falta de abertura do chamado, não conseguimos seguir com seu atendimento.\n\n_Digite *Chamado* para voltar ao início_";
            $this->wa->send($from, $blocked);
            $this->updateSession($from, [
                'state'   => self::STATE_MENU_BLOCKED,
                'context' => $this->encodeContext($context)
            ]);
            return;
        }

        $this->updateSession($from, ['context' => $this->encodeContext($context)]);

        $reminder = $nudges === 1
            ? ($this->config['menu_reminder_message']
                ?: "⚠️ Para seguir, é necessário escolher uma das opções abaixo (é preciso *abrir um chamado* para que a gente possa te ajudar):")
            : ($this->config['menu_reminder_message_2']
                ?: "Não consegui entender sua mensagem. Por favor, escolha uma das opções abaixo:");

        $this->wa->send($from, $reminder);
        $this->sendMenu($from, $waName);
    }

    /**
     * Bot em silêncio após 3 tentativas erradas no menu — só reage se a
     * pessoa pedir explicitamente pra voltar ("chamado"). As outras
     * palavras de escape ("menu"/"0"/"voltar"/"cancelar") já são
     * tratadas globalmente em processIncoming() antes de chegar aqui.
     */
    private function handleMenuBlocked(string $from, string $body, array $session, string $waName): void {
        if (strtolower(trim($body)) === 'chamado') {
            $this->sendMenu($from, $waName);
            $this->updateSession($from, ['state' => self::STATE_MENU, 'context' => null]);
        }
        // Qualquer outra coisa: fica em silêncio, sem responder nada.
    }

    /**
     * Aguarda descrição do problema (passo 1 de abrir chamado)
     */
    private function handleOpenTicketDesc(string $from, string $body, array $session, ?string $fromReal = null, ?array $media = null): void {
        if (!empty($media['tooLarge'])) {
            $this->wa->send($from, "⚠️ O arquivo enviado é muito grande e não pôde ser anexado (limite: 5MB). Pode continuar descrevendo o problema por texto, ou enviar um arquivo menor.");
            $media = null;
        }

        // Uma imagem/documento junto da mensagem dispensa a exigência do
        // mínimo de caracteres — nesse caso a descrição vira só um
        // complemento do anexo, não a fonte principal da informação.
        if (strlen($body) < 10 && empty($media['base64'])) {
            $this->wa->send($from, "⚠️ A descrição é muito curta. Por favor, descreva melhor o problema (mínimo 10 caracteres).");
            return;
        }
        $description = strlen($body) >= 10
            ? $body
            : trim(($body ? "{$body} " : '') . '(anexo enviado — ver imagem/documento em anexo no chamado)');

        // JSON_UNESCAPED_UNICODE (dentro de encodeContext): sem isso, acentos
        // são gravados como sequências "\uXXXX" no banco. O GLPI parece
        // remover barras invertidas de strings ao buscar do banco
        // (compatibilidade antiga com magic quotes), o que corrompe esse
        // escape (ex: "ã" vira "u00e3" — "não" aparece como "nu00e3o").
        $context = ['description' => $description, 'fromReal' => $fromReal];

        // Se a imagem/documento já veio junto com a descrição, não tem
        // motivo pra perguntar de novo se a pessoa quer anexar algo — já
        // anexou. Só pergunta quando ninguém mandou nada ainda.
        if (!empty($media['base64'])) {
            $this->attachMediaToContext($context, $media, $from);
            $this->askNameAndProceed($from, $context);
            return;
        }

        $askAttachment = $this->config['ask_attachment_message']
            ?: "📎 Quer anexar uma foto ou documento relacionado ao problema? Envie agora, ou digite *não* para continuar sem anexo.";
        $this->wa->send($from, $askAttachment);
        $this->updateSession($from, [
            'state'   => self::STATE_OPEN_TICKET_ATTACH,
            'context' => $this->encodeContext($context)
        ]);
    }

    /**
     * Aguarda a resposta sobre anexar imagem/documento (passo opcional,
     * só ocorre quando a descrição foi mandada sem nenhuma mídia junto).
     * Qualquer texto que não seja um anexo é tratado como "pular" — não
     * exige uma palavra exata (ex: "não"), já que travar aqui esperando
     * uma resposta específica só frustraria quem só quer seguir andando.
     */
    private function handleOpenTicketAttach(string $from, string $body, array $session, ?array $media = null): void {
        $context = $this->decodeContext($session['context'] ?? null);

        if (!empty($media['tooLarge'])) {
            $this->wa->send($from, "⚠️ O arquivo enviado é muito grande (limite: 5MB) e não foi anexado. Seguindo sem anexo.");
        } elseif (!empty($media['base64'])) {
            $this->attachMediaToContext($context, $media, $from);
        }

        $this->askNameAndProceed($from, $context);
    }

    /**
     * Envia o arquivo (imagem/documento) pro GLPI como documento avulso e
     * guarda só o ID retornado no contexto — a coluna "context" tem
     * limite de 64KB e teria que sobreviver por várias trocas de mensagem
     * até a criação do chamado, então não dá pra guardar o conteúdo em
     * base64 ali. O vínculo com o chamado de fato acontece em
     * createTicket(), depois que o chamado é criado.
     */
    private function attachMediaToContext(array &$context, array $media, string $from): void {
        $docId = $this->glpiApi->uploadDocument($media['base64'], $media['mimetype'] ?? '', $media['filename'] ?? 'anexo');
        if ($docId > 0) {
            $context['attachment_ids'] = array_merge($context['attachment_ids'] ?? [], [$docId]);
        } else {
            $this->log("attachMediaToContext: falha ao enviar anexo pro GLPI (from={$from})");
        }
    }

    /**
     * Pede o nome do solicitante (passo seguinte, depois de descrição +
     * anexo opcional) e avança o estado da conversa.
     */
    private function askNameAndProceed(string $from, array $context): void {
        $askName = $this->config['ask_name_message'] ?: "👤 Informe seu nome:";
        $this->wa->send($from, $askName);
        $this->updateSession($from, [
            'state'   => self::STATE_OPEN_TICKET_NAME,
            'context' => $this->encodeContext($context)
        ]);
    }

    /**
     * Aguarda o nome de quem está solicitando (passo 2 de abrir chamado) —
     * obrigatório. Usado no chamado em vez do nome de contato do WhatsApp
     * (que às vezes vem vazio, com apelido, ou não corresponde à pessoa).
     */
    private function handleOpenTicketName(string $from, string $body, array $session): void {
        $name = trim($body);
        if (mb_strlen($name) < 2) {
            $this->wa->send($from, "⚠️ Por favor, informe seu nome (é obrigatório para abrir o chamado).");
            return;
        }

        $context         = $this->decodeContext($session['context'] ?? null);
        $context['name'] = $name;

        $askEmail = $this->config['ask_email_message'] ?: "📧 Informe seu e-mail:";
        $this->wa->send($from, $askEmail);
        $this->updateSession($from, [
            'state'   => self::STATE_OPEN_TICKET_EMAIL,
            'context' => $this->encodeContext($context)
        ]);
    }

    /**
     * Aguarda o e-mail de quem está solicitando (passo 3 de abrir chamado) —
     * obrigatório. A maioria das contas do GLPI não tem celular cadastrado
     * (o campo que findUserByPhone() usa), mas praticamente todas têm
     * e-mail — então usamos o e-mail pra vincular o solicitante a uma conta
     * já existente no GLPI (users_id), o principal critério de identificação.
     */
    private function handleOpenTicketEmail(string $from, string $body, array $session): void {
        $email = trim($body);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->wa->send($from, "⚠️ E-mail inválido. Por favor, informe um e-mail válido (ex: nome@empresa.com).");
            return;
        }

        $context          = $this->decodeContext($session['context'] ?? null);
        $context['email'] = $email;

        // Tenta vincular a uma conta GLPI já existente pelo e-mail informado.
        // Se encontrar, isso substitui qualquer vínculo anterior (feito por
        // telefone na criação da sessão, que raramente acerta — a maioria
        // dos usuários não tem celular cadastrado no GLPI).
        $userId = $this->glpiApi->findUserByEmail($email);
        if ($userId > 0) {
            $session['users_id'] = $userId;
            $this->updateSession($from, ['users_id' => $userId]);
        }

        // Busca localizações cadastradas no GLPI para o usuário escolher
        // numa lista (em vez de digitar texto livre) — assim o chamado usa
        // o campo nativo "Localização" do GLPI, não um texto solto.
        $locations = $this->glpiApi->getLocations();

        if (!empty($locations)) {
            $header = $this->config['ask_location_message'] ?: "📍 Informe sua filial/localização:";
            $msg = "{$header}\n\n";
            foreach ($locations as $i => $loc) {
                $num = $i + 1;
                // Sem negrito de propósito: tentativas de destacar o número
                // (isolado ou a linha inteira) renderizavam estranho em
                // alguns clientes de WhatsApp. Texto simples fica mais
                // consistente entre clientes.
                $msg .= "{$num} — {$loc['name']}\n";
            }
            // Guarda só o essencial (id/name) — o resto que a API do GLPI
            // devolve (endereço, lat/long, cache internos, etc.) não é
            // usado e só deixa o contexto salvo maior à toa.
            $context['locations'] = array_map(
                fn($loc) => ['id' => $loc['id'], 'name' => $loc['name']],
                $locations
            );

            $this->wa->send($from, $msg);
            $this->updateSession($from, [
                'state'   => self::STATE_OPEN_TICKET_LOCATION,
                'context' => $this->encodeContext($context)
            ]);
        } else {
            // Sem localizações cadastradas no GLPI — cria o chamado sem
            // vincular ao campo de localização.
            $description = $context['description'] ?? 'Sem descrição';
            $fromReal    = $context['fromReal'] ?? null;
            $name        = $context['name'] ?? null;
            $categories  = $this->glpiApi->getCategories();
            $catId       = $this->ai->detectCategory($description, $categories);
            $this->createTicket($from, $description, 0, $catId, $session, $fromReal, $name, $context['attachment_ids'] ?? []);
        }
    }

    /**
     * Aguarda escolha da localização/filial (passo 4 de abrir chamado) —
     * obrigatório. Depois disso, a categoria é escolhida automaticamente
     * pela IA com base na descrição, e o chamado é criado.
     */
    private function handleOpenTicketLocation(string $from, string $body, array $session): void {
        $context     = $this->decodeContext($session['context'] ?? null);
        $description = $context['description'] ?? 'Sem descrição';
        $fromReal    = $context['fromReal'] ?? null;
        $name        = $context['name'] ?? null;
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

        $this->createTicket($from, $description, $locationsId, $catId, $session, $fromReal, $name, $context['attachment_ids'] ?? []);
    }

    /**
     * Cria o chamado no GLPI e confirma (fluxo antigo, máquina de estados).
     * A criação de fato (GLPI + anexos + notificação de técnicos) fica em
     * createTicketRecord(), compartilhada com o modo agente — só a
     * confirmação enviada ao usuário é fixa aqui; no modo agente, quem
     * formula a confirmação é a própria IA.
     */
    private function createTicket(string $from, string $description, int $locationsId, int $catId, array $session, ?string $fromReal = null, ?string $requesterName = null, array $attachmentIds = []): void {
        $ticket = $this->createTicketRecord($from, $description, $locationsId, $catId, $session, $fromReal, $requesterName, $attachmentIds);

        if ($ticket) {
            $this->updateSession($from, ['state' => self::STATE_MENU, 'context' => null]);
            $this->wa->send($from,
                "✅ *Chamado aberto com sucesso!*\n\n" .
                "🔢 Número: #{$ticket['id']}\n" .
                "📝 Nome: {$ticket['requester_name']}\n" .
                "⏳ Status: Chamado Aberto\n\n" .
                "Você receberá atualizações aqui quando houver novidades.\n\n" .
                "_Responda *menu* a qualquer momento para voltar ao início_"
            );
        } else {
            $this->wa->send($from,
                "❌ Não foi possível abrir o chamado no momento. Tente novamente ou escolha a *opção 3* para falar com um técnico."
            );
            $this->updateSession($from, ['state' => self::STATE_MENU, 'context' => null]);
        }
    }

    /**
     * Cria o chamado de fato no GLPI: monta o conteúdo, resolve
     * solicitante/categoria/localização, vincula anexos e notifica
     * técnicos. Usada tanto pelo fluxo antigo (createTicket, acima)
     * quanto pela ferramenta "create_ticket" do modo agente — nenhuma das
     * duas duplica essa lógica.
     *
     * Retorna ['id' => int, 'requester_name' => string] em caso de
     * sucesso, ou null se a API do GLPI falhar.
     */
    private function createTicketRecord(string $from, string $description, int $locationsId, int $catId, array $session, ?string $fromReal = null, ?string $requesterName = null, array $attachmentIds = []): ?array {
        $userId = (int)($session['users_id'] ?? 0);
        // Sem conta vinculada (e-mail/telefone não bateram com ninguém no
        // GLPI): usa o solicitante padrão configurado, se houver — evita
        // chamados sem nenhum solicitante ("Anônimo"), que quebravam
        // integrações externas que esperam sempre ter um usuário no chamado.
        if ($userId <= 0) {
            $userId = (int)($this->config['default_requester_id'] ?? 0);
        }
        // Prioriza o nome que a pessoa digitou no fluxo (mais confiável) sobre
        // o nome de contato do WhatsApp (pode vir vazio, com apelido, etc.)
        $name = trim($requesterName ?? '') ?: (trim($session['wa_name'] ?? '') ?: 'Contato WhatsApp');

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
        if (!$result || !isset($result['id'])) {
            return null;
        }

        $ticketId = (int)$result['id'];
        $this->updateSession($from, ['last_ticket_id' => $ticketId, 'users_id' => $userId]);
        $this->setTicketChannel($ticketId, $from, 0);
        $this->saveMessage($from, 'out', "Chamado #{$ticketId} criado");

        // Vincula ao chamado qualquer anexo (imagem/documento) enviado
        // durante a descrição — o documento já existe no GLPI desde antes,
        // só faltava associar ao chamado que agora acabou de ser criado.
        foreach ($attachmentIds as $docId) {
            $this->glpiApi->linkDocumentToTicket((int)$docId, $ticketId);
        }

        // Notifica técnicos do grupo configurado (a API do GLPI não
        // devolve o campo "name" na resposta do POST /Ticket — usamos o
        // mesmo título que enviamos)
        $this->notifyTechnicians($ticketId, $ticketData['name'], $description, $session);

        return ['id' => $ticketId, 'requester_name' => $name];
    }

    // ---------------------------------------------------------------
    // Modo agente — IA com function calling decide o fluxo dinamicamente
    // ---------------------------------------------------------------

    /**
     * Processa uma mensagem inteira no modo agente: carrega o histórico da
     * conversa, chama a IA com as ferramentas disponíveis, executa
     * qualquer ação que ela decidir chamar (abrir chamado, consultar,
     * transferir pra humano...) e manda a resposta final formulada pela
     * própria IA — em vez de percorrer uma máquina de estados fixa.
     */
    private function handleAgentTurn(string $from, string $body, array $session, ?string $fromReal, ?array $media): void {
        $context = $this->decodeContext($session['context'] ?? null);
        $history = $context['history'] ?? [];

        // Anexo (imagem/documento) enviado nesta mensagem: sobe pro GLPI
        // já como documento avulso e guarda o ID — igual ao fluxo antigo,
        // só que aqui pode chegar em qualquer momento da conversa, não só
        // num passo fixo. O create_ticket usa esses IDs quando chamado.
        if (!empty($media['tooLarge'])) {
            $body = trim($body . "\n\n[o usuário tentou enviar um arquivo, mas era grande demais (limite 5MB) e não foi anexado]");
        } elseif (!empty($media['base64'])) {
            $docId = $this->glpiApi->uploadDocument($media['base64'], $media['mimetype'] ?? '', $media['filename'] ?? 'anexo');
            if ($docId > 0) {
                $context['attachment_ids'] = array_merge($context['attachment_ids'] ?? [], [$docId]);
                $body = trim($body) !== '' ? $body : '[o usuário enviou uma imagem/documento em anexo ao problema]';
            } else {
                $this->log("handleAgentTurn: falha ao enviar anexo pro GLPI (from={$from})");
            }
        }

        $history[] = ['role' => 'user', 'content' => $body];

        $messages = array_merge(
            [['role' => 'system', 'content' => $this->buildAgentSystemPrompt($session)]],
            array_slice($history, -self::AGENT_HISTORY_LIMIT)
        );

        $tools = $this->agentToolDefinitions();
        $finalReply = null;

        for ($round = 0; $round < self::AGENT_MAX_TOOL_ROUNDS; $round++) {
            $result = $this->ai->chatWithTools($messages, $tools);
            if (!$result) break;

            if (!empty($result['tool_calls'])) {
                $messages[] = [
                    'role'       => 'assistant',
                    'content'    => $result['content'],
                    'tool_calls' => $result['tool_calls'],
                ];

                foreach ($result['tool_calls'] as $call) {
                    $toolResult = $this->executeAgentTool($call, $from, $fromReal, $session, $context);
                    $messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $call['id'] ?? '',
                        'content'      => json_encode($toolResult, JSON_UNESCAPED_UNICODE),
                    ];
                }
                continue; // manda os resultados de volta pra IA formular a resposta final
            }

            $finalReply = trim((string)($result['content'] ?? ''));
            break;
        }

        // IA indisponível, ou insistiu em chamar ferramentas sem nunca dar
        // uma resposta final — não deixa o usuário sem retorno nenhum.
        if ($finalReply === null || $finalReply === '') {
            $finalReply = "Desculpe, não consegui processar sua mensagem agora. Pode tentar de novo? Se preferir, digite *3* para falar com um atendente.";
            $this->log("handleAgentTurn: sem resposta final da IA (from={$from})");
        }

        $this->wa->send($from, $finalReply);
        $this->saveMessage($from, 'out', $finalReply);

        // Persiste só o histórico em texto simples (user/assistant) — não
        // guarda as chamadas de ferramenta nem os resultados delas, pra
        // não estourar o limite de 64KB da coluna "context" nem inflar o
        // custo de tokens à toa nas próximas mensagens.
        $history[] = ['role' => 'assistant', 'content' => $finalReply];
        $context['history'] = array_slice($history, -self::AGENT_HISTORY_LIMIT);

        $this->updateSession($from, ['state' => self::STATE_AGENT, 'context' => $this->encodeContext($context)]);
    }

    /**
     * Monta o system prompt do agente: papel, regras e o prompt
     * personalizado configurado pela empresa (aba "Inteligência
     * Artificial"), combinados com as instruções fixas de como/quando
     * usar cada ferramenta.
     */
    private function buildAgentSystemPrompt(array $session): string {
        $custom = trim($this->config['openai_system_prompt'] ?? '');

        $base = <<<PROMPT
Você é o assistente de suporte de TI da empresa, atendendo pelo WhatsApp.
Converse em português, de forma natural, calorosa e direta — como uma pessoa
de verdade atenderia, não como um menu de opções. Frases curtas, sem
formalidade excessiva. Use *negrito* com moderação (formatação do WhatsApp:
um asterisco de cada lado). Nunca use markdown de link nem tabelas.

Seu objetivo, na prática, é sempre um destes três:
1. Ajudar a pessoa a abrir um chamado de suporte.
2. Consultar o andamento de um chamado já aberto.
3. Transferir para um atendente humano, quando for o caso.

Para abrir um chamado, você precisa reunir, na conversa (pode ser em
qualquer ordem, e mais de uma coisa por mensagem):
- uma descrição clara do problema;
- o nome de quem está pedindo;
- o e-mail de quem está pedindo (usado para achar a conta da pessoa no
  sistema, se existir);
- a localização/filial da pessoa — chame a ferramenta list_locations pra
  saber quais existem antes de perguntar; se a lista vier vazia, pode
  seguir sem perguntar localização.
Só chame create_ticket depois de ter TODOS esses dados. Nunca invente
nome, e-mail ou localização — se a pessoa não disse, pergunte.

Se a pessoa mandar uma foto ou documento, considere isso como evidência do
problema — não precisa pedir de novo, só confirme que recebeu.

Se a pessoa quiser saber de um chamado específico e souber o número, use
get_ticket_status. Se quiser ver os chamados dela sem saber o número, use
list_my_tickets.

Se a pessoa pedir explicitamente para falar com uma pessoa/atendente/
técnico, ou estiver claramente frustrada/insatisfeita, ou você não
conseguir ajudar de outro jeito, chame request_human_handoff e avise que
um atendente vai continuar por ali.

Nunca invente chamados, categorias, status ou qualquer dado do sistema —
use sempre as ferramentas para obter informação real. Se uma ferramenta
falhar, avise a pessoa com naturalidade e sugira tentar de novo ou falar
com um atendente.

Mantenha as respostas curtas (poucas linhas) — é uma conversa de WhatsApp,
não um e-mail.
PROMPT;

        if ($custom !== '') {
            $base .= "\n\nInstruções específicas desta empresa:\n{$custom}";
        }

        if (!empty($session['wa_name'])) {
            $base .= "\n\nNome de contato do WhatsApp (pode não ser o nome real — confirme com a pessoa se for usar): {$session['wa_name']}";
        }

        return $base;
    }

    /**
     * Definições das ferramentas disponíveis pro agente, no formato de
     * function calling da OpenAI.
     */
    private function agentToolDefinitions(): array {
        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'list_locations',
                    'description' => 'Lista as filiais/localizações cadastradas no sistema, para o usuário escolher a dele antes de abrir um chamado.',
                    'parameters'  => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'create_ticket',
                    'description' => 'Abre um novo chamado de suporte. Só chame quando já tiver descrição, nome e e-mail do solicitante, e (se list_locations retornou alguma) o location_id escolhido.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'description'     => ['type' => 'string', 'description' => 'Descrição do problema relatado pelo usuário'],
                            'requester_name'  => ['type' => 'string', 'description' => 'Nome de quem está solicitando'],
                            'requester_email' => ['type' => 'string', 'description' => 'E-mail de quem está solicitando'],
                            'location_id'     => ['type' => 'integer', 'description' => 'ID da localização escolhida (retornado por list_locations). Use 0 se não houver localizações cadastradas.'],
                        ],
                        'required' => ['description', 'requester_name', 'requester_email'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'list_my_tickets',
                    'description' => 'Lista os chamados abertos do usuário atual, se ele tiver conta cadastrada no sistema.',
                    'parameters'  => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_ticket_status',
                    'description' => 'Consulta o status e os últimos acompanhamentos de um chamado específico, pelo número.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => ['ticket_id' => ['type' => 'integer', 'description' => 'Número do chamado']],
                        'required'   => ['ticket_id'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'request_human_handoff',
                    'description' => 'Transfere a conversa para um atendente humano.',
                    'parameters'  => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
                ],
            ],
        ];
    }

    /**
     * Executa uma chamada de ferramenta decidida pela IA e devolve o
     * resultado (que volta pra IA como mensagem "tool", pra ela formular
     * a resposta final em linguagem natural).
     */
    private function executeAgentTool(array $call, string $from, ?string $fromReal, array $session, array &$context): array {
        $name = $call['function']['name'] ?? '';
        $args = json_decode($call['function']['arguments'] ?? '{}', true);
        if (!is_array($args)) $args = [];

        switch ($name) {
            case 'list_locations':
                $locations = $this->glpiApi->getLocations();
                return ['locations' => array_map(
                    fn($l) => ['id' => (int)$l['id'], 'name' => $l['name']],
                    $locations
                )];

            case 'create_ticket':
                $description = trim($args['description'] ?? '');
                $reqName     = trim($args['requester_name'] ?? '');
                $email       = trim($args['requester_email'] ?? '');
                $locationId  = (int)($args['location_id'] ?? 0);

                if ($description === '' || $reqName === '' || $email === '') {
                    return ['ok' => false, 'error' => 'Faltam dados obrigatórios: descrição, nome e e-mail são todos necessários.'];
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return ['ok' => false, 'error' => 'O e-mail informado não é válido.'];
                }

                $userId = $this->glpiApi->findUserByEmail($email);
                $sessionForCreate = $session;
                $sessionForCreate['users_id'] = $userId;

                $categories = $this->glpiApi->getCategories();
                $catId      = $this->ai->detectCategory($description, $categories);

                $ticket = $this->createTicketRecord(
                    $from, $description, $locationId, $catId, $sessionForCreate,
                    $fromReal, $reqName, $context['attachment_ids'] ?? []
                );

                if (!$ticket) {
                    return ['ok' => false, 'error' => 'Falha ao criar o chamado no sistema.'];
                }

                $context['attachment_ids'] = [];
                return ['ok' => true, 'ticket_id' => $ticket['id']];

            case 'list_my_tickets':
                $userId = (int)($session['users_id'] ?? 0);
                if ($userId <= 0) {
                    return ['ok' => false, 'error' => 'Este contato não tem uma conta vinculada no sistema — peça o número do chamado, se souber.'];
                }
                $tickets = $this->glpiApi->getUserTickets($userId, 5);
                return ['ok' => true, 'tickets' => array_map(fn($t) => [
                    'id'     => $t['id'] ?? 0,
                    'name'   => $t['name'] ?? '',
                    'status' => $this->getStatusLabel((int)($t['status'] ?? 0)),
                ], $tickets)];

            case 'get_ticket_status':
                $ticketId = (int)($args['ticket_id'] ?? 0);
                if ($ticketId <= 0) {
                    return ['ok' => false, 'error' => 'Número de chamado inválido.'];
                }
                $ticket = $this->glpiApi->getTicket($ticketId);
                if (!$ticket) {
                    return ['ok' => false, 'error' => 'Chamado não encontrado.'];
                }
                return ['ok' => true, 'ticket' => [
                    'id'        => $ticket['id'] ?? $ticketId,
                    'name'      => $ticket['name'] ?? '',
                    'status'    => $this->getStatusLabel((int)($ticket['status'] ?? 0)),
                    'followups' => array_map(
                        fn($f) => $f['content'] ?? '',
                        array_slice($ticket['followups'] ?? [], 0, 3)
                    ),
                ]];

            case 'request_human_handoff':
                $this->transferToHuman($from, $session, false);
                return ['ok' => true];

            default:
                return ['ok' => false, 'error' => "Ferramenta desconhecida: {$name}"];
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
                "⚠️ Não encontrei sua conta no sistema.\n\nPor favor, escolha outra opção ou fale com um técnico (opção 3)."
            );
            return;
        }

        $tickets = $this->glpiApi->getUserTickets($userId, 5);

        if (empty($tickets)) {
            $this->wa->send($from, "📭 Você não possui chamados abertos no momento.\n\nDigite a opção 1 para abrir um novo chamado.");
            return;
        }

        // Formata com IA
        $rawData = json_encode($tickets, JSON_UNESCAPED_UNICODE);
        $prompt  = "Formate esta lista de chamados de suporte de forma clara e amigável para WhatsApp. Use emojis de status. Dados: $rawData";
        $formatted = $this->ai->complete($prompt);

        if (!$formatted) {
            // Fallback sem IA
            $msg = "📋 Seus chamados:\n\n";
            foreach ($tickets as $t) {
                $statusLabel = $this->getStatusLabel($t['status']);
                $msg .= "• *#{$t['id']}* — {$t['name']}\n  {$statusLabel}\n\n";
            }
            $formatted = $msg;
        }

        $formatted .= "\n\n_Digite menu para voltar_";
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

        $this->wa->send($from, $formatted . "\n\n_Digite menu para voltar_");
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

        // Registra a nota como acompanhamento privado no próprio chamado —
        // sem isso, a avaliação era só processada em memória pra decidir a
        // resposta e depois descartada, sem deixar nenhum rastro visível
        // pro técnico na tela do chamado.
        if (!empty($session['last_ticket_id'])) {
            $stars = str_repeat('⭐', $nota) . str_repeat('☆', 5 - $nota);
            $this->glpiApi->addFollowup(
                (int)$session['last_ticket_id'],
                "Avaliação do usuário via WhatsApp: {$nota}/5 {$stars}",
                true // is_private
            );
        }

        if ($nota < 3) {
            $this->transferToHuman($from, $session);
        } else {
            $this->updateSession($from, ['state' => self::STATE_MENU]);
        }
    }

    /**
     * Transfere para atendimento humano
     */
    private function transferToHuman(string $from, array $session, bool $sendMessage = true): void {
        // No modo agente quem avisa a pessoa é a própria IA, na resposta
        // final que ela formula depois de chamar request_human_handoff —
        // mandar essa mensagem fixa também duplicaria o aviso.
        if ($sendMessage) {
            $this->wa->send($from,
                "👨‍💻 Transferindo para atendimento humano...\n\nUm técnico irá assumir essa conversa em breve. Por favor, aguarde.\n\n_Tempo médio de espera: alguns minutos_"
            );
        }
        $this->updateSession($from, [
            'state'    => self::STATE_HUMAN,
            'is_human' => 1
        ]);

        // Notifica no GLPI via comentário interno e marca o canal do
        // chamado como "atendente" (se tiver ticket ativo)
        if (!empty($session['last_ticket_id'])) {
            $this->setTicketChannel((int)$session['last_ticket_id'], $from, 1);
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
                "⭐ Como você avalia o atendimento?\n\nResponda com um número de 1 a 5:\n\n1 — Péssimo\n2 — Ruim\n3 — Regular\n4 — Bom\n5 — Excelente"
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

        $from = $session['wa_number'];
        $content = $this->formatFollowupContent($followup->fields['content'] ?? '');
        if (empty($content)) return;

        $this->wa->send($from,
            "💬 Atualização no chamado #{$ticketId}:\n\n{$content}\n\n_Responda menu para ver suas opções_"
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

        // Só compara com o solicitante quando ele realmente tem conta GLPI
        // vinculada (users_id > 0). Números fixos são guardados com id=0
        // (não têm conta associada) — sem essa checagem de ">0", sempre que
        // o solicitante NÃO tivesse conta vinculada (users_id também 0,
        // caso comum), a comparação "0 === 0" fazia o código achar que o
        // número fixo era o próprio solicitante e pular ele por engano,
        // silenciosamente notificando ninguém.
        $requesterId = (int)($session['users_id'] ?? 0);

        $sent  = 0;
        $fails = 0;
        foreach ($technicians as $tech) {
            $techNumber = $tech['mobile'] ?? '';
            if (empty($techNumber)) continue;

            // Não notifica o próprio solicitante se ele for técnico
            if ($requesterId > 0 && $requesterId === (int)$tech['id']) continue;

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
        $welcome   = $this->config['welcome_message'] ?? "Olá! Como posso ajudar?\n\n*1 — Abrir chamado*\n*2 — Consultar andamento*\n*3 — Falar com humano*";
        $this->wa->send($from, $welcome);
        $this->saveMessage($from, 'out', $welcome);
    }

    /**
     * Converte o HTML do editor de texto do GLPI (acompanhamento de
     * chamado) em texto simples pro WhatsApp.
     *
     * O editor grava o conteúdo com as tags já codificadas como entidades
     * HTML numéricas (ex: "&#60;p&#62;" em vez de "<p>" literal) — por
     * isso strip_tags() sozinho não fazia nada: ele só reconhece tags
     * literais, então "&#60;p data-start=..." passava direto pro usuário
     * como texto quebrado. html_entity_decode() primeiro transforma essas
     * entidades de volta em tags de verdade, aí sim strip_tags() consegue
     * removê-las. Um segundo decode depois pega entidades de texto que
     * sobraram (ex: "&#8217;" → "'"), e o preg_replace no final evita um
     * bloco de linhas em branco enorme (cada tag de bloco removida como
     * <p> vira uma quebra de linha).
     */
    private function formatFollowupContent(string $raw): string {
        $content = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = preg_replace("/\n{3,}/", "\n\n", $content);
        return trim($content);
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

    /**
     * Codifica o contexto da conversa para gravar na coluna "context".
     *
     * Usa base64 em cima do JSON, não JSON puro: o GLPI parece remover
     * barras invertidas de strings ao buscar do banco (compatibilidade
     * antiga com magic quotes). Isso corrompe qualquer aspas escapada
     * (\") dentro do JSON — e alguns dados que vêm da API do GLPI (ex:
     * campos internos "sons_cache"/"ancestors_cache" de Location) já
     * contêm aspas dentro do próprio valor, exigindo esse escape.
     * Base64 não usa barra invertida nem aspas, então fica imune a
     * esse comportamento, seja lá o que for guardado no contexto.
     */
    private function encodeContext(array $context): string {
        return base64_encode(json_encode($context, JSON_UNESCAPED_UNICODE));
    }

    private function decodeContext(?string $raw): array {
        if (empty($raw)) return [];
        $json = base64_decode($raw, true);
        if ($json === false) return [];
        return json_decode($json, true) ?? [];
    }

    // ---------------------------------------------------------------
    // Sessões no banco
    // ---------------------------------------------------------------

    /**
     * Devolve ao menu inicial qualquer sessão parada (sem nenhuma mensagem
     * nova) há mais tempo que "Timeout sem resposta" — inclui quem ficou
     * esperando atendimento humano sem ninguém assumir, ou travado no meio
     * da abertura de um chamado. O usuário recebe um aviso e, se quiser
     * continuar, precisa começar de novo digitando *menu*.
     *
     * O plugin não tem um processo de fundo (cron) rodando sozinho — essa
     * checagem é chamada a cada mensagem recebida (ver processIncoming()),
     * o que já é suficiente pra manter as sessões em dia sempre que o bot
     * está sendo usado por alguém. Faz a varredura só 1 a cada ~10
     * mensagens (não a cada uma) pra não bater no banco à toa.
     */
    private function releaseTimedOutSessions(): void {
        global $DB;

        $timeoutMin = (int)($this->config['timeout_minutes'] ?? 0);
        if ($timeoutMin <= 0) return;
        if (random_int(1, 10) !== 1) return;

        $cutoff = date('Y-m-d H:i:s', time() - $timeoutMin * 60);

        $stale = $DB->request([
            'FROM'  => 'glpi_plugin_whatsappbot_sessions',
            'WHERE' => [
                'date_last_msg' => ['<', $cutoff],
                'state'         => ['<>', self::STATE_MENU],
            ],
        ]);

        foreach ($stale as $s) {
            if (!empty($s['is_human']) && !empty($s['last_ticket_id'])) {
                $this->setTicketChannel((int)$s['last_ticket_id'], $s['wa_number'], 0);
            }
            $this->wa->send($s['wa_number'],
                "⏰ Sua conversa foi encerrada por inatividade.\n\n_Digite *menu* para começar de novo_"
            );
            $this->updateSession($s['wa_number'], [
                'state'    => self::STATE_MENU,
                'context'  => null,
                'is_human' => 0,
            ]);
            $this->log("releaseTimedOutSessions: sessão {$s['wa_number']} devolvida ao menu (parada desde {$s['date_last_msg']})");
        }
    }

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
        $agentMode = !empty($this->config['agent_mode']);

        $DB->insert('glpi_plugin_whatsappbot_sessions', [
            'wa_number'     => $from,
            'wa_name'       => $name,
            'users_id'      => $userId,
            'state'         => $agentMode ? self::STATE_AGENT : self::STATE_MENU,
            'is_human'      => 0,
            'date_start'    => date('Y-m-d H:i:s'),
            'date_last_msg' => date('Y-m-d H:i:s'),
        ]);

        $session = $DB->request([
            'FROM'  => 'glpi_plugin_whatsappbot_sessions',
            'WHERE' => ['wa_number' => $from],
            'LIMIT' => 1
        ])->current();

        if ($agentMode) {
            // No modo agente não existe "menu de boas-vindas" fixo — a
            // primeira mensagem da pessoa já entra direto na conversa com
            // a IA (processIncoming trata normalmente, sem sinalizar
            // sessão nova), que responde de forma natural em vez de
            // devolver um menu numerado antes de qualquer coisa.
            return $session;
        }

        // Envia menu de boas-vindas na primeira mensagem
        $this->sendMenu($from, $name);

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

    /**
     * Registra se um chamado específico está com o bot ou com um atendente
     * humano. Guardado por chamado (não por sessão): a sessão só lembra o
     * "last_ticket_id" mais recente, então sem isso, assim que a mesma
     * pessoa abrisse um segundo chamado, o status de atendimento do
     * primeiro se perdia — telas externas (dashboard de acompanhamento)
     * que consultam por chamado específico sempre viam o status errado
     * (ou nenhum) pra qualquer chamado que não fosse o último.
     */
    private function setTicketChannel(int $ticketId, string $waNumber, int $isHuman): void {
        global $DB;
        if ($ticketId <= 0) return;

        $exists = $DB->request([
            'FROM'  => 'glpi_plugin_whatsappbot_ticket_channel',
            'WHERE' => ['tickets_id' => $ticketId],
            'LIMIT' => 1
        ])->current();

        $fields = [
            'wa_number' => $waNumber,
            'is_human'  => $isHuman,
            'date_mod'  => date('Y-m-d H:i:s'),
        ];

        if ($exists) {
            $DB->update('glpi_plugin_whatsappbot_ticket_channel', $fields, ['tickets_id' => $ticketId]);
        } else {
            $fields['tickets_id'] = $ticketId;
            $DB->insert('glpi_plugin_whatsappbot_ticket_channel', $fields);
        }
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
