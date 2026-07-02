<?php
// Initialize UserSpice security and environment
require_once 'users/init.php';

// Secure the page - UserSpice redirects automatically if the user has no permissions or is logged out
if (!isset($bypass_secure_page) && !securePage(Server::get('PHP_SELF'))) {
    // Fallback if securePage doesn't automatically redirect
    Redirect::to($us_url_root . 'users/login.php');
    exit;
}

// Get logged in user details
$user_id = $user->data()->id;
$username = htmlspecialchars($user->data()->username);
$fname = htmlspecialchars($user->data()->fname);
$lname = htmlspecialchars($user->data()->lname);
$full_name = trim($fname . ' ' . $lname) ?: $username;

// Check if user is Administrator
$is_admin = hasPerm([2], $user_id);
$role_title = $is_admin ? 'Administrador' : 'Atendente';

$db = DB::getInstance();
$error_msg = "";
$success_msg = "";

// Capture success message from redirect URL
if (Input::get('success')) {
    $success_msg = Input::get('success');
}

// ==========================================
// CRUD OPERATIONS HANDLER (POST Requests)
// ==========================================
if (Input::exists()) {
    if (Token::check(Input::get('csrf'))) {
        $action = Input::get('action');
        
        // 1. QUICK STATUS UPDATE FROM TABLE
        if ($action === 'quick_status_update') {
            $task_id = Input::get('task_id');
            $new_status = Input::get('status');
            
            if (in_array($new_status, ['pending', 'in_progress', 'completed'])) {
                $task_query = $db->query("SELECT * FROM tasks WHERE id = ?", [$task_id]);
                if ($task_query->count() > 0) {
                    $task_data = $task_query->first();
                    $can_update = false;
                    if ($is_admin) {
                        $can_update = true;
                    } else if ($task_data->assigned_to == $user_id) {
                        $can_update = true;
                    }
                    
                    if ($can_update) {
                        $db->query("UPDATE tasks SET status = ? WHERE id = ?", [$new_status, $task_id]);
                        $status_label = $new_status == 'pending' ? 'Pendente' : ($new_status == 'in_progress' ? 'Em andamento' : 'Concluído');
                        Redirect::to('tasks.php?success=' . urlencode("Status da tarefa #{$task_id} alterado para '{$status_label}'!"));
                    } else {
                        $error_msg = "Erro: Você não tem permissão para atualizar esta tarefa.";
                    }
                } else {
                    $error_msg = "Erro: Tarefa não encontrada.";
                }
            } else {
                $error_msg = "Erro: Status inválido informado.";
            }
        }
        
        // 2. CREATE TASK
        else if ($action === 'create_task') {
            $title = trim(Input::get('title'));
            $description = trim(Input::get('description'));
            $priority = Input::get('priority');
            $customer_id = (int)Input::get('customer_id');
            $assigned_to = $is_admin ? (int)Input::get('assigned_to') : $user_id;
            
            $errors = [];
            if (empty($title)) $errors[] = "O título é obrigatório.";
            if (!in_array($priority, ['low', 'medium', 'high'])) $errors[] = "Prioridade inválida.";
            if ($customer_id <= 0) $errors[] = "Cliente inválido.";
            
            // If Agent, validate that the selected customer belongs to this Agent
            if (!$is_admin) {
                $check_ca = $db->query("SELECT * FROM customer_agent WHERE customer_id = ? AND user_id = ?", [$customer_id, $user_id]);
                if ($check_ca->count() === 0) {
                    $errors[] = "Acesso negado: Este cliente não está associado à sua conta.";
                }
                $assigned_to = $user_id; // Agents can only assign to themselves
            }
            
            if (empty($errors)) {
                $db->insert('tasks', [
                    'customer_id' => $customer_id,
                    'assigned_to' => $assigned_to,
                    'title' => $title,
                    'description' => $description,
                    'priority' => $priority,
                    'status' => 'pending'
                ]);
                Redirect::to('tasks.php?success=' . urlencode("Tarefa '{$title}' criada com sucesso!"));
            } else {
                $error_msg = implode("<br>", $errors);
            }
        }
        
        // 3. EDIT TASK
        else if ($action === 'edit_task') {
            $task_id = (int)Input::get('task_id');
            $title = trim(Input::get('title'));
            $description = trim(Input::get('description'));
            $priority = Input::get('priority');
            $status = Input::get('status');
            $customer_id = (int)Input::get('customer_id');
            $assigned_to = $is_admin ? (int)Input::get('assigned_to') : $user_id;
            
            $errors = [];
            if (empty($title)) $errors[] = "O título é obrigatório.";
            if (!in_array($priority, ['low', 'medium', 'high'])) $errors[] = "Prioridade inválida.";
            if (!in_array($status, ['pending', 'in_progress', 'completed'])) $errors[] = "Status inválido.";
            if ($customer_id <= 0) $errors[] = "Cliente inválido.";
            
            // Check authorization to edit
            $task_query = $db->query("SELECT * FROM tasks WHERE id = ?", [$task_id]);
            if ($task_query->count() > 0) {
                $task_data = $task_query->first();
                $can_edit = false;
                if ($is_admin) {
                    $can_edit = true;
                } else if ($task_data->assigned_to == $user_id) {
                    $can_edit = true;
                    $assigned_to = $user_id; // Agents cannot re-assign tasks
                }
                
                if ($can_edit) {
                    // If Agent, validate customer association
                    if (!$is_admin) {
                        $check_ca = $db->query("SELECT * FROM customer_agent WHERE customer_id = ? AND user_id = ?", [$customer_id, $user_id]);
                        if ($check_ca->count() === 0) {
                            $errors[] = "Acesso negado: Este cliente não está associado à sua conta.";
                        }
                    }
                    
                    if (empty($errors)) {
                        $db->query("UPDATE tasks SET customer_id = ?, assigned_to = ?, title = ?, description = ?, priority = ?, status = ? WHERE id = ?", [
                            $customer_id, $assigned_to, $title, $description, $priority, $status, $task_id
                        ]);
                        Redirect::to('tasks.php?success=' . urlencode("Tarefa #{$task_id} atualizada com sucesso!"));
                    } else {
                        $error_msg = implode("<br>", $errors);
                    }
                } else {
                    $error_msg = "Erro: Você não tem permissão para editar esta tarefa.";
                }
            } else {
                $error_msg = "Erro: Tarefa não encontrada.";
            }
        }
        
        // 4. DELETE TASK
        else if ($action === 'delete_task') {
            $task_id = (int)Input::get('task_id');
            
            $task_query = $db->query("SELECT * FROM tasks WHERE id = ?", [$task_id]);
            if ($task_query->count() > 0) {
                $task_data = $task_query->first();
                $can_delete = false;
                if ($is_admin) {
                    $can_delete = true;
                } else if ($task_data->assigned_to == $user_id) {
                    $can_delete = true;
                }
                
                if ($can_delete) {
                    $db->query("DELETE FROM tasks WHERE id = ?", [$task_id]);
                    Redirect::to('tasks.php?success=' . urlencode("Tarefa #{$task_id} excluída com sucesso!"));
                } else {
                    $error_msg = "Erro: Você não tem permissão para excluir esta tarefa.";
                }
            } else {
                $error_msg = "Erro: Tarefa não encontrada.";
            }
        }
    } else {
        $error_msg = "Erro: Validação de token CSRF falhou. Tente novamente.";
    }
}

// ==========================================
// DATA SELECTS FOR FORMS AND STATS
// ==========================================

// Get available customers and agents for selectors based on role
if ($is_admin) {
    $available_customers = $db->query("SELECT id, name, company_name FROM customers WHERE status = 1 ORDER BY name ASC")->results();
    $available_agents = $db->query("SELECT id, fname, lname, username FROM users WHERE active = 1 ORDER BY fname ASC, username ASC")->results();
} else {
    $available_customers = $db->query("
        SELECT c.id, c.name, c.company_name 
        FROM customers c 
        JOIN customer_agent ca ON c.id = ca.customer_id 
        WHERE ca.user_id = ? AND c.status = 1 
        ORDER BY c.name ASC", [$user_id])->results();
    $available_agents = [];
}

// ==========================================
// TASK FILTERS AND STATS (Multitenant Logic)
// ==========================================

// Build basic WHERE conditions for filters (GET Requests)
$where_clauses = [];
$params = [];

if (!$is_admin) {
    $where_clauses[] = "t.assigned_to = ?";
    $params[] = $user_id;
}

// Apply Priority Filter
$filter_priority = Input::get('priority');
if ($filter_priority && in_array($filter_priority, ['low', 'medium', 'high'])) {
    $where_clauses[] = "t.priority = ?";
    $params[] = $filter_priority;
}

// Apply Status Filter
$filter_status = Input::get('status');
if ($filter_status && in_array($filter_status, ['pending', 'in_progress', 'completed'])) {
    $where_clauses[] = "t.status = ?";
    $params[] = $filter_status;
}

$where_sql = "";
if (count($where_clauses) > 0) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

// 1. Task Counters (Kept global/total for user workload awareness)
if ($is_admin) {
    $cnt_all = $db->query("SELECT COUNT(*) AS total FROM tasks")->first()->total;
    $cnt_pending = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE status = 'pending'")->first()->total;
    $cnt_in_progress = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE status = 'in_progress'")->first()->total;
    $cnt_completed = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE status = 'completed'")->first()->total;
    $cnt_customers = $db->query("SELECT COUNT(*) AS total FROM customers WHERE status = 1")->first()->total;
} else {
    $cnt_all = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE assigned_to = ?", [$user_id])->first()->total;
    $cnt_pending = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE assigned_to = ? AND status = 'pending'", [$user_id])->first()->total;
    $cnt_in_progress = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE assigned_to = ? AND status = 'in_progress'", [$user_id])->first()->total;
    $cnt_completed = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE assigned_to = ? AND status = 'completed'", [$user_id])->first()->total;
    $cnt_customers = $db->query("SELECT COUNT(DISTINCT customer_id) AS total FROM customer_agent WHERE user_id = ?", [$user_id])->first()->total;
}

// 2. Fetch Filtered Tasks
$tasks_sql = "
    SELECT t.*, c.name AS customer_name, c.company_name AS customer_company, u.fname, u.lname, u.username AS agent_username
    FROM tasks t
    JOIN customers c ON t.customer_id = c.id
    JOIN users u ON t.assigned_to = u.id
    $where_sql
    ORDER BY 
      CASE t.status
        WHEN 'in_progress' THEN 1
        WHEN 'pending' THEN 2
        WHEN 'completed' THEN 3
      END, 
      CASE t.priority
        WHEN 'high' THEN 1
        WHEN 'medium' THEN 2
        WHEN 'low' THEN 3
      END,
      t.updated_at DESC";
$tasks = $db->query($tasks_sql, $params)->results();

// 3. Fetch Customer Assets
if ($is_admin) {
    $assets_list = $db->query("SELECT * FROM assets")->results();
} else {
    $assets_list = $db->query("
        SELECT a.* 
        FROM assets a 
        JOIN customer_agent ca ON a.customer_id = ca.customer_id 
        WHERE ca.user_id = ?", [$user_id])->results();
}

$assets_by_customer = [];
foreach ($assets_list as $asset) {
    $assets_by_customer[$asset->customer_id][] = [
        'id' => $asset->id,
        'name' => $asset->name,
        'type' => $asset->type,
        'settings' => json_decode($asset->settings, true)
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncDesk - Gestão de Tarefas</title>
    <!-- Google Fonts: Inter & Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    
    <!-- Custom Premium CSS Styling (Axis360 Inspired) -->
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

        /* Top bar styling */
        .top-search-bar {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.5rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }

        .top-search-input {
            border: none;
            outline: none;
            width: 100%;
            font-size: 0.9rem;
        }

        /* Stat Cards Styling */
        .stats-row {
            margin-bottom: 2.25rem;
        }

        .stat-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 14px;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: transform 0.2s, box-shadow 0.2s;
            text-decoration: none;
            color: inherit;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }

        .stat-icon-blue { background-color: #eff6ff; color: #3b82f6; }
        .stat-icon-orange { background-color: #fff7ed; color: #f97316; }
        .stat-icon-purple { background-color: #faf5ff; color: #a855f7; }
        .stat-icon-green { background-color: #f0fdf4; color: #22c55e; }
        .stat-icon-dark { background-color: #f8fafc; color: #475569; }

        .stat-info {
            display: flex;
            flex-direction: column;
        }

        .stat-title {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-muted);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
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

        .table-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding: 0 0.5rem;
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

        .task-title-link {
            font-weight: 600;
            color: var(--text-main);
            text-decoration: none;
            display: block;
        }

        .task-title-link:hover {
            color: var(--sb-active-bg);
        }

        .task-meta {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.2rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .customer-link {
            font-weight: 600;
            color: #2563eb;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .customer-link:hover {
            text-decoration: underline;
        }

        .agent-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
        }

        .agent-avatar-small {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background-color: #cbd5e1;
            color: #475569;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        /* priority badges */
        .priority-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 6px;
            text-align: center;
            min-width: 65px;
        }

        .priority-high { background-color: #fef2f2; color: #ef4444; }
        .priority-medium { background-color: #fffbeb; color: #f59e0b; }
        .priority-low { background-color: #f0fdf4; color: #10b981; }

        /* status selector */
        .status-select {
            font-size: 0.8rem;
            font-weight: 600;
            border-radius: 8px;
            padding: 0.3rem 1.8rem 0.3rem 0.6rem;
            border: 1px solid transparent;
            cursor: pointer;
            width: 140px;
            transition: all 0.2s ease;
        }

        .status-select:focus {
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
            border-color: #cbd5e1;
        }

        .status-select.status-pending {
            background-color: #fff7ed;
            color: #ea580c;
            border-color: #fed7aa;
        }

        .status-select.status-in_progress {
            background-color: #eff6ff;
            color: #2563eb;
            border-color: #bfdbfe;
        }

        .status-select.status-completed {
            background-color: #f0fdf4;
            color: #16a34a;
            border-color: #bbf7d0;
        }

        /* Asset Modal Styling */
        .modal-content {
            border-radius: 18px;
            border: none;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .modal-header {
            border-bottom: 1px solid #f1f5f9;
            padding: 1.5rem 1.5rem 1rem 1.5rem;
        }

        .modal-body {
            padding: 1.5rem;
            background-color: #f8fafc;
            border-bottom-left-radius: 18px;
            border-bottom-right-radius: 18px;
        }

        .asset-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.01);
        }

        .asset-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }

        .asset-type-badge {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            text-transform: uppercase;
        }

        .type-meta_bm { background-color: #eff6ff; color: #2563eb; }
        .type-n8n_workflow { background-color: #fdf2f8; color: #db2777; }
        .type-ia_instance { background-color: #faf5ff; color: #9333ea; }
        .type-other { background-color: #f8fafc; color: #475569; }

        .settings-table {
            font-size: 0.8rem;
            margin: 0;
            background: #f8fafc;
            border-radius: 6px;
        }

        .settings-table td {
            padding: 0.4rem 0.6rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .settings-table tr:last-child td {
            border-bottom: none;
        }

        .custom-alert {
            border-radius: 12px;
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            font-size: 0.9rem;
            font-weight: 500;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <!-- Sidebar Navigation -->
    <?php require_once 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <main class="main-content">
        <!-- Top Search Bar -->
        <header class="top-search-bar">
            <i class="bi bi-search text-muted"></i>
            <input type="text" class="top-search-input" placeholder="Busque por tarefas, clientes, atendimentos... (Ctrl + K)">
            <span class="badge bg-light text-muted border" style="font-size: 0.7rem;">Ctrl + K</span>
            
            <div class="d-flex align-items-center gap-3 ms-auto">
                <button class="btn btn-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;" data-bs-toggle="modal" data-bs-target="#createTaskModal" title="Criar Nova Tarefa">
                    <i class="bi bi-plus fs-5"></i>
                </button>
                <i class="bi bi-bell text-muted fs-5 position-relative" style="cursor:pointer;">
                    <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle"></span>
                </i>
                <i class="bi bi-question-circle text-muted fs-5" style="cursor:pointer;"></i>
                <div class="profile-avatar" style="width: 32px; height: 32px; font-size: 0.8rem;">
                    <?= strtoupper(substr($full_name, 0, 1)) ?>
                </div>
            </div>
        </header>

        <!-- Page Title & Header Actions -->
        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <h2 class="mb-1 fw-bold">Tarefas</h2>
                <p class="text-muted mb-0">Gerencie todas as tarefas atribuídas e acompanhe o progresso operacional.</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm px-3 rounded-3 d-inline-flex align-items-center gap-2" style="font-weight: 500;">
                    <i class="bi bi-download"></i> Exportar
                </button>
                <button class="btn btn-primary btn-sm px-3 rounded-3 d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#createTaskModal" style="font-weight: 500;">
                    <i class="bi bi-plus-lg"></i> Nova tarefa
                </button>
            </div>
        </div>

        <!-- Success & Error Alert Messages -->
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

        <!-- Stat Counter Cards Row -->
        <div class="row g-3 stats-row">
            <div class="col-md-2-4 col-sm-6 col-12">
                <a href="tasks.php" class="stat-card">
                    <div class="stat-icon stat-icon-blue">
                        <i class="bi bi-list-task"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-title">Todas</span>
                        <span class="stat-value"><?= $cnt_all ?></span>
                    </div>
                </a>
            </div>
            <div class="col-md-2-4 col-sm-6 col-12">
                <a href="tasks.php?status=pending" class="stat-card">
                    <div class="stat-icon stat-icon-orange">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-title">Pendentes</span>
                        <span class="stat-value"><?= $cnt_pending ?></span>
                    </div>
                </a>
            </div>
            <div class="col-md-2-4 col-sm-6 col-12">
                <a href="tasks.php?status=in_progress" class="stat-card">
                    <div class="stat-icon stat-icon-purple">
                        <i class="bi bi-play-circle"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-title">Em andamento</span>
                        <span class="stat-value"><?= $cnt_in_progress ?></span>
                    </div>
                </a>
            </div>
            <div class="col-md-2-4 col-sm-6 col-12">
                <a href="tasks.php?status=completed" class="stat-card">
                    <div class="stat-icon stat-icon-green">
                        <i class="bi bi-check-all"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-title">Concluídas</span>
                        <span class="stat-value"><?= $cnt_completed ?></span>
                    </div>
                </a>
            </div>
            <div class="col-md-2-4 col-sm-6 col-12">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-dark">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div class="stat-info">
                        <span class="stat-title">Clientes</span>
                        <span class="stat-value"><?= $cnt_customers ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Task Table Section -->
        <div class="table-container">
            <div class="table-header-row">
                <div class="d-flex align-items-center gap-3">
                    <ul class="nav nav-pills nav-fill bg-light p-1 rounded-3" style="font-size: 0.85rem; font-weight: 500;">
                        <li class="nav-item">
                            <a class="nav-link active py-1 px-3 text-primary bg-white shadow-sm rounded-2" href="tasks.php">Lista</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link py-1 px-3 text-muted" href="#">Quadro</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link py-1 px-3 text-muted" href="#">Minhas tarefas</a>
                        </li>
                    </ul>
                </div>
                
                <!-- Dynamic Filters Form -->
                <form method="GET" action="" id="filterForm" class="d-flex gap-2">
                    <select name="priority" class="form-select form-select-sm rounded-3 border-light-subtle" style="width: 130px; font-size:0.85rem;" onchange="this.form.submit()">
                        <option value="">Prioridade (Todas)</option>
                        <option value="low" <?= Input::get('priority') == 'low' ? 'selected' : '' ?>>Baixa</option>
                        <option value="medium" <?= Input::get('priority') == 'medium' ? 'selected' : '' ?>>Média</option>
                        <option value="high" <?= Input::get('priority') == 'high' ? 'selected' : '' ?>>Alta</option>
                    </select>
                    <select name="status" class="form-select form-select-sm rounded-3 border-light-subtle" style="width: 130px; font-size:0.85rem;" onchange="this.form.submit()">
                        <option value="">Status (Todos)</option>
                        <option value="pending" <?= Input::get('status') == 'pending' ? 'selected' : '' ?>>Pendente</option>
                        <option value="in_progress" <?= Input::get('status') == 'in_progress' ? 'selected' : '' ?>>Em andamento</option>
                        <option value="completed" <?= Input::get('status') == 'completed' ? 'selected' : '' ?>>Concluído</option>
                    </select>
                    <?php if (Input::get('priority') || Input::get('status')): ?>
                        <a href="tasks.php" class="btn btn-outline-secondary btn-sm rounded-3 d-inline-flex align-items-center" title="Limpar Filtros">
                            <i class="bi bi-x-circle"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table custom-table">
                    <thead>
                        <tr>
                            <th width="40"><input type="checkbox" class="form-check-input"></th>
                            <th>Tarefa</th>
                            <th>Cliente</th>
                            <th>Responsável</th>
                            <th>Prioridade</th>
                            <th>Status</th>
                            <th width="50" class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tasks)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                    Nenhuma tarefa cadastrada ou correspondente aos filtros.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tasks as $task): 
                                $agent_name = trim($task->fname . ' ' . $task->lname) ?: $task->agent_username;
                                $agent_initials = strtoupper(substr($agent_name, 0, 1));
                                
                                $status_class = 'status-' . $task->status;
                                $priority_class = 'priority-' . $task->priority;
                                $priority_label = $task->priority == 'high' ? 'Alta' : ($task->priority == 'medium' ? 'Média' : 'Baixa');
                                
                                $cust_assets = isset($assets_by_customer[$task->customer_id]) ? $assets_by_customer[$task->customer_id] : [];
                                $assets_json = json_encode($cust_assets);
                            ?>
                                <tr>
                                    <td><input type="checkbox" class="form-check-input"></td>
                                    <td>
                                        <a href="#" class="task-title-link edit-task-trigger-link" 
                                           data-bs-toggle="modal" 
                                           data-bs-target="#editTaskModal"
                                           data-task-id="<?= $task->id ?>"
                                           data-task-title="<?= htmlspecialchars($task->title) ?>"
                                           data-task-desc="<?= htmlspecialchars($task->description ?? '') ?>"
                                           data-task-priority="<?= $task->priority ?>"
                                           data-task-status="<?= $task->status ?>"
                                           data-task-customer-id="<?= $task->customer_id ?>"
                                           data-task-assigned-to="<?= $task->assigned_to ?>"><?= htmlspecialchars($task->title) ?></a>
                                        <div class="task-meta">
                                            <span class="badge bg-light text-secondary border">#T-<?= $task->id ?></span>
                                            <span class="text-muted"><i class="bi bi-clock me-1"></i>Atualizado em: <?= date('d/m/Y H:i', strtotime($task->updated_at)) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="#" class="customer-link" 
                                           data-bs-toggle="modal" 
                                           data-bs-target="#customerAssetsModal"
                                           data-customer-name="<?= htmlspecialchars($task->customer_name) ?>"
                                           data-company-name="<?= htmlspecialchars($task->customer_company) ?>"
                                           data-assets='<?= htmlspecialchars($assets_json, ENT_QUOTES, 'UTF-8') ?>'>
                                            <?= htmlspecialchars($task->customer_name) ?>
                                            <i class="bi bi-box-arrow-up-right" style="font-size: 0.75rem;"></i>
                                        </a>
                                        <a href="manage_assets.php?customer_id=<?= $task->customer_id ?>" class="text-muted ms-2" title="Gerenciar Ativos do Cliente">
                                            <i class="bi bi-gear" style="font-size: 0.8rem;"></i>
                                        </a>
                                        <div class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($task->customer_company) ?></div>
                                    </td>
                                    <td>
                                        <div class="agent-badge">
                                            <div class="agent-avatar-small" title="<?= htmlspecialchars($agent_name) ?>">
                                                <?= $agent_initials ?>
                                            </div>
                                            <span><?= htmlspecialchars($agent_name) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="priority-badge <?= $priority_class ?>"><?= $priority_label ?></span>
                                    </td>
                                    <td>
                                        <!-- Inline Quick Status Updater Form -->
                                        <form method="POST" action="" class="d-inline">
                                            <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                                            <input type="hidden" name="action" value="quick_status_update">
                                            <input type="hidden" name="task_id" value="<?= $task->id ?>">
                                            <select name="status" class="form-select status-select <?= $status_class ?>" onchange="this.form.submit()">
                                                <option value="pending" <?= $task->status == 'pending' ? 'selected' : '' ?>>Pendente</option>
                                                <option value="in_progress" <?= $task->status == 'in_progress' ? 'selected' : '' ?>>Em andamento</option>
                                                <option value="completed" <?= $task->status == 'completed' ? 'selected' : '' ?>>Concluído</option>
                                            </select>
                                        </form>
                                    </td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-link text-muted p-0" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end" style="font-size: 0.85rem;">
                                                <li>
                                                    <a class="dropdown-item edit-task-btn" href="#" 
                                                       data-bs-toggle="modal" 
                                                       data-bs-target="#editTaskModal"
                                                       data-task-id="<?= $task->id ?>"
                                                       data-task-title="<?= htmlspecialchars($task->title) ?>"
                                                       data-task-desc="<?= htmlspecialchars($task->description ?? '') ?>"
                                                       data-task-priority="<?= $task->priority ?>"
                                                       data-task-status="<?= $task->status ?>"
                                                       data-task-customer-id="<?= $task->customer_id ?>"
                                                       data-task-assigned-to="<?= $task->assigned_to ?>">
                                                        <i class="bi bi-pencil me-2"></i>Editar Tarefa
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item text-danger delete-task-btn" href="#"
                                                       data-bs-toggle="modal"
                                                       data-bs-target="#deleteTaskModal"
                                                       data-task-id="<?= $task->id ?>"
                                                       data-task-title="<?= htmlspecialchars($task->title) ?>">
                                                        <i class="bi bi-trash me-2"></i>Excluir
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
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
      MODAL: CREATE TASK (Bootstrap 5)
     ========================================== -->
<div class="modal fade" id="createTaskModal" tabindex="-1" aria-labelledby="createTaskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                <input type="hidden" name="action" value="create_task">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="createTaskModalLabel"><i class="bi bi-plus-circle text-primary me-2"></i>Nova Tarefa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-white rounded-bottom-4">
                    <div class="mb-3">
                        <label for="create_title" class="form-label fw-semibold" style="font-size:0.9rem;">Título da Tarefa</label>
                        <input type="text" class="form-control rounded-3" id="create_title" name="title" required placeholder="Ex: Ajustar webhook do n8n">
                    </div>
                    <div class="mb-3">
                        <label for="create_description" class="form-label fw-semibold" style="font-size:0.9rem;">Descrição / Detalhes</label>
                        <textarea class="form-control rounded-3" id="create_description" name="description" rows="3" placeholder="Detalhes do que deve ser feito..."></textarea>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="create_priority" class="form-label fw-semibold" style="font-size:0.9rem;">Prioridade</label>
                            <select class="form-select rounded-3" id="create_priority" name="priority" required>
                                <option value="low">Baixa</option>
                                <option value="medium" selected>Média</option>
                                <option value="high">Alta</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="create_customer_id" class="form-label fw-semibold" style="font-size:0.9rem;">Cliente</label>
                            <select class="form-select rounded-3" id="create_customer_id" name="customer_id" required>
                                <option value="" disabled selected>Selecionar...</option>
                                <?php foreach ($available_customers as $cust): ?>
                                    <option value="<?= $cust->id ?>"><?= htmlspecialchars($cust->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <?php if ($is_admin): ?>
                        <div class="mb-3">
                            <label for="create_assigned_to" class="form-label fw-semibold" style="font-size:0.9rem;">Responsável (Atendente)</label>
                            <select class="form-select rounded-3" id="create_assigned_to" name="assigned_to" required>
                                <?php foreach ($available_agents as $agent): 
                                    $a_name = trim($agent->fname . ' ' . $agent->lname) ?: $agent->username;
                                ?>
                                    <option value="<?= $agent->id ?>" <?= $agent->id == $user_id ? 'selected' : '' ?>><?= htmlspecialchars($a_name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    
                    <div class="d-flex gap-2 justify-content-end mt-4">
                        <button type="button" class="btn btn-light rounded-3 px-4" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary rounded-3 px-4">Criar Tarefa</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
      MODAL: EDIT TASK (Bootstrap 5)
     ========================================== -->
<div class="modal fade" id="editTaskModal" tabindex="-1" aria-labelledby="editTaskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                <input type="hidden" name="action" value="edit_task">
                <input type="hidden" name="task_id" id="edit_task_id">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="editTaskModalLabel"><i class="bi bi-pencil-square text-primary me-2"></i>Editar Tarefa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-white rounded-bottom-4">
                    <div class="mb-3">
                        <label for="edit_title" class="form-label fw-semibold" style="font-size:0.9rem;">Título da Tarefa</label>
                        <input type="text" class="form-control rounded-3" id="edit_title" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label fw-semibold" style="font-size:0.9rem;">Descrição / Detalhes</label>
                        <textarea class="form-control rounded-3" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="edit_priority" class="form-label fw-semibold" style="font-size:0.9rem;">Prioridade</label>
                            <select class="form-select rounded-3" id="edit_priority" name="priority" required>
                                <option value="low">Baixa</option>
                                <option value="medium">Média</option>
                                <option value="high">Alta</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_status" class="form-label fw-semibold" style="font-size:0.9rem;">Status</label>
                            <select class="form-select rounded-3" id="edit_status" name="status" required>
                                <option value="pending">Pendente</option>
                                <option value="in_progress">Em andamento</option>
                                <option value="completed">Concluído</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_customer_id" class="form-label fw-semibold" style="font-size:0.9rem;">Cliente</label>
                        <select class="form-select rounded-3" id="edit_customer_id" name="customer_id" required>
                            <?php foreach ($available_customers as $cust): ?>
                                <option value="<?= $cust->id ?>"><?= htmlspecialchars($cust->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if ($is_admin): ?>
                        <div class="mb-3">
                            <label for="edit_assigned_to" class="form-label fw-semibold" style="font-size:0.9rem;">Responsável (Atendente)</label>
                            <select class="form-select rounded-3" id="edit_assigned_to" name="assigned_to" required>
                                <?php foreach ($available_agents as $agent): 
                                    $a_name = trim($agent->fname . ' ' . $agent->lname) ?: $agent->username;
                                ?>
                                    <option value="<?= $agent->id ?>"><?= htmlspecialchars($a_name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    
                    <div class="d-flex gap-2 justify-content-end mt-4">
                        <button type="button" class="btn btn-light rounded-3 px-4" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary rounded-3 px-4">Salvar Alterações</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
      MODAL: DELETE TASK (Bootstrap 5)
     ========================================== -->
<div class="modal fade" id="deleteTaskModal" tabindex="-1" aria-labelledby="deleteTaskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                <input type="hidden" name="action" value="delete_task">
                <input type="hidden" name="task_id" id="delete_task_id">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger" id="deleteTaskModalLabel"><i class="bi bi-exclamation-triangle me-2"></i>Excluir Tarefa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-white rounded-bottom-4 text-center">
                    <p class="mb-3">Tem certeza que deseja excluir permanentemente a tarefa:</p>
                    <p class="fw-bold mb-4 text-dark" id="delete_task_title"></p>
                    
                    <div class="d-flex gap-2 justify-content-center">
                        <button type="button" class="btn btn-light rounded-3 px-3" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger rounded-3 px-3">Sim, Excluir</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
      CUSTOMER ASSETS MODAL (Bootstrap 5)
     ========================================== -->
<div class="modal fade" id="customerAssetsModal" tabindex="-1" aria-labelledby="customerAssetsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold" id="customerAssetsModalLabel">Ativos do Cliente</h5>
                    <p class="text-muted mb-0" id="modalCustomerCompany" style="font-size:0.85rem;"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="modalAssetsList">
                    <!-- Dinamic Content Loaded via JavaScript -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap 5 Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Dynamic Modals JavaScript Logic -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        
        // 1. POPULATE EDIT TASK MODAL
        const editTaskModal = document.getElementById('editTaskModal');
        editTaskModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            
            // Extract data attributes
            const id = button.getAttribute('data-task-id');
            const title = button.getAttribute('data-task-title');
            const desc = button.getAttribute('data-task-desc');
            const priority = button.getAttribute('data-task-priority');
            const status = button.getAttribute('data-task-status');
            const customerId = button.getAttribute('data-task-customer-id');
            const assignedTo = button.getAttribute('data-task-assigned-to');
            
            // Set input values
            document.getElementById('edit_task_id').value = id;
            document.getElementById('edit_title').value = title;
            document.getElementById('edit_description').value = desc;
            document.getElementById('edit_priority').value = priority;
            document.getElementById('edit_status').value = status;
            document.getElementById('edit_customer_id').value = customerId;
            
            // Check if element exists (only rendered for Admins)
            const agentSelect = document.getElementById('edit_assigned_to');
            if (agentSelect) {
                agentSelect.value = assignedTo;
            }
        });
        
        // 2. POPULATE DELETE TASK CONFIRMATION MODAL
        const deleteTaskModal = document.getElementById('deleteTaskModal');
        deleteTaskModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const id = button.getAttribute('data-task-id');
            const title = button.getAttribute('data-task-title');
            
            document.getElementById('delete_task_id').value = id;
            document.getElementById('delete_task_title').textContent = title;
        });

        // 3. POPULATE CUSTOMER ASSETS MODAL
        const customerAssetsModal = document.getElementById('customerAssetsModal');
        customerAssetsModal.addEventListener('show.bs.modal', function(event) {
            const triggerLink = event.relatedTarget;
            const customerName = triggerLink.getAttribute('data-customer-name');
            const companyName = triggerLink.getAttribute('data-company-name');
            const assetsData = JSON.parse(triggerLink.getAttribute('data-assets') || '[]');
            
            const modalTitle = customerAssetsModal.querySelector('.modal-title');
            const modalCompany = document.getElementById('modalCustomerCompany');
            modalTitle.textContent = `Ativos de ${customerName}`;
            modalCompany.textContent = companyName;
            
            const assetsListContainer = document.getElementById('modalAssetsList');
            assetsListContainer.innerHTML = '';
            
            if (assetsData.length === 0) {
                assetsListContainer.innerHTML = `
                    <div class="text-center py-4 text-muted bg-white rounded-3 border">
                        <i class="bi bi-shield-slash fs-3 d-block mb-2 text-secondary"></i>
                        Nenhum ativo cadastrado para este cliente.
                    </div>
                `;
                return;
            }
            
            assetsData.forEach(asset => {
                const card = document.createElement('div');
                card.className = 'asset-card';
                
                let typeLabel = asset.type;
                let typeClass = 'type-' + asset.type;
                
                if (asset.type === 'meta_bm') typeLabel = 'Meta Business Manager';
                if (asset.type === 'n8n_workflow') typeLabel = 'Fluxo n8n';
                if (asset.type === 'ia_instance') typeLabel = 'Instância de IA';
                if (asset.type === 'other') typeLabel = 'Outros Acessos';
                
                let settingsRows = '';
                if (asset.settings && typeof asset.settings === 'object') {
                    for (const [key, value] of Object.entries(asset.settings)) {
                        let renderedValue = value;
                        if (typeof value === 'string' && (value.startsWith('http://') || value.startsWith('https://'))) {
                            renderedValue = `<a href="${value}" target="_blank" class="text-primary text-break">${value} <i class="bi bi-box-arrow-up-right" style="font-size:0.7rem;"></i></a>`;
                        } else if (Array.isArray(value)) {
                            renderedValue = value.join(', ');
                        } else if (typeof value === 'boolean') {
                            renderedValue = value ? '<span class="text-success">Ativo</span>' : '<span class="text-danger">Inativo</span>';
                        }
                        
                        const formattedKey = key.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
                        
                        settingsRows += `
                            <tr>
                                <td width="180" class="fw-semibold text-muted text-capitalize">${formattedKey}:</td>
                                <td>${renderedValue}</td>
                            </tr>
                        `;
                    }
                } else {
                    settingsRows = `
                        <tr>
                            <td class="text-muted">Nenhuma configuração adicional encontrada.</td>
                        </tr>
                    `;
                }
                
                card.innerHTML = `
                    <div class="asset-card-header">
                        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-hdd-network me-2 text-primary"></i>${escapeHtml(asset.name)}</h6>
                        <span class="asset-type-badge ${typeClass}">${typeLabel}</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table settings-table table-sm">
                            <tbody>
                                ${settingsRows}
                            </tbody>
                        </table>
                    </div>
                `;
                
                assetsListContainer.appendChild(card);
            });
        });
        
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    });
</script>
</body>
</html>
