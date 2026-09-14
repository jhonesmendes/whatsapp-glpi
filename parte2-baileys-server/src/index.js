/**
 * WhatsApp Bot — Servidor Baileys (Parte 2)
 * Roda em servidor separado do GLPI.
 *
 * Responsabilidades:
 * 1. Manter sessão WhatsApp Web ativa via Baileys
 * 2. Receber mensagens do WhatsApp → repassar para o GLPI (webhook)
 * 3. Receber chamadas da API REST do GLPI → enviar mensagens ao usuário
 */

import 'dotenv/config';
import express    from 'express';
import QRCode     from 'qrcode';
import { logger } from './logger.js';
import { createBot, getClient } from './whatsapp.js';
import { authMiddleware } from './middleware.js';

const app  = express();
const PORT = process.env.PORT || 3333;
const BOT_TOKEN = process.env.BOT_TOKEN || '';

app.use(express.json());

// ---------------------------------------------------------------
// Rotas da API REST (chamadas pelo GLPI)
// ---------------------------------------------------------------

/**
 * GET /status
 * Retorna estado da conexão WhatsApp
 */
app.get('/status', authMiddleware, (req, res) => {
  const client = getClient();
  if (!client) {
    return res.json({ status: 'disconnected', number: null });
  }
  res.json({
    status : client.connectionState || 'unknown',
    number : client.user?.id?.split(':')[0] || null,
    uptime : process.uptime(),
  });
});

/**
 * GET /qr
 * Retorna QR code atual em base64 (para exibir no painel GLPI)
 */
app.get('/qr', authMiddleware, (req, res) => {
  const { qrCode } = getClient() || {};
  if (!qrCode) {
    return res.status(404).json({ error: 'QR code não disponível. Já conectado ou aguardando.' });
  }
  res.json({ qr: qrCode });
});

/**
 * POST /send
 * Envia mensagem de texto para um número WhatsApp
 * Body: { to: "5511999990000@s.whatsapp.net", text: "Mensagem aqui" }
 */
app.post('/send', authMiddleware, async (req, res) => {
  const { to, text } = req.body;

  if (!to || !text) {
    return res.status(400).json({ error: 'Campos obrigatórios: to, text' });
  }

  try {
    const client = getClient();
    if (!client?.sock) {
      return res.status(503).json({ error: 'WhatsApp não conectado' });
    }

    // Garante formato JID correto
    const jid = to.includes('@') ? to : to + '@s.whatsapp.net';

    await client.sock.sendMessage(jid, { text });
    logger.info({ to: jid, len: text.length }, 'Mensagem enviada');
    res.json({ ok: true });
  } catch (err) {
    logger.error({ err, to }, 'Erro ao enviar mensagem');
    res.status(500).json({ error: err.message });
  }
});

/**
 * POST /send-image
 * Envia imagem com legenda opcional
 * Body: { to, url, caption }
 */
app.post('/send-image', authMiddleware, async (req, res) => {
  const { to, url, caption = '' } = req.body;

  if (!to || !url) {
    return res.status(400).json({ error: 'Campos obrigatórios: to, url' });
  }

  try {
    const client = getClient();
    if (!client?.sock) {
      return res.status(503).json({ error: 'WhatsApp não conectado' });
    }

    const jid = to.includes('@') ? to : to + '@s.whatsapp.net';
    await client.sock.sendMessage(jid, { image: { url }, caption });
    res.json({ ok: true });
  } catch (err) {
    logger.error({ err }, 'Erro ao enviar imagem');
    res.status(500).json({ error: err.message });
  }
});

/**
 * POST /logout
 * Desconecta a sessão WhatsApp (força novo QR)
 */
app.post('/logout', authMiddleware, async (req, res) => {
  try {
    const client = getClient();
    if (client?.sock) {
      await client.sock.logout();
    }
    res.json({ ok: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

/**
 * GET /qr-view
 * Página HTML simples para escanear o QR code pelo navegador.
 * Autenticação via ?token= (query), já que é acessado direto pelo navegador.
 * Auto-atualiza sozinha até a conexão ser estabelecida.
 */
app.get('/qr-view', async (req, res) => {
  if (BOT_TOKEN && req.query.token !== BOT_TOKEN) {
    return res.status(401).send('Unauthorized — informe ?token=SEU_BOT_TOKEN na URL');
  }

  const client = getClient();

  if (client?.connectionState === 'open') {
    const number = client.user?.id?.split(':')[0] || 'desconhecido';
    return res.send(`
      <html><head><meta charset="utf-8"><title>WhatsApp conectado</title></head>
      <body style="font-family:sans-serif;text-align:center;margin-top:80px">
        <h2>✅ WhatsApp conectado</h2>
        <p>Número: <strong>${number}</strong></p>
      </body></html>
    `);
  }

  if (!client?.qrCode) {
    return res.send(`
      <html><head><meta charset="utf-8"><meta http-equiv="refresh" content="3">
      <title>Aguardando QR code</title></head>
      <body style="font-family:sans-serif;text-align:center;margin-top:80px">
        <h2>Aguardando QR code...</h2>
        <p>Esta página atualiza sozinha.</p>
      </body></html>
    `);
  }

  try {
    const dataUrl = await QRCode.toDataURL(client.qrCode, { width: 320 });
    res.send(`
      <html><head><meta charset="utf-8"><meta http-equiv="refresh" content="20">
      <title>Escaneie o QR code</title></head>
      <body style="font-family:sans-serif;text-align:center;margin-top:40px">
        <h2>Escaneie com o WhatsApp</h2>
        <p>WhatsApp &gt; Aparelhos conectados &gt; Conectar aparelho</p>
        <img src="${dataUrl}" alt="QR Code" style="width:320px;height:320px" />
        <p style="color:#888">Esta página atualiza sozinha a cada 20s.</p>
      </body></html>
    `);
  } catch (err) {
    logger.error({ err }, 'Erro ao gerar imagem do QR code');
    res.status(500).send('Erro ao gerar QR code');
  }
});

// Health check sem autenticação (para monitoramento)
app.get('/health', (req, res) => res.json({ ok: true, ts: Date.now() }));

// 404
app.use((req, res) => res.status(404).json({ error: 'Route not found' }));

// ---------------------------------------------------------------
// Inicialização
// ---------------------------------------------------------------

app.listen(PORT, async () => {
  logger.info(`Servidor rodando na porta ${PORT}`);

  // Inicia a conexão WhatsApp
  await createBot();
});

// Captura erros não tratados para não derrubar o processo
process.on('uncaughtException',  (err) => logger.error({ err }, 'uncaughtException'));
process.on('unhandledRejection', (err) => logger.error({ err }, 'unhandledRejection'));
