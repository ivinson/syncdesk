<?php
// sidebar.php - Modular Navigation Sidebar for SyncDesk (Axis360 Design)
// Make sure UserSpice session is active before reading values
if (isset($user) && $user->isLoggedIn()) {
    $sb_user_id = $user->data()->id;
    $sb_fname = htmlspecialchars($user->data()->fname);
    $sb_lname = htmlspecialchars($user->data()->lname);
    $sb_username = htmlspecialchars($user->data()->username);
    $sb_full_name = trim($sb_fname . ' ' . $sb_lname) ?: $sb_username;
    $sb_is_admin = hasPerm([2], $sb_user_id);
    $sb_role = $sb_is_admin ? 'Administrador' : 'Atendente';
} else {
    $sb_full_name = "Convidado";
    $sb_role = "Deslogado";
    $sb_is_admin = false;
}

$sb_current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- Self-contained Sidebar Styles -->
<style>
    .sidebar {
        width: 260px;
        background-color: var(--sb-bg, #0b0f19);
        color: #ffffff;
        display: flex;
        flex-direction: column;
        padding: 1.5rem 1rem;
        position: fixed;
        top: 0;
        bottom: 0;
        left: 0;
        z-index: 100;
        box-shadow: 4px 0 10px rgba(0,0,0,0.05);
        
        /* Enable internal vertical scrolling for small screens/resolutions */
        overflow-y: auto;
        overflow-x: hidden;
    }

    /* Premium Custom Scrollbar for Sidebar */
    .sidebar::-webkit-scrollbar {
        width: 6px;
    }
    
    .sidebar::-webkit-scrollbar-track {
        background: transparent;
    }
    
    .sidebar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.12);
        border-radius: 10px;
    }
    
    .sidebar::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.22);
    }

    /* Prevent content from overlapping behind the fixed sidebar on all views */
    .main-content {
        margin-left: 260px !important;
    }

    .brand-section {
        padding: 0.5rem 0.75rem 2rem 0.75rem;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        margin-bottom: 1.5rem;
    }

    .brand-logo {
        font-size: 1.5rem;
        font-weight: 700;
        color: #ffffff;
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
    }

    .tenant-card {
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 10px;
        padding: 0.75rem;
        margin-top: 1rem;
        font-size: 0.85rem;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .sidebar-menu {
        list-style: none !important;
        padding: 0 !important;
        margin: 0 !important;
        flex-grow: 1;
    }

    .sidebar-menu .menu-item {
        margin-bottom: 0.4rem;
        list-style: none !important;
    }

    .sidebar-menu .menu-link {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 0.75rem 1rem;
        color: var(--sb-text, #94a3b8);
        text-decoration: none !important;
        border-radius: 8px;
        font-size: 0.95rem;
        font-weight: 500;
        transition: all 0.2s ease;
    }

    .sidebar-menu .menu-link:hover {
        background: rgba(255, 255, 255, 0.05);
        color: #ffffff !important;
    }

    .sidebar-menu .menu-link.active {
        background-color: var(--sb-active-bg, #e11d48);
        color: var(--sb-active-text, #ffffff) !important;
        font-weight: 600;
    }

    .sidebar-menu .menu-link i {
        font-size: 1.15rem;
    }

    .sidebar-menu .menu-item.disabled-menu {
        opacity: 0.55;
        cursor: not-allowed;
    }
    
    .sidebar-menu .menu-item.disabled-menu .menu-link {
        color: #64748b !important;
        pointer-events: none;
    }

    .sidebar-menu .menu-item.disabled-menu .menu-link:hover {
        background: transparent !important;
    }

    .sidebar-footer {
        border-top: 1px solid rgba(255,255,255,0.05);
        padding-top: 1.25rem;
        margin-top: auto;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .sidebar-footer .profile-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background-color: #e11d48;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1rem;
    }

    .sidebar-footer .profile-info {
        display: flex;
        flex-direction: column;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .sidebar-footer .profile-name {
        color: #ffffff;
        font-size: 0.9rem;
        font-weight: 600;
    }

    .sidebar-footer .profile-role {
        font-size: 0.75rem;
        color: var(--sb-text, #94a3b8);
    }
    
    .text-danger-hover:hover span, 
    .text-danger-hover:hover i {
        color: #ef4444 !important;
    }
</style>

<aside class="sidebar">
    <!-- Brand Title -->
    <div class="brand-section">
        <a href="index.php" class="brand-logo d-block text-center mb-3">
            <img src="assets/logo_white.png" alt="Sync Logo" style="max-height: 42px; max-width: 100%; object-fit: contain;">
        </a>
        <div class="tenant-card">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-building" style="color: #e11d48;"></i>
                <div class="text-truncate" style="max-width: 140px;">Empresa Exemplo</div>
            </div>
            <i class="bi bi-chevron-expand text-muted"></i>
        </div>
    </div>

    <!-- Navigation Menu Items -->
    <ul class="sidebar-menu">
        <!-- 1. Dashboard (Mockup Tactical Dashboard) -->
        <li class="menu-item">
            <a href="index.php" class="menu-link <?= $sb_current_page == 'index.php' ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Dashboard</span>
            </a>
        </li>
        
        
        <!-- 3. Clientes/Ativos (Active) -->
        <li class="menu-item">
            <a href="manage_assets.php" class="menu-link <?= $sb_current_page == 'manage_assets.php' ? 'active' : '' ?>">
                <i class="bi bi-people"></i>
                <span>Clientes</span>
            </a>
        </li>
        
        <!-- 4. Knowledge Base (Active) -->
        <li class="menu-item">
            <a href="knowledge_base.php" class="menu-link <?= $sb_current_page == 'knowledge_base.php' ? 'active' : '' ?>">
                <i class="bi bi-journal-text"></i>
                <span>Knowledge Base</span>
            </a>
        </li>
        
        <!-- 5. Tarefas (Active - Renamed to tasks.php) -->
        <li class="menu-item">
            <a href="tasks.php" class="menu-link <?= ($sb_current_page == 'tasks.php' || $sb_current_page == 'kanban.php') ? 'active' : '' ?>">
                <i class="bi bi-check2-square"></i>
                <span>Tarefas</span>
            </a>
        </li>
        
        <!-- 6. Projetos (Disabled) -->
        <li class="menu-item disabled-menu">
            <a href="#" class="menu-link" tabindex="-1" aria-disabled="true">
                <i class="bi bi-folder2"></i>
                <span>Projetos</span>
            </a>
        </li>
        
        <!-- 7. Relatórios (Disabled) -->
        <li class="menu-item disabled-menu">
            <a href="#" class="menu-link" tabindex="-1" aria-disabled="true">
                <i class="bi bi-bar-chart"></i>
                <span>Relatórios</span>
            </a>
        </li>
        
        <!-- 8. Notificações (Active) -->
        <li class="menu-item">
            <a href="user_notifications.php" class="menu-link <?= $sb_current_page == 'user_notifications.php' ? 'active' : '' ?>">
                <i class="bi bi-bell"></i>
                <span>Notificações</span>
            </a>
        </li>
        
        <!-- 9. Configurações (Active - Admin Only) -->
        <?php if ($sb_is_admin): ?>
            <li class="menu-item">
                <a href="settings.php" class="menu-link <?= $sb_current_page == 'settings.php' ? 'active' : '' ?>">
                    <i class="bi bi-gear"></i>
                    <span>Configurações</span>
                </a>
            </li>
        <?php endif; ?>
        
        <!-- 10. Vincular Equipe (Active - Admin Only) -->
        <?php if ($sb_is_admin): ?>
            <li class="menu-item">
                <a href="manage_customer_agents.php" class="menu-link <?= $sb_current_page == 'manage_customer_agents.php' ? 'active' : '' ?>">
                    <i class="bi bi-person-gear"></i>
                    <span>Vincular Equipe</span>
                </a>
            </li>
        <?php endif; ?>

        <!-- 11. Logout Menu Option -->
        <li class="menu-item mt-3">
            <a href="users/logout.php" class="menu-link text-danger-hover">
                <i class="bi bi-box-arrow-right text-danger"></i>
                <span class="text-danger">Sair do Sistema</span>
            </a>
        </li>
    </ul>

    <!-- Sidebar Profile Footer Section -->
    <div class="sidebar-footer">
        <div class="profile-avatar">
            <?= strtoupper(substr($sb_full_name, 0, 1)) ?>
        </div>
        <div class="profile-info">
            <span class="profile-name" title="<?= $sb_full_name ?>"><?= $sb_full_name ?></span>
            <span class="profile-role"><?= $sb_role ?></span>
        </div>
        <a href="users/logout.php" class="ms-auto text-muted" title="Sair">
            <i class="bi bi-power fs-5 text-danger"></i>
        </a>
    </div>
</aside>

<!-- Global Command Palette Search Modal -->
<div id="searchPaletteModal" class="search-palette-overlay" style="display: none;">
    <div class="search-palette-box">
        <div class="search-palette-header">
            <i class="bi bi-search search-palette-icon"></i>
            <input type="text" id="searchPaletteInput" placeholder="Buscar tarefas, clientes, manuais... (Digite para pesquisar)" autocomplete="off">
            <button id="closeSearchPaletteBtn" class="search-palette-close-btn">&times;</button>
        </div>
        <div id="searchPaletteResults" class="search-palette-body">
            <div class="search-palette-empty">Digite pelo menos 2 caracteres para pesquisar...</div>
        </div>
        <div class="search-palette-footer">
            <span><kbd>↑↓</kbd> Navegar</span>
            <span><kbd>Enter</kbd> Selecionar</span>
            <span><kbd>Esc</kbd> Fechar</span>
        </div>
    </div>
</div>

<style>
    .search-palette-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(2, 6, 23, 0.7);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        z-index: 9999;
        display: flex;
        justify-content: center;
        padding-top: 10vh;
    }

    .search-palette-box {
        width: 100%;
        max-width: 600px;
        background-color: rgba(15, 23, 42, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 16px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        display: flex;
        flex-direction: column;
        max-height: 480px;
        color: #f8fafc;
        font-family: 'Inter', sans-serif;
        overflow: hidden;
    }

    .search-palette-header {
        display: flex;
        align-items: center;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        gap: 12px;
    }

    .search-palette-icon {
        font-size: 1.25rem;
        color: #94a3b8;
    }

    #searchPaletteInput {
        flex-grow: 1;
        background: transparent;
        border: none;
        outline: none;
        color: #ffffff;
        font-size: 1.1rem;
        font-family: 'Inter', sans-serif;
    }

    #searchPaletteInput::placeholder {
        color: #64748b;
    }

    .search-palette-close-btn {
        background: transparent;
        border: none;
        color: #64748b;
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0;
        line-height: 1;
        transition: color 0.15s ease;
    }

    .search-palette-close-btn:hover {
        color: #ffffff;
    }

    .search-palette-body {
        flex-grow: 1;
        overflow-y: auto;
        padding: 1rem;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .search-palette-empty {
        text-align: center;
        color: #64748b;
        padding: 2rem 0;
        font-size: 0.9rem;
    }

    .search-palette-group-title {
        font-size: 0.72rem;
        font-weight: 600;
        color: #e11d48;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 0.5rem;
    }

    .search-palette-group-list {
        display: flex;
        flex-direction: column;
        gap: 4px;
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .search-palette-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 0.6rem 0.8rem;
        border-radius: 8px;
        color: #cbd5e1;
        text-decoration: none;
        font-size: 0.9rem;
        transition: all 0.15s ease;
        cursor: pointer;
    }

    .search-palette-item i {
        font-size: 1.1rem;
        color: #64748b;
    }

    .search-palette-item:hover, 
    .search-palette-item.active {
        background-color: rgba(225, 29, 72, 0.12);
        color: #ffffff;
        text-decoration: none;
    }

    .search-palette-item:hover i, 
    .search-palette-item.active i {
        color: #e11d48;
    }

    .search-palette-footer {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 0.75rem 1.25rem;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        font-size: 0.75rem;
        color: #64748b;
        background-color: rgba(2, 6, 23, 0.2);
    }

    .search-palette-footer kbd {
        background-color: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.15);
        border-radius: 4px;
        padding: 1px 4px;
        color: #94a3b8;
        font-size: 0.7rem;
    }
</style>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const overlay = document.getElementById("searchPaletteModal");
        const input = document.getElementById("searchPaletteInput");
        const resultsContainer = document.getElementById("searchPaletteResults");
        const closeBtn = document.getElementById("closeSearchPaletteBtn");
        let searchTimeout = null;
        let currentSelectedIndex = -1;

        function openPalette() {
            overlay.style.display = "flex";
            input.value = "";
            resultsContainer.innerHTML = '<div class="search-palette-empty">Digite pelo menos 2 caracteres para pesquisar...</div>';
            currentSelectedIndex = -1;
            setTimeout(() => input.focus(), 50);
        }

        function closePalette() {
            overlay.style.display = "none";
        }

        window.addEventListener("keydown", function(e) {
            // Check for Ctrl + K or Cmd + K
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") {
                e.preventDefault();
                if (overlay.style.display === "flex") {
                    closePalette();
                } else {
                    openPalette();
                }
            }

            if (e.key === "Escape" && overlay.style.display === "flex") {
                closePalette();
            }

            // Keyboard Navigation inside active Modal
            if (overlay.style.display === "flex") {
                const items = resultsContainer.querySelectorAll(".search-palette-item");
                if (items.length > 0) {
                    if (e.key === "ArrowDown") {
                        e.preventDefault();
                        currentSelectedIndex = (currentSelectedIndex + 1) % items.length;
                        updateActiveItem(items);
                    } else if (e.key === "ArrowUp") {
                        e.preventDefault();
                        currentSelectedIndex = (currentSelectedIndex - 1 + items.length) % items.length;
                        updateActiveItem(items);
                    } else if (e.key === "Enter") {
                        if (currentSelectedIndex >= 0 && currentSelectedIndex < items.length) {
                            e.preventDefault();
                            items[currentSelectedIndex].click();
                        }
                    }
                }
            }
        });

        function updateActiveItem(items) {
            items.forEach((item, index) => {
                if (index === currentSelectedIndex) {
                    item.classList.add("active");
                    item.scrollIntoView({ block: "nearest" });
                } else {
                    item.classList.remove("active");
                }
            });
        }

        closeBtn.addEventListener("click", closePalette);
        overlay.addEventListener("click", function(e) {
            if (e.target === overlay) {
                closePalette();
            }
        });

        // Bind clicks on all .top-search-input elements to open the palette modal
        function bindTopInputs() {
            document.querySelectorAll(".top-search-input").forEach(function(topInput) {
                // Remove standard behavior
                topInput.addEventListener("focus", function(e) {
                    e.preventDefault();
                    topInput.blur();
                    openPalette();
                });
                topInput.addEventListener("click", function(e) {
                    e.preventDefault();
                    openPalette();
                });
            });
        }
        
        bindTopInputs();

        // Search fetching with Debounce
        input.addEventListener("input", function() {
            clearTimeout(searchTimeout);
            const q = input.value.trim();

            if (q.length < 2) {
                resultsContainer.innerHTML = '<div class="search-palette-empty">Digite pelo menos 2 caracteres para pesquisar...</div>';
                currentSelectedIndex = -1;
                return;
            }

            resultsContainer.innerHTML = '<div class="search-palette-empty"><div class="spinner-border spinner-border-sm" role="status" style="border-color: #e11d48; border-right-color: transparent;"></div> Pesquisando...</div>';

            searchTimeout = setTimeout(() => {
                fetch('global_search.php?q=' + encodeURIComponent(q))
                    .then(response => response.json())
                    .then(data => {
                        renderResults(data);
                    })
                    .catch(err => {
                        console.error(err);
                        resultsContainer.innerHTML = '<div class="search-palette-empty text-danger">Erro ao realizar a busca.</div>';
                    });
            }, 200);
        });

        function renderResults(data) {
            resultsContainer.innerHTML = "";
            currentSelectedIndex = -1;
            let hasResults = false;

            const categories = [
                { key: 'shortcuts', label: 'Atalhos do Sistema' },
                { key: 'customers', label: 'Clientes' },
                { key: 'tasks', label: 'Tarefas' },
                { key: 'articles', label: 'Base de Conhecimento' }
            ];

            categories.forEach(cat => {
                const list = data[cat.key];
                if (list && list.length > 0) {
                    hasResults = true;

                    const groupDiv = document.createElement("div");
                    groupDiv.className = "search-palette-group";

                    const title = document.createElement("div");
                    title.className = "search-palette-group-title";
                    title.innerText = cat.label;
                    groupDiv.appendChild(title);

                    const ul = document.createElement("ul");
                    ul.className = "search-palette-group-list";

                    list.forEach(item => {
                        const li = document.createElement("li");
                        const a = document.createElement("a");
                        a.className = "search-palette-item";
                        a.href = item.url;
                        a.innerHTML = `<i class="bi ${item.icon}"></i> <span>${escapeHtml(item.title)}</span>`;
                        li.appendChild(a);
                        ul.appendChild(li);
                    });

                    groupDiv.appendChild(ul);
                    resultsContainer.appendChild(groupDiv);
                }
            });

            if (!hasResults) {
                resultsContainer.innerHTML = '<div class="search-palette-empty">Nenhum resultado encontrado para "' + escapeHtml(input.value) + '".</div>';
            }
        }

        function escapeHtml(str) {
            return str
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
    });
</script>
