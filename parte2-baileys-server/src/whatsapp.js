/**
 * whatsapp.js
 * Gerencia a conexão WhatsApp via Baileys.
 * Recebe mensagens e as encaminha para o GLPI via webhook.
 */

import makeWASocket, {
  DisconnectReason,
  useMultiFileAuthState,
  fetchLatestBaileysVersion,
  makeCacheableSignalKeyStore,
} from '@whiskeysockets/baileys';
import { Boom }   from '@hapi/boom';
import axios      from 'axios';
import qrcode     from 'qrcode-terminal';
import { logger } from './logger.js';

const SESSION_DIR  = process.env.SESSION_DIR   || './sessions';
const WEBHOOK_URL  = process.env.GLPI_WEBHOOK_URL;
const BOT_TOKEN    = process.env.BOT_TOKEN     || '';
const MAX_RECONNECT = parseInt(process.env.MAX_RECONNECT || '5');

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
          logger.error('Número máximo de reconexões atingido. Reinicie o servidor manualmente.');
        }
      } else {
        logger.warn('Sessão encerrada (logout). Delete a pasta sessions/ e reinicie para um novo QR.');
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

      if (!body) continue;

      logger.info({ from, body: body.substring(0, 80) }, 'Mensagem recebida');

      // Encaminha para o GLPI
      await forwardToGlpi({ from, body, pushName, msgId: msg.key.id });
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
