# WhatsApp Bot para GLPI

Bot de atendimento via WhatsApp (Web, não-oficial) integrado ao GLPI:
abre e consulta chamados, categoriza automaticamente com IA e permite
transferir para atendimento humano — tudo configurável dentro do
próprio GLPI, sem editar código.

Repositório único e público:
```
https://github.com/jhonesmendes/whatsapp-glpi
```

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
[Servidor A — GLPI + Plugin PHP]   ← processa bot, chama API GLPI, chama GPT
       │  POST /send
       ▼
[Servidor B — Node.js]             ← envia resposta ao usuário
```

O GLPI e o Baileys normalmente rodam em servidores diferentes porque a
hospedagem do GLPI (Servidor A) costuma não ter Node.js disponível.

## Parte 1 — Plugin GLPI

- Instalado em `glpi/plugins/whatsappbot/`
- Configurado 100% pela UI: **Configuração → Plugins → WhatsApp Bot**
  (conexão com o Baileys, credenciais da API do GLPI, chave da OpenAI,
  todas as mensagens do bot, notificação de técnicos, conversas ativas)
- Expõe endpoint público: `https://SEU_GLPI/plugins/whatsappbot/webhook.php`
- Deploy: como a maioria das hospedagens de GLPI não tem git/SSH, os
  arquivos são enviados por **FTP** a partir deste repositório (veja
  [`parte1-plugin-glpi/docs/INSTALACAO.md`](parte1-plugin-glpi/docs/INSTALACAO.md))

## Parte 2 — Servidor Baileys (Node.js)

- Roda em qualquer servidor/VM com Node.js 18+
- Expõe API REST (porta 3333) usada pelo plugin do GLPI para enviar
  mensagens, consultar status e exibir o QR code de conexão
- Deploy via `git clone`/`git pull` direto no servidor (veja
  [`parte2-baileys-server/docs/INSTALACAO.md`](parte2-baileys-server/docs/INSTALACAO.md))

## Fluxo completo

1. Usuário manda mensagem → Baileys recebe
2. Baileys faz POST no GLPI → `webhook.php`
3. Plugin identifica o usuário pelo número e processa o menu
   (abrir chamado / consultar chamado / falar com humano)
4. Ao abrir chamado: pede descrição, nome do solicitante e localização
   (lista de locais cadastrados no GLPI), categoriza automaticamente
   via IA e cria o chamado pela API REST do GLPI
5. GPT formata as respostas de forma amigável
6. Plugin chama a API do Baileys para enviar a resposta ao usuário
7. Se o usuário não escolhe uma opção válida no menu, o bot cobra até
   3 vezes e depois fica em silêncio até ele digitar "chamado"/"menu"
8. Quando um técnico resolve o chamado → hook do GLPI notifica o
   usuário no WhatsApp

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

## Instalação e atualização

Guias detalhados de cada parte:

- [`parte1-plugin-glpi/docs/INSTALACAO.md`](parte1-plugin-glpi/docs/INSTALACAO.md) — instalar o plugin no GLPI e como atualizar via FTP
- [`parte2-baileys-server/docs/INSTALACAO.md`](parte2-baileys-server/docs/INSTALACAO.md) — instalar o servidor Baileys e como atualizar via `git pull`
