// fazer com que os eventos permaneçam em localStorage
(function(){
  const STORAGE_KEY = 'ppi_events';

  function defaultEvents(){
    return [
      { id: 'e1', nome: 'Semana Acadêmica de Tecnologia', data: '2024-12-15', hora: '09:00', local: 'Auditório', descricao: 'Palestras e workshops sobre tecnologia.' },
      { id: 'e2', nome: 'Workshop de React', data: '2025-01-18', hora: '14:00', local: 'Lab 2', descricao: 'Hands-on de React.' },
      { id: 'e3', nome: 'Mostra de Ciências', data: '2025-02-10', hora: '10:00', local: 'Ginásio', descricao: 'Projetos estudantis.' }
    ];
  }

  function loadEvents(){
    try{
      const raw = localStorage.getItem(STORAGE_KEY);
      if(!raw) { const d = defaultEvents(); localStorage.setItem(STORAGE_KEY, JSON.stringify(d)); return d; }
      return JSON.parse(raw);
    }catch(e){ console.error('Erro ao carregar eventos', e); return defaultEvents(); }
  }

  function saveEvents(events){
    try{ localStorage.setItem(STORAGE_KEY, JSON.stringify(events)); }catch(e){ console.error('Erro ao salvar eventos', e); }
  }

  function generateId(){ return 'e' + Date.now().toString(36); }

  function addEvent(ev){ const events = loadEvents(); ev.id = generateId(); events.unshift(ev); saveEvents(events); return ev.id; }
  function updateEvent(ev){ const events = loadEvents(); const idx = events.findIndex(e => e.id === ev.id); if(idx === -1) return false; events[idx] = ev; saveEvents(events); return true; }
  function deleteEvent(id){ let events = loadEvents(); events = events.filter(e => e.id !== id); saveEvents(events); }

  // Expor globalmente
  window.PPIEvents = {
    load: loadEvents,
    save: saveEvents,
    add: addEvent,
    update: updateEvent,
    remove: deleteEvent
  };
})();
