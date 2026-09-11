# WhatsApp Bot para GLPI

Projeto dividido em duas partes independentes:

```
whatsapp-glpi/
├── parte1-plugin-glpi/     → Plugin PHP instalado dentro do GLPI
└── parte2-baileys-server/  → Serviço Node.js rodando em servidor separado
```

## Arquitetura

```
[Usuário WhatsApp]
       │
       ▼
[Servidor B — Node.js + Baileys]   ← mantém sessão WA Web
       │  POST /webhook.php
       ▼
[Servidor A — GLPI + Plugin PHP]   ← processa bot, chama API GLPI, chama GPT-4.1
       │  POST /send
       ▼
[Servidor B — Node.js]             ← envia resposta ao usuário
```

## Parte 1 — Plugin GLPI

- Instalar em `glpi/plugins/whatsappbot/`
- Configurar via GLPI → Configuração → Plugins → WhatsApp Bot
- Expõe endpoint público: `https://SEU_GLPI/plugins/whatsappbot/webhook.php`

## Parte 2 — Servidor Baileys (Node.js)

- Roda em qualquer servidor com Node.js 18+
- Expõe API REST na porta 3333
- GLPI aponta para a URL deste servidor nas configurações

## Fluxo completo

1. Usuário manda mensagem → Baileys recebe
2. Baileys faz POST no GLPI → `webhook.php`
3. Plugin identifica usuário pelo número, processa menu
4. Cria/consulta chamado via API REST do GLPI
5. GPT-4.1 formata a resposta de forma amigável
6. Plugin chama API do Baileys para enviar resposta ao usuário
7. Quando técnico resolve chamado → hook do GLPI notifica usuário no WA

## Requisitos

### Servidor A (GLPI)
- GLPI 10.x
- PHP 8.1+
- Extensão `curl` habilitada

### Servidor B (Baileys)
- Node.js 18+
- NPM 9+
- Acesso à internet (para WhatsApp Web)
- Porta 3333 acessível pelo Servidor A
