const campoNome = document.getElementById('nome');
const campoAtivo = document.getElementById('ativo');
const campoGlpiUrl = document.getElementById('glpiUrl');
const campoGlpiToken = document.getElementById('glpiToken');
const previaNome = document.getElementById('previaNome');
const status = document.getElementById('status');

function limpar(nome) {
  // Remove asteriscos e quebras de linha para não quebrar o negrito do WhatsApp
  return nome.replace(/[*\r\n]/g, '').replace(/\s+/g, ' ').trim();
}

function atualizarPrevia() {
  previaNome.textContent = limpar(campoNome.value) || 'Seu nome';
}

function mostrar(msg, classe) {
  status.textContent = msg;
  status.className = classe;
}

// "https://glpi.x.com/glpi/plugins/..." → "https://glpi.x.com/*" (permissão do navegador)
function origemDe(url) {
  try {
    const u = new URL(url);
    if (!/^https?:$/.test(u.protocol)) return null;
    return u.origin + '/*';
  } catch (_) {
    return null;
  }
}

chrome.storage.sync.get({ nome: '', ativo: true, glpiUrl: '', glpiToken: '' }, (cfg) => {
  campoNome.value = cfg.nome;
  campoAtivo.checked = cfg.ativo;
  campoGlpiUrl.value = cfg.glpiUrl;
  campoGlpiToken.value = cfg.glpiToken;
  atualizarPrevia();
  campoNome.focus();
});

campoNome.addEventListener('input', atualizarPrevia);
[campoNome, campoGlpiUrl, campoGlpiToken].forEach((c) =>
  c.addEventListener('keydown', (e) => { if (e.key === 'Enter') salvar(); }));
document.getElementById('salvar').addEventListener('click', salvar);

async function salvar() {
  const nome = limpar(campoNome.value);
  if (!nome) {
    mostrar('Informe seu nome.', 'erro');
    campoNome.focus();
    return;
  }
  const glpiUrl = campoGlpiUrl.value.trim();
  const glpiToken = campoGlpiToken.value.trim();

  // O pedido de permissão precisa sair direto do clique, antes de qualquer await.
  let permissao = Promise.resolve(true);
  if (glpiUrl) {
    const origem = origemDe(glpiUrl);
    if (!origem) {
      mostrar('URL do GLPI inválida (use http:// ou https://).', 'erro');
      campoGlpiUrl.focus();
      return;
    }
    permissao = chrome.permissions.request({ origins: [origem] });
  }

  await chrome.storage.sync.set({ nome, ativo: campoAtivo.checked, glpiUrl, glpiToken });
  campoNome.value = nome;

  if (!glpiUrl || !glpiToken) {
    mostrar('Salvo!', 'ok');
    setTimeout(() => { status.textContent = ''; }, 2000);
    return;
  }
  if (!(await permissao)) {
    mostrar('Salvo, mas sem permissão para acessar o GLPI.', 'erro');
    return;
  }
  mostrar('Salvo! Testando o GLPI...', 'ok');
  const resp = await chrome.runtime.sendMessage({ tipo: 'glpi', corpo: { action: 'ping' } });
  if (resp && resp.ok) mostrar('Salvo! GLPI conectado.', 'ok');
  else mostrar('Salvo, mas o GLPI falhou: ' + ((resp && resp.message) || 'sem resposta'), 'erro');
}
