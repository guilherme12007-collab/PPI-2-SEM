// certificados.js
// Atualizado: remove busca; carrega inscritos ao selecionar evento; valida 70-75% somente ao emitir.

async function api(path, opts){ const r = await fetch(path, Object.assign({credentials:'same-origin'}, opts||{})); return r.json(); }
async function postJson(path, data){ const r = await fetch(path, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data)}); return r.json(); }

const eventSelect = document.getElementById('eventSelect');
const lista = document.getElementById('lista');
const emitBtn = document.getElementById('emitBtn');
const emitAllBtn = document.getElementById('emitAllBtn');

let eventosCache = [];

(async function loadEvents(){
  try {
    const j = await api('certificados.php?action=events');
    eventosCache = j.data || [];
    populateEventSelect(eventosCache);
  } catch (err) { console.error('Erro ao carregar eventos', err); lista.innerHTML = '<p style="color:#e11d48">Erro ao carregar eventos.</p>'; }
})();

function populateEventSelect(eventos) {
  eventSelect.innerHTML = '';
  const optDefault = document.createElement('option');
  optDefault.value = '';
  optDefault.textContent = '-- selecione evento --';
  eventSelect.appendChild(optDefault);
  eventos.forEach(e=>{
    const opt = document.createElement('option');
    opt.value = e.id_evento;
    opt.textContent = `${e.titulo} (${e.data_inicio})`;
    eventSelect.appendChild(opt);
  });
}

// ao mudar o evento, carrega inscritos (sem validar)
eventSelect.addEventListener('change', ()=> {
  if (!eventSelect.value) { lista.innerHTML = '<p style="color:#6b7280">Selecione um evento para ver os inscritos.</p>'; return; }
  loadList();
});

async function loadList(){
  const id = eventSelect.value; if(!id){ alert('Selecione um evento'); return; }
  lista.innerHTML = '<p style="color:#6b7280">Carregando inscritos...</p>';
  try {
    const res = await api(`certificados.php?action=participants&id_evento=${encodeURIComponent(id)}`);
    const items = res.data || [];
    if (!items.length) { lista.innerHTML = '<p style="color:#6b7280">Nenhum inscrito encontrado.</p>'; return; }
    lista.innerHTML = '';
    items.forEach(p=>{
      const div = document.createElement('div'); div.className = 'list-item';
      const left = document.createElement('div');
      left.innerHTML = `<strong>${escapeHtml(p.nome)}</strong><br><small style="color:#6b7280">${escapeHtml(p.email)} · Presenças: ${p.presencas} / ${p.total_dias} (${Number(p.pct).toFixed(2)}%)</small>`;
      const right = document.createElement('div');
      right.innerHTML = `<input type="checkbox" value="${p.id_inscricao}" checked aria-label="Selecionar participante">`;
      div.appendChild(left); div.appendChild(right);
      lista.appendChild(div);
    });
  } catch (err) {
    console.error(err);
    lista.innerHTML = '<p style="color:#e11d48">Erro ao carregar inscritos.</p>';
  }
}

function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

// emitir selecionados: envia ids ao servidor; servidor valida 70-75% e grava apenas os válidos
emitBtn.addEventListener('click', async ()=>{
  const ids = Array.from(lista.querySelectorAll('input[type="checkbox"]:checked')).map(i=>i.value);
  if (!ids.length) { alert('Selecione ao menos um inscrito'); return; }
  if (!confirm(`Gravar certificados para ${ids.length} inscrito(s)? A validação 70%-75% será aplicada.`)) return;
  try {
    const res = await postJson('certificados.php?action=emit_lote', { ids });
    if (res.success) {
      // exibir resumo: saved / skipped / errors
      const saved = res.results.filter(r=> r.status === 'saved');
      const skipped = res.results.filter(r=> r.status === 'skipped' || r.error === 'not_found');
      const errors = res.results.filter(r=> r.error && r.error !== 'not_found');

      let msg = '';
      if (saved.length) msg += `Salvos: ${saved.length}\n` + saved.map(s=>`${s.nome} — rastreio: ${s.codigo_rastreio}`).join('\n') + '\n\n';
      if (skipped.length) msg += `Ignorados (fora do intervalo): ${skipped.length}\n` + skipped.map(s=>`${s.nome} — ${s.reason || ('pct=' + (s.pct ?? 'N/A'))}`).join('\n') + '\n\n';
      if (errors.length) msg += `Erros: ${errors.length}\n` + errors.map(e=>`${e.id_inscricao} — ${e.error} ${e.detail?('('+e.detail+')'):''}`).join('\n') + '\n\n';
      alert(msg || 'Operação concluída.');
      loadList();
    } else {
      alert('Falha: ' + (res.error || 'erro desconhecido'));
    }
  } catch (err) {
    console.error(err);
    alert('Erro ao gravar certificados. Veja console.');
  }
});

// emitir todos: envia todos os ids visíveis ao servidor
emitAllBtn.addEventListener('click', async ()=>{
  const ids = Array.from(lista.querySelectorAll('input[type="checkbox"]')).map(i=>i.value);
  if (!ids.length) { alert('Nenhum inscrito para emitir'); return; }
  if (!confirm(`Gravar certificados para ${ids.length} inscrito(s)? A validação 70%-75% será aplicada.`)) return;
  try {
    const res = await postJson('certificados.php?action=emit_lote', { ids });
    if (res.success) {
      const saved = res.results.filter(r=> r.status === 'saved');
      const skipped = res.results.filter(r=> r.status === 'skipped' || r.error === 'not_found');
      const errors = res.results.filter(r=> r.error && r.error !== 'not_found');

      let msg = '';
      if (saved.length) msg += `Salvos: ${saved.length}\n` + saved.map(s=>`${s.nome} — rastreio: ${s.codigo_rastreio}`).join('\n') + '\n\n';
      if (skipped.length) msg += `Ignorados (fora do intervalo): ${skipped.length}\n` + skipped.map(s=>`${s.nome} — ${s.reason || ('pct=' + (s.pct ?? 'N/A'))}`).join('\n') + '\n\n';
      if (errors.length) msg += `Erros: ${errors.length}\n` + errors.map(e=>`${e.id_inscricao} — ${e.error} ${e.detail?('('+e.detail+')'):''}`).join('\n') + '\n\n';
      alert(msg || 'Operação concluída.');
      loadList();
    } else {
      alert('Falha: ' + (res.error || 'erro desconhecido'));
    }
  } catch (err) {
    console.error(err);
    alert('Erro ao gravar certificados. Veja console.');
  }
});
