# Instalação do Plugin GLPI — WhatsApp Bot

## Pré-requisitos

- GLPI 10.0.x instalado e funcionando
- PHP 8.1+ com extensões: `curl`, `json`, `mbstring`
- Acesso ao servidor para copiar arquivos

## Passos

### 1. Copiar o plugin

```bash
# Copie a pasta parte1-plugin-glpi para dentro do GLPI
cp -r parte1-plugin-glpi/ /var/www/html/glpi/plugins/whatsappbot/
```

### 2. Ajustar permissões

```bash
chown -R www-data:www-data /var/www/html/glpi/plugins/whatsappbot/
chmod -R 755 /var/www/html/glpi/plugins/whatsappbot/
```

### 3. Instalar no GLPI

1. Acesse o GLPI como administrador
2. Vá em **Configuração → Plugins**
3. Localize **WhatsApp Bot** na lista
4. Clique em **Instalar** (ícone de engrenagem)
5. Depois clique em **Ativar**

### 4. Configurar

Vá em **Configuração → Plugins → WhatsApp Bot → Configuração** e preencha:

#### Conexão com Baileys

| Campo | Descrição | Exemplo |
|-------|-----------|---------|
| URL do servidor Baileys | Endereço do servidor Node.js (Parte 2) | `http://192.168.1.200:3333` |
| Token de autenticação | Token secreto (igual ao configurado na Parte 2) | `meu-token-secreto-123` |
| Número WhatsApp | Número conectado no Baileys | `+55 11 99999-0000` |

#### API GLPI

1. Habilite a API REST: **Configuração → Geral → API → Habilitar API REST** ✅
2. Crie um App Token em **Configuração → Geral → API → Clientes da API**
3. Obtenha o User Token: **Perfil do usuário → API token** (gere se não existir)

| Campo | Valor |
|-------|-------|
| URL da API | `https://seuglpi.com/apirest.php` |
| App Token | Token gerado em Clientes da API |
| User Token | Token do perfil do usuário |

#### OpenAI

1. Crie uma conta em [platform.openai.com](https://platform.openai.com)
2. Gere uma API Key em **API keys → Create new secret key**
3. Preencha no campo **OpenAI API Key**

### 5. Configurar webhook no servidor Baileys

A URL do webhook é exibida na tela de configuração:
```
https://SEU_GLPI/plugins/whatsappbot/webhook.php
```

Copie essa URL e configure na Parte 2 (arquivo `.env` do servidor Baileys).

### 6. Ativar o bot

Marque a opção **Bot ativo** na configuração e clique em **Salvar**.

## Estrutura de arquivos

```
/var/www/html/glpi/plugins/whatsappbot/
├── setup.php              ← Registro do plugin no GLPI
├── hook.php               ← Hooks de eventos (chamado resolvido, etc.)
├── webhook.php            ← Endpoint público para receber mensagens
├── inc/
│   ├── config.class.php   ← Gerenciamento de configurações
│   ├── bot.class.php      ← Lógica principal do bot
│   ├── whatsapp.class.php ← Cliente HTTP para o servidor Baileys
│   ├── glpiapi.class.php  ← Integração com API REST do GLPI
│   └── ai.class.php       ← Integração com OpenAI GPT
└── front/
    ├── config.form.php    ← Tela de configuração no GLPI
    └── conversations.php  ← Lista de conversas ativas
```

## Verificação de logs

```bash
tail -f /var/www/html/glpi/files/_log/whatsappbot.log
```

Formato de log:
```
2024-01-15 10:30:00 IN  [5511999990000] oi
2024-01-15 10:30:01 OUT [5511999990000] Olá! Bem-vindo...
2024-01-15 10:30:02 API GET /Ticket HTTP 200
2024-01-15 10:30:03 AI  Tokens usados: 287
```

## Solução de problemas

**Bot não responde:**
- Verifique se `is_active = 1` nas configurações
- Verifique o log: `tail -f /glpi/files/_log/whatsappbot.log`
- Teste a conexão Baileys na tela de configuração

**Erro ao criar chamado:**
- Verifique os tokens da API GLPI
- Teste manualmente: `curl -H "App-Token: SEU_TOKEN" -H "Authorization: user_token SEU_USER_TOKEN" https://glpi/apirest.php/initSession`

**Mensagens não chegam no GLPI:**
- Confirme que a URL do webhook está correta no servidor Baileys
- Verifique se o webhook é acessível publicamente (sem firewall bloqueando)
- Confirme que o token enviado pelo Baileys no header `x-bot-token` é igual ao configurado
