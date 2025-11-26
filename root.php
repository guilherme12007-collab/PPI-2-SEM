<?php
require_once 'Database.php';

// Inicia a sessão no topo do script
session_start();

// Credenciais simples para o usuário root (em um sistema real, use um banco de dados)
define('ROOT_USER', 'admin');
define('ROOT_PASS', 'admin');

// Esta parte do script agora funciona como um "endpoint" de API para o JavaScript.
// Ele verifica as credenciais enviadas via POST e retorna os dados dos eventos.
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    header('Content-Type: application/json');
    $user = $_POST['user'] ?? null;
    $pass = $_POST['pass'] ?? null;

    if ($user === ROOT_USER && $pass === ROOT_PASS) {
        // Se o login for bem-sucedido, armazena o status na sessão
        $_SESSION['is_root_authenticated'] = true;
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Usuário ou senha inválidos.']);
    }
    exit;
}

// Endpoint para buscar os eventos, protegido pela sessão
if (isset($_GET['action']) && $_GET['action'] === 'get_events') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['is_root_authenticated']) || $_SESSION['is_root_authenticated'] !== true) {
        echo json_encode(['success' => false, 'message' => 'Não autenticado.']);
        exit;
    }

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT e.id_evento, e.titulo, e.descricao, e.data_inicio, e.data_fim, e.local, e.carga_horaria, e.status, u.nome AS organizador_nome
            FROM evento e
            JOIN usuario u ON e.id_organizador = u.id_usuario
            ORDER BY
                CASE e.status
                    WHEN 'Pendente' THEN 1
                    WHEN 'Aberto' THEN 2
                    WHEN 'Encerrado' THEN 3
                    WHEN 'Cancelado' THEN 4
                END,
                e.data_inicio ASC
        ");
        $stmt->execute();
        $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'eventos' => $eventos]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
    }
    exit; // Importante: termina o script após responder à requisição POST.
}

// Endpoint para logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    // A sessão já foi iniciada no topo do arquivo
    session_unset();
    session_destroy();
    header('Location: root.php'); // Redireciona de volta para a página de login
    exit;
}

// Se a requisição não for POST, o HTML da página é renderizado.
// O conteúdo será preenchido pelo JavaScript após a autenticação.
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aprovação de Eventos - Root</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background-color: #f1f5f9; }
        .card {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn-approve {
            background-color: #22c55e; /* green-500 */
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
        }
        .btn-approve:hover {
            background-color: #16a34a; /* green-600 */
        }
        .status-badge {
            padding: 4px 12px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body>
    <div class="container mx-auto p-4 md:p-8">
        <header class="mb-8 flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-slate-800">Painel de Aprovação</h1>
                <p class="text-slate-600">Gerencie o status dos eventos cadastrados no sistema.</p>
            </div>
            <div id="header-actions"></div>
        </header>

        <!-- Modal de Login -->
        <div id="login-modal" class="fixed inset-0 bg-gray-800 bg-opacity-60 overflow-y-auto h-full w-full hidden z-50">
            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                <div class="mt-3 text-center">
                    <h3 class="text-lg leading-6 font-medium text-gray-900">Acesso Restrito</h3>
                    <div class="mt-4 px-7 py-3">
                        <form id="login-form">
                            <div class="mb-4">
                                <input type="text" id="login-user" placeholder="Usuário" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                            </div>
                            <div class="mb-4">
                                <input type="password" id="login-pass" placeholder="Senha" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                            </div>
                            <p id="login-error" class="text-red-500 text-sm mb-4 hidden"></p>
                            <button type="submit" class="w-full px-4 py-2 bg-blue-500 text-white text-base font-medium rounded-md shadow-sm hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-300">Entrar</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Seção de Eventos Pendentes -->
        <section id="pending-section" class="mb-12">
            <h2 class="text-2xl font-semibold text-slate-700 mb-4 border-b pb-2">Eventos Pendentes de Aprovação</h2>
            <div id="pending-events-list">
                <div class="card"><p class="text-slate-700">Carregando eventos...</p></div>
            </div>
        </section>

        <!-- Seção de Histórico de Eventos -->
        <section id="history-section">
            <h2 class="text-2xl font-semibold text-slate-700 mb-4 border-b pb-2">Histórico de Eventos</h2>
            <div id="history-events-list">
                <!-- Conteúdo do histórico -->
            </div>
        </section>
    </div>
</body>
</html>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Função para escapar HTML e evitar XSS
    const isAuthenticated = <?php echo json_encode(isset($_SESSION['is_root_authenticated']) && $_SESSION['is_root_authenticated'] === true); ?>;
    const pendingListContainer = document.getElementById('pending-events-list');
    const historyListContainer = document.getElementById('history-events-list');

    function escapeHTML(str) {
        const p = document.createElement('p');
        p.appendChild(document.createTextNode(str));
        return p.innerHTML;
    }

    function formatDate(dateStr) {
        if (!dateStr) return 'N/A';
        const date = new Date(dateStr + 'T00:00:00'); // Adiciona T00:00:00 para evitar problemas de fuso
        return date.toLocaleDateString('pt-BR');
    }

    // Pede usuário e senha usando o prompt do JavaScript
    function performLogin() {
        const modal = document.getElementById('login-modal');
        const form = document.getElementById('login-form');
        const userField = document.getElementById('login-user');
        const passField = document.getElementById('login-pass');
        const errorMsg = document.getElementById('login-error');
        pendingListContainer.innerHTML = '<div class="card"><p class="text-slate-700">Por favor, forneça as credenciais para ver os eventos.</p></div>';

        // Mostra o modal
        modal.classList.remove('hidden');
        userField.focus();

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            errorMsg.classList.add('hidden');

            const formData = new FormData();
            formData.append('action', 'login');
            formData.append('user', userField.value);
            formData.append('pass', passField.value);

            fetch('root.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    errorMsg.textContent = data.message || 'Ocorreu um erro.';
                    errorMsg.classList.remove('hidden');
                }
            });
        });
    }

    function fetchEvents() {
        fetch('root.php?action=get_events')
        .then(response => response.json())
        .then(data => {
            pendingListContainer.innerHTML = '';
            historyListContainer.innerHTML = '';

            if (data.success) {
                const pendingEvents = data.eventos.filter(e => e.status === 'Pendente');
                const otherEvents = data.eventos.filter(e => e.status !== 'Pendente');

                if (pendingEvents.length > 0) {
                    pendingEvents.forEach(evento => {
                        pendingListContainer.innerHTML += createEventCard(evento);
                    });
                } else {
                    pendingListContainer.innerHTML = '<div class="card"><p class="text-slate-700 font-semibold">Nenhum evento pendente de aprovação no momento.</p></div>';
                }

                if (otherEvents.length > 0) {
                    otherEvents.forEach(evento => {
                        historyListContainer.innerHTML += createEventCard(evento);
                    });
                } else {
                    historyListContainer.innerHTML = '<div class="card"><p class="text-slate-700 font-semibold">Nenhum evento no histórico.</p></div>';
                }

            } else {
                pendingListContainer.innerHTML = `<div class="card text-red-600 font-bold">Erro: ${data.message}</div>`;
            }
        });
    }

    function createEventCard(evento) {
        const dateText = evento.data_fim ? `${formatDate(evento.data_inicio)} a ${formatDate(evento.data_fim)}` : formatDate(evento.data_inicio);
        return `
            <div class="card" id="evento-${evento.id_evento}">
                <div class="flex-grow">
                    <h2 class="text-xl font-bold text-slate-900">${escapeHTML(evento.titulo)}</h2>
                    <p class="text-slate-600 mt-1 text-sm">Organizador: ${escapeHTML(evento.organizador_nome)}</p>
                    <p class="text-sm text-slate-500 mt-3">${escapeHTML(evento.descricao)}</p>
                    <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-600">
                        <span title="Data do Evento"><i class="fa-solid fa-calendar-days mr-2 text-slate-400"></i>${dateText}</span>
                        <span title="Local"><i class="fa-solid fa-location-dot mr-2 text-slate-400"></i>${escapeHTML(evento.local)}</span>
                        <span title="Carga Horária"><i class="fa-solid fa-clock mr-2 text-slate-400"></i>${escapeHTML(evento.carga_horaria)} horas</span>
                    </div>
                </div>
                ${getActionButton(evento)}
            </div>`;
    }

    if (isAuthenticated) {
        fetchEvents();
        // Adiciona o botão de logout se estiver autenticado
        const headerActions = document.getElementById('header-actions');
        headerActions.innerHTML = '<a href="root.php?action=logout" class="bg-red-500 hover:bg-red-600 text-white font-bold py-2 px-4 rounded">Sair</a>';

    } else {
        performLogin();
    }

    // Função para gerar o botão de ação ou o status do evento
    function getActionButton(evento) {
        if (evento.status === 'Pendente') {
            return `<div class="ml-6 text-center flex-shrink-0">
                        <button data-id="${evento.id_evento}" data-title="${escapeHTML(evento.titulo)}" class="btn-approve">Aprovar</button>
                    </div>`;
        }

        let statusClass = 'bg-gray-400 text-white';
        if (evento.status === 'Aberto') {
            statusClass = 'bg-green-500 text-white';
        } else if (evento.status === 'Encerrado') {
            statusClass = 'bg-blue-500 text-white';
        } else if (evento.status === 'Cancelado') {
            statusClass = 'bg-red-500 text-white';
        }

        return `<div class="ml-6 text-center flex-shrink-0">
                    <span class="status-badge ${statusClass}">${escapeHTML(evento.status)}</span>
                </div>`;
    }

    // Adiciona um listener de eventos para os botões de aprovação
    // Adicionado ao body para capturar cliques em ambas as listas
    document.body.addEventListener('click', function(e) {
        // Verifica se o clique foi em um botão de aprovar
        if (e.target && e.target.classList.contains('btn-approve')) {
            const button = e.target;
            const eventId = button.getAttribute('data-id');
            const eventTitle = button.getAttribute('data-title');

            if (confirm(`Tem certeza que deseja aprovar o evento '${eventTitle}'?`)) {
                // Desabilita o botão para evitar cliques duplos
                button.disabled = true;
                button.textContent = 'Aprovando...';

                fetch('process/approve_event.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `id_evento=${eventId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        window.location.reload(); // Recarrega a página para ver o status atualizado
                    } else {
                        alert(`Erro: ${data.message}`);
                        button.disabled = false; // Reabilita o botão em caso de erro
                        button.textContent = 'Aprovar';
                    }
                });
            }
        }
    });
});
</script>