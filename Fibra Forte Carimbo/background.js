// Na primeira instalação, abre a tela de cadastro do nome.
chrome.runtime.onInstalled.addListener((details) => {
  if (details.reason === 'install') {
    chrome.runtime.openOptionsPage();
  }
});

// Aceita tanto a URL completa do endpoint quanto só o endereço do GLPI.
function urlDoEndpoint(url) {
  url = (url || '').trim().replace(/\/+$/, '');
  if (!url) return '';
  if (/\.php$/i.test(url)) return url;
  return url + '/plugins/whatsappbot/extension.php';
}

// Chamadas ao GLPI saem daqui (não do content script): a extensão tem
// permissão para o domínio do GLPI e não esbarra no CORS do WhatsApp Web.
async function chamarGlpi(corpo) {
  const cfg = await chrome.storage.sync.get({ glpiUrl: '', glpiToken: '' });
  const url = urlDoEndpoint(cfg.glpiUrl);
  if (!url || !cfg.glpiToken) {
    return { ok: false, naoConfigurado: true, message: 'Configure a URL e o token do GLPI na extensão.' };
  }
  try {
    const resp = await fetch(url, {
      method: 'POST',
      // "text/plain" de propósito (igual ao webhook do Baileys): com
      // "application/json" o GLPI joga o corpo em $_POST e bloqueia com
      // "Acesso negado" por falta de token CSRF de sessão.
      headers: { 'Content-Type': 'text/plain', 'X-Ext-Token': cfg.glpiToken },
      body: JSON.stringify(corpo)
    });
    const texto = await resp.text();
    try {
      return JSON.parse(texto);
    } catch (_) {
      // Mostra o que veio no lugar do JSON (página de login, 404 do GLPI,
      // aviso do PHP...) — sem isso não dá pra saber o que deu errado.
      const trecho = texto.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
      console.warn('[Atendimento] resposta não-JSON de', resp.url, '\n', texto.slice(0, 2000));
      return {
        ok: false,
        message: `Resposta inválida do GLPI (HTTP ${resp.status}` +
          (resp.redirected ? `, redirecionado para ${resp.url}` : '') + `): ${trecho || '(vazia)'}`
      };
    }
  } catch (e) {
    return { ok: false, message: 'Sem conexão com o GLPI: ' + e.message };
  }
}

chrome.runtime.onMessage.addListener((msg, _remetente, responder) => {
  if (msg && msg.tipo === 'glpi') {
    chamarGlpi(msg.corpo).then(responder);
    return true; // resposta assíncrona
  }
});
