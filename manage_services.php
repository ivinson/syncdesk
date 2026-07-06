<?php
// manage_services.php - CRUD for services & SLAs
require_once 'users/init.php';

// Force strict administrator session check
if (!isset($user) || !$user->isLoggedIn() || !hasPerm([2], $user->data()->id)) {
    Redirect::to('index.php');
}

$db = DB::getInstance();
$error_msg = "";
$success_msg = "";

// ==========================================
// POST ACTION HANDLER
// ==========================================
if (Input::exists()) {
    if (Token::check(Input::get('csrf'))) {
        $action = Input::get('action');
        
        // 1. ADD SERVICE
        if ($action === 'add') {
            $name = trim(Input::get('name'));
            $sla_hours = (int)Input::get('sla_hours');
            
            if (empty($name)) {
                $error_msg = "O nome do serviço é obrigatório.";
            } elseif ($sla_hours <= 0) {
                $error_msg = "O prazo limite do SLA deve ser maior que 0 horas.";
            } else {
                // Check uniqueness
                $check = $db->query("SELECT id FROM services WHERE name = ?", [$name]);
                if ($check->count() > 0) {
                    $error_msg = "Já existe um serviço cadastrado com este nome.";
                } else {
                    $db->insert('services', [
                        'name' => $name,
                        'sla_hours' => $sla_hours
                    ]);
                    $success_msg = "Serviço '{$name}' cadastrado com sucesso!";
                }
            }
        }
        
        // 2. EDIT SERVICE
        elseif ($action === 'edit') {
            $id = (int)Input::get('service_id');
            $name = trim(Input::get('name'));
            $sla_hours = (int)Input::get('sla_hours');
            
            if ($id <= 0) {
                $error_msg = "ID do serviço inválido.";
            } elseif (empty($name)) {
                $error_msg = "O nome do serviço é obrigatório.";
            } elseif ($sla_hours <= 0) {
                $error_msg = "O prazo limite do SLA deve ser maior que 0 horas.";
            } else {
                // Check uniqueness excluding current ID
                $check = $db->query("SELECT id FROM services WHERE name = ? AND id != ?", [$name, $id]);
                if ($check->count() > 0) {
                    $error_msg = "Já existe outro serviço cadastrado com este nome.";
                } else {
                    $db->query("UPDATE services SET name = ?, sla_hours = ? WHERE id = ?", [$name, $sla_hours, $id]);
                    $success_msg = "Serviço atualizado com sucesso!";
                }
            }
        }
        
        // 3. DELETE SERVICE
        elseif ($action === 'delete') {
            $id = (int)Input::get('service_id');
            if ($id > 0) {
                // Check if any tasks are linked to this service
                $check_tasks = $db->query("SELECT id FROM tasks WHERE service_id = ? LIMIT 1", [$id]);
                if ($check_tasks->count() > 0) {
                    $error_msg = "Não é possível excluir este serviço pois existem chamados/tarefas associados a ele.";
                } else {
                    $db->query("DELETE FROM services WHERE id = ?", [$id]);
                    $success_msg = "Serviço excluído com sucesso!";
                }
            } else {
                $error_msg = "ID do serviço inválido para exclusão.";
            }
        }
    } else {
        $error_msg = "Erro: Validação CSRF falhou. Recarregue a página.";
    }
}

// Fetch all services
$services = $db->query("SELECT * FROM services ORDER BY name ASC")->results();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncDesk - Gerenciar Serviços & SLAs</title>
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

        h2, h5, th, .title-brand {
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

        .table-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
            padding: 1.5rem;
        }

        .table > :not(caption) > * > * {
            padding: 0.75rem 1rem;
            vertical-align: middle;
        }

        .table th {
            font-weight: 600;
            color: #475569;
            background-color: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.85rem;
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
            font-weight: 600;
            border-radius: 8px;
            font-size: 0.9rem;
            padding: 0.5rem 1.25rem;
            transition: all 0.2s ease;
        }

        .btn-primary-axis:hover {
            background-color: #be123c;
            border-color: #be123c;
        }

        .btn-sm-axis {
            padding: 0.35rem 0.6rem;
            font-size: 0.8rem;
            border-radius: 6px;
            font-weight: 500;
        }
    </style>
</head>
<body>

    <!-- Include Modular Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
        <div class="dashboard-header d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-3">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1" style="font-size:0.8rem;">
                        <li class="breadcrumb-item"><a href="settings.php" class="text-decoration-none">Configurações</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Serviços & SLAs</li>
                    </ol>
                </nav>
                <h2 class="fw-bold m-0 text-slate-800">Serviços & SLAs</h2>
            </div>
            
            <button class="btn btn-primary btn-primary-axis" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                <i class="bi bi-plus-lg me-1"></i> Novo Serviço
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

        <!-- Services Data Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width: 80px;">ID</th>
                            <th>Nome do Serviço</th>
                            <th style="width: 150px;">Prazo SLA</th>
                            <th style="width: 180px; text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($services) > 0): ?>
                            <?php foreach ($services as $srv): ?>
                                <tr>
                                    <td><strong>#<?= $srv->id ?></strong></td>
                                    <td class="fw-semibold text-slate-700"><?= htmlspecialchars($srv->name) ?></td>
                                    <td>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                            <i class="bi bi-clock me-1"></i> <?= $srv->sla_hours ?> horas
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <div class="d-inline-flex gap-2">
                                            <!-- Edit Button -->
                                            <button class="btn btn-outline-secondary btn-sm btn-sm-axis" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editServiceModal"
                                                    data-id="<?= $srv->id ?>"
                                                    data-name="<?= htmlspecialchars($srv->name) ?>"
                                                    data-sla="<?= $srv->sla_hours ?>"
                                                    title="Editar Serviço">
                                                <i class="bi bi-pencil"></i> Editar
                                            </button>
                                            
                                            <!-- Delete Button -->
                                            <button class="btn btn-outline-danger btn-sm btn-sm-axis" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteServiceModal"
                                                    data-id="<?= $srv->id ?>"
                                                    data-name="<?= htmlspecialchars($srv->name) ?>"
                                                    title="Excluir Serviço">
                                                <i class="bi bi-trash"></i> Excluir
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">
                                    <i class="bi bi-folder-x fs-3 d-block mb-2"></i>
                                    Nenhum serviço cadastrado no momento.
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

    <!-- 1. Add Service Modal -->
    <div class="modal fade" id="addServiceModal" tabindex="-1" aria-labelledby="addServiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border:none; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
                <form method="POST" action="">
                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                        <h5 class="modal-title fw-bold" id="addServiceModalLabel">Novo Serviço</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label for="name" class="form-label fw-semibold text-muted" style="font-size:0.8rem;">Nome do Serviço</label>
                            <input type="text" name="name" id="name" class="form-control rounded-3" placeholder="Ex: Criação de Fluxo Personalizado" required>
                        </div>
                        <div class="mb-2">
                            <label for="sla_hours" class="form-label fw-semibold text-muted" style="font-size:0.8rem;">Prazo de SLA (em Horas)</label>
                            <input type="number" name="sla_hours" id="sla_hours" class="form-control rounded-3" min="1" placeholder="Ex: 72" required>
                            <div class="form-text" style="font-size:0.7rem;">Tempo limite para resolução do chamado.</div>
                        </div>
                    </div>
                    
                    <div class="modal-footer border-top-0 pt-0 pb-4 px-4 gap-2">
                        <button type="button" class="btn btn-light rounded-3 px-3 py-2 fw-semibold" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-primary-axis rounded-3 px-4 py-2">Salvar Serviço</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 2. Edit Service Modal -->
    <div class="modal fade" id="editServiceModal" tabindex="-1" aria-labelledby="editServiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border:none; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
                <form method="POST" action="">
                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="service_id" id="edit_service_id" value="">
                    
                    <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                        <h5 class="modal-title fw-bold" id="editServiceModalLabel">Editar Serviço</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label fw-semibold text-muted" style="font-size:0.8rem;">Nome do Serviço</label>
                            <input type="text" name="name" id="edit_name" class="form-control rounded-3" required>
                        </div>
                        <div class="mb-2">
                            <label for="edit_sla_hours" class="form-label fw-semibold text-muted" style="font-size:0.8rem;">Prazo de SLA (em Horas)</label>
                            <input type="number" name="sla_hours" id="edit_sla_hours" class="form-control rounded-3" min="1" required>
                            <div class="form-text" style="font-size:0.7rem;">Tempo limite para resolução do chamado.</div>
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

    <!-- 3. Delete Service Modal -->
    <div class="modal fade" id="deleteServiceModal" tabindex="-1" aria-labelledby="deleteServiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border:none; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
                <form method="POST" action="">
                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="service_id" id="delete_service_id" value="">
                    
                    <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                        <h5 class="modal-title fw-bold" id="deleteServiceModalLabel">Excluir Serviço</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body p-4">
                        <p class="mb-0 text-muted">Tem certeza que deseja excluir o serviço <strong id="delete_service_name" class="text-dark"></strong>?</p>
                        <div class="alert alert-warning p-2.5 mt-3 mb-0" style="font-size:0.75rem; border-radius:8px;">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Esta ação é irreversível e só é permitida caso não existam chamados ativos vinculados a este serviço.
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
        const editModal = document.getElementById('editServiceModal');
        if (editModal) {
            editModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const name = button.getAttribute('data-name');
                const sla = button.getAttribute('data-sla');
                
                editModal.querySelector('#edit_service_id').value = id;
                editModal.querySelector('#edit_name').value = name;
                editModal.querySelector('#edit_sla_hours').value = sla;
            });
        }

        // Populate delete modal fields
        const deleteModal = document.getElementById('deleteServiceModal');
        if (deleteModal) {
            deleteModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const name = button.getAttribute('data-name');
                
                deleteModal.querySelector('#delete_service_id').value = id;
                deleteModal.querySelector('#delete_service_name').textContent = name;
            });
        }
    </script>
</body>
</html>
