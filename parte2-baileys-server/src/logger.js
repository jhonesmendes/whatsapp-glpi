/**
 * logger.js
 * Configuração do Pino — logger estruturado com output legível no terminal
 */

import pino from 'pino';
import fs   from 'fs';
import path from 'path';

const LOG_LEVEL = process.env.LOG_LEVEL || 'info';
const LOG_DIR   = path.join(process.cwd(), 'logs');

// Garante que a pasta de logs existe
if (!fs.existsSync(LOG_DIR)) {
  fs.mkdirSync(LOG_DIR, { recursive: true });
}

const logFile = path.join(LOG_DIR, 'bot.log');

// Em produção: JSON puro para arquivo + formatado para terminal
// Em dev: pino-pretty no terminal
const isDev = process.env.NODE_ENV !== 'production';

export const logger = pino(
  {
    level     : LOG_LEVEL,
    timestamp : pino.stdTimeFunctions.isoTime,
    base      : { pid: process.pid },
  },
  isDev
    // Dev: formata no terminal
    ? pino.transport({
        target  : 'pino-pretty',
        options : {
          colorize      : true,
          translateTime : 'SYS:HH:MM:ss',
          ignore        : 'pid,hostname',
        },
      })
    // Produção: escreve em arquivo (redirecione stdout para o arquivo via pm2 ou Docker)
    : pino.destination({ dest: logFile, sync: false })
);
