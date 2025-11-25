let currentAction = null;
        let currentEvent = null;

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
        }

        function openCheckinModal(eventName, action) {
            currentEvent = eventName;
            currentAction = action;
            const modal = document.getElementById('modal');
            const title = document.getElementById('modal-title');
            const message = document.getElementById('modal-message');
            
            if (action === 'check-in') {
                title.textContent = 'Confirmar Check-in';
                message.textContent = `Deseja fazer check-in no evento "${eventName}"?`;
            } else {
                title.textContent = 'Confirmar Check-out';
                message.textContent = `Deseja fazer check-out do evento "${eventName}"?`;
            }
            
            modal.classList.add('show');
        }

        function openLogoutModal() {
            currentAction = 'logout';
            const modal = document.getElementById('modal');
            const title = document.getElementById('modal-title');
            const message = document.getElementById('modal-message');
            
            title.textContent = 'Confirmar Logout';
            message.textContent = 'Deseja realmente sair do sistema?';
            
            modal.classList.add('show');
        }


        function closeModal() {
            const modal = document.getElementById('modal');
            modal.classList.remove('show');
            currentAction = null;
            currentEvent = null;
        }

        function closeModalOutside(event) {
            if (event.target.id === 'modal') {
                closeModal();
            }
        }

        function confirmAction() {
            if (currentAction === 'logout') {
                window.location.href = 'login.php';
            } else if (currentAction === 'check-in') {
                alert(`Check-in realizado com sucesso no evento "${currentEvent}"!`);
                closeModal();
            } else if (currentAction === 'check-out') {
                alert(`Check-out realizado com sucesso no evento "${currentEvent}"!`);
                closeModal();
            } else if (currentAction === 'settings') {
                closeModal();
            }
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });