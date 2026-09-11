/**
 * middleware.js
 * Middleware de autenticação: valida o token enviado no header x-bot-token.
 * O mesmo token deve estar configurado no plugin GLPI.
 */

import { logger } from './logger.js';

const BOT_TOKEN = process.env.BOT_TOKEN || '';

export function authMiddleware(req, res, next) {
  // Se não há token configurado, permite tudo (modo desenvolvimento)
  if (!BOT_TOKEN) {
    logger.warn('BOT_TOKEN não configurado — requisições sem autenticação são aceitas');
    return next();
  }

  const sentToken = req.headers['x-bot-token'] || '';

  if (sentToken !== BOT_TOKEN) {
    logger.warn({ ip: req.ip, path: req.path }, 'Requisição rejeitada: token inválido');
    return res.status(401).json({ error: 'Unauthorized' });
  }

  next();
}
