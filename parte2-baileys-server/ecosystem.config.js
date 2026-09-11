/**
 * ecosystem.config.js — Configuração PM2
 *
 * Uso:
 *   pm2 start ecosystem.config.js
 *   pm2 save
 *   pm2 startup   ← para iniciar automaticamente no boot
 */

module.exports = {
  apps: [
    {
      name        : 'whatsapp-bot',
      script      : 'src/index.js',
      interpreter : 'node',
      node_args   : '--experimental-vm-modules',

      // Ambiente
      env: {
        NODE_ENV: 'production',
      },

      // Reconexão automática
      watch         : false,
      max_restarts  : 10,
      restart_delay : 5000,
      min_uptime    : '10s',

      // Logs via PM2
      out_file      : './logs/pm2-out.log',
      error_file    : './logs/pm2-err.log',
      merge_logs    : true,
      log_date_format: 'YYYY-MM-DD HH:mm:ss',

      // Limpa logs antigos (mantém últimos 7 dias)
      max_memory_restart: '300M',
    }
  ]
};
