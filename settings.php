<?php
// settings.php - Administrative Settings Hub
require_once 'users/init.php';

// Force strict administrator session check
if (!isset($user) || !$user->isLoggedIn() || !hasPerm([2], $user->data()->id)) {
    Redirect::to('index.php');
}

$db = DB::getInstance();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncDesk - Configurações Gerais</title>
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

        h2, h5, .title-brand {
            font-family: 'Outfit', sans-serif;
        }

        .main-content {
            margin-left: 260px; /* Aligned with fixed sidebar size */
            padding: 2.5rem;
            transition: margin-left 0.3s ease;
        }

        /* Responsive adjustments for sidebar collapse if needed */
        @media (max-width: 991.98px) {
            .main-content {
                margin-left: 0;
                padding: 1.5rem;
            }
        }

        .dashboard-header {
            margin-bottom: 2rem;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .settings-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 250px;
        }

        .settings-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05);
            border-color: #e11d48;
        }

        .card-icon {
            width: 48px;
            height: 48px;
            background-color: #fff1f2;
            color: #e11d48;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1.25rem;
            transition: all 0.2s ease;
        }

        .settings-card:hover .card-icon {
            background-color: #e11d48;
            color: #ffffff;
        }

        .card-title {
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }

        .card-desc {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 1.5rem;
            line-height: 1.4;
        }

        .btn-action {
            width: 100%;
            background-color: #f1f5f9;
            color: #334155;
            border: none;
            border-radius: 8px;
            padding: 0.6rem;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.2s ease;
            text-align: center;
            text-decoration: none;
            display: inline-block;
        }

        .settings-card:hover .btn-action {
            background-color: #e11d48;
            color: #ffffff;
        }
    </style>
</head>
<body>

    <!-- Include Modular Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
        <div class="dashboard-header d-flex align-items-center justify-content-between">
            <div>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 text-uppercase mb-2" style="font-size: 0.75rem; font-weight:600;">Painel de Controle</span>
                <h2 class="fw-bold m-0 text-slate-800">Configurações Gerais</h2>
            </div>
            <div class="header-date text-muted" style="font-size: 0.9rem;">
                <i class="bi bi-calendar3 me-1"></i> <?= date('d/m/Y') ?>
            </div>
        </div>

        <div class="settings-grid">
            <!-- 1. Serviços e SLAs -->
            <div class="settings-card">
                <div>
                    <div class="card-icon"><i class="bi bi-clock-history"></i></div>
                    <h5 class="card-title">Serviços & SLAs</h5>
                    <p class="card-desc">Gerencie a lista de serviços prestados e configure as metas de prazo limite (SLA) para cada tipo de chamado.</p>
                </div>
                <a href="manage_services.php" class="btn-action">Configurar Serviços</a>
            </div>

            <!-- 2. Solicitantes (Contatos de Clientes) -->
            <div class="settings-card">
                <div>
                    <div class="card-icon"><i class="bi bi-person-lines-fill"></i></div>
                    <h5 class="card-title">Solicitantes (Clientes)</h5>
                    <p class="card-desc">Cadastre e gerencie as pessoas autorizadas em cada cliente corporativo para abrir chamados no portal público.</p>
                </div>
                <a href="manage_contacts.php" class="btn-action">Gerenciar Contatos</a>
            </div>

            <!-- 3. Clientes & Ativos -->
            <div class="settings-card">
                <div>
                    <div class="card-icon"><i class="bi bi-people"></i></div>
                    <h5 class="card-title">Clientes e Ativos</h5>
                    <p class="card-desc">Gerencie os clientes corporativos cadastrados no sistema e seus respectivos ativos (BMs da Meta, IAs, n8n).</p>
                </div>
                <a href="manage_assets.php" class="btn-action">Gerenciar Ativos</a>
            </div>

            <!-- 4. Vincular Equipe -->
            <div class="settings-card">
                <div>
                    <div class="card-icon"><i class="bi bi-person-gear"></i></div>
                    <h5 class="card-title">Vincular Equipe</h5>
                    <p class="card-desc">Mapeie e associe quais atendentes de suporte serão responsáveis pelo atendimento de cada cliente cadastrado.</p>
                </div>
                <a href="manage_customer_agents.php" class="btn-action">Mapear Contas</a>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
