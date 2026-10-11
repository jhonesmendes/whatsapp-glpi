/*
 * Atendimento - WhatsApp Web (v1.2.1)
 * Mostra, na conversa aberta, se o bot do GLPI está atendendo e permite
 * assumir (parar o bot) ou devolver ao bot sem abrir o GLPI.
 *
 * A conversa é identificada pelo JID (ex: "123456@lid"), o mesmo que o bot
 * grava no GLPI, lido dos módulos internos do WhatsApp Web por pagina.js.
 * O nome do cabeçalho só é usado como plano B.
 */
(() => {
  'use strict';

  const LOG = (...a) => console.log('%c[Atendimento]', 'color:#008069;font-weight:bold', ...a);

  const estado = {
    chave: '',        // conversa aberta (título + JID)
    sessao: null,     // resposta do GLPI para a conversa aberta
    seq: 0,           // descarta respostas de conversas anteriores
    ocupado: false,
    configurado: true,
    nome: ''
  };

  chrome.storage.sync.get({ nome: '' }, (cfg) => { estado.nome = (cfg.nome || '').trim(); });
  chrome.storage.onChanged.addListener((mud) => {
    if (mud.nome) estado.nome = (mud.nome.newValue || '').trim();
    if (mud.glpiUrl || mud.glpiToken) { estado.chave = ''; } // força reconsulta
  });

  // ---------- Conversa aberta ----------
  const RE_DATA_ID = /^(?:true|false)_([^_]+@(?:lid|c\.us|s\.whatsapp\.net))_/;

  // Área da conversa aberta. "#main" é o id histórico do WhatsApp Web;
  // os outros são planos B caso o layout mude.
  function areaConversa() {
    const main = document.getElementById('main');
    if (main) return main;
    const editor = document.querySelector('footer [contenteditable="true"]');
    return editor ? editor.closest('[role="application"], div[tabindex="-1"]') || document.body : null;
  }

  function jidsDaConversa() {
    // 1) IDs vindos do próprio app (pagina.js) — o meio mais confiável
    const jids = (document.documentElement.getAttribute('data-ff-chat') || '')
      .split(',').filter(Boolean);

    // 2) data-id das mensagens (versões antigas do WhatsApp Web)
    const area = areaConversa();
    if (area) {
      const els = area.querySelectorAll('[data-id]');
      for (let i = els.length - 1, n = 0; i >= 0 && n < 5; i--) {
        const m = RE_DATA_ID.exec(els[i].getAttribute('data-id') || '');
        if (m && !jids.includes(m[1])) { jids.push(m[1]); n++; }
      }
    }

    // 3) contato não salvo: o título é o próprio número ("+55 19 99956-7289")
    const titulo = tituloDaConversa();
    if (/^\+?[\d\s().-]{8,}$/.test(titulo)) {
      const jid = titulo.replace(/\D/g, '') + '@c.us';
      if (!jids.includes(jid)) jids.push(jid);
    }
    return jids;
  }

  function cabecalho() {
    const area = areaConversa();
    if (!area) return null;
    return area.querySelector('header') ||
      document.querySelector('[data-testid="conversation-header"]') ||
      area.querySelector('[role="banner"]');
  }

  function tituloDaConversa() {
    const h = cabecalho();
    if (!h) return '';
    const span = h.querySelector('span[dir="auto"]');
    const texto = span ? span.textContent : (h.innerText || '').split('\n')[0];
    return (texto || '').trim();
  }

  // ---------- Comunicação com o GLPI (via background.js) ----------
  async function glpi(corpo) {
    try {
      return await chrome.runtime.sendMessage({ tipo: 'glpi', corpo });
    } catch (e) {
      // extensão recarregada/atualizada com a aba aberta
      return { ok: false, message: 'Extensão atualizada: recarregue o WhatsApp Web (F5).' };
    }
  }

  async function consultar(silencioso) {
    const seq = ++estado.seq;
    const jids = jidsDaConversa();
    const name = tituloDaConversa();
    if (!silencioso) desenhar({ carregando: true });
    const resp = await glpi({ action: 'status', jids, name });
    if (seq !== estado.seq) return;
    estado.configurado = !(resp && resp.naoConfigurado);
    estado.sessao = resp;
    LOG('conversa:', JSON.stringify(name), '| JIDs:', jids, '| GLPI:', resp);
    desenhar(resp || { ok: false, message: 'Sem resposta do GLPI' });
  }

  async function executar(action) {
    const s = estado.sessao;
    if (!s || !s.found || estado.ocupado) return;
    if (action === 'release' &&
        !confirm(`Devolver ${s.wa_name || 'esta conversa'} ao bot?\n\nO usuário recebe a mensagem "Atendimento encerrado pelo técnico".`)) {
      return;
    }
    estado.ocupado = true;
    const seq = ++estado.seq;
    desenhar({ carregando: true });
    const resp = await glpi({ action, wa_number: s.wa_number, agent: estado.nome || 'WhatsApp Web' });
    estado.ocupado = false;
    if (seq !== estado.seq) return;
    if (resp && resp.ok) {
      LOG(action === 'take' ? 'conversa assumida' : 'conversa devolvida ao bot', s.wa_number);
      estado.sessao = resp;
      desenhar(resp);
    } else {
      desenhar(s);
      aviso((resp && resp.message) || 'Falha ao falar com o GLPI.');
    }
  }

  // ---------- Interface ----------
  const CORES = {
    bot: '#00a884', humano: '#1f7aec', neutro: '#8696a0', erro: '#d93025'
  };

  function cartao() {
    let el = document.getElementById('ff-atendimento');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'ff-atendimento';
    Object.assign(el.style, {
      position: 'fixed', zIndex: 99998, display: 'none', alignItems: 'center', gap: '10px',
      padding: '6px 6px 6px 12px', borderRadius: '18px', background: '#fff',
      boxShadow: '0 1px 4px rgba(11,20,26,.25)', fontSize: '13px', color: '#111b21',
      fontFamily: 'Segoe UI, Helvetica, Arial, sans-serif', maxWidth: '520px'
    });
    el.innerHTML =
      '<span data-ff="ponto" style="width:9px;height:9px;border-radius:50%;flex:none"></span>' +
      '<span data-ff="texto" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></span>' +
      '<button data-ff="botao" type="button" style="border:none;border-radius:14px;padding:6px 14px;' +
      'font-size:13px;font-weight:600;color:#fff;cursor:pointer;flex:none"></button>';
    el.querySelector('[data-ff="botao"]').addEventListener('click', () => {
      const s = estado.sessao;
      if (s && s.found) executar(s.is_human ? 'release' : 'take');
    });
    document.body.appendChild(el);
    return el;
  }

  // Fica logo abaixo do cabeçalho da conversa, encostado à direita
  function posicionar(el) {
    const area = areaConversa();
    if (!area) { el.style.display = 'none'; return false; }
    const h = cabecalho();
    const r = (h || area).getBoundingClientRect();
    // sem cabeçalho reconhecido, fica no canto superior direito da conversa
    el.style.top = ((h ? r.bottom : r.top + 60) + 8) + 'px';
    el.style.right = Math.max(16, window.innerWidth - r.right + 16) + 'px';
    return true;
  }

  function desenhar(s) {
    const el = cartao();
    if (!estado.configurado) {
      LOG('URL/token do GLPI não configurados no popup da extensão — cartão oculto.');
      el.style.display = 'none';
      return;
    }
    if (!posicionar(el)) { el.style.display = 'none'; return; }

    const ponto = el.querySelector('[data-ff="ponto"]');
    const texto = el.querySelector('[data-ff="texto"]');
    const botao = el.querySelector('[data-ff="botao"]');
    let cor = CORES.neutro, msg = '', rotulo = '', corBotao = '';

    if (s.carregando) {
      msg = 'Consultando o bot...';
    } else if (!s.ok) {
      cor = CORES.erro; msg = s.message || 'Erro ao consultar o GLPI';
    } else if (!s.found) {
      msg = 'Conversa fora do bot';
    } else if (s.is_human) {
      cor = CORES.humano;
      msg = 'Atendimento humano' + (s.human_agent ? ': ' + s.human_agent : '');
      rotulo = 'Devolver ao bot'; corBotao = CORES.bot;
    } else {
      cor = CORES.bot;
      msg = 'Bot atendendo' + (s.last_ticket_id ? ' · #' + s.last_ticket_id : '');
      rotulo = 'Assumir'; corBotao = CORES.humano;
    }

    ponto.style.background = cor;
    texto.textContent = msg;
    texto.title = msg;
    botao.style.display = rotulo ? '' : 'none';
    botao.textContent = rotulo;
    botao.style.background = corBotao;
    botao.disabled = !!s.carregando;
    el.style.padding = rotulo ? '6px 6px 6px 12px' : '6px 12px';
    el.style.display = 'flex';
    sincronizarSelo();
  }

  // Selo do carimbo ("Fibra Forte: Nome", criado pelo content.js): com o
  // cartão visível ele vai para dentro do cartão, em vez de ficar fixo no
  // topo da tela por cima do nome do contato; sem cartão, volta pro topo.
  const SELO_NO_TOPO = { position: 'fixed', top: '6px', left: '50%', transform: 'translateX(-50%)', flex: '' };
  const SELO_NO_CARTAO = { position: 'static', top: '', left: '', transform: 'none', flex: 'none' };

  function sincronizarSelo() {
    const selo = document.getElementById('carimbo-nome-selo');
    const el = document.getElementById('ff-atendimento');
    if (!selo || !el) return;
    const noCartao = el.style.display !== 'none';
    if (noCartao && selo.parentElement !== el) {
      Object.assign(selo.style, SELO_NO_CARTAO);
      el.insertBefore(selo, el.firstChild);
    } else if (!noCartao && selo.parentElement === el) {
      Object.assign(selo.style, SELO_NO_TOPO);
      document.body.appendChild(selo);
    }
  }

  function aviso(msg) {
    let caixa = document.getElementById('ff-atendimento-aviso');
    if (!caixa) {
      caixa = document.createElement('div');
      caixa.id = 'ff-atendimento-aviso';
      Object.assign(caixa.style, {
        position: 'fixed', bottom: '90px', left: '50%', transform: 'translateX(-50%)',
        background: CORES.erro, color: '#fff', padding: '10px 16px', borderRadius: '8px',
        fontSize: '14px', fontFamily: 'Segoe UI, Helvetica, Arial, sans-serif',
        zIndex: 99999, boxShadow: '0 2px 8px rgba(0,0,0,.25)', maxWidth: '80vw'
      });
      document.body.appendChild(caixa);
    }
    caixa.textContent = msg;
    caixa.style.display = 'block';
    clearTimeout(caixa._t);
    caixa._t = setTimeout(() => { caixa.style.display = 'none'; }, 5000);
  }

  // ---------- Acompanha a troca de conversa ----------
  let ultimaConsulta = 0;
  let pendente = null;

  LOG('carregado. Abra uma conversa para ver o cartão do bot.');

  setInterval(() => {
    const el = cartao();
    sincronizarSelo();
    if (!areaConversa()) {
      estado.chave = '';
      el.style.display = 'none';
      return;
    }
    if (el.style.display !== 'none') posicionar(el);

    const chave = tituloDaConversa() + '|' + (jidsDaConversa()[0] || '');
    if (chave !== estado.chave) {
      estado.chave = chave;
      estado.sessao = null;
      // espera as mensagens da conversa nova renderizarem
      clearTimeout(pendente);
      pendente = setTimeout(() => { ultimaConsulta = Date.now(); consultar(); }, 400);
    } else if (!estado.ocupado && document.visibilityState === 'visible' &&
               Date.now() - ultimaConsulta > 30000) {
      // pega mudanças feitas por fora (tela do GLPI, timeout do bot)
      ultimaConsulta = Date.now();
      consultar(true);
    }
  }, 1000);

  window.addEventListener('resize', () => {
    const el = document.getElementById('ff-atendimento');
    if (el && el.style.display !== 'none') posicionar(el);
  });
})();
