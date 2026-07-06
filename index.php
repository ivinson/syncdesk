<?php
// index.php - Tactical Dashboard for SyncDesk (Axis360 Design)
require_once 'users/init.php';

// Secure the page - redirect to login if user is not authenticated
if (!$user->isLoggedIn()) {
    Redirect::to($us_url_root . 'users/login.php');
    exit;
}

$user_id = $user->data()->id;
$username = htmlspecialchars($user->data()->username);
$fname = htmlspecialchars($user->data()->fname);
$lname = htmlspecialchars($user->data()->lname);
$full_name = trim($fname . ' ' . $lname) ?: $username;

// Check user level
$is_admin = hasPerm([2], $user_id);
$role_title = $is_admin ? 'Administrador' : 'Atendente';

$db = DB::getInstance();

// ==========================================
// 1. DYNAMIC CUSTOMER FILTER & MULTITENANCY
// ==========================================
if ($is_admin) {
    $allowed_customers = $db->query("SELECT * FROM customers WHERE status = 1 ORDER BY name ASC")->results();
} else {
    $allowed_customers = $db->query("
        SELECT c.* 
        FROM customers c
        JOIN customer_agent ca ON c.id = ca.customer_id
        WHERE ca.user_id = ? AND c.status = 1
        ORDER BY c.name ASC
    ", [$user_id])->results();
}

$selected_customer_id = (int)Input::get('customer_id');

// Validate selected customer is authorized for this user session
$is_valid_customer = false;
if ($selected_customer_id > 0) {
    foreach ($allowed_customers as $ac) {
        if ((int)$ac->id === $selected_customer_id) {
            $is_valid_customer = true;
            break;
        }
    }
    if (!$is_valid_customer) {
        $selected_customer_id = 0; // Fallback to all
    }
}

// ==========================================
// 2. DATA AGGREGATION & KPI CALCULATIONS
// ==========================================
if ($is_admin) {
    $cnt_customers = count($allowed_customers);
    
    // Assets count
    if ($selected_customer_id > 0) {
        $q_assets = $db->query("SELECT COUNT(*) as cnt FROM assets WHERE customer_id = ?", [$selected_customer_id]);
    } else {
        $q_assets = $db->query("SELECT COUNT(*) as cnt FROM assets");
    }
    $cnt_assets = $q_assets->first()->cnt;
    
    // Tasks counts
    $task_where = "WHERE 1=1";
    $task_params = [];
    if ($selected_customer_id > 0) {
        $task_where .= " AND customer_id = ?";
        $task_params[] = $selected_customer_id;
    }
    
    $cnt_tasks = $db->query("SELECT COUNT(*) as cnt FROM tasks {$task_where}", $task_params)->first()->cnt;
    $cnt_tasks_completed = $db->query("SELECT COUNT(*) as cnt FROM tasks {$task_where} AND status = 'completed'", $task_params)->first()->cnt;
    $cnt_tasks_active = $db->query("SELECT COUNT(*) as cnt FROM tasks {$task_where} AND status IN ('pending', 'in_progress')", $task_params)->first()->cnt;
} else {
    $cnt_customers = count($allowed_customers);
    
    $task_where = "WHERE assigned_to = ?";
    $task_params = [$user_id];
    if ($selected_customer_id > 0) {
        $task_where .= " AND customer_id = ?";
        $task_params[] = $selected_customer_id;
    }
    
    $cnt_tasks = $db->query("SELECT COUNT(*) as cnt FROM tasks {$task_where}", $task_params)->first()->cnt;
    $cnt_tasks_completed = $db->query("SELECT COUNT(*) as cnt FROM tasks {$task_where} AND status = 'completed'", $task_params)->first()->cnt;
    $cnt_tasks_active = $db->query("SELECT COUNT(*) as cnt FROM tasks {$task_where} AND status IN ('pending', 'in_progress')", $task_params)->first()->cnt;
    
    // Assets count
    if ($selected_customer_id > 0) {
        $q_assets = $db->query("SELECT COUNT(*) as cnt FROM assets WHERE customer_id = ?", [$selected_customer_id]);
    } else {
        $q_assets = $db->query("SELECT COUNT(*) as cnt FROM assets WHERE customer_id IN (SELECT customer_id FROM customer_agent WHERE user_id = ?)", [$user_id]);
    }
    $cnt_assets = $q_assets->first()->cnt;
}

$efficiency = $cnt_tasks > 0 ? round(($cnt_tasks_completed / $cnt_tasks) * 100) : 0;

// ==========================================
// 3. CHART DATA QUERIES
// ==========================================

// Chart A: Status Doughnut
if ($is_admin) {
    $status_where = "WHERE 1=1";
    $status_params = [];
    if ($selected_customer_id > 0) {
        $status_where .= " AND customer_id = ?";
        $status_params[] = $selected_customer_id;
    }
    $status_data = $db->query("SELECT status, COUNT(*) as cnt FROM tasks {$status_where} GROUP BY status", $status_params)->results();
} else {
    $status_where = "WHERE assigned_to = ?";
    $status_params = [$user_id];
    if ($selected_customer_id > 0) {
        $status_where .= " AND customer_id = ?";
        $status_params[] = $selected_customer_id;
    }
    $status_data = $db->query("SELECT status, COUNT(*) as cnt FROM tasks {$status_where} GROUP BY status", $status_params)->results();
}
$status_counts = ['pending' => 0, 'in_progress' => 0, 'completed' => 0];
foreach ($status_data as $row) {
    $status_counts[$row->status] = (int)$row->cnt;
}

// Chart B: Workload/Distribution Bar
if ($is_admin) {
    $bar_where = "WHERE u.active = 1";
    $bar_params = [];
    if ($selected_customer_id > 0) {
        $bar_where .= " AND t.customer_id = ?";
        $bar_params[] = $selected_customer_id;
    }
    $bar_data = $db->query("
        SELECT u.fname, u.lname, u.username, COUNT(t.id) as cnt 
        FROM users u 
        LEFT JOIN tasks t ON u.id = t.assigned_to {$bar_where}
        GROUP BY u.id 
        ORDER BY cnt DESC
    ", $bar_params)->results();
    
    $bar_labels = [];
    $bar_values = [];
    foreach ($bar_data as $row) {
        $name = trim($row->fname . ' ' . $row->lname) ?: $row->username;
        $bar_labels[] = $name;
        $bar_values[] = (int)$row->cnt;
    }
    $bar_chart_title = 'Distribuição de Tarefas por Atendente';
} else {
    $bar_where = "WHERE t.assigned_to = ?";
    $bar_params = [$user_id];
    if ($selected_customer_id > 0) {
        $bar_where .= " AND c.id = ?";
        $bar_params[] = $selected_customer_id;
    }
    $bar_data = $db->query("
        SELECT c.name as customer_name, COUNT(t.id) as cnt 
        FROM customers c 
        JOIN tasks t ON c.id = t.customer_id 
        {$bar_where}
        GROUP BY c.id
        ORDER BY cnt DESC
    ", $bar_params)->results();
    
    $bar_labels = [];
    $bar_values = [];
    foreach ($bar_data as $row) {
        $bar_labels[] = $row->customer_name;
        $bar_values[] = (int)$row->cnt;
    }
    $bar_chart_title = 'Minhas Tarefas por Cliente';
}

// Chart C: Distribution by Customer/Company
if ($is_admin) {
    $cust_chart_where = "WHERE 1=1";
    $cust_chart_params = [];
    if ($selected_customer_id > 0) {
        $cust_chart_where .= " AND t.customer_id = ?";
        $cust_chart_params[] = $selected_customer_id;
    }
    $cust_chart_data = $db->query("
        SELECT c.name as customer_name, COUNT(t.id) as cnt 
        FROM tasks t
        JOIN customers c ON t.customer_id = c.id
        {$cust_chart_where}
        GROUP BY t.customer_id, c.name
        ORDER BY cnt DESC
    ", $cust_chart_params)->results();
} else {
    $cust_chart_where = "WHERE t.assigned_to = ?";
    $cust_chart_params = [$user_id];
    if ($selected_customer_id > 0) {
        $cust_chart_where .= " AND t.customer_id = ?";
        $cust_chart_params[] = $selected_customer_id;
    }
    $cust_chart_data = $db->query("
        SELECT c.name as customer_name, COUNT(t.id) as cnt 
        FROM tasks t
        JOIN customers c ON t.customer_id = c.id
        {$cust_chart_where}
        GROUP BY t.customer_id, c.name
        ORDER BY cnt DESC
    ", $cust_chart_params)->results();
}

$cust_chart_labels = [];
$cust_chart_values = [];
foreach ($cust_chart_data as $row) {
    $cust_chart_labels[] = $row->customer_name;
    $cust_chart_values[] = (int)$row->cnt;
}

// Chart D: 7-Day Evolution of Opened/Created Tickets
if ($is_admin) {
    $line_where = "WHERE t.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
    $line_params = [];
    if ($selected_customer_id > 0) {
        $line_where .= " AND t.customer_id = ?";
        $line_params[] = $selected_customer_id;
    }
    $line_data = $db->query("
        SELECT DATE(t.created_at) as date_created, COUNT(t.id) as cnt 
        FROM tasks t
        {$line_where}
        GROUP BY DATE(t.created_at)
        ORDER BY DATE(t.created_at) ASC
    ", $line_params)->results();
} else {
    $line_where = "WHERE t.assigned_to = ? AND t.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
    $line_params = [$user_id];
    if ($selected_customer_id > 0) {
        $line_where .= " AND t.customer_id = ?";
        $line_params[] = $selected_customer_id;
    }
    $line_data = $db->query("
        SELECT DATE(t.created_at) as date_created, COUNT(t.id) as cnt 
        FROM tasks t
        {$line_where}
        GROUP BY DATE(t.created_at)
        ORDER BY DATE(t.created_at) ASC
    ", $line_params)->results();
}

// Pre-fill the last 7 days map to ensure clean continuous lines
$line_map = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    // Format e.g., "04/Jul" in Portuguese
    $label = date('d/M', strtotime("-{$i} days"));
    $line_map[$d] = ['label' => $label, 'count' => 0];
}

foreach ($line_data as $row) {
    $d = $row->date_created;
    if (isset($line_map[$d])) {
        $line_map[$d]['count'] = (int)$row->cnt;
    }
}

$line_labels = [];
$line_values = [];
foreach ($line_map as $date_key => $info) {
    $line_labels[] = $info['label'];
    $line_values[] = $info['count'];
}

// ==========================================
// 5. RECENT TASKS LIST (Filtered)
// ==========================================
if ($is_admin) {
    $recent_where = "WHERE 1=1";
    $recent_params = [];
    if ($selected_customer_id > 0) {
        $recent_where .= " AND t.customer_id = ?";
        $recent_params[] = $selected_customer_id;
    }
    $recent_tasks = $db->query("
        SELECT t.*, c.name as customer_name, u.fname, u.lname, u.username as agent_username
        FROM tasks t
        JOIN customers c ON t.customer_id = c.id
        LEFT JOIN users u ON t.assigned_to = u.id
        {$recent_where}
        ORDER BY t.updated_at DESC, t.id DESC
        LIMIT 5
    ", $recent_params)->results();
} else {
    $recent_where = "WHERE t.assigned_to = ?";
    $recent_params = [$user_id];
    if ($selected_customer_id > 0) {
        $recent_where .= " AND t.customer_id = ?";
        $recent_params[] = $selected_customer_id;
    }
    $recent_tasks = $db->query("
        SELECT t.*, c.name as customer_name, u.fname, u.lname, u.username as agent_username
        FROM tasks t
        JOIN customers c ON t.customer_id = c.id
        LEFT JOIN users u ON t.assigned_to = u.id
        {$recent_where}
        ORDER BY t.updated_at DESC, t.id DESC
        LIMIT 5
    ", $recent_params)->results();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncDesk - Dashboard Tático</title>
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

        h1, h2, h3, h4, h5, .brand-title {
            font-family: 'Outfit', sans-serif;
        }

        .wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Sizing Override */
        .sidebar {
            width: 260px;
            background-color: var(--sb-bg);
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
        }

        .main-content {
            flex-grow: 1;
            padding: 2rem 2.5rem;
            margin-left: 260px;
            min-height: 100vh;
            overflow-y: auto;
        }

        /* Top Bar styling */
        .top-search-bar {
            display: flex;
            align-items: center;
            padding: 0.75rem 0rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid #e2e8f0;
            gap: 12px;
        }

        .top-search-input {
            border: none;
            background: transparent;
            font-size: 0.9rem;
            width: 300px;
            outline: none;
        }

        /* Profile details */
        .profile-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: #e11d48;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.8rem;
        }

        /* Cards customization */
        .tactical-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            padding: 1.5rem;
            height: 100%;
        }

        .kpi-title {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .kpi-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1;
        }

        .kpi-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .kpi-icon-blue { background: #fff1f2; color: #e11d48; }
        .kpi-icon-green { background: #f0fdf4; color: #16a34a; }
        .kpi-icon-orange { background: #fff7ed; color: #ea580c; }
        .kpi-icon-purple { background: #faf5ff; color: #9333ea; }

        /* Dashboard specific charts area */
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }

        /* Recent Tasks Custom Layout */
        .task-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            border: 1px solid #f1f5f9;
            margin-bottom: 0.75rem;
            transition: all 0.2s ease;
            background: #fff;
        }

        .task-list-item:hover {
            transform: translateX(3px);
            border-color: #cbd5e1;
        }

        .task-info-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .task-priority-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        .priority-dot-high { background-color: #ef4444; }
        .priority-dot-medium { background-color: #f59e0b; }
        .priority-dot-low { background-color: #3b82f6; }

        .task-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
            text-decoration: none;
        }

        .task-title:hover {
            color: var(--sb-active-bg);
        }

        .task-status-badge {
            font-size: 0.75rem;
            font-weight: 500;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
        }
        .status-completed { background-color: #e2fbf0; color: #10b981; }
        .status-in_progress { background-color: #f3e8ff; color: #7c3aed; }
        .status-pending { background-color: #fffbeb; color: #d97706; }
    </style>
</head>
<body>

<div class="wrapper">
    <!-- Sidebar Navigation Include -->
    <?php require_once 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <main class="main-content">
        <!-- Top Search Header Bar -->
        <header class="top-search-bar">
            <i class="bi bi-search text-muted"></i>
            <input type="text" class="top-search-input" placeholder="Buscar tarefas, clientes, relatórios... (Ctrl + K)">
            <span class="badge bg-light text-muted border ms-2" style="font-size: 0.7rem;">Ctrl + K</span>
            
            <div class="d-flex align-items-center gap-3 ms-auto">
                <i class="bi bi-bell text-muted fs-5 position-relative" style="cursor:pointer;">
                    <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle"></span>
                </i>
                <div class="profile-avatar">
                    <?= strtoupper(substr($full_name, 0, 1)) ?>
                </div>
            </div>
        </header>

        <!-- Welcome Banner -->
        <div class="mb-4">
            <h2 class="fw-bold mb-1">Olá, <?= explode(' ', $full_name)[0] ?> 👋</h2>
            <p class="text-muted">Bem-vindo ao painel tático do SyncDesk. Veja um resumo operacional.</p>
        </div>

        <!-- Dynamic Customer Filter Bar (Multitenancy) -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="tactical-card" style="padding: 1rem 1.5rem;">
                    <form method="GET" action="" id="dashboardFilterForm">
                        <div class="row align-items-center g-2">
                            <div class="col-auto">
                                <label for="customer_id" class="col-form-label fw-semibold text-muted" style="font-size:0.85rem;">Filtrar por Cliente:</label>
                            </div>
                            <div class="col-sm-4 col-md-3">
                                <select name="customer_id" id="customer_id" class="form-select form-select-sm" onchange="document.getElementById('dashboardFilterForm').submit()">
                                    <option value="0"><?= $is_admin ? 'Todos os Clientes' : 'Todos os meus Clientes' ?></option>
                                    <?php foreach ($allowed_customers as $ac): ?>
                                        <option value="<?= $ac->id ?>" <?= $ac->id == $selected_customer_id ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ac->name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- 4 KPI Metrics Row -->
        <div class="row g-3 mb-4">
            <!-- 1. Clientes Ativos -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="tactical-card d-flex align-items-center justify-content-between">
                    <div>
                        <div class="kpi-title">Clientes Ativos</div>
                        <div class="kpi-value"><?= $cnt_customers ?></div>
                    </div>
                    <div class="kpi-icon kpi-icon-blue">
                        <i class="bi bi-building"></i>
                    </div>
                </div>
            </div>
            <!-- 2. Ativos Totais -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="tactical-card d-flex align-items-center justify-content-between">
                    <div>
                        <div class="kpi-title">Ativos Gerenciados</div>
                        <div class="kpi-value"><?= $cnt_assets ?></div>
                    </div>
                    <div class="kpi-icon kpi-icon-purple">
                        <i class="bi bi-cpu"></i>
                    </div>
                </div>
            </div>
            <!-- 3. Tarefas Ativas -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="tactical-card d-flex align-items-center justify-content-between">
                    <div>
                        <div class="kpi-title">Tarefas Ativas</div>
                        <div class="kpi-value"><?= $cnt_tasks_active ?></div>
                    </div>
                    <div class="kpi-icon kpi-icon-orange">
                        <i class="bi bi-clock-history"></i>
                    </div>
                </div>
            </div>
            <!-- 4. Eficiência Operacional -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="tactical-card d-flex align-items-center justify-content-between">
                    <div>
                        <div class="kpi-title">Eficiência</div>
                        <div class="kpi-value"><?= $efficiency ?>%</div>
                    </div>
                    <div class="kpi-icon kpi-icon-green">
                        <i class="bi bi-check-all"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts and Graphs Section -->
        <div class="row g-4 mb-4">
            <!-- Doughnut Chart 1: Task Status -->
            <div class="col-lg-6 col-md-12">
                <div class="tactical-card">
                    <h5 class="fw-bold mb-3"><i class="bi bi-pie-chart me-2 text-primary"></i>Status das Tarefas</h5>
                    <div class="chart-container">
                        <canvas id="taskStatusChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Bar Chart 2: Workload Allocation -->
            <div class="col-lg-6 col-md-12">
                <div class="tactical-card">
                    <h5 class="fw-bold mb-3"><i class="bi bi-bar-chart-steps me-2 text-primary"></i><?= $bar_chart_title ?></h5>
                    <div class="chart-container">
                        <canvas id="workloadChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Doughnut Chart 3: Customer Task Distribution -->
            <div class="col-lg-6 col-md-12">
                <div class="tactical-card">
                    <h5 class="fw-bold mb-3"><i class="bi bi-building-check me-2 text-primary"></i>Demandas por Cliente</h5>
                    <div class="chart-container">
                        <canvas id="customerChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Line Chart 4: Historical 7-day evolution -->
            <div class="col-lg-6 col-md-12">
                <div class="tactical-card">
                    <h5 class="fw-bold mb-3"><i class="bi bi-graph-up me-2 text-primary"></i>Evolução de Atendimentos (Últimos 7 dias)</h5>
                    <div class="chart-container">
                        <canvas id="evolutionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Tasks and Alerts Layout -->
        <div class="row g-4">
            <div class="col-md-12">
                <div class="tactical-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold m-0"><i class="bi bi-list-stars me-2 text-primary"></i>Fila de Tarefas Recentes</h5>
                        <a href="tasks.php" class="btn btn-link text-decoration-none btn-sm fw-semibold p-0">Ver todas <i class="bi bi-chevron-right"></i></a>
                    </div>
                    
                    <div class="task-list-container">
                        <?php if (empty($recent_tasks)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-clipboard-x fs-3 d-block mb-1"></i>
                                Nenhuma tarefa operacional cadastrada.
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_tasks as $task): 
                                $priority_dot = 'priority-dot-' . $task->priority;
                                $status_label = $task->status == 'pending' ? 'Pendente' : ($task->status == 'in_progress' ? 'Em andamento' : 'Concluído');
                                $status_class = 'status-' . $task->status;
                            ?>
                                <div class="task-list-item">
                                    <div class="task-info-group">
                                        <div class="task-priority-dot <?= $priority_dot ?>" title="Prioridade: <?= $task->priority ?>"></div>
                                        <div>
                                            <a href="tasks.php" class="task-title"><?= htmlspecialchars($task->title) ?></a>
                                            <div class="text-muted" style="font-size: 0.75rem;">
                                                <i class="bi bi-building"></i> <?= htmlspecialchars($task->customer_name) ?>
                                                <span class="mx-1">•</span>
                                                <i class="bi bi-person"></i> <?= htmlspecialchars(trim($task->fname . ' ' . $task->lname) ?: $task->agent_username) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="task-status-badge <?= $status_class ?>"><?= $status_label ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Chart.js Library from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    // 1. Task Status Chart (Doughnut)
    const ctxStatus = document.getElementById('taskStatusChart').getContext('2d');
    const taskStatusChart = new Chart(ctxStatus, {
        type: 'doughnut',
        data: {
            labels: ['Pendentes', 'Em andamento', 'Concluídas'],
            datasets: [{
                data: [
                    <?= $status_counts['pending'] ?>, 
                    <?= $status_counts['in_progress'] ?>, 
                    <?= $status_counts['completed'] ?>
                ],
                backgroundColor: ['#f59e0b', '#7c3aed', '#10b981'],
                borderColor: ['#ffffff', '#ffffff', '#ffffff'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        padding: 15,
                        font: { size: 11, family: 'Inter' }
                    }
                }
            },
            cutout: '68%'
        }
    });

    // 2. Workload / Distribution Chart (Bar)
    const ctxWorkload = document.getElementById('workloadChart').getContext('2d');
    const workloadChart = new Chart(ctxWorkload, {
        type: 'bar',
        data: {
            labels: <?= json_encode($bar_labels) ?>,
            datasets: [{
                label: 'Quantidade de tarefas',
                data: <?= json_encode($bar_values) ?>,
                backgroundColor: '#e11d48',
                borderRadius: 8,
                maxBarThickness: 45
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        font: { size: 10, family: 'Inter' }
                    },
                    grid: {
                        color: '#f1f5f9'
                    }
                },
                x: {
                    ticks: {
                        font: { size: 10, family: 'Inter' }
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });

    // 3. Customer Task Distribution Chart (Doughnut)
    const ctxCustomer = document.getElementById('customerChart').getContext('2d');
    const customerChart = new Chart(ctxCustomer, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($cust_chart_labels) ?>,
            datasets: [{
                data: <?= json_encode($cust_chart_values) ?>,
                backgroundColor: ['#e11d48', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#3f3f46', '#14b8a6'],
                borderColor: ['#ffffff', '#ffffff', '#ffffff'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        padding: 15,
                        font: { size: 11, family: 'Inter' }
                    }
                }
            },
            cutout: '68%'
        }
    });

    // 4. Historical 7-Day Evolution Chart (Line)
    const ctxEvolution = document.getElementById('evolutionChart').getContext('2d');
    const evolutionChart = new Chart(ctxEvolution, {
        type: 'line',
        data: {
            labels: <?= json_encode($line_labels) ?>,
            datasets: [{
                label: 'Chamados Abertos',
                data: <?= json_encode($line_values) ?>,
                borderColor: '#e11d48',
                backgroundColor: 'rgba(225, 29, 72, 0.05)',
                borderWidth: 3,
                tension: 0.3,
                fill: true,
                pointBackgroundColor: '#e11d48',
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        font: { size: 10, family: 'Inter' }
                    },
                    grid: {
                        color: '#f1f5f9'
                    }
                },
                x: {
                    ticks: {
                        font: { size: 10, family: 'Inter' }
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
</script>

</body>
</html>