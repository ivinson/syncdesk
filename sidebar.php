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
        background-color: var(--sb-active-bg, #2563eb);
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
        background-color: #3b82f6;
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
        <a href="index.php" class="brand-logo">
            <i class="bi bi-cpu text-primary"></i>
            <span class="brand-title">SyncDesk</span>
        </a>
        <div class="tenant-card">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-building text-primary"></i>
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
        
        <!-- 2. Atendimentos (Disabled) -->
        <li class="menu-item disabled-menu">
            <a href="#" class="menu-link" tabindex="-1" aria-disabled="true">
                <i class="bi bi-chat-left-text"></i>
                <span>Atendimentos</span>
            </a>
        </li>
        
        <!-- 3. Clientes/Ativos (Active) -->
        <li class="menu-item">
            <a href="manage_assets.php" class="menu-link <?= $sb_current_page == 'manage_assets.php' ? 'active' : '' ?>">
                <i class="bi bi-people"></i>
                <span>Clientes</span>
            </a>
        </li>
        
        <!-- 4. Knowledge Base (Disabled) -->
        <li class="menu-item disabled-menu">
            <a href="#" class="menu-link" tabindex="-1" aria-disabled="true">
                <i class="bi bi-journal-text"></i>
                <span>Knowledge Base</span>
            </a>
        </li>
        
        <!-- 5. Tarefas (Active - Renamed to tasks.php) -->
        <li class="menu-item">
            <a href="tasks.php" class="menu-link <?= $sb_current_page == 'tasks.php' ? 'active' : '' ?>">
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
        
        <!-- 8. Notificações (Disabled) -->
        <li class="menu-item disabled-menu">
            <a href="#" class="menu-link" tabindex="-1" aria-disabled="true">
                <i class="bi bi-bell"></i>
                <span>Notificações</span>
            </a>
        </li>
        
        <!-- 9. Configurações (Disabled) -->
        <li class="menu-item disabled-menu">
            <a href="#" class="menu-link" tabindex="-1" aria-disabled="true">
                <i class="bi bi-gear"></i>
                <span>Configurações</span>
            </a>
        </li>
        
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
