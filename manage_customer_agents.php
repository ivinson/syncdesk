<?php
// Initialize UserSpice security and environment
require_once 'users/init.php';

// Secure the page
if (!isset($bypass_secure_page) && !securePage(Server::get('PHP_SELF'))) {
    Redirect::to($us_url_root . 'users/login.php');
    exit;
}

$user_id = $user->data()->id;
$username = htmlspecialchars($user->data()->username);
$fname = htmlspecialchars($user->data()->fname);
$lname = htmlspecialchars($user->data()->lname);
$full_name = trim($fname . ' ' . $lname) ?: $username;

$is_admin = hasPerm([2], $user_id);
$role_title = $is_admin ? 'Administrador' : 'Atendente';

// Hard Block for Non-Administrators
if (!$is_admin) {
    Redirect::to('agent_dashboard.php?success=' . urlencode("Acesso negado: Página exclusiva para administradores."));
    exit;
}

$db = DB::getInstance();
$error_msg = "";
$success_msg = "";

// Capture success message from redirect URL
if (Input::get('success')) {
    $success_msg = Input::get('success');
}

// ==========================================
// PIVOT TABLE UPDATES (POST Requests)
// ==========================================
if (Input::exists()) {
    if (Token::check(Input::get('csrf'))) {
        $action = Input::get('action');
        
        if ($action === 'update_customer_agents') {
            $customer_id = (int)Input::get('customer_id');
            $agent_ids = Input::get('agent_ids'); // Array of user IDs
            
            if ($customer_id > 0) {
                // Perform transactional update
                $db->beginTransaction();
                try {
                    // Remove all existing associations for this customer
                    $db->query("DELETE FROM customer_agent WHERE customer_id = ?", [$customer_id]);
                    
                    // Insert new associations
                    if (is_array($agent_ids)) {
                        foreach ($agent_ids as $uid) {
                            $uid = (int)$uid;
                            if ($uid > 0) {
                                $db->insert('customer_agent', [
                                    'customer_id' => $customer_id,
                                    'user_id' => $uid
                                ]);
                            }
                        }
                    }
                    $db->commit();
                    Redirect::to('manage_customer_agents.php?success=' . urlencode("Associações de atendentes atualizadas com sucesso!"));
                } catch (Exception $e) {
                    $db->rollBack();
                    $error_msg = "Erro ao salvar atribuições: " . $e->getMessage();
                }
            } else {
                $error_msg = "Erro: Cliente inválido informado.";
            }
        }
    } else {
        $error_msg = "Erro: Validação de token CSRF falhou.";
    }
}

// ==========================================
// DATA RETRIEVAL
// ==========================================

// Get all active users (agents/admins) for selection list
$all_agents = $db->query("SELECT id, fname, lname, username FROM users WHERE active = 1 ORDER BY fname ASC, username ASC")->results();

// Get all active customers
$all_customers = $db->query("SELECT * FROM customers WHERE status = 1 ORDER BY name ASC")->results();

// Get pivot mapping (Agents assigned to each customer) to optimize queries (no N+1 loops)
$agents_by_customer = [];
$pivot_sql = "
    SELECT ca.customer_id, ca.user_id, u.fname, u.lname, u.username 
    FROM customer_agent ca 
    JOIN users u ON ca.user_id = u.id 
    WHERE u.active = 1";
$pivot_results = $db->query($pivot_sql)->results();

foreach ($pivot_results as $row) {
    $agents_by_customer[$row->customer_id][] = [
        'id' => $row->user_id,
        'name' => trim($row->fname . ' ' . $row->lname) ?: $row->username
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncDesk - Vincular Atendentes</title>
    <!-- Google Fonts: Inter & Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    
    <style>
        :root {
            --sb-bg: #0b0f19;
            --sb-active-bg: #2563eb;
            --sb-text: #94a3b8;
            --sb-active-text: #ffffff;
            --main-bg: #f8fafc;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--main-bg);
            color: var(--text-main);
            overflow-x: hidden;
        }

        h1, h2, h3, h4, .brand-title {
            font-family: 'Outfit', sans-serif;
        }

        .wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styling */
        .sidebar {
            width: 260px;
            background-color: var(--sb-bg);
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            padding: 1.5rem 1rem;
            color: var(--sb-text);
            transition: all 0.3s ease;
            box-shadow: 4px 0 10px rgba(0,0,0,0.05);
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
            list-style: none;
            padding: 0;
            margin: 0;
            flex-grow: 1;
        }

        .menu-item {
            margin-bottom: 0.4rem;
        }

        .menu-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0.75rem 1rem;
            color: var(--sb-text);
            text-decoration: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .menu-link:hover {
            background: rgba(255, 255, 255, 0.05);
            color: #ffffff;
        }

        .menu-link.active {
            background-color: var(--sb-active-bg);
            color: var(--sb-active-text);
            font-weight: 600;
        }

        .menu-link i {
            font-size: 1.15rem;
        }

        .sidebar-footer {
            border-top: 1px solid rgba(255,255,255,0.05);
            padding-top: 1.25rem;
            margin-top: auto;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .profile-avatar {
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

        .profile-info {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .profile-name {
            color: #ffffff;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .profile-role {
            font-size: 0.75rem;
            color: var(--sb-text);
        }

        /* Main Content Styling */
        .main-content {
            flex-grow: 1;
            padding: 2rem 2.5rem;
            overflow-y: auto;
            max-height: 100vh;
        }

        /* Clean Dense Table Styling */
        .table-container {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            margin-bottom: 2rem;
        }

        .custom-table {
            margin: 0;
            vertical-align: middle;
        }

        .custom-table thead th {
            background-color: #f8fafc;
            color: var(--text-muted);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--card-border);
        }

        .custom-table tbody td {
            padding: 1rem;
            font-size: 0.88rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .custom-table tbody tr:last-child td {
            border-bottom: none;
        }

        .customer-title {
            font-weight: 600;
            color: var(--text-main);
        }

        .agent-list-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .agent-badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background-color: #f1f5f9;
            color: #334155;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 500;
            border: 1px solid #e2e8f0;
        }

        .agent-avatar-micro {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background-color: #3b82f6;
            color: #ffffff;
            font-size: 0.55rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .custom-alert {
            border-radius: 12px;
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <!-- Sidebar Navigation -->
    <aside class="sidebar">
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

        <ul class="sidebar-menu">
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <i class="bi bi-grid-1x2-fill"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <i class="bi bi-chat-left-text"></i>
                    <span>Atendimentos</span>
                </a>
            </li>
            <li class="menu-item">
                <a href="manage_assets.php" class="menu-link">
                    <i class="bi bi-people"></i>
                    <span>Clientes</span>
                </a>
            </li>
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <i class="bi bi-journal-text"></i>
                    <span>Knowledge Base</span>
                </a>
            </li>
            <li class="menu-item">
                <a href="agent_dashboard.php" class="menu-link">
                    <i class="bi bi-check2-square"></i>
                    <span>Tarefas</span>
                </a>
            </li>
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <i class="bi bi-folder2"></i>
                    <span>Projetos</span>
                </a>
            </li>
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <i class="bi bi-bar-chart"></i>
                    <span>Relatórios</span>
                </a>
            </li>
            <li class="menu-item">
                <a href="#" class="menu-link d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-bell"></i>
                        <span>Notificações</span>
                    </div>
                    <span class="badge bg-primary rounded-pill" style="font-size: 0.7rem;">12</span>
                </a>
            </li>
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <i class="bi bi-gear"></i>
                    <span>Configurações</span>
                </a>
            </li>
            
            <?php if ($is_admin): ?>
                <li class="menu-item">
                    <a href="manage_customer_agents.php" class="menu-link active">
                        <i class="bi bi-person-gear"></i>
                        <span>Vincular Equipe</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>

        <div class="sidebar-footer">
            <div class="profile-avatar">
                <?= strtoupper(substr($full_name, 0, 1)) ?>
            </div>
            <div class="profile-info">
                <span class="profile-name" title="<?= $full_name ?>"><?= $full_name ?></span>
                <span class="profile-role"><?= $role_title ?></span>
            </div>
            <a href="users/logout.php" class="ms-auto text-muted hover-white" title="Sair">
                <i class="bi bi-box-arrow-right fs-5"></i>
            </a>
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="main-content">
        <div class="mb-4">
            <h2 class="mb-1 fw-bold">Atribuição de Equipe</h2>
            <p class="text-muted mb-0">Associe atendentes operacionais aos clientes para definir os níveis de visibilidade (multitenancy) no SyncDesk.</p>
        </div>

        <!-- Success & Error Messages -->
        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show custom-alert p-3 mb-4" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($success_msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger alert-dismissible fade show custom-alert p-3 mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error_msg ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Customers & Assigned Agents Table -->
        <div class="table-container">
            <div class="table-responsive">
                <table class="table custom-table">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Empresa / Razão Social</th>
                            <th>Atendentes Vinculados</th>
                            <th width="150" class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_customers)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-5 text-muted">
                                    <i class="bi bi-people fs-2 d-block mb-2"></i>
                                    Nenhum cliente cadastrado no sistema.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($all_customers as $cust): 
                                $assigned = isset($agents_by_customer[$cust->id]) ? $agents_by_customer[$cust->id] : [];
                                $assigned_ids = array_column($assigned, 'id');
                                $assigned_json = json_encode($assigned_ids);
                            ?>
                                <tr>
                                    <td>
                                        <span class="customer-title"><?= htmlspecialchars($cust->name) ?></span>
                                    </td>
                                    <td>
                                        <span class="text-muted"><?= htmlspecialchars($cust->company_name) ?></span>
                                    </td>
                                    <td>
                                        <div class="agent-list-badges">
                                            <?php if (empty($assigned)): ?>
                                                <span class="text-danger small fw-semibold"><i class="bi bi-exclamation-circle me-1"></i>Nenhum atendente vinculado (Sem acesso)</span>
                                            <?php else: ?>
                                                <?php foreach ($assigned as $ag): ?>
                                                    <span class="agent-badge-pill" title="ID Usuário: <?= $ag['id'] ?>">
                                                        <span class="agent-avatar-micro"><?= strtoupper(substr($ag['name'], 0, 1)) ?></span>
                                                        <?= htmlspecialchars($ag['name']) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-outline-primary btn-sm rounded-3 px-3 manage-links-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#assignAgentsModal"
                                                data-customer-id="<?= $cust->id ?>"
                                                data-customer-name="<?= htmlspecialchars($cust->name) ?>"
                                                data-assigned-agents='<?= $assigned_json ?>'>
                                            <i class="bi bi-person-plus me-1"></i> Vincular
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- ==========================================
      MODAL: ASSIGN AGENTS (Bootstrap 5)
     ========================================== -->
<div class="modal fade" id="assignAgentsModal" tabindex="-1" aria-labelledby="assignAgentsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                <input type="hidden" name="action" value="update_customer_agents">
                <input type="hidden" name="customer_id" id="modal_customer_id">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="assignAgentsModalLabel"><i class="bi bi-person-gear text-primary me-2"></i>Vincular Equipe</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-white rounded-bottom-4">
                    <p class="text-muted small mb-3">Selecione quais atendentes operacionais têm autorização para gerenciar ativos e visualizar as tarefas de:</p>
                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3" id="modal_customer_name"></h6>
                    
                    <div class="mb-3" style="max-height: 280px; overflow-y: auto; padding: 0.5rem 0.2rem;">
                        <?php if (empty($all_agents)): ?>
                            <p class="text-center text-muted small py-3">Nenhum atendente ativo encontrado.</p>
                        <?php else: ?>
                            <?php foreach ($all_agents as $ag): 
                                $ag_full_name = trim($ag->fname . ' ' . $ag->lname) ?: $ag->username;
                            ?>
                                <div class="form-check p-2 rounded-2 hover-bg-light border-bottom border-light-subtle">
                                    <input class="form-check-input ms-0 me-3" type="checkbox" name="agent_ids[]" value="<?= $ag->id ?>" id="agent_chk_<?= $ag->id ?>">
                                    <label class="form-check-label fw-medium text-dark cursor-pointer" for="agent_chk_<?= $ag->id ?>">
                                        <?= htmlspecialchars($ag_full_name) ?> <span class="text-muted small fw-normal">(<?= htmlspecialchars($ag->username) ?>)</span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="d-flex gap-2 justify-content-end mt-4">
                        <button type="button" class="btn btn-light rounded-3 px-4" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary rounded-3 px-4">Salvar Alterações</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap 5 Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Dynamic Modal Checklist Population Logic -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const assignAgentsModal = document.getElementById('assignAgentsModal');
        
        if (assignAgentsModal) {
            assignAgentsModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const customerId = button.getAttribute('data-customer-id');
                const customerName = button.getAttribute('data-customer-name');
                const assignedIds = JSON.parse(button.getAttribute('data-assigned-agents') || '[]');
                
                document.getElementById('modal_customer_id').value = customerId;
                document.getElementById('modal_customer_name').textContent = customerName;
                
                // Clear all checkboxes first
                const checkboxes = assignAgentsModal.querySelectorAll('input[type="checkbox"][name="agent_ids[]"]');
                checkboxes.forEach(chk => {
                    chk.checked = false;
                });
                
                // Pre-check the assigned agent checkboxes
                assignedIds.forEach(uid => {
                    const chk = document.getElementById('agent_chk_' + uid);
                    if (chk) {
                        chk.checked = true;
                    }
                });
            });
        }
    });
</script>
</body>
</html>
