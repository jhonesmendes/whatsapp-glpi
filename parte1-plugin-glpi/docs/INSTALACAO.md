# Instalação do Plugin GLPI — WhatsApp Bot

## Pré-requisitos

- GLPI 10.0.x instalado e funcionando
- PHP 8.1+ com extensões: `curl`, `json`, `mbstring`
- Acesso ao servidor para copiar arquivos (normalmente FTP, já que a
  maioria das hospedagens de GLPI não dá acesso SSH/git)

## Onde fica o código-fonte

Todo o projeto (Parte 1 — este plugin — e Parte 2 — servidor Baileys)
vive num único repositório público no GitHub:

```
https://github.com/jhonesmendes/whatsapp-glpi
```

O servidor onde o GLPI roda normalmente **não tem `git`/SSH disponível**
(hospedagem compartilhada), então esta parte **não é clonada direto no
servidor** — o fluxo é: você edita/atualiza localmente (ou puxa do
GitHub para sua máquina), e depois **envia os arquivos alterados por
FTP** para dentro de `glpi/plugins/whatsappbot/` no servidor.

> Se seu servidor GLPI tiver acesso a git (VPS própria, por exemplo),
> pode perfeitamente clonar o repositório direto lá dentro de
> `plugins/whatsappbot/` e usar `git pull` a cada atualização — o fluxo
> por FTP abaixo é só para quem não tem essa opção.

## Passos (primeira instalação)

### 1. Obter o código

```bash
git clone https://github.com/jhonesmendes/whatsapp-glpi.git
```

A pasta `parte1-plugin-glpi/` é o plugin em si.

### 2. Enviar por FTP

Envie **todo o conteúdo** de `parte1-plugin-glpi/` para dentro de:

```
glpi/plugins/whatsappbot/
```

(ou seja, o `setup.php` do plugin deve ficar em
`glpi/plugins/whatsappbot/setup.php`, e assim por diante).

### 3. Ajustar permissões (se tiver acesso ao servidor)

```bash
chown -R www-data:www-data /var/www/html/glpi/plugins/whatsappbot/
chmod -R 755 /var/www/html/glpi/plugins/whatsappbot/
```

Em hospedagem compartilhada isso geralmente já vem certo via FTP; pule
se não tiver esse acesso.

### 4. Instalar no GLPI

1. Acesse o GLPI como administrador
2. Vá em **Configuração → Plugins**
3. Localize **WhatsApp Bot** na lista
4. Clique em **Instalar** (ícone de engrenagem)
5. Depois clique em **Ativar**

Isso cria as tabelas `glpi_plugin_whatsappbot_configs` e
`glpi_plugin_whatsappbot_sessions` no banco.

### 5. Configurar

A configuração é dividida em abas, em **Configuração → Plugins →
WhatsApp Bot**:

- **Conexão** — endereço do servidor Baileys (Parte 2), token de
  autenticação, botões de **Testar conexão**, **Ver QR code** e
  **Desconectar**
- **Integração GLPI** — URL da API REST, App Token, User Token
- **Inteligência Artificial** — chave da OpenAI (usada tanto para
  formatar respostas quanto para categorizar o chamado automaticamente
  com base na descrição digitada pelo usuário)
- **Mensagens do Bot** — todos os textos que o bot manda (boas-vindas,
  pedido de descrição/nome/localização, cobranças do menu, mensagem de
  bloqueio) — editáveis aqui, sem precisar mexer em código
- **Notificação de Técnicos** — quem é avisado quando um chamado novo
  chega
- **Conversas** — lista de atendimentos em andamento, com opção de
  assumir/liberar atendimento humano

#### Conexão com Baileys

| Campo | Descrição | Exemplo |
|-------|-----------|---------|
| URL do servidor Baileys | Endereço do servidor Node.js (Parte 2) | `http://192.168.1.200:3333` |
| Token de autenticação | Token secreto (igual ao `BOT_TOKEN` da Parte 2) | `meu-token-secreto-123` |

Depois de preencher, use **Testar conexão** para confirmar que o
Baileys está de fato conectado ao WhatsApp (não só que o servidor está
de pé), e **Ver QR code** para escanear direto do navegador, sem
precisar entrar na VM do Baileys.

#### API GLPI

1. Habilite a API REST: **Configuração → Geral → API → Habilitar API REST** ✅
2. Crie um App Token em **Configuração → Geral → API → Clientes da API**
3. Obtenha o User Token: **Perfil do usuário → API token** (gere se não existir)
4. O usuário/perfil usado no token precisa de permissão de **Técnico**
   (ou superior) para conseguir ler `ITILCategory`, `Location` e
   `User` pela API — perfis mais baixos (ex.: "Auto-atendimento")
   recebem `ERROR_RIGHT_MISSING`.

| Campo | Valor |
|-------|-------|
| URL da API | `https://seuglpi.com/apirest.php` |
| App Token | Token gerado em Clientes da API |
| User Token | Token do perfil do usuário |

#### OpenAI

1. Crie uma conta em [platform.openai.com](https://platform.openai.com)
2. Gere uma API Key em **API keys → Create new secret key**
3. Preencha no campo **OpenAI API Key**
4. Defina uma **Categoria padrão** de fallback (usada quando a IA não
   consegue identificar uma categoria correspondente na lista do GLPI)

### 6. Configurar webhook no servidor Baileys

A URL do webhook é exibida na aba "Conexão":
```
https://SEU_GLPI/plugins/whatsappbot/webhook.php
```

Copie essa URL e configure na Parte 2 (variável `GLPI_WEBHOOK_URL` do
`.env` do servidor Baileys — veja
[`parte2-baileys-server/docs/INSTALACAO.md`](../../parte2-baileys-server/docs/INSTALACAO.md)).

### 7. Ativar o bot

Marque a opção **Bot ativo** na aba "Conexão" e clique em **Salvar**.

## Atualizando (a cada nova versão)

1. Puxe as novidades do repositório na sua máquina:
   ```bash
   cd whatsapp-glpi
   git pull
   ```
2. Veja quais arquivos mudaram desde o último deploy:
   ```bash
   git log --stat -1
   # ou, comparando duas versões específicas:
   git diff --name-only COMMIT_ANTIGO COMMIT_NOVO -- parte1-plugin-glpi/
   ```
3. Envie **só os arquivos alterados** por FTP para dentro de
   `glpi/plugins/whatsappbot/`, mantendo os mesmos caminhos.
4. Se o commit adicionar colunas novas na tabela de configuração (ver
   `setup.php` — bloco de `ALTER TABLE ... ADD COLUMN`), rode o SQL
   equivalente manualmente via phpMyAdmin **antes** de acessar a tela
   de configuração — o plugin não roda migração automática numa
   instalação já existente. O próprio `setup.php` tem os `ALTER TABLE`
   comentados por versão; copie o trecho relevante.
5. Se sobrar dúvida sobre qual coluna falta, a tela de "Mensagens do
   Bot" agora mostra o erro real do MySQL (ex.:
   `Unknown column 'xyz' in 'field list'`) em vez de dizer "sucesso" —
   use isso para saber exatamente o que rodar no banco.

> Diferente da Parte 2 (que faz `git pull` direto no servidor), aqui
> não existe atalho automático porque a hospedagem não tem `git`. Se
> seu ambiente tiver git/SSH, prefira clonar o repositório direto
> dentro de `plugins/whatsappbot/` e usar `git pull` normalmente.

## Estrutura de arquivos

```
/var/www/html/glpi/plugins/whatsappbot/
├── setup.php                  ← Registro do plugin no GLPI
├── hook.php                   ← Hooks de eventos (chamado resolvido, followup, etc.)
├── webhook.php                ← Endpoint público para receber mensagens do Baileys
├── inc/
│   ├── config.class.php       ← Gerenciamento de configurações (leitura/gravação)
│   ├── bot.class.php          ← Máquina de estados do bot (menu, abertura, consulta, humano)
│   ├── whatsapp.class.php     ← Cliente HTTP para o servidor Baileys
│   ├── glpiapi.class.php      ← Integração com API REST do GLPI (Ticket, Location, ITILCategory...)
│   └── ai.class.php           ← Integração com OpenAI GPT (formatação + categorização)
├── ajax/
│   ├── save.php                ← Salvar configuração (chamado via /ajax/, evita bloqueio de CSRF)
│   ├── test_connection.php     ← Testar conexão com o Baileys
│   └── disconnect.php          ← Botão "Desconectar" (logout do WhatsApp)
└── front/
    ├── config.form.php         ← Aba "Conexão"
    ├── config-glpi.php         ← Aba "Integração GLPI"
    ├── config-ia.php           ← Aba "Inteligência Artificial"
    ├── config-mensagens.php    ← Aba "Mensagens do Bot"
    ├── config-notificacoes.php ← Aba "Notificação de Técnicos"
    └── conversations.php       ← Aba "Conversas"
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
- Verifique se **Bot ativo** está marcado na aba "Conexão"
- Verifique o log: `tail -f /glpi/files/_log/whatsappbot.log`
- Use o botão **Testar conexão** na aba "Conexão" — ele só retorna
  sucesso se o WhatsApp estiver realmente conectado (`status: "open"`),
  não apenas se o servidor Node estiver de pé

**Erro ao criar chamado:**
- Verifique os tokens da API GLPI
- Confirme que o usuário do token tem perfil **Técnico** ou superior
  (perfis básicos não conseguem ler `Location`/`ITILCategory`/`User`)
- Teste manualmente: `curl -H "App-Token: SEU_TOKEN" -H "Authorization: user_token SEU_USER_TOKEN" https://glpi/apirest.php/initSession`

**Mensagens não chegam no GLPI:**
- Confirme que a URL do webhook está correta no servidor Baileys (`GLPI_WEBHOOK_URL`)
- Verifique se o webhook é acessível publicamente (sem firewall bloqueando)
- Confirme que o token enviado pelo Baileys no header `x-bot-token` é igual ao configurado

**"❌ Erro interno: Falha ao salvar no banco: Unknown column..." ao salvar uma aba:**
- Uma atualização adicionou uma coluna nova na tabela de configuração
  que ainda não existe no seu banco — veja a seção "Atualizando"
  acima e rode o `ALTER TABLE` correspondente via phpMyAdmin.

**Tela de QR code trava em "Aguardando QR code..." depois de desconectar:**
- Isso é resolvido do lado da Parte 2 (o servidor Baileys tenta gerar
  um QR novo sozinho, de tempos em tempos) — veja a seção de solução
  de problemas em
  [`parte2-baileys-server/docs/INSTALACAO.md`](../../parte2-baileys-server/docs/INSTALACAO.md).
