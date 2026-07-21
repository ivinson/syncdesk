<?php
// whatsapp_numbers.php - Management of Official Sync WhatsApp Numbers
require_once 'users/init.php';

// Strict session check
if (!isset($user) || !$user->isLoggedIn()) {
    Redirect::to('users/login.php');
    exit;
}

$user_id = $user->data()->id;
$is_admin = hasPerm([2], $user_id);
$db = DB::getInstance();

$error_msg = "";
$success_msg = "";

if (Input::get('success')) {
    $success_msg = Input::get('success');
}

// ---------------------------------------------------------
// AUTO TABLE MIGRATION / CREATION
// ---------------------------------------------------------
$db->query("
    CREATE TABLE IF NOT EXISTS `whatsapp_numbers` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `phone_number` VARCHAR(50) NOT NULL,
      `name` VARCHAR(255) NOT NULL,
      `connected_to` VARCHAR(255) DEFAULT NULL,
      `connection_status` ENUM('connected', 'disconnected') NOT NULL DEFAULT 'connected',
      `notes` TEXT DEFAULT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Helper function to format phone numbers in PHP
function format_phone_number_string($phone) {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (empty($digits)) return $phone;

    if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
        $country = '+55';
        $ddd = substr($digits, 2, 2);
        $rest = substr($digits, 4);
        if (strlen($rest) <= 8) {
            return "{$country} ({$ddd}) " . substr($rest, 0, 4) . '-' . substr($rest, 4);
        } else {
            return "{$country} ({$ddd}) " . substr($rest, 0, 5) . '-' . substr($rest, 5, 4);
        }
    }

    if (strlen($digits) == 10) {
        return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 4) . '-' . substr($digits, 6, 4);
    }

    if (strlen($digits) == 11) {
        return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 5) . '-' . substr($digits, 7, 4);
    }

    return $phone;
}

// ---------------------------------------------------------
// POST ACTION HANDLERS (CRUD & TOGGLES)
// ---------------------------------------------------------
if (Input::exists()) {
    if (Token::check(Input::get('csrf'))) {
        $action = Input::get('action');

        // 1. ADD NEW WHATSAPP NUMBER
        if ($action === 'add') {
            $name = trim(Input::get('name'));
            $phone_number = format_phone_number_string(trim(Input::get('phone_number')));
            $connected_to = trim(Input::get('connected_to'));
            $connection_status = Input::get('connection_status');
            $notes = trim(Input::get('notes'));

            if (empty($name)) {
                $error_msg = "O nome/identificação do número é obrigatório.";
            } elseif (empty($phone_number)) {
                $error_msg = "O número de telefone é obrigatório.";
            } elseif (!in_array($connection_status, ['connected', 'disconnected'])) {
                $error_msg = "Status de conexão inválido.";
            } else {
                $db->insert('whatsapp_numbers', [
                    'name' => $name,
                    'phone_number' => $phone_number,
                    'connected_to' => !empty($connected_to) ? $connected_to : 'Não especificado',
                    'connection_status' => $connection_status,
                    'notes' => !empty($notes) ? $notes : null
                ]);

                Redirect::to('whatsapp_numbers.php?success=' . urlencode("Número '{$name}' cadastrado com sucesso!"));
            }
        }

        // 2. EDIT WHATSAPP NUMBER
        elseif ($action === 'edit') {
            $id = (int)Input::get('number_id');
            $name = trim(Input::get('name'));
            $phone_number = format_phone_number_string(trim(Input::get('phone_number')));
            $connected_to = trim(Input::get('connected_to'));
            $connection_status = Input::get('connection_status');
            $notes = trim(Input::get('notes'));

            if ($id <= 0) {
                $error_msg = "ID de número inválido.";
            } elseif (empty($name)) {
                $error_msg = "O nome/identificação do número é obrigatório.";
            } elseif (empty($phone_number)) {
                $error_msg = "O número de telefone é obrigatório.";
            } elseif (!in_array($connection_status, ['connected', 'disconnected'])) {
                $error_msg = "Status de conexão inválido.";
            } else {
                $db->query("
                    UPDATE whatsapp_numbers 
                    SET name = ?, phone_number = ?, connected_to = ?, connection_status = ?, notes = ?
                    WHERE id = ?
                ", [$name, $phone_number, !empty($connected_to) ? $connected_to : 'Não especificado', $connection_status, !empty($notes) ? $notes : null, $id]);

                Redirect::to('whatsapp_numbers.php?success=' . urlencode("Número #{$id} atualizado com sucesso!"));
            }
        }

        // 3. TOGGLE CONNECTION STATUS (Manual quick switch)
        elseif ($action === 'toggle_connection') {
            $id = (int)Input::get('number_id');
            $new_conn = Input::get('new_connection_status');
            if ($id > 0 && in_array($new_conn, ['connected', 'disconnected'])) {
                $db->query("UPDATE whatsapp_numbers SET connection_status = ? WHERE id = ?", [$new_conn, $id]);
                Redirect::to('whatsapp_numbers.php?success=' . urlencode("Status de conexão atualizado com sucesso!"));
            }
        }

        // 4. DELETE WHATSAPP NUMBER
        elseif ($action === 'delete') {
            $id = (int)Input::get('number_id');
            if ($id > 0) {
                $db->query("DELETE FROM whatsapp_numbers WHERE id = ?", [$id]);
                Redirect::to('whatsapp_numbers.php?success=' . urlencode("Número excluído com sucesso."));
            }
        }
    } else {
        $error_msg = "Erro: Validação CSRF falhou. Recarregue a página.";
    }
}

// ---------------------------------------------------------
// QUERY FILTERS & RETRIEVAL
// ---------------------------------------------------------
$search_query = trim(Input::get('search'));
$filter_conn = Input::get('filter_conn');

$where_clauses = [];
$params = [];

if ($search_query !== '') {
    $where_clauses[] = "(name LIKE ? OR phone_number LIKE ? OR connected_to LIKE ? OR notes LIKE ?)";
    $term = "%{$search_query}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if (!empty($filter_conn)) {
    $where_clauses[] = "connection_status = ?";
    $params[] = $filter_conn;
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";
$numbers = $db->query("SELECT * FROM whatsapp_numbers {$where_sql} ORDER BY id DESC", $params)->results();

// KPI Metrics
$total_numbers = $db->query("SELECT COUNT(*) as cnt FROM whatsapp_numbers")->first()->cnt;
$total_connected = $db->query("SELECT COUNT(*) as cnt FROM whatsapp_numbers WHERE connection_status = 'connected'")->first()->cnt;
$total_disconnected = $db->query("SELECT COUNT(*) as cnt FROM whatsapp_numbers WHERE connection_status = 'disconnected'")->first()->cnt;

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncDesk - Meus Números (WhatsApp)</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
        }

        h2, h5, th, .title-brand, .kpi-title {
            font-family: 'Outfit', sans-serif;
        }

        .main-content {
            margin-left: 260px;
            padding: 2.5rem;
            transition: margin-left 0.3s ease;
        }

        @media (max-width: 991.98px) {
            .main-content {
                margin-left: 0;
                padding: 1.5rem;
            }
        }

        .dashboard-header {
            margin-bottom: 2rem;
        }

        /* KPI Cards */
        .kpi-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: all 0.2s ease;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .kpi-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
        }

        .kpi-icon-total { background: #eff6ff; color: #3b82f6; }
        .kpi-icon-connected { background: #ecfdf5; color: #059669; }
        .kpi-icon-disconnected { background: #fef2f2; color: #dc2626; }

        /* Filter Card */
        .filter-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px -1px rgba(0, 0, 0, 0.01);
        }

        /* Table Styling */
        .table-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
            padding: 1.5rem;
        }

        .table > :not(caption) > * > * {
            padding: 0.85rem 1rem;
            vertical-align: middle;
        }

        .table th {
            font-weight: 600;
            color: #475569;
            background-color: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table td {
            font-size: 0.9rem;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
        }

        .btn-primary-axis {
            background-color: #e11d48;
            border-color: #e11d48;
            color: #ffffff;
            font-weight: 600;
            border-radius: 8px;
            font-size: 0.9rem;
            padding: 0.5rem 1.25rem;
            transition: all 0.2s ease;
        }

        .btn-primary-axis:hover {
            background-color: #be123c;
            border-color: #be123c;
            color: #ffffff;
        }

        .btn-sm-axis {
            padding: 0.35rem 0.65rem;
            font-size: 0.8rem;
            border-radius: 6px;
            font-weight: 500;
        }

        .form-select, .form-control {
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            padding: 0.5rem 0.75rem;
            font-size: 0.9rem;
        }

        .form-select:focus, .form-control:focus {
            border-color: #e11d48;
            box-shadow: 0 0 0 3px rgba(225, 29, 72, 0.15);
        }

        /* Status Badges & Pulse Animations */
        .status-badge {
            font-size: 0.78rem;
            font-weight: 600;
            padding: 0.35rem 0.75rem;
            border-radius: 50rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .status-connected {
            background-color: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .status-disconnected {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }

        .pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .pulse-connected {
            background-color: #22c55e;
            box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
            animation: pulse-green 2s infinite;
        }

        .pulse-disconnected {
            background-color: #ef4444;
        }

        @keyframes pulse-green {
            0% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
            }
            70% {
                transform: scale(1);
                box-shadow: 0 0 0 6px rgba(34, 197, 94, 0);
            }
            100% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
            }
        }

        .phone-chip {
            background-color: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.25rem 0.6rem;
            font-family: monospace;
            font-size: 0.88rem;
            color: #0f172a;
            font-weight: 600;
        }
    </style>
</head>
<body>

    <!-- Include Modular Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="dashboard-header d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-3">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1" style="font-size:0.8rem;">
                        <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">SyncDesk</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Meus números</li>
                    </ol>
                </nav>
                <h2 class="fw-bold m-0 text-slate-800 d-flex align-items-center gap-2">
                    <i class="bi bi-whatsapp text-success"></i> Meus Números WhatsApp
                </h2>
                <p class="text-muted small mb-0 mt-1">Gerenciamento dos números oficiais de WhatsApp da empresa Sync e controle dos locais de conexão.</p>
            </div>

            <button class="btn btn-primary btn-primary-axis d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addNumberModal">
                <i class="bi bi-plus-lg"></i> Novo Número
            </button>
        </div>

        <!-- Success & Error Alerts -->
        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show p-3 mb-4" role="alert" style="border-radius: 12px; font-size: 0.9rem;">
                <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($success_msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger alert-dismissible fade show p-3 mb-4" role="alert" style="border-radius: 12px; font-size: 0.9rem;">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error_msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- KPI Metrics Grid -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4">
                <div class="kpi-card d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-semibold">Total de Números</div>
                        <div class="fs-3 fw-bold text-dark mt-1"><?= $total_numbers ?></div>
                    </div>
                    <div class="kpi-icon kpi-icon-total">
                        <i class="bi bi-telephone-fill"></i>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="kpi-card d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-semibold">Conectados</div>
                        <div class="fs-3 fw-bold text-emerald-600 mt-1" style="color: #059669;"><?= $total_connected ?></div>
                    </div>
                    <div class="kpi-icon kpi-icon-connected">
                        <i class="bi bi-wifi"></i>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="kpi-card d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-semibold">Desconectados</div>
                        <div class="fs-3 fw-bold text-danger mt-1"><?= $total_disconnected ?></div>
                    </div>
                    <div class="kpi-icon kpi-icon-disconnected">
                        <i class="bi bi-wifi-off"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter & Search Bar -->
        <div class="filter-card">
            <form method="GET" action="" id="searchFilterForm" class="row g-3 align-items-center">
                <div class="col-12 col-md-7">
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted border-end-0"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control border-start-0 ps-0" placeholder="Buscar por número, identificação ou local..." value="<?= htmlspecialchars($search_query) ?>">
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <select name="filter_conn" class="form-select" onchange="document.getElementById('searchFilterForm').submit()">
                        <option value="">Status da Conexão (Todas)</option>
                        <option value="connected" <?= $filter_conn === 'connected' ? 'selected' : '' ?>>Conectado</option>
                        <option value="disconnected" <?= $filter_conn === 'disconnected' ? 'selected' : '' ?>>Desconectado</option>
                    </select>
                </div>
                <div class="col-12 col-md-1 text-end">
                    <?php if ($search_query !== '' || !empty($filter_conn)): ?>
                        <a href="whatsapp_numbers.php" class="btn btn-outline-secondary btn-sm w-100" title="Limpar filtros">
                            <i class="bi bi-x-circle"></i> Limpar
                        </a>
                    <?php else: ?>
                        <button type="submit" class="btn btn-light border btn-sm w-100"><i class="bi bi-funnel"></i></button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Numbers Data Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th>Identificação / Nome</th>
                            <th>Número de WhatsApp</th>
                            <th>Onde está Conectado</th>
                            <th style="width: 170px;">Status da Conexão</th>
                            <th style="width: 160px; text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($numbers) > 0): ?>
                            <?php foreach ($numbers as $num): 
                                $clean_phone = preg_replace('/[^0-9]/', '', $num->phone_number);
                                $wa_link = !empty($clean_phone) ? "https://wa.me/{$clean_phone}" : "#";
                            ?>
                                <tr>
                                    <td><strong>#<?= $num->id ?></strong></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($num->name) ?></div>
                                        <?php if (!empty($num->notes)): ?>
                                            <div class="text-muted small text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($num->notes) ?>">
                                                <i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($num->notes) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-inline-flex align-items-center gap-2">
                                            <span class="phone-chip">
                                                <i class="bi bi-whatsapp text-success me-1"></i><?= htmlspecialchars($num->phone_number) ?>
                                            </span>
                                            <?php if ($clean_phone): ?>
                                                <a href="<?= $wa_link ?>" target="_blank" class="btn btn-sm btn-light border p-1 rounded-2 text-success" title="Abrir no WhatsApp Web">
                                                    <i class="bi bi-box-arrow-up-right" style="font-size: 0.75rem;"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($num->connected_to)): ?>
                                            <span class="badge bg-slate-100 text-dark border p-2 fw-medium" style="background-color: #f1f5f9; border-color: #cbd5e1 !important;">
                                                <i class="bi bi-cpu-fill text-primary me-1"></i><?= htmlspecialchars($num->connected_to) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small"><i>Não especificado</i></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="dropdown">
                                            <button class="btn btn-sm border-0 p-0 dropdown-toggle text-start" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Clique para alterar status da conexão manualmente">
                                                <?php if ($num->connection_status === 'connected'): ?>
                                                    <span class="status-badge status-connected">
                                                        <span class="pulse-dot pulse-connected"></span> Conectado
                                                    </span>
                                                <?php else: ?>
                                                    <span class="status-badge status-disconnected">
                                                        <span class="pulse-dot pulse-disconnected"></span> Desconectado
                                                    </span>
                                                <?php endif; ?>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="border-radius: 12px; font-size: 0.85rem;">
                                                <li class="dropdown-header text-uppercase fw-semibold" style="font-size: 0.7rem; color: #94a3b8;">Alterar Conexão</li>
                                                <li>
                                                    <form method="POST" action="">
                                                        <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                                                        <input type="hidden" name="action" value="toggle_connection">
                                                        <input type="hidden" name="number_id" value="<?= $num->id ?>">
                                                        <input type="hidden" name="new_connection_status" value="connected">
                                                        <button type="submit" class="dropdown-item d-flex align-items-center gap-2 text-success">
                                                            <i class="bi bi-wifi"></i> Marcar como Conectado
                                                        </button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <form method="POST" action="">
                                                        <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                                                        <input type="hidden" name="action" value="toggle_connection">
                                                        <input type="hidden" name="number_id" value="<?= $num->id ?>">
                                                        <input type="hidden" name="new_connection_status" value="disconnected">
                                                        <button type="submit" class="dropdown-item d-flex align-items-center gap-2 text-danger">
                                                            <i class="bi bi-wifi-off"></i> Marcar como Desconectado
                                                        </button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                    <td style="text-align: right;">
                                        <div class="d-inline-flex gap-2">
                                            <!-- Edit Button -->
                                            <button class="btn btn-outline-secondary btn-sm btn-sm-axis"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editNumberModal"
                                                    data-id="<?= $num->id ?>"
                                                    data-name="<?= htmlspecialchars($num->name) ?>"
                                                    data-phone="<?= htmlspecialchars($num->phone_number) ?>"
                                                    data-connected="<?= htmlspecialchars($num->connected_to) ?>"
                                                    data-conn="<?= $num->connection_status ?>"
                                                    data-notes="<?= htmlspecialchars($num->notes ?? '') ?>"
                                                    title="Editar número">
                                                <i class="bi bi-pencil"></i> Editar
                                            </button>

                                            <!-- Delete Button -->
                                            <button class="btn btn-outline-danger btn-sm btn-sm-axis"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#deleteNumberModal"
                                                    data-id="<?= $num->id ?>"
                                                    data-name="<?= htmlspecialchars($num->name) ?>"
                                                    title="Excluir número">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-whatsapp fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                    Nenhum número oficial de WhatsApp cadastrado ou encontrado com os filtros atuais.
                                    <div class="mt-3">
                                        <button class="btn btn-sm btn-primary-axis" data-bs-toggle="modal" data-bs-target="#addNumberModal">
                                            <i class="bi bi-plus-lg me-1"></i> Cadastrar Primeiro Número
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ==========================================
         MODALS SECTION
         ========================================== -->

    <!-- 1. Add Number Modal -->
    <div class="modal fade" id="addNumberModal" tabindex="-1" aria-labelledby="addNumberModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius: 16px; border:none; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
                <form method="POST" action="">
                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                    <input type="hidden" name="action" value="add">

                    <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                        <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="addNumberModalLabel">
                            <i class="bi bi-whatsapp text-success fs-4"></i> Novo Número de WhatsApp Sync
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="add_name" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Nome / Identificação *</label>
                                <input type="text" name="name" id="add_name" class="form-control rounded-3" placeholder="Ex: Sync - Atendimento Vendas" required>
                            </div>
                            <div class="col-md-6">
                                <label for="add_phone" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Número do WhatsApp *</label>
                                <input type="text" name="phone_number" id="add_phone" class="form-control rounded-3" placeholder="Ex: +55 11 99999-8888" required>
                            </div>
                            <div class="col-md-6">
                                <label for="add_connected_to" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Onde está Conectado?</label>
                                <input type="text" name="connected_to" id="add_connected_to" class="form-control rounded-3" placeholder="Ex: Evolution API - Instância 01 / n8n / Z-API">
                            </div>
                            <div class="col-md-6">
                                <label for="add_conn_status" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Status da Conexão</label>
                                <select name="connection_status" id="add_conn_status" class="form-select rounded-3">
                                    <option value="connected" selected>Conectado</option>
                                    <option value="disconnected">Desconectado</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="add_notes" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Observações / Detalhes de Integração (Opcional)</label>
                                <textarea name="notes" id="add_notes" class="form-control rounded-3" rows="3" placeholder="Ex: Token da API, responsável pela linha, dados de servidor..."></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer border-top-0 pt-0 pb-4 px-4 gap-2">
                        <button type="button" class="btn btn-light rounded-3 px-3 py-2 fw-semibold" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-primary-axis rounded-3 px-4 py-2">Salvar Número</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 2. Edit Number Modal -->
    <div class="modal fade" id="editNumberModal" tabindex="-1" aria-labelledby="editNumberModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius: 16px; border:none; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
                <form method="POST" action="">
                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="number_id" id="edit_number_id" value="">

                    <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                        <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="editNumberModalLabel">
                            <i class="bi bi-pencil-square text-primary fs-4"></i> Editar Número de WhatsApp
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="edit_name" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Nome / Identificação *</label>
                                <input type="text" name="name" id="edit_name" class="form-control rounded-3" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_phone" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Número do WhatsApp *</label>
                                <input type="text" name="phone_number" id="edit_phone" class="form-control rounded-3" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_connected_to" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Onde está Conectado?</label>
                                <input type="text" name="connected_to" id="edit_connected_to" class="form-control rounded-3">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_conn_status" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Status da Conexão</label>
                                <select name="connection_status" id="edit_conn_status" class="form-select rounded-3">
                                    <option value="connected">Conectado</option>
                                    <option value="disconnected">Desconectado</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="edit_notes" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Observações / Detalhes</label>
                                <textarea name="notes" id="edit_notes" class="form-control rounded-3" rows="3"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer border-top-0 pt-0 pb-4 px-4 gap-2">
                        <button type="button" class="btn btn-light rounded-3 px-3 py-2 fw-semibold" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-primary-axis rounded-3 px-4 py-2">Salvar Alterações</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 3. Delete Number Modal -->
    <div class="modal fade" id="deleteNumberModal" tabindex="-1" aria-labelledby="deleteNumberModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border:none; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
                <form method="POST" action="">
                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="number_id" id="delete_number_id" value="">

                    <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                        <h5 class="modal-title fw-bold text-dark" id="deleteNumberModalLabel">Excluir Número</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-4">
                        <p class="mb-0 text-muted">Tem certeza que deseja remover o número <strong id="delete_number_name" class="text-dark"></strong>?</p>
                        <div class="alert alert-warning p-2.5 mt-3 mb-0" style="font-size:0.8rem; border-radius:8px;">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Esta ação removerá o registro do número do painel do SyncDesk.
                        </div>
                    </div>

                    <div class="modal-footer border-top-0 pt-0 pb-4 px-4 gap-2">
                        <button type="button" class="btn btn-light rounded-3 px-3 py-2 fw-semibold" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger rounded-3 px-4 py-2 fw-semibold">Confirmar Exclusão</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Populate edit modal fields
        const editModal = document.getElementById('editNumberModal');
        if (editModal) {
            editModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const name = button.getAttribute('data-name');
                const phone = button.getAttribute('data-phone');
                const connected = button.getAttribute('data-connected');
                const conn = button.getAttribute('data-conn');
                const notes = button.getAttribute('data-notes');

                editModal.querySelector('#edit_number_id').value = id;
                editModal.querySelector('#edit_name').value = name;
                editModal.querySelector('#edit_phone').value = phone;
                editModal.querySelector('#edit_connected_to').value = connected;
                editModal.querySelector('#edit_conn_status').value = conn;
            });
        }

        // Populate delete modal fields
        const deleteModal = document.getElementById('deleteNumberModal');
        if (deleteModal) {
            deleteModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const name = button.getAttribute('data-name');

                deleteModal.querySelector('#delete_number_id').value = id;
                deleteModal.querySelector('#delete_number_name').textContent = name;
            });
        }

        // Phone Number Auto Formatting Mask
        function formatPhoneInput(input) {
            let digits = input.value.replace(/\D/g, '');
            if (digits.length === 0) {
                input.value = '';
                return;
            }

            let formatted = '';
            if (digits.startsWith('55') && digits.length >= 12) {
                let ddd = digits.substring(2, 4);
                let rest = digits.substring(4);
                if (rest.length <= 8) {
                    formatted = `+55 (${ddd}) ${rest.substring(0, 4)}${rest.length > 4 ? '-' + rest.substring(4) : ''}`;
                } else {
                    formatted = `+55 (${ddd}) ${rest.substring(0, 5)}${rest.length > 5 ? '-' + rest.substring(5, 9) : ''}`;
                }
            } else {
                if (digits.length <= 2) {
                    formatted = `(${digits}`;
                } else if (digits.length <= 6) {
                    formatted = `(${digits.substring(0, 2)}) ${digits.substring(2)}`;
                } else if (digits.length <= 10) {
                    formatted = `(${digits.substring(0, 2)}) ${digits.substring(2, 6)}-${digits.substring(6)}`;
                } else {
                    formatted = `(${digits.substring(0, 2)}) ${digits.substring(2, 7)}-${digits.substring(7, 11)}`;
                }
            }

            input.value = formatted;
        }

        document.querySelectorAll('#add_phone, #edit_phone').forEach(function(inputEl) {
            inputEl.addEventListener('input', function() {
                formatPhoneInput(this);
            });
            inputEl.addEventListener('blur', function() {
                formatPhoneInput(this);
            });
        });
    </script>
</body>
</html>
