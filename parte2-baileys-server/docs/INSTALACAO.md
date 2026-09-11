# Instalação do Servidor Baileys (Parte 2)

Roda em servidor separado do GLPI, com Node.js 18+.

## Pré-requisitos

```bash
# Ubuntu/Debian
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs

# Verificar versão (precisa ser >= 18)
node --version

# Instalar PM2 (gerenciador de processos)
npm install -g pm2
```

## Instalação

```bash
# 1. Copiar os arquivos para o servidor
cp -r parte2-baileys-server/ /opt/whatsapp-bot/
cd /opt/whatsapp-bot/

# 2. Instalar dependências
npm install

# 3. Criar arquivo de configuração
cp .env.example .env
nano .env   # Edite com suas configurações
```

## Configuração (.env)

```env
PORT=3333
BOT_TOKEN=CRIE_UM_TOKEN_SECRETO_FORTE_AQUI
GLPI_WEBHOOK_URL=https://SEU_GLPI/plugins/whatsappbot/webhook.php
SESSION_DIR=./sessions
LOG_LEVEL=info
MAX_RECONNECT=5
```

> ⚠️ O `BOT_TOKEN` deve ser **idêntico** ao configurado no plugin GLPI.

## Primeiro uso — Escanear QR Code

```bash
# Inicia em modo interativo para escanear o QR
node src/index.js
```

O QR Code aparece no terminal. Escaneie com o WhatsApp:
- Abra o WhatsApp no celular
- Menu (⋮) → **Dispositivos vinculados**
- **Adicionar dispositivo**
- Escaneie o QR Code

Após conectar você verá:
```
✅ WhatsApp conectado com sucesso! | Número: 5511999990000
```

Pressione `Ctrl+C` e inicie via PM2.

## Rodar em produção com PM2

```bash
# Inicia o serviço
pm2 start ecosystem.config.js

# Verifica se está rodando
pm2 status

# Ver logs em tempo real
pm2 logs whatsapp-bot

# Iniciar automaticamente no boot do servidor
pm2 save
pm2 startup
# ↑ Execute o comando que o PM2 mostrar
```

## Verificar que está funcionando

```bash
# Status da conexão
curl -H "x-bot-token: SEU_TOKEN" http://localhost:3333/status

# Resposta esperada:
# {"status":"open","number":"5511999990000","uptime":1234}

# Health check (sem token)
curl http://localhost:3333/health
# {"ok":true,"ts":1705123456789}
```

## Configuração de rede / firewall

O servidor GLPI (Servidor A) precisa acessar este servidor (Servidor B) na porta 3333.

```bash
# Liberar porta no UFW
sudo ufw allow from IP_DO_SERVIDOR_GLPI to any port 3333

# Verificar se a porta está aberta
sudo netstat -tlnp | grep 3333
```

> ✅ **Não abra a porta 3333 para a internet.** Só o servidor GLPI precisa acessar.

## Atualização da sessão após desconexão

Se o WhatsApp desconectar (troca de celular, etc.):

```bash
# Para o bot
pm2 stop whatsapp-bot

# Deleta a sessão antiga
rm -rf sessions/

# Inicia interativo para novo QR
node src/index.js

# Após escanear, reinicia via PM2
pm2 restart whatsapp-bot
```

## Estrutura de arquivos

```
/opt/whatsapp-bot/
├── src/
│   ├── index.js        ← Servidor Express + rotas da API
│   ├── whatsapp.js     ← Conexão Baileys + recebe mensagens
│   ├── middleware.js   ← Autenticação por token
│   └── logger.js       ← Logger Pino
├── sessions/           ← Sessão WhatsApp (criada automaticamente)
├── logs/               ← Logs do servidor
├── ecosystem.config.js ← Configuração PM2
├── .env                ← Suas configurações (não versionar!)
└── package.json
```

## API REST disponível

| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/status` | Estado da conexão WA |
| GET | `/qr` | QR code em base64 |
| GET | `/health` | Health check (sem token) |
| POST | `/send` | Envia mensagem de texto |
| POST | `/send-image` | Envia imagem com legenda |
| POST | `/logout` | Desconecta sessão WA |

Todas as rotas (exceto `/health`) exigem o header `x-bot-token`.

## Logs

```bash
# Logs do PM2
pm2 logs whatsapp-bot --lines 100

# Logs em arquivo
tail -f logs/bot.log
```

Formato dos logs:
```
10:30:00 INFO  Servidor rodando na porta 3333
10:30:02 INFO  QR Code gerado — escaneie com o WhatsApp
10:30:15 INFO  ✅ WhatsApp conectado! | 5511999990000
10:30:45 INFO  Mensagem recebida | from: 5511988880000 | body: oi
10:30:45 INFO  Webhook GLPI respondeu | status: 200
```
