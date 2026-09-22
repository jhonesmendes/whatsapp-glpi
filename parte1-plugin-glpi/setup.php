<?php
/**
 * WhatsApp Bot Plugin para GLPI
 * Parte 1: Plugin PHP
 */

define('PLUGIN_WHATSAPPBOT_VERSION', '1.0.0');
define('PLUGIN_WHATSAPPBOT_MIN_GLPI', '10.0.0');
define('PLUGIN_WHATSAPPBOT_MAX_GLPI', '10.1.99');

/**
 * Inicializa o plugin
 */
function plugin_init_whatsappbot() {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['whatsappbot'] = true;

    // Menu de configuração
    Plugin::registerClass('PluginWhatsappbotConfig', [
        'addtabon' => ['Config']
    ]);

    // Hook: quando chamado é resolvido/fechado, notificar usuário no WA
    $PLUGIN_HOOKS['item_update']['whatsappbot'] = [
        'Ticket' => 'plugin_whatsappbot_ticket_updated'
    ];

    // Hook: quando comentário é adicionado ao chamado
    // (post_item_form é para desenhar a tela do formulário, não dispara
    // quando o registro é salvo — item_add é o gancho certo para isso)
    $PLUGIN_HOOKS['item_add']['whatsappbot'] = [
        'ITILFollowup' => 'plugin_whatsappbot_followup_added'
    ];

    // Adiciona menu lateral no GLPI
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['menu_toadd']['whatsappbot'] = [
            'config' => 'PluginWhatsappbotConfig'
        ];
    }
}

/**
 * Informações do plugin
 */
function plugin_version_whatsappbot() {
    return [
        'name'           => 'WhatsApp Bot',
        'version'        => PLUGIN_WHATSAPPBOT_VERSION,
        'author'         => 'Jhontisystem',
        'license'        => 'GPL v2+',
        'homepage'       => 'https://jhontisystem.com.br',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_WHATSAPPBOT_MIN_GLPI,
                'max' => PLUGIN_WHATSAPPBOT_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.1',
                'exts' => ['curl', 'json', 'mbstring']
            ]
        ]
    ];
}

/**
 * Verifica pré-requisitos
 */
function plugin_whatsappbot_check_prerequisites() {
    if (!extension_loaded('curl')) {
        echo "A extensão PHP 'curl' é necessária.<br>";
        return false;
    }
    if (!extension_loaded('json')) {
        echo "A extensão PHP 'json' é necessária.<br>";
        return false;
    }
    return true;
}

/**
 * Verifica configuração
 */
function plugin_whatsappbot_check_config($verbose = false) {
    return true;
}

/**
 * Instala tabelas do plugin
 */
function plugin_whatsappbot_install() {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    // Tabela de configuração
    if (!$DB->tableExists('glpi_plugin_whatsappbot_configs')) {
        $query = "CREATE TABLE `glpi_plugin_whatsappbot_configs` (
            `id`                    int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `baileys_url`           varchar(255) DEFAULT 'http://localhost:3333',
            `baileys_token`         varchar(255) DEFAULT '',
            `whatsapp_number`       varchar(20)  DEFAULT '',
            `openai_api_key`        varchar(255) DEFAULT '',
            `openai_model`          varchar(50)  DEFAULT 'gpt-4.1',
            `openai_system_prompt`  text,
            `welcome_message`       text,
            `ask_description_message` text,
            `ask_name_message`      text,
            `ask_email_message`     text,
            `ask_attachment_message` text,
            `ask_location_message`  text,
            `menu_reminder_message` text,
            `menu_reminder_message_2` text,
            `menu_blocked_message`  text,
            `timeout_minutes`       int          DEFAULT 15,
            `glpi_api_url`          varchar(255) DEFAULT '',
            `glpi_app_token`        varchar(255) DEFAULT '',
            `glpi_user_token`       varchar(255) DEFAULT '',
            `default_category_id`   int          DEFAULT 0,
            `default_group_id`      int          DEFAULT 0,
            `default_requester_id`  int          DEFAULT 0,
            `unknown_user_action`   varchar(20)  DEFAULT 'visitor',
            `post_resolve_action`   varchar(50)  DEFAULT 'ask_rating',
            `tech_notify_numbers`   text,
            `is_active`             tinyint(1)   DEFAULT 0,
            `date_mod`              datetime     DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->queryOrDie($query, "Erro ao criar tabela de configurações");

        // Insere configuração padrão
        $DB->insert('glpi_plugin_whatsappbot_configs', [
            'welcome_message'      => "Olá! 👋 Bem-vindo ao suporte de TI.\n\nComo posso ajudar?\n\n*1 — Abrir chamado*\n*2 — Consultar andamento*\n*3 — Falar com humano*",
            'openai_system_prompt' => "Você é o assistente de TI da empresa. Organize as informações de chamados de forma clara e amigável em português. Use emojis com moderação. Nunca invente dados — use somente o que veio da API do GLPI. Seja conciso e direto.",
            'ask_description_message' => "📋 *Abrir chamado*\n\nDescreva o problema que está tendo.\n\nDigite uma descrição clara (mínimo 10 caracteres):\n\n_Digite 0 para voltar ao menu_",
            'ask_name_message'        => "👤 Informe seu nome:",
            'ask_email_message'       => "📧 Informe seu e-mail:",
            'ask_attachment_message'  => "📎 Quer anexar uma foto ou documento relacionado ao problema? Envie agora, ou digite *não* para continuar sem anexo.",
            'ask_location_message'    => "📍 Informe sua filial/localização:",
            'menu_reminder_message'   => "⚠️ Para seguir, é necessário escolher uma das opções abaixo (é preciso *abrir um chamado* para que a gente possa te ajudar):",
            'menu_reminder_message_2' => "Não consegui entender sua mensagem. Por favor, escolha uma das opções abaixo:",
            'menu_blocked_message'    => "😕 Por falta de abertura do chamado, não conseguimos seguir com seu atendimento.\n\n_Digite *Chamado* para voltar ao início_",
            'date_mod'             => date('Y-m-d H:i:s')
        ]);
    } else {
        // Instalação já existente — adiciona colunas novas se ainda não existirem
        // (upgrade sem apagar dados). Necessário porque este plugin não tem
        // um sistema formal de migração por versão ainda.
        $newColumns = [
            'ask_description_message' => "ALTER TABLE `glpi_plugin_whatsappbot_configs` ADD COLUMN `ask_description_message` text AFTER `welcome_message`",
            'ask_name_message'        => "ALTER TABLE `glpi_plugin_whatsappbot_configs` ADD COLUMN `ask_name_message` text AFTER `ask_description_message`",
            'ask_email_message'       => "ALTER TABLE `glpi_plugin_whatsappbot_configs` ADD COLUMN `ask_email_message` text AFTER `ask_name_message`",
            'ask_attachment_message'  => "ALTER TABLE `glpi_plugin_whatsappbot_configs` ADD COLUMN `ask_attachment_message` text AFTER `ask_email_message`",
            'ask_location_message'    => "ALTER TABLE `glpi_plugin_whatsappbot_configs` ADD COLUMN `ask_location_message` text AFTER `ask_attachment_message`",
            'menu_reminder_message'   => "ALTER TABLE `glpi_plugin_whatsappbot_configs` ADD COLUMN `menu_reminder_message` text AFTER `ask_location_message`",
            'menu_reminder_message_2' => "ALTER TABLE `glpi_plugin_whatsappbot_configs` ADD COLUMN `menu_reminder_message_2` text AFTER `menu_reminder_message`",
            'menu_blocked_message'    => "ALTER TABLE `glpi_plugin_whatsappbot_configs` ADD COLUMN `menu_blocked_message` text AFTER `menu_reminder_message_2`",
            'default_requester_id'    => "ALTER TABLE `glpi_plugin_whatsappbot_configs` ADD COLUMN `default_requester_id` int DEFAULT 0 AFTER `default_group_id`",
        ];
        foreach ($newColumns as $column => $alterQuery) {
            if (!$DB->fieldExists('glpi_plugin_whatsappbot_configs', $column)) {
                $DB->queryOrDie($alterQuery, "Erro ao adicionar coluna $column");
            }
        }

        // Preenche as mensagens padrão em instalações que ainda estão vazias
        $DB->query("UPDATE `glpi_plugin_whatsappbot_configs` SET
            `ask_description_message` = '📋 *Abrir chamado*\n\nDescreva o problema que está tendo.\n\nDigite uma descrição clara (mínimo 10 caracteres):\n\n_Digite 0 para voltar ao menu_'
            WHERE `ask_description_message` IS NULL OR `ask_description_message` = ''");
        $DB->query("UPDATE `glpi_plugin_whatsappbot_configs` SET
            `ask_name_message` = '👤 Informe seu nome:'
            WHERE `ask_name_message` IS NULL OR `ask_name_message` = ''");
        $DB->query("UPDATE `glpi_plugin_whatsappbot_configs` SET
            `ask_email_message` = '📧 Informe seu e-mail:'
            WHERE `ask_email_message` IS NULL OR `ask_email_message` = ''");
        $DB->query("UPDATE `glpi_plugin_whatsappbot_configs` SET
            `ask_attachment_message` = '📎 Quer anexar uma foto ou documento relacionado ao problema? Envie agora, ou digite *não* para continuar sem anexo.'
            WHERE `ask_attachment_message` IS NULL OR `ask_attachment_message` = ''");
        $DB->query("UPDATE `glpi_plugin_whatsappbot_configs` SET
            `ask_location_message` = '📍 Informe sua filial/localização:'
            WHERE `ask_location_message` IS NULL OR `ask_location_message` = ''");
        $DB->query("UPDATE `glpi_plugin_whatsappbot_configs` SET
            `menu_reminder_message` = '⚠️ Para seguir, é necessário escolher uma das opções abaixo (é preciso *abrir um chamado* para que a gente possa te ajudar):'
            WHERE `menu_reminder_message` IS NULL OR `menu_reminder_message` = ''");
        $DB->query("UPDATE `glpi_plugin_whatsappbot_configs` SET
            `menu_reminder_message_2` = 'Não consegui entender sua mensagem. Por favor, escolha uma das opções abaixo:'
            WHERE `menu_reminder_message_2` IS NULL OR `menu_reminder_message_2` = ''");
        $DB->query("UPDATE `glpi_plugin_whatsappbot_configs` SET
            `menu_blocked_message` = '😕 Por falta de abertura do chamado, não conseguimos seguir com seu atendimento.\n\n_Digite *Chamado* para voltar ao início_'
            WHERE `menu_blocked_message` IS NULL OR `menu_blocked_message` = ''");
    }

    // Tabela de sessões/conversas ativas
    if (!$DB->tableExists('glpi_plugin_whatsappbot_sessions')) {
        $query = "CREATE TABLE `glpi_plugin_whatsappbot_sessions` (
            `id`            int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `wa_number`     varchar(50)  NOT NULL,
            `wa_name`       varchar(100) DEFAULT '',
            `users_id`      int          DEFAULT 0,
            `state`         varchar(50)  DEFAULT 'menu',
            `context`       text,
            `last_ticket_id` int         DEFAULT 0,
            `last_msg_id`   varchar(100) DEFAULT '',
            `is_human`      tinyint(1)   DEFAULT 0,
            `human_agent`   varchar(100) DEFAULT '',
            `date_start`    datetime     DEFAULT NULL,
            `date_last_msg` datetime     DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `wa_number` (`wa_number`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->queryOrDie($query, "Erro ao criar tabela de sessões");
    } elseif (!$DB->fieldExists('glpi_plugin_whatsappbot_sessions', 'last_msg_id')) {
        // Upgrade: instalação já existente sem a coluna de deduplicação
        $DB->queryOrDie(
            "ALTER TABLE `glpi_plugin_whatsappbot_sessions` ADD COLUMN `last_msg_id` varchar(100) DEFAULT '' AFTER `last_ticket_id`",
            "Erro ao adicionar coluna last_msg_id"
        );
    }

    // Tabela de histórico de mensagens
    if (!$DB->tableExists('glpi_plugin_whatsappbot_messages')) {
        $query = "CREATE TABLE `glpi_plugin_whatsappbot_messages` (
            `id`            int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `wa_number`     varchar(50) NOT NULL,
            `direction`     enum('in','out') NOT NULL DEFAULT 'in',
            `message`       text,
            `ticket_id`     int         DEFAULT 0,
            `date_sent`     datetime    DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `wa_number` (`wa_number`),
            KEY `date_sent` (`date_sent`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->queryOrDie($query, "Erro ao criar tabela de mensagens");
    }

    // Canal (bot/atendente) de cada chamado — separado da tabela de sessões
    // de propósito: "last_ticket_id" na sessão só guarda o chamado MAIS
    // RECENTE de cada número, então quando a mesma pessoa abre um segundo
    // chamado, o status de atendimento do primeiro chamado se perdia (só
    // dava pra saber o status do último). Um registro por chamado resolve
    // isso — usado por telas externas (ex: dashboard de acompanhamento)
    // que precisam saber se um chamado específico ainda está com o bot ou
    // já foi assumido por um atendente humano.
    if (!$DB->tableExists('glpi_plugin_whatsappbot_ticket_channel')) {
        $query = "CREATE TABLE `glpi_plugin_whatsappbot_ticket_channel` (
            `id`         int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `tickets_id` int          NOT NULL,
            `wa_number`  varchar(50)  NOT NULL,
            `is_human`   tinyint(1)   DEFAULT 0,
            `date_mod`   datetime     DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tickets_id` (`tickets_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->queryOrDie($query, "Erro ao criar tabela de canal do chamado");
    }

    return true;
}

/**
 * Desinstala o plugin
 */
function plugin_whatsappbot_uninstall() {
    global $DB;
    foreach ([
        'glpi_plugin_whatsappbot_configs',
        'glpi_plugin_whatsappbot_sessions',
        'glpi_plugin_whatsappbot_messages',
        'glpi_plugin_whatsappbot_ticket_channel'
    ] as $table) {
        $DB->queryOrDie("DROP TABLE IF EXISTS `$table`");
    }
    return true;
}

/**
 * Hook: chamado atualizado
 */
function plugin_whatsappbot_ticket_updated(Ticket $ticket) {
    $status = $ticket->fields['status'];
    // Notifica quando chamado é resolvido ou fechado
    if (in_array($status, [Ticket::SOLVED, Ticket::CLOSED])) {
        $bot = new PluginWhatsappbotBot();
        $bot->notifyTicketResolved($ticket);
    }
}

/**
 * Hook: acompanhamento adicionado
 */
function plugin_whatsappbot_followup_added(ITILFollowup $followup) {
    // Notifica usuário que um técnico comentou no chamado
    $bot = new PluginWhatsappbotBot();
    $bot->notifyFollowup($followup);
}
