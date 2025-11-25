'use strict';

document.addEventListener('DOMContentLoaded', function () {
  // mobile sidebar toggle (keeps inline onclick as fallback)
  window.toggleSidebar = function () {
    document.getElementById('sidebar').classList.toggle('active');
  };

  // Busca local (filtra cards já renderizados)
  const searchInput = document.getElementById('searchInput');
  if (searchInput) {
    searchInput.addEventListener('input', function (e) {
      const searchTerm = e.target.value.toLowerCase();
      const cards = document.querySelectorAll('.event-card');

      cards.forEach(card => {
        const title = card.querySelector('.event-title').textContent.toLowerCase();
        const description = card.querySelector('.event-description').textContent.toLowerCase();

        if (title.includes(searchTerm) || description.includes(searchTerm)) {
          card.style.display = 'block';
        } else {
          card.style.display = 'none';
        }
      });
    });
  }
});

// Função de inscrição disponível globalmente (chamada pelo onclick do botão)
async function inscrever(eventId) {
  const btn = document.getElementById('btn-inscrever-' + eventId);
  if (!btn) return;
  btn.disabled = true;
  btn.textContent = 'Processando...';

  try {
    const res = await fetch('process/inscricao.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ event_id: eventId })
    });

    const data = await res.json();

    if (res.ok && data.success) {
      // atualiza contador de inscritos
      const counter = document.querySelector('.inscritos-count[data-event-id="' + eventId + '"]');
      if (counter) counter.textContent = data.inscritos;
      btn.textContent = 'Inscrito';
      btn.disabled = true;
      showMessage('Inscrição realizada com sucesso.', false);
    } else {
      const err = data.error || 'Erro ao inscrever';
      btn.disabled = false;
      btn.textContent = 'Inscrever-se';
      showMessage(err, true);
      if (res.status === 401) {
        // opcional: redirecionar para login
        // window.location.href = 'login.php';
      }
    }
  } catch (err) {
    btn.disabled = false;
    btn.textContent = 'Inscrever-se';
    showMessage('Erro de comunicação: ' + (err.message || err), true);
  }
}

// disponibiliza globalmente
window.inscrever = inscrever;

function showMessage(msg, isError) {
  let cont = document.getElementById('message-container');
  if (!cont) {
    cont = document.createElement('div');
    cont.id = 'message-container';
    cont.style.position = 'fixed';
    cont.style.top = '1rem';
    cont.style.right = '1rem';
    cont.style.zIndex = 9999;
    document.body.appendChild(cont);
  }

  const el = document.createElement('div');
  el.textContent = msg;
  el.style.padding = '0.6rem 0.9rem';
  el.style.marginTop = '0.5rem';
  el.style.borderRadius = '6px';
  el.style.boxShadow = '0 4px 12px rgba(0,0,0,0.08)';
  el.style.fontWeight = '600';
  el.style.color = isError ? '#8b0000' : '#155724';
  el.style.background = isError ? '#ffe6e6' : '#e6ffed';
  cont.appendChild(el);

  setTimeout(() => el.remove(), 4500);
}