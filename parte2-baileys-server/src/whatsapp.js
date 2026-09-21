/**
 * whatsapp.js
 * Gerencia a conexão WhatsApp via Baileys.
 * Recebe mensagens e as encaminha para o GLPI via webhook.
 */

import {
  makeWASocket,
  DisconnectReason,
  useMultiFileAuthState,
  fetchLatestBaileysVersion,
  makeCacheableSignalKeyStore,
  downloadMediaMessage,
} from '@whiskeysockets/baileys';
import { Boom }   from '@hapi/boom';
import axios      from 'axios';
import qrcode     from 'qrcode-terminal';
import fs         from 'fs';
import { logger } from './logger.js';

const SESSION_DIR  = process.env.SESSION_DIR   || './sessions';
const WEBHOOK_URL  = process.env.GLPI_WEBHOOK_URL;
const BOT_TOKEN    = process.env.BOT_TOKEN     || '';
const MAX_RECONNECT = parseInt(process.env.MAX_RECONNECT || '5');
// Limite de tamanho do anexo (imagem/documento) encaminhado ao GLPI. O
// arquivo vai embutido em base64 dentro do JSON do webhook — hospedagens
// compartilhadas costumam ter post_max_size baixo (8MB é comum), e o
// base64 já adiciona ~33% de overhead sobre o tamanho original.
const MAX_MEDIA_BYTES = parseInt(process.env.MAX_MEDIA_BYTES || String(5 * 1024 * 1024));

let clientState = {
  sock            : null,
  qrCode          : null,
  connectionState : 'disconnected',
  user            : null,
  reconnectCount  : 0,
};

/**
 * Retorna o estado atual do cliente
 */
export function getClient() {
  return clientState;
}

/**
 * Cria e inicializa a conexão Baileys
 */
export async function createBot() {
  logger.info('Iniciando conexão WhatsApp...');

  const { state, saveCreds } = await useMultiFileAuthState(SESSION_DIR);
  const { version }          = await fetchLatestBaileysVersion();

  logger.info({ version }, 'Versão Baileys carregada');

  const sock = makeWASocket({
    version,
    logger : logger.child({ module: 'baileys' }),
    auth   : {
      creds      : state.creds,
      keys       : makeCacheableSignalKeyStore(state.keys, logger.child({ module: 'signal' })),
    },
    printQRInTerminal  : false, // controlamos manualmente
    generateHighQualityLinkPreview: false,
    markOnlineOnConnect: true,
  });

  clientState.sock = sock;

  // ---------------------------------------------------------------
  // Evento: atualização de credenciais (salva sessão no disco)
  // ---------------------------------------------------------------
  sock.ev.on('creds.update', saveCreds);

  // ---------------------------------------------------------------
  // Evento: atualização de conexão
  // ---------------------------------------------------------------
  sock.ev.on('connection.update', async (update) => {
    const { connection, lastDisconnect, qr } = update;

    // QR Code disponível para scan
    if (qr) {
      clientState.qrCode = qr;
      clientState.connectionState = 'qr';
      logger.info('QR Code gerado — escaneie com o WhatsApp');
      // Exibe no terminal para facilitar no primeiro setup
      qrcode.generate(qr, { small: true });
    }

    if (connection === 'close') {
      clientState.connectionState = 'disconnected';
      clientState.sock = null;
      clientState.qrCode = null;

      const statusCode = (lastDisconnect?.error instanceof Boom)
        ? lastDisconnect.error.output?.statusCode
        : null;

      const shouldReconnect = statusCode !== DisconnectReason.loggedOut;

      logger.warn({ statusCode, shouldReconnect }, 'Conexão encerrada');

      if (shouldReconnect) {
        clientState.reconnectCount++;
        if (clientState.reconnectCount <= MAX_RECONNECT) {
          const delay = Math.min(clientState.reconnectCount * 3000, 30000);
          logger.info({ attempt: clientState.reconnectCount, delay }, 'Reconectando...');
          setTimeout(createBot, delay);
        } else {
          // Comum quando o QR expira (Baileys só permite renovar um número
          // limitado de vezes) e ninguém escaneia a tempo — antes disso
          // desistia de vez e exigia reiniciar o servidor manualmente, o
          // que travava a tela de QR do GLPI em "Aguardando QR code..."
          // pra sempre. Em vez de desistir, zera o contador e continua
          // tentando de tempos em tempos (1 min), sem precisar de reinício.
          logger.error('Número máximo de reconexões atingido — continuando a tentar a cada 1 minuto.');
          clientState.reconnectCount = 0;
          setTimeout(createBot, 60000);
        }
      } else {
        // Logout de verdade (usuário desvinculou pelo celular, ou clicou em
        // "Desconectar" no GLPI): antes isso só avisava no log e exigia
        // reiniciar o servidor manualmente + apagar a pasta de sessão à
        // mão, deixando a tela de QR do GLPI travada em "Aguardando QR
        // code..." pra sempre. Agora limpa as credenciais velhas sozinho e
        // já gera um QR novo, sem precisar mexer no servidor.
        logger.warn('Sessão encerrada (logout). Limpando credenciais antigas e gerando novo QR automaticamente...');
        try {
          await fs.promises.rm(SESSION_DIR, { recursive: true, force: true });
        } catch (err) {
          logger.error({ err }, 'Erro ao limpar pasta de sessão');
        }
        clientState.reconnectCount = 0;
        setTimeout(createBot, 2000);
      }
    }

    if (connection === 'open') {
      clientState.connectionState = 'open';
      clientState.user  = sock.user;
      clientState.qrCode = null;
      clientState.reconnectCount = 0;

      const number = sock.user?.id?.split(':')[0] || 'desconhecido';
      logger.info({ number }, '✅ WhatsApp conectado com sucesso!');
    }
  });

  // ---------------------------------------------------------------
  // Evento: mensagens recebidas
  // ---------------------------------------------------------------
  sock.ev.on('messages.upsert', async ({ messages, type }) => {
    // Processa apenas mensagens novas de chats
    if (type !== 'notify') return;

    for (const msg of messages) {
      // Ignora mensagens enviadas pelo próprio bot
      if (msg.key.fromMe) continue;

      // Ignora mensagens de grupos
      if (msg.key.remoteJid?.endsWith('@g.us')) continue;

      // Ignora mensagens de status do WhatsApp
      if (msg.key.remoteJid === 'status@broadcast') continue;

      const from     = msg.key.remoteJid || '';
      const body     = extractMessageText(msg);
      const pushName = msg.pushName || '';
      const media    = await extractMedia(msg);

      // Antes exigia texto sempre — uma imagem/documento sem legenda vinha
      // com body vazio e era descartado silenciosamente. Agora só descarta
      // se não tiver nem texto nem anexo.
      if (!body && !media) continue;

      // Quando o contato usa a privacidade "@lid" do WhatsApp, o remoteJid
      // é um identificador pseudônimo, não o número de telefone real.
      // Baileys às vezes expõe o número real num campo alternativo — tenta
      // achar em qualquer um dos nomes conhecidos, sem garantia (depende
      // da versão do Baileys e de como o WhatsApp entregou a mensagem).
      const fromReal =
        msg.key.remoteJidAlt ||
        msg.key.senderPn ||
        msg.key.participantAlt ||
        null;

      logger.info({ from, fromReal, body: body.substring(0, 80), hasMedia: !!media }, 'Mensagem recebida');

      // Encaminha para o GLPI
      await forwardToGlpi({ from, fromReal, body, pushName, msgId: msg.key.id, media });
    }
  });

  return sock;
}

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------

/**
 * Extrai o texto de uma mensagem (suporta texto simples, resposta, lista, botão)
 */
function extractMessageText(msg) {
  const m = msg.message;
  if (!m) return '';

  return (
    m.conversation ||
    m.extendedTextMessage?.text ||
    m.imageMessage?.caption ||
    m.videoMessage?.caption ||
    m.listResponseMessage?.singleSelectReply?.selectedRowId ||
    m.buttonsResponseMessage?.selectedButtonId ||
    m.templateButtonReplyMessage?.selectedId ||
    ''
  );
}

/**
 * Baixa a mídia (imagem ou documento) de uma mensagem, se houver, e devolve
 * em base64 pronta pra ir dentro do JSON do webhook. Retorna null se a
 * mensagem não tem mídia, se o download falhar, ou se o arquivo passar do
 * limite configurado (MAX_MEDIA_BYTES) — nesses casos o texto/legenda ainda
 * é processado normalmente, só o anexo em si é descartado.
 */
async function extractMedia(msg) {
  const m = msg.message;
  if (!m) return null;

  const imageMsg    = m.imageMessage;
  const documentMsg = m.documentMessage;
  if (!imageMsg && !documentMsg) return null;

  try {
    const buffer = await downloadMediaMessage(msg, 'buffer', {});
    if (buffer.length > MAX_MEDIA_BYTES) {
      logger.warn({ size: buffer.length, limit: MAX_MEDIA_BYTES }, 'Anexo maior que o limite — descartado');
      return { tooLarge: true };
    }

    const mimetype = imageMsg?.mimetype || documentMsg?.mimetype || 'application/octet-stream';
    const filename = documentMsg?.fileName
      || `imagem_${msg.key.id}.${(mimetype.split('/')[1] || 'jpg').split(';')[0]}`;

    return { base64: buffer.toString('base64'), mimetype, filename };
  } catch (err) {
    logger.error({ err }, 'Erro ao baixar mídia do WhatsApp');
    return null;
  }
}

/**
 * Envia a mensagem recebida para o webhook do GLPI.
 * Força IPv4 (family: 4): esta VM tem IPv6 sem rota de saída
 * (ENETUNREACH), fazendo o Node perder tempo tentando IPv6 antes de
 * cair pro IPv4 — causava timeouts intermitentes. Tenta de novo uma
 * vez em caso de falha de rede, pois mensagens perdidas aqui nunca
 * chegam ao GLPI.
 */
async function forwardToGlpi(payload, attempt = 1) {
  if (!WEBHOOK_URL) {
    logger.warn('GLPI_WEBHOOK_URL não configurada — mensagem descartada');
    return;
  }

  try {
    // Content-Type "text/plain" de propósito, não "application/json": o
    // núcleo do GLPI decodifica corpos JSON automaticamente para dentro de
    // $_POST, o que aciona a checagem de CSRF do bootstrap (inc/includes.php)
    // exigindo um token que este webhook, chamado de fora sem sessão de
    // navegador, nunca teria como enviar. webhook.php já lê o corpo bruto
    // via php://input e decodifica o JSON manualmente, então o Content-Type
    // aqui não precisa refletir o formato real do corpo.
    const resp = await axios.post(WEBHOOK_URL, payload, {
      timeout      : 15000,
      family       : 4,
      headers      : {
        'Content-Type' : 'text/plain',
        'x-bot-token'  : BOT_TOKEN,
      },
    });
    logger.info({ status: resp.status, data: resp.data }, 'Webhook GLPI respondeu');
  } catch (err) {
    const status  = err.response?.status;
    const message = err.response?.data || err.message;

    if (attempt < 2) {
      logger.warn({ status, message, attempt }, 'Erro ao chamar webhook GLPI — tentando novamente');
      await new Promise((r) => setTimeout(r, 2000));
      return forwardToGlpi(payload, attempt + 1);
    }

    logger.error({ status, message, payload }, 'Erro ao chamar webhook GLPI (desistindo após retry)');
  }
}
