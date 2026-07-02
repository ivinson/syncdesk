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
// 1. DATA AGGREGATION & KPI CALCULATIONS
// ==========================================
if ($is_admin) {
    // Total active customers
    $q_cust = $db->query("SELECT COUNT(*) as cnt FROM customers WHERE status = 1");
    $cnt_customers = $q_cust->first()->cnt;
    
    // Total tasks
    $q_tasks = $db->query("SELECT COUNT(*) as cnt FROM tasks");
    $cnt_tasks = $q_tasks->first()->cnt;
    
    // Tasks completed
    $q_tasks_comp = $db->query("SELECT COUNT(*) as cnt FROM tasks WHERE status = 'completed'");
    $cnt_tasks_completed = $q_tasks_comp->first()->cnt;
    
    // Active tasks (pending + in_progress)
    $q_tasks_active = $db->query("SELECT COUNT(*) as cnt FROM tasks WHERE status IN ('pending', 'in_progress')");
    $cnt_tasks_active = $q_tasks_active->first()->cnt;
    
    // Total assets
    $q_assets = $db->query("SELECT COUNT(*) as cnt FROM assets");
    $cnt_assets = $q_assets->first()->cnt;
} else {
    // Customers associated to agent
    $q_cust = $db->query("SELECT COUNT(DISTINCT customer_id) as cnt FROM customer_agent WHERE user_id = ?", [$user_id]);
    $cnt_customers = $q_cust->first()->cnt;
    
    // Tasks assigned to agent
    $q_tasks = $db->query("SELECT COUNT(*) as cnt FROM tasks WHERE assigned_to = ?", [$user_id]);
    $cnt_tasks = $q_tasks->first()->cnt;
    
    // Tasks completed
    $q_tasks_comp = $db->query("SELECT COUNT(*) as cnt FROM tasks WHERE assigned_to = ? AND status = 'completed'", [$user_id]);
    $cnt_tasks_completed = $q_tasks_comp->first()->cnt;
    
    // Active tasks
    $q_tasks_active = $db->query("SELECT COUNT(*) as cnt FROM tasks WHERE assigned_to = ? AND status IN ('pending', 'in_progress')", [$user_id]);
    $cnt_tasks_active = $q_tasks_active->first()->cnt;
    
    // Total assets associated to agent's customers
    $q_assets = $db->query("SELECT COUNT(*) as cnt FROM assets WHERE customer_id IN (SELECT customer_id FROM customer_agent WHERE user_id = ?)", [$user_id]);
    $cnt_assets = $q_assets->first()->cnt;
}

$efficiency = $cnt_tasks > 0 ? round(($cnt_tasks_completed / $cnt_tasks) * 100) : 0;

// ==========================================
// 2. CHART DATA QUERIES
// ==========================================

// Chart A: Status Doughnut
if ($is_admin) {
    $status_data = $db->query("SELECT status, COUNT(*) as cnt FROM tasks GROUP BY status")->results();
} else {
    $status_data = $db->query("SELECT status, COUNT(*) as cnt FROM tasks WHERE assigned_to = ? GROUP BY status", [$user_id])->results();
}
$status_counts = ['pending' => 0, 'in_progress' => 0, 'completed' => 0];
foreach ($status_data as $row) {
    $status_counts[$row->status] = (int)$row->cnt;
}

// Chart B: Workload/Distribution Bar
if ($is_admin) {
    // Admin: Tasks assigned per Agent
    $bar_data = $db->query("
        SELECT u.fname, u.lname, u.username, COUNT(t.id) as cnt 
        FROM users u 
        LEFT JOIN tasks t ON u.id = t.assigned_to 
        WHERE u.active = 1
        GROUP BY u.id 
        ORDER BY cnt DESC
    ")->results();
    
    $bar_labels = [];
    $bar_values = [];
    foreach ($bar_data as $row) {
        $name = trim($row->fname . ' ' . $row->lname) ?: $row->username;
        $bar_labels[] = $name;
        $bar_values[] = (int)$row->cnt;
    }
    $bar_chart_title = 'Distribuição de Tarefas por Atendente';
} else {
    // Agent: Tasks per Customer
    $bar_data = $db->query("
        SELECT c.name as customer_name, COUNT(t.id) as cnt 
        FROM customers c 
        JOIN tasks t ON c.id = t.customer_id 
        WHERE t.assigned_to = ?
        GROUP BY c.id
        ORDER BY cnt DESC
    ", [$user_id])->results();
    
    $bar_labels = [];
    $bar_values = [];
    foreach ($bar_data as $row) {
        $bar_labels[] = $row->customer_name;
        $bar_values[] = (int)$row->cnt;
    }
    $bar_chart_title = 'Minhas Tarefas por Cliente';
}

// ==========================================
// 3. RECENT TASKS LIST
// ==========================================
if ($is_admin) {
    $recent_tasks = $db->query("
        SELECT t.*, c.name as customer_name, u.fname, u.lname, u.username as agent_username
        FROM tasks t
        JOIN customers c ON t.customer_id = c.id
        LEFT JOIN users u ON t.assigned_to = u.id
        ORDER BY t.updated_at DESC, t.id DESC
        LIMIT 5
    ")->results();
} else {
    $recent_tasks = $db->query("
        SELECT t.*, c.name as customer_name, u.fname, u.lname, u.username as agent_username
        FROM tasks t
        JOIN customers c ON t.customer_id = c.id
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.assigned_to = ?
        ORDER BY t.updated_at DESC, t.id DESC
        LIMIT 5
    ", [$user_id])->results();
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
            background-color: #3b82f6;
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

        .kpi-icon-blue { background: #eff6ff; color: #2563eb; }
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
            <!-- Doughnut Chart: Task Status -->
            <div class="col-lg-4 col-md-12">
                <div class="tactical-card">
                    <h5 class="fw-bold mb-3"><i class="bi bi-pie-chart me-2 text-primary"></i>Status das Tarefas</h5>
                    <div class="chart-container">
                        <canvas id="taskStatusChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Bar Chart: Workload Allocation -->
            <div class="col-lg-8 col-md-12">
                <div class="tactical-card">
                    <h5 class="fw-bold mb-3"><i class="bi bi-bar-chart-steps me-2 text-primary"></i><?= $bar_chart_title ?></h5>
                    <div class="chart-container">
                        <canvas id="workloadChart"></canvas>
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
                backgroundColor: '#2563eb',
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
</script>

</body>
</html>