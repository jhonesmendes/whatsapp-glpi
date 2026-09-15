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
    $PLUGIN_HOOKS['post_item_form']['whatsappbot'] = [
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
            `timeout_minutes`       int          DEFAULT 15,
            `glpi_api_url`          varchar(255) DEFAULT '',
            `glpi_app_token`        varchar(255) DEFAULT '',
            `glpi_user_token`       varchar(255) DEFAULT '',
            `default_category_id`   int          DEFAULT 0,
            `default_group_id`      int          DEFAULT 0,
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
            'welcome_message'      => "Olá! 👋 Bem-vindo ao suporte de TI.\n\nComo posso ajudar?\n\n*1* — Abrir chamado\n*2* — Consultar andamento\n*3* — Falar com humano",
            'openai_system_prompt' => "Você é o assistente de TI da empresa. Organize as informações de chamados de forma clara e amigável em português. Use emojis com moderação. Nunca invente dados — use somente o que veio da API do GLPI. Seja conciso e direto.",
            'date_mod'             => date('Y-m-d H:i:s')
        ]);
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
            `is_human`      tinyint(1)   DEFAULT 0,
            `human_agent`   varchar(100) DEFAULT '',
            `date_start`    datetime     DEFAULT NULL,
            `date_last_msg` datetime     DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `wa_number` (`wa_number`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        $DB->queryOrDie($query, "Erro ao criar tabela de sessões");
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
        'glpi_plugin_whatsappbot_messages'
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
