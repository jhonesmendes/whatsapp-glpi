/*
 * Roda no contexto da própria página do WhatsApp Web ("world": "MAIN"),
 * onde dá pra consultar os módulos internos do app e saber qual conversa
 * está aberta. O content script (atendimento.js) não enxerga esses
 * módulos, então o resultado é deixado no atributo data-ff-chat do <html>.
 *
 * Exporta todos os IDs conhecidos do contato (ex: "123@lid" e
 * "5565...@c.us"): o bot grava o que o Baileys recebeu, que pode ser
 * qualquer um dos dois.
 */
(() => {
  'use strict';

  function modulo(nome) {
    try {
      return typeof window.require === 'function' ? window.require(nome) : null;
    } catch (_) {
      return null;
    }
  }

  function texto(wid) {
    if (!wid) return null;
    if (typeof wid === 'string') return wid;
    return wid._serialized || (typeof wid.toString === 'function' ? wid.toString() : null);
  }

  function chatAberto() {
    const mod = modulo('WAWebChatCollection');
    const colecao = mod && (mod.ChatCollection || mod.default);
    if (!colecao) return null;
    try {
      const c = colecao.getActive && colecao.getActive();
      if (c) return c;
    } catch (_) {}
    try {
      const lista = colecao.getModelsArray ? colecao.getModelsArray() : (colecao._models || colecao.models || []);
      return lista.find((c) => c.active) || null;
    } catch (_) {
      return null;
    }
  }

  function idsDoChat() {
    const chat = chatAberto();
    if (!chat || !chat.id) return [];
    const ids = [texto(chat.id)];
    const api = modulo('WAWebApiContact');
    const tentar = (fn) => { try { ids.push(texto(fn())); } catch (_) {} };
    if (api) {
      tentar(() => api.getCurrentLid && api.getCurrentLid(chat.id));
      tentar(() => api.getPhoneNumber && api.getPhoneNumber(chat.id));
    }
    tentar(() => chat.contact && chat.contact.phoneNumber);
    tentar(() => chat.contact && chat.contact.lid);
    return [...new Set(ids)].filter((j) => j && /@(lid|c\.us|s\.whatsapp\.net)$/.test(j));
  }

  setInterval(() => {
    let valor = '';
    try { valor = idsDoChat().join(','); } catch (_) {}
    const raiz = document.documentElement;
    if (raiz.getAttribute('data-ff-chat') !== valor) raiz.setAttribute('data-ff-chat', valor);
  }, 700);
})();
