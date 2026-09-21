# Instalação do Servidor Baileys (Parte 2)

Roda em servidor separado do GLPI, com Node.js 18+.

## Pré-requisitos

```bash
# Ubuntu/Debian
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs git

# Verificar versão (precisa ser >= 18)
node --version

# Instalar PM2 (gerenciador de processos)
npm install -g pm2
```

## Instalação (via Git)

O repositório inteiro (plugin GLPI + servidor Baileys) fica num único
repositório Git — clone ele direto no servidor onde o Baileys vai rodar:

```bash
# 1. Clonar o repositório
git clone https://github.com/jhonesmendes/whatsapp-glpi.git
cd whatsapp-glpi/parte2-baileys-server

# 2. Instalar dependências
npm install

# 3. Criar arquivo de configuração (nunca é versionado — fica só nesta VM)
cp .env.example .env
nano .env   # Edite com suas configurações
```

## Atualizando o servidor (git pull)

Sempre que houver uma correção ou funcionalidade nova no repositório,
atualize assim — não precisa copiar arquivo por arquivo:

```bash
cd ~/whatsapp-glpi
git pull
cd parte2-baileys-server
npm install          # só necessário se package.json mudou
pm2 restart whatsapp-bot
```

> O arquivo `.env` (com token e URL do webhook) e a pasta `sessions/`
> (sessão do WhatsApp já conectado) **não fazem parte do repositório**
> — `git pull` nunca mexe neles, então atualizar não desconecta o
> WhatsApp nem apaga suas configurações.

## Configuração (.env)

```env
PORT=3333
BOT_TOKEN=CRIE_UM_TOKEN_SECRETO_FORTE_AQUI
GLPI_WEBHOOK_URL=https://SEU_GLPI/plugins/whatsappbot/webhook.php
SESSION_DIR=./sessions
LOG_LEVEL=info
MAX_RECONNECT=5
```

> ⚠️ O `BOT_TOKEN` deve ser **idêntico** ao configurado no plugin GLPI
> (aba "Conexão", campo "Token de autenticação").

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

## Primeiro uso — Escanear QR Code

Não precisa rodar nada em modo interativo no terminal — o QR code é
exibido direto no navegador, e a própria tela de configuração do
plugin no GLPI já tem um atalho para isso.

**Pelo GLPI** (mais simples): aba "Conexão" → botão **"📷 Ver QR code"**.

**Direto no navegador**, sem passar pelo GLPI:
```
http://IP_DA_VM:3333/qr-view?token=SEU_BOT_TOKEN
```
A página atualiza sozinha até você escanear.

Escaneie com o WhatsApp:
- Abra o WhatsApp no celular
- Menu (⋮) → **Dispositivos vinculados**
- **Adicionar dispositivo**
- Escaneie o QR Code

> ⏱️ O QR code expira depois de alguns minutos (o WhatsApp limita quantas
> vezes ele é renovado). Se isso acontecer, não precisa reiniciar nada —
> o servidor continua tentando gerar um QR novo sozinho a cada 1 minuto
> até alguém escanear. Só recarregue a página do QR depois de um tempo.

## Verificar que está funcionando

```bash
# Status da conexão
curl -H "x-bot-token: SEU_TOKEN" http://localhost:3333/status

# Resposta esperada quando conectado:
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

## Desconectar / trocar o número de WhatsApp

Pelo GLPI: aba "Conexão" → botão **"🔌 Desconectar"** (pede confirmação).
Isso já limpa a sessão antiga e gera um QR novo automaticamente — não
precisa mexer na VM nem apagar a pasta `sessions/` manualmente.

Se preferir fazer direto na VM (equivalente ao botão acima):
```bash
curl -X POST -H "x-bot-token: SEU_TOKEN" http://localhost:3333/logout
```

## Estrutura de arquivos

```
whatsapp-glpi/parte2-baileys-server/
├── src/
│   ├── index.js        ← Servidor Express + rotas da API
│   ├── whatsapp.js     ← Conexão Baileys + recebe mensagens
│   ├── middleware.js   ← Autenticação por token
│   └── logger.js       ← Logger Pino
├── sessions/           ← Sessão WhatsApp (criada automaticamente, fora do git)
├── logs/               ← Logs do servidor (fora do git)
├── ecosystem.config.js ← Configuração PM2
├── .env                ← Suas configurações (fora do git, nunca versionar!)
└── package.json
```

## API REST disponível

| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/status` | Estado da conexão WA |
| GET | `/qr` | QR code em base64 (JSON) |
| GET | `/qr-view?token=...` | QR code como página HTML (para abrir no navegador) |
| GET | `/health` | Health check (sem token) |
| POST | `/send` | Envia mensagem de texto |
| POST | `/send-image` | Envia imagem com legenda |
| POST | `/logout` | Desconecta sessão WA (gera QR novo em seguida) |

Todas as rotas exigem o header `x-bot-token`, exceto `/health` (sem
autenticação) e `/qr-view` (usa `?token=` na própria URL, pensado para
ser aberto direto no navegador).

## Logs

```bash
# Logs do PM2 (avisos, erros, warnings do Node)
pm2 logs whatsapp-bot --lines 100

# Logs estruturados em arquivo (JSON, um evento por linha)
tail -f logs/bot.log
```

Formato dos logs (`logs/bot.log`, JSON por linha):
```json
{"level":30,"time":"...","msg":"Servidor rodando na porta 3333"}
{"level":30,"time":"...","msg":"QR Code gerado — escaneie com o WhatsApp"}
{"level":30,"time":"...","number":"5511999990000","msg":"✅ WhatsApp conectado com sucesso!"}
{"level":30,"time":"...","from":"5511988880000@s.whatsapp.net","body":"oi","msg":"Mensagem recebida"}
{"level":30,"time":"...","status":200,"data":{"status":"ok"},"msg":"Webhook GLPI respondeu"}
```
