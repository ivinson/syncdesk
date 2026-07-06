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
                        
                        if (Input::get('ajax')) {
                            header('Content-Type: application/json');
                            echo json_encode([
                                'success' => true,
                                'message' => "Status da tarefa #{$task_id} alterado para '{$status_label}'!"
                            ]);
                            exit;
                        }
                        
                        Redirect::to('kanban.php?success=' . urlencode("Status da tarefa #{$task_id} alterado para '{$status_label}'!"));
                    } else {
                        if (Input::get('ajax')) {
                            header('Content-Type: application/json');
                            echo json_encode([
                                'success' => false,
                                'message' => "Erro: Você não tem permissão para atualizar esta tarefa."
                            ]);
                            exit;
                        }
                        $error_msg = "Erro: Você não tem permissão para atualizar esta tarefa.";
                    }
                } else {
                    if (Input::get('ajax')) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => false,
                            'message' => "Erro: Tarefa não encontrada."
                        ]);
                        exit;
                    }
                    $error_msg = "Erro: Tarefa não encontrada.";
                }
            } else {
                if (Input::get('ajax')) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => "Erro: Status inválido informado."
                    ]);
                    exit;
                }
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
                Redirect::to('kanban.php?success=' . urlencode("Tarefa '{$title}' criada com sucesso!"));
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
                        Redirect::to('kanban.php?success=' . urlencode("Tarefa #{$task_id} atualizada com sucesso!"));
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
                    Redirect::to('kanban.php?success=' . urlencode("Tarefa #{$task_id} excluída com sucesso!"));
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

$view = 'board';
$my_tasks_only = (bool)Input::get('my_tasks');

// Build basic WHERE conditions for filters (GET Requests)
$where_clauses = [];
$params = [];

if (!$is_admin) {
    if ($my_tasks_only) {
        $where_clauses[] = "t.assigned_to = ?";
        $params[] = $user_id;
    } else {
        $where_clauses[] = "t.customer_id IN (SELECT customer_id FROM customer_agent WHERE user_id = ?)";
        $params[] = $user_id;
    }
} else {
    if ($my_tasks_only) {
        $where_clauses[] = "t.assigned_to = ?";
        $params[] = $user_id;
    }
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
    if ($my_tasks_only) {
        $cnt_all = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE assigned_to = ?", [$user_id])->first()->total;
        $cnt_pending = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE status = 'pending' AND assigned_to = ?", [$user_id])->first()->total;
        $cnt_in_progress = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE status = 'in_progress' AND assigned_to = ?", [$user_id])->first()->total;
        $cnt_completed = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE status = 'completed' AND assigned_to = ?", [$user_id])->first()->total;
    } else {
        $cnt_all = $db->query("SELECT COUNT(*) AS total FROM tasks")->first()->total;
        $cnt_pending = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE status = 'pending'")->first()->total;
        $cnt_in_progress = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE status = 'in_progress'")->first()->total;
        $cnt_completed = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE status = 'completed'")->first()->total;
    }
    $cnt_customers = $db->query("SELECT COUNT(*) AS total FROM customers WHERE status = 1")->first()->total;
} else {
    if ($my_tasks_only) {
        $cnt_all = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE assigned_to = ?", [$user_id])->first()->total;
        $cnt_pending = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE assigned_to = ? AND status = 'pending'", [$user_id])->first()->total;
        $cnt_in_progress = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE assigned_to = ? AND status = 'in_progress'", [$user_id])->first()->total;
        $cnt_completed = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE assigned_to = ? AND status = 'completed'", [$user_id])->first()->total;
    } else {
        $cnt_all = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE customer_id IN (SELECT customer_id FROM customer_agent WHERE user_id = ?)", [$user_id])->first()->total;
        $cnt_pending = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE status = 'pending' AND customer_id IN (SELECT customer_id FROM customer_agent WHERE user_id = ?)", [$user_id])->first()->total;
        $cnt_in_progress = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE status = 'in_progress' AND customer_id IN (SELECT customer_id FROM customer_agent WHERE user_id = ?)", [$user_id])->first()->total;
        $cnt_completed = $db->query("SELECT COUNT(*) AS total FROM tasks WHERE status = 'completed' AND customer_id IN (SELECT customer_id FROM customer_agent WHERE user_id = ?)", [$user_id])->first()->total;
    }
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

// Group tasks by status for the board view
$tasks_by_status = [
    'pending' => [],
    'in_progress' => [],
    'completed' => []
];
foreach ($tasks as $task) {
    if (isset($tasks_by_status[$task->status])) {
        $tasks_by_status[$task->status][] = $task;
    }
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
            --sb-active-bg: #e11d48;
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
            color: #e11d48;
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
            background-color: #f3e8ff;
            color: #7c3aed;
            border-color: #e9d5ff;
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

        .type-meta_bm { background-color: #fff1f2; color: #e11d48; }
        .type-n8n_workflow { background-color: #fdf2f8; color: #db2777; }
        .type-ia_instance { background-color: #faf5ff; color: #9333ea; }
        .type-system { background-color: #f0fdf4; color: #16a34a; }
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

        /* Board / Kanban View Styles */
        .board-column {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 16px;
            padding: 1.25rem;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .board-cards-container {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            min-height: 480px;
            height: 100%;
            transition: background-color 0.2s ease, border-color 0.2s ease;
            border-radius: 12px;
            padding: 0.5rem;
            margin-top: 0.5rem;
        }

        .board-cards-container.drag-over {
            background-color: #f1f5f9;
            border: 2px dashed #cbd5e1;
        }

        .task-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            user-select: none;
        }

        .task-card.priority-border-high {
            border-left: 4px solid #ef4444 !important;
        }

        .task-card.priority-border-medium {
            border-left: 4px solid #f59e0b !important;
        }

        .task-card.priority-border-low {
            border-left: 4px solid #10b981 !important;
        }

        .task-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.04);
            border-color: #cbd5e1;
        }

        .task-card:active {
            cursor: grabbing;
        }

        .task-card-title {
            font-size: 0.95rem;
            font-weight: 600;
            line-height: 1.4;
            color: var(--text-main);
        }

        .text-truncate-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;  
            overflow: hidden;
        }
        
        .board-column-header {
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <!-- Sidebar Navigation -->
    <?php require_once 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <main class="main-content">
        <!-- Clean Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom mt-3">
            <div>
                <h2 class="fw-bold mb-0">Quadro Kanban</h2>
                <p class="text-muted mb-0 small">SyncDesk Operações</p>
            </div>
            <div class="d-flex align-items-center gap-3">
                <!-- Apenas Minhas Toggle Form -->
                <form method="GET" action="" id="filterForm" class="d-flex align-items-center me-2">
                    <div class="form-check form-switch d-flex align-items-center mb-0">
                        <input class="form-check-input me-1" type="checkbox" name="my_tasks" value="1" id="myTasksToggle" <?= $my_tasks_only ? 'checked' : '' ?> onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                        <label class="form-check-label small fw-semibold text-muted" for="myTasksToggle" style="font-size: 0.8rem; white-space: nowrap; user-select: none; cursor: pointer;">Apenas Minhas</label>
                    </div>
                </form>
                <a href="tasks.php" class="btn btn-outline-secondary btn-sm px-3 rounded-3 d-inline-flex align-items-center gap-2" style="font-weight: 500;">
                    <i class="bi bi-arrow-left"></i> Voltar para Lista
                </a>
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

        <!-- Kanban Board View -->
        <div class="row g-3 board-container">
                    <!-- Column Pending -->
                    <div class="col-lg-4 col-12">
                        <div class="board-column">
                            <div class="board-column-header d-flex justify-content-between align-items-center mb-2">
                                <span class="status-select py-1 px-3 d-inline-block text-center rounded-3 status-pending" style="width: auto; font-size: 0.85rem; font-weight: 600;">
                                    Pendente
                                </span>
                                <span class="badge bg-light text-dark border border-light-subtle rounded-pill px-2.5 py-1" style="font-size: 0.8rem;"><?= count($tasks_by_status['pending']) ?></span>
                            </div>
                            <div class="board-cards-container" data-status="pending">
                                <div class="no-tasks-placeholder text-center py-4 text-muted bg-white rounded-3 border border-dashed" style="font-size: 0.85rem; <?= empty($tasks_by_status['pending']) ? '' : 'display: none;' ?>">
                                    <i class="bi bi-inbox fs-4 d-block mb-1 text-secondary"></i>
                                    Sem tarefas pendentes
                                </div>
                                <?php if (!empty($tasks_by_status['pending'])): ?>
                                    <?php foreach ($tasks_by_status['pending'] as $task):  
                                        $agent_name = trim($task->fname . ' ' . $task->lname) ?: $task->agent_username;
                                        $agent_initials = strtoupper(substr($agent_name, 0, 1));
                                        
                                        $status_class = 'status-' . $task->status;
                                        $priority_class = 'priority-' . $task->priority;
                                        $priority_label = $task->priority == 'high' ? 'Alta' : ($task->priority == 'medium' ? 'Média' : 'Baixa');
                                        $can_edit_task = $is_admin || ($task->assigned_to == $user_id);
                                        
                                        $cust_assets = isset($assets_by_customer[$task->customer_id]) ? $assets_by_customer[$task->customer_id] : [];
                                        $assets_json = json_encode($cust_assets);
                                    ?>
                                        <div class="task-card priority-border-<?= $task->priority ?>" 
                                             draggable="<?= $can_edit_task ? 'true' : 'false' ?>"
                                             data-bs-toggle="modal" 
                                             data-bs-target="#editTaskModal"
                                             data-task-id="<?= $task->id ?>"
                                             data-task-title="<?= htmlspecialchars($task->title) ?>"
                                             data-task-desc="<?= htmlspecialchars($task->description ?? '') ?>"
                                             data-task-priority="<?= $task->priority ?>"
                                             data-task-status="<?= $task->status ?>"
                                             data-task-customer-id="<?= $task->customer_id ?>"
                                             data-task-assigned-to="<?= $task->assigned_to ?>"
                                             style="cursor: pointer;">
                                             
                                             <div class="task-card-title mb-2"><?= htmlspecialchars($task->title) ?></div>
                                             
                                             <?php if (!empty($task->description)): ?>
                                                 <p class="text-muted mb-2 text-truncate-2" style="font-size: 0.8rem; line-height: 1.4;">
                                                     <?= htmlspecialchars(mb_strimwidth($task->description, 0, 85, '...')) ?>
                                                 </p>
                                             <?php endif; ?>
                                             
                                             <div class="border-top pt-2 mt-2">
                                                 <div class="mb-1 d-flex align-items-center justify-content-between">
                                                     <div class="small fw-semibold text-muted" style="font-size: 0.75rem;">Cliente:</div>
                                                     <div class="small text-end" onclick="event.stopPropagation();">
                                                         <a href="#" class="customer-link fw-bold" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#customerAssetsModal"
                                                            data-customer-name="<?= htmlspecialchars($task->customer_name) ?>"
                                                            data-company-name="<?= htmlspecialchars($task->customer_company) ?>"
                                                            data-assets='<?= htmlspecialchars($assets_json, ENT_QUOTES, 'UTF-8') ?>'
                                                            style="font-size: 0.8rem;">
                                                             <?= htmlspecialchars($task->customer_name) ?>
                                                             <i class="bi bi-box-arrow-up-right" style="font-size: 0.7rem;"></i>
                                                         </a>
                                                         <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($task->customer_company) ?></div>
                                                     </div>
                                                 </div>
                                                 
                                                 <div class="d-flex align-items-center justify-content-between mt-2 pt-2 border-top border-light-subtle">
                                                     <div class="agent-badge">
                                                         <div class="agent-avatar-small" title="<?= htmlspecialchars($agent_name) ?>" style="width: 20px; height: 20px; font-size: 0.65rem;">
                                                             <?= $agent_initials ?>
                                                         </div>
                                                         <span class="small text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars(explode(' ', $agent_name)[0]) ?></span>
                                                     </div>
                                                     <div class="d-flex align-items-center gap-2">
                                                         <span class="priority-badge <?= $priority_class ?>" style="min-width: auto; padding: 0.15rem 0.4rem; font-size: 0.7rem;"><?= $priority_label ?></span>
                                                         <span class="text-muted" style="font-size: 0.7rem;"><i class="bi bi-clock me-1"></i><?= date('d/m H:i', strtotime($task->updated_at)) ?></span>
                                                     </div>
                                                 </div>
                                             </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Column In Progress -->
                    <div class="col-lg-4 col-12">
                        <div class="board-column">
                            <div class="board-column-header d-flex justify-content-between align-items-center mb-2">
                                <span class="status-select py-1 px-3 d-inline-block text-center rounded-3 status-in_progress" style="width: auto; font-size: 0.85rem; font-weight: 600;">
                                    Em andamento
                                </span>
                                <span class="badge bg-light text-dark border border-light-subtle rounded-pill px-2.5 py-1" style="font-size: 0.8rem;"><?= count($tasks_by_status['in_progress']) ?></span>
                            </div>
                            <div class="board-cards-container" data-status="in_progress">
                                <div class="no-tasks-placeholder text-center py-4 text-muted bg-white rounded-3 border border-dashed" style="font-size: 0.85rem; <?= empty($tasks_by_status['in_progress']) ? '' : 'display: none;' ?>">
                                    <i class="bi bi-inbox fs-4 d-block mb-1 text-secondary"></i>
                                    Sem tarefas em andamento
                                </div>
                                <?php if (!empty($tasks_by_status['in_progress'])): ?>
                                    <?php foreach ($tasks_by_status['in_progress'] as $task):  
                                        $agent_name = trim($task->fname . ' ' . $task->lname) ?: $task->agent_username;
                                        $agent_initials = strtoupper(substr($agent_name, 0, 1));
                                        
                                        $status_class = 'status-' . $task->status;
                                        $priority_class = 'priority-' . $task->priority;
                                        $priority_label = $task->priority == 'high' ? 'Alta' : ($task->priority == 'medium' ? 'Média' : 'Baixa');
                                        $can_edit_task = $is_admin || ($task->assigned_to == $user_id);
                                        
                                        $cust_assets = isset($assets_by_customer[$task->customer_id]) ? $assets_by_customer[$task->customer_id] : [];
                                        $assets_json = json_encode($cust_assets);
                                    ?>
                                        <div class="task-card priority-border-<?= $task->priority ?>" 
                                             draggable="<?= $can_edit_task ? 'true' : 'false' ?>"
                                             data-bs-toggle="modal" 
                                             data-bs-target="#editTaskModal"
                                             data-task-id="<?= $task->id ?>"
                                             data-task-title="<?= htmlspecialchars($task->title) ?>"
                                             data-task-desc="<?= htmlspecialchars($task->description ?? '') ?>"
                                             data-task-priority="<?= $task->priority ?>"
                                             data-task-status="<?= $task->status ?>"
                                             data-task-customer-id="<?= $task->customer_id ?>"
                                             data-task-assigned-to="<?= $task->assigned_to ?>"
                                             style="cursor: pointer;">
                                             
                                             <div class="task-card-title mb-2"><?= htmlspecialchars($task->title) ?></div>
                                             
                                             <?php if (!empty($task->description)): ?>
                                                 <p class="text-muted mb-2 text-truncate-2" style="font-size: 0.8rem; line-height: 1.4;">
                                                     <?= htmlspecialchars(mb_strimwidth($task->description, 0, 85, '...')) ?>
                                                 </p>
                                             <?php endif; ?>
                                             
                                             <div class="border-top pt-2 mt-2">
                                                 <div class="mb-1 d-flex align-items-center justify-content-between">
                                                     <div class="small fw-semibold text-muted" style="font-size: 0.75rem;">Cliente:</div>
                                                     <div class="small text-end" onclick="event.stopPropagation();">
                                                         <a href="#" class="customer-link fw-bold" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#customerAssetsModal"
                                                            data-customer-name="<?= htmlspecialchars($task->customer_name) ?>"
                                                            data-company-name="<?= htmlspecialchars($task->customer_company) ?>"
                                                            data-assets='<?= htmlspecialchars($assets_json, ENT_QUOTES, 'UTF-8') ?>'
                                                            style="font-size: 0.8rem;">
                                                             <?= htmlspecialchars($task->customer_name) ?>
                                                             <i class="bi bi-box-arrow-up-right" style="font-size: 0.7rem;"></i>
                                                         </a>
                                                         <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($task->customer_company) ?></div>
                                                     </div>
                                                 </div>
                                                 
                                                 <div class="d-flex align-items-center justify-content-between mt-2 pt-2 border-top border-light-subtle">
                                                     <div class="agent-badge">
                                                         <div class="agent-avatar-small" title="<?= htmlspecialchars($agent_name) ?>" style="width: 20px; height: 20px; font-size: 0.65rem;">
                                                             <?= $agent_initials ?>
                                                         </div>
                                                         <span class="small text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars(explode(' ', $agent_name)[0]) ?></span>
                                                     </div>
                                                     <div class="d-flex align-items-center gap-2">
                                                         <span class="priority-badge <?= $priority_class ?>" style="min-width: auto; padding: 0.15rem 0.4rem; font-size: 0.7rem;"><?= $priority_label ?></span>
                                                         <span class="text-muted" style="font-size: 0.7rem;"><i class="bi bi-clock me-1"></i><?= date('d/m H:i', strtotime($task->updated_at)) ?></span>
                                                     </div>
                                                 </div>
                                             </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Column Completed -->
                    <div class="col-lg-4 col-12">
                        <div class="board-column">
                            <div class="board-column-header d-flex justify-content-between align-items-center mb-2">
                                <span class="status-select py-1 px-3 d-inline-block text-center rounded-3 status-completed" style="width: auto; font-size: 0.85rem; font-weight: 600;">
                                    Concluído
                                </span>
                                <span class="badge bg-light text-dark border border-light-subtle rounded-pill px-2.5 py-1" style="font-size: 0.8rem;"><?= count($tasks_by_status['completed']) ?></span>
                            </div>
                            <div class="board-cards-container" data-status="completed">
                                <div class="no-tasks-placeholder text-center py-4 text-muted bg-white rounded-3 border border-dashed" style="font-size: 0.85rem; <?= empty($tasks_by_status['completed']) ? '' : 'display: none;' ?>">
                                    <i class="bi bi-inbox fs-4 d-block mb-1 text-secondary"></i>
                                    Sem tarefas concluídas
                                </div>
                                <?php if (!empty($tasks_by_status['completed'])): ?>
                                    <?php foreach ($tasks_by_status['completed'] as $task):  
                                        $agent_name = trim($task->fname . ' ' . $task->lname) ?: $task->agent_username;
                                        $agent_initials = strtoupper(substr($agent_name, 0, 1));
                                        
                                        $status_class = 'status-' . $task->status;
                                        $priority_class = 'priority-' . $task->priority;
                                        $priority_label = $task->priority == 'high' ? 'Alta' : ($task->priority == 'medium' ? 'Média' : 'Baixa');
                                        $can_edit_task = $is_admin || ($task->assigned_to == $user_id);
                                        
                                        $cust_assets = isset($assets_by_customer[$task->customer_id]) ? $assets_by_customer[$task->customer_id] : [];
                                        $assets_json = json_encode($cust_assets);
                                    ?>
                                        <div class="task-card priority-border-<?= $task->priority ?>" 
                                             draggable="<?= $can_edit_task ? 'true' : 'false' ?>"
                                             data-bs-toggle="modal" 
                                             data-bs-target="#editTaskModal"
                                             data-task-id="<?= $task->id ?>"
                                             data-task-title="<?= htmlspecialchars($task->title) ?>"
                                             data-task-desc="<?= htmlspecialchars($task->description ?? '') ?>"
                                             data-task-priority="<?= $task->priority ?>"
                                             data-task-status="<?= $task->status ?>"
                                             data-task-customer-id="<?= $task->customer_id ?>"
                                             data-task-assigned-to="<?= $task->assigned_to ?>"
                                             style="cursor: pointer;">
                                             
                                             <div class="task-card-title mb-2"><?= htmlspecialchars($task->title) ?></div>
                                             
                                             <?php if (!empty($task->description)): ?>
                                                 <p class="text-muted mb-2 text-truncate-2" style="font-size: 0.8rem; line-height: 1.4;">
                                                     <?= htmlspecialchars(mb_strimwidth($task->description, 0, 85, '...')) ?>
                                                 </p>
                                             <?php endif; ?>
                                             
                                             <div class="border-top pt-2 mt-2">
                                                 <div class="mb-1 d-flex align-items-center justify-content-between">
                                                     <div class="small fw-semibold text-muted" style="font-size: 0.75rem;">Cliente:</div>
                                                     <div class="small text-end" onclick="event.stopPropagation();">
                                                         <a href="#" class="customer-link fw-bold" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#customerAssetsModal"
                                                            data-customer-name="<?= htmlspecialchars($task->customer_name) ?>"
                                                            data-company-name="<?= htmlspecialchars($task->customer_company) ?>"
                                                            data-assets='<?= htmlspecialchars($assets_json, ENT_QUOTES, 'UTF-8') ?>'
                                                            style="font-size: 0.8rem;">
                                                             <?= htmlspecialchars($task->customer_name) ?>
                                                             <i class="bi bi-box-arrow-up-right" style="font-size: 0.7rem;"></i>
                                                         </a>
                                                         <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($task->customer_company) ?></div>
                                                     </div>
                                                 </div>
                                                 
                                                 <div class="d-flex align-items-center justify-content-between mt-2 pt-2 border-top border-light-subtle">
                                                     <div class="agent-badge">
                                                         <div class="agent-avatar-small" title="<?= htmlspecialchars($agent_name) ?>" style="width: 20px; height: 20px; font-size: 0.65rem;">
                                                             <?= $agent_initials ?>
                                                         </div>
                                                         <span class="small text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars(explode(' ', $agent_name)[0]) ?></span>
                                                     </div>
                                                     <div class="d-flex align-items-center gap-2">
                                                         <span class="priority-badge <?= $priority_class ?>" style="min-width: auto; padding: 0.15rem 0.4rem; font-size: 0.7rem;"><?= $priority_label ?></span>
                                                         <span class="text-muted" style="font-size: 0.7rem;"><i class="bi bi-clock me-1"></i><?= date('d/m H:i', strtotime($task->updated_at)) ?></span>
                                                     </div>
                                                 </div>
                                             </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
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
                if (asset.type === 'system') typeLabel = 'Sistema';
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

        // 4. TOAST NOTIFICATIONS SYSTEM
        function showToast(message, type = 'success') {
            let container = document.getElementById('toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast-container';
                container.style.position = 'fixed';
                container.style.top = '20px';
                container.style.right = '20px';
                container.style.zIndex = '9999';
                container.style.display = 'flex';
                container.style.flexDirection = 'column';
                container.style.gap = '10px';
                document.body.appendChild(container);
            }
            
            const toast = document.createElement('div');
            toast.className = `alert alert-${type} alert-dismissible fade show shadow-lg border-0`;
            toast.style.margin = '0';
            toast.style.borderRadius = '12px';
            toast.style.minWidth = '300px';
            toast.style.fontSize = '0.9rem';
            toast.style.fontWeight = '500';
            
            const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
            toast.innerHTML = `
                <div class="d-flex align-items-center gap-2">
                    <i class="bi ${icon}"></i>
                    <div>${message}</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="padding: 1rem 0.75rem 1rem 0.5rem;"></button>
            `;
            
            container.appendChild(toast);
            
            setTimeout(() => {
                const bsAlert = bootstrap.Alert.getInstance(toast) || new bootstrap.Alert(toast);
                bsAlert.close();
            }, 4000);
        }

        // 5. ASYNC PAGE UPDATE (HTML Over-The-Wire)
        async function updatePageContent(formData) {
            const tableContainer = document.querySelector('.board-container');
            const statsRow = document.querySelector('.stats-row');
            
            if (tableContainer) tableContainer.style.opacity = '0.6';
            if (statsRow) statsRow.style.opacity = '0.6';
            
            const params = new URLSearchParams(formData);
            if (!formData.has('my_tasks')) {
                params.delete('my_tasks');
            }
            const url = 'kanban.php?' + params.toString();
            
            try {
                const response = await fetch(url);
                if (!response.ok) throw new Error('Erro de conexão');
                
                const html = await response.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                // Update stats row
                const newStatsRow = doc.querySelector('.stats-row');
                if (statsRow && newStatsRow) {
                    statsRow.innerHTML = newStatsRow.innerHTML;
                }
                
                // Update table/board container
                const newTableContainer = doc.querySelector('.board-container');
                if (tableContainer && newTableContainer) {
                    tableContainer.innerHTML = newTableContainer.innerHTML;
                }
                
                // Update browser URL
                window.history.pushState(null, '', url);
                
                // Re-bind all dynamic events
                initializeBoardEvents();
            } catch (error) {
                console.error(error);
                showToast('Erro ao atualizar filtros.', 'danger');
            } finally {
                if (tableContainer) tableContainer.style.opacity = '1';
                if (statsRow) statsRow.style.opacity = '1';
            }
        }

        // 6. SILENT REFRESH OF COUNTS
        async function refreshStatsAndBoard() {
            try {
                const response = await fetch(window.location.href);
                const html = await response.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                const statsRow = document.querySelector('.stats-row');
                const newStatsRow = doc.querySelector('.stats-row');
                if (statsRow && newStatsRow) {
                    statsRow.innerHTML = newStatsRow.innerHTML;
                }
                
                // Update column headers for board view
                document.querySelectorAll('.board-column').forEach((col, idx) => {
                    const newColHeader = doc.querySelectorAll('.board-column')[idx]?.querySelector('.board-column-header');
                    if (newColHeader) {
                        col.querySelector('.board-column-header').innerHTML = newColHeader.innerHTML;
                    }
                });
            } catch (e) {
                console.error(e);
            }
        }

        // 6.5 DYNAMIC EMPTY COLUMN PLACEHOLDERS
        function updatePlaceholders() {
            document.querySelectorAll('.board-cards-container').forEach(container => {
                const hasCards = container.querySelectorAll('.task-card').length > 0;
                const placeholder = container.querySelector('.no-tasks-placeholder');
                if (placeholder) {
                    placeholder.style.display = hasCards ? 'none' : 'block';
                }
            });
        }

        // 7. INITIALIZE BOARD DRAG & DROP EVENTS
        function initializeBoardEvents() {
            updatePlaceholders();

            const cards = document.querySelectorAll('.task-card');
            const containers = document.querySelectorAll('.board-cards-container');

            cards.forEach(card => {
                card.addEventListener('dragstart', (e) => {
                    e.dataTransfer.setData('text/plain', card.getAttribute('data-task-id'));
                    card.style.opacity = '0.4';
                });

                card.addEventListener('dragend', () => {
                    card.style.opacity = '1';
                });
            });

            containers.forEach(container => {
                container.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    container.classList.add('drag-over');
                });

                container.addEventListener('dragleave', () => {
                    container.classList.remove('drag-over');
                });

                container.addEventListener('drop', (e) => {
                    e.preventDefault();
                    container.classList.remove('drag-over');
                    
                    const taskId = e.dataTransfer.getData('text/plain');
                    const newStatus = container.getAttribute('data-status');
                    const card = document.querySelector(`.task-card[data-task-id="${taskId}"]`);
                    
                    if (card) {
                        const currentStatus = card.getAttribute('data-task-status');
                        if (currentStatus === newStatus) return; // No change
                        
                        // AJAX update for status change
                        const formData = new FormData();
                        formData.append('csrf', document.querySelector('input[name="csrf"]').value);
                        formData.append('action', 'quick_status_update');
                        formData.append('task_id', taskId);
                        formData.append('status', newStatus);
                        formData.append('ajax', '1');
                        
                        card.style.opacity = '0.5';
                        
                        fetch('kanban.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                card.setAttribute('data-task-status', newStatus);
                                container.appendChild(card);
                                updatePlaceholders();
                                refreshStatsAndBoard();
                            } else {
                                showToast(data.message, 'danger');
                                setTimeout(() => window.location.reload(), 1000);
                            }
                        })
                        .catch(error => {
                            console.error(error);
                            showToast('Erro de rede ao atualizar status.', 'danger');
                            setTimeout(() => window.location.reload(), 1000);
                        })
                        .finally(() => {
                            card.style.opacity = '1';
                        });
                    }
                });
            });
        }

        // 8. INTERCEPT FILTER FORM SUBMISSION
        document.addEventListener('submit', function(e) {
            if (e.target && e.target.id === 'filterForm') {
                e.preventDefault();
                updatePageContent(new FormData(e.target));
            }
            
            // Intercept Quick Status Update forms in the list table
            if (e.target && e.target.classList.contains('d-inline') && e.target.querySelector('input[name="action"]')?.value === 'quick_status_update') {
                e.preventDefault();
                const formData = new FormData(e.target);
                formData.append('ajax', '1');
                
                const tableContainer = document.querySelector('.board-container');
                if (tableContainer) tableContainer.style.opacity = '0.6';
                
                fetch('kanban.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        // Refresh the entire board/table silently
                        const params = new URLSearchParams(window.location.search);
                        updatePageContent(params);
                    } else {
                        showToast(data.message, 'danger');
                    }
                })
                .catch(error => {
                    console.error(error);
                    showToast('Erro de rede ao atualizar status.', 'danger');
                })
                .finally(() => {
                    if (tableContainer) tableContainer.style.opacity = '1';
                });
            }
        });

        // Initial event binding
        initializeBoardEvents();
        
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
