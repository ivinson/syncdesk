<?php
// manage_contacts.php - CRUD for customer contacts/requesters
require_once 'users/init.php';

// Force strict administrator session check
if (!isset($user) || !$user->isLoggedIn() || !hasPerm([2], $user->data()->id)) {
    Redirect::to('index.php');
}

$db = DB::getInstance();
$error_msg = "";
$success_msg = "";

// Fetch all active customers to populate filter
$customers = $db->query("SELECT * FROM customers WHERE status = 1 ORDER BY name ASC")->results();

// Retrieve selected customer filter
$filter_customer_id = (int)Input::get('customer_id');
if ($filter_customer_id <= 0 && count($customers) > 0) {
    $filter_customer_id = (int)$customers[0]->id;
}

// ==========================================
// POST ACTION HANDLER
// ==========================================
if (Input::exists()) {
    if (Token::check(Input::get('csrf'))) {
        $action = Input::get('action');
        
        // 1. ADD CONTACT
        if ($action === 'add') {
            $cust_id = (int)Input::get('target_customer_id');
            $name = trim(Input::get('name'));
            $email = trim(Input::get('email'));
            
            if ($cust_id <= 0) {
                $error_msg = "Selecione uma empresa válida.";
            } elseif (empty($name)) {
                $error_msg = "O nome do solicitante é obrigatório.";
            } else {
                $db->insert('customer_contacts', [
                    'customer_id' => $cust_id,
                    'name' => $name,
                    'email' => !empty($email) ? $email : null
                ]);
                $success_msg = "Solicitante '{$name}' cadastrado com sucesso!";
                // Keep the filter on the client we just added the contact to
                $filter_customer_id = $cust_id;
            }
        }
        
        // 2. EDIT CONTACT
        elseif ($action === 'edit') {
            $id = (int)Input::get('contact_id');
            $cust_id = (int)Input::get('target_customer_id');
            $name = trim(Input::get('name'));
            $email = trim(Input::get('email'));
            
            if ($id <= 0) {
                $error_msg = "ID do solicitante inválido.";
            } elseif (empty($name)) {
                $error_msg = "O nome do solicitante é obrigatório.";
            } else {
                $db->query("UPDATE customer_contacts SET name = ?, email = ? WHERE id = ?", [$name, !empty($email) ? $email : null, $id]);
                $success_msg = "Solicitante atualizado com sucesso!";
                if ($cust_id > 0) {
                    $filter_customer_id = $cust_id;
                }
            }
        }
        
        // 3. DELETE CONTACT
        elseif ($action === 'delete') {
            $id = (int)Input::get('contact_id');
            $cust_id = (int)Input::get('target_customer_id');
            
            if ($id > 0) {
                // Check if any tasks are linked to this contact
                $check_tasks = $db->query("SELECT id FROM tasks WHERE customer_contact_id = ? LIMIT 1", [$id]);
                if ($check_tasks->count() > 0) {
                    $error_msg = "Não é possível excluir este solicitante pois existem chamados/tarefas associados a ele.";
                } else {
                    $db->query("DELETE FROM customer_contacts WHERE id = ?", [$id]);
                    $success_msg = "Solicitante excluído com sucesso!";
                }
                if ($cust_id > 0) {
                    $filter_customer_id = $cust_id;
                }
            } else {
                $error_msg = "ID do solicitante inválido para exclusão.";
            }
        }
    } else {
        $error_msg = "Erro: Validação CSRF falhou. Recarregue a página.";
    }
}

// Fetch filtered contacts
$contacts = [];
if ($filter_customer_id > 0) {
    $contacts = $db->query("SELECT * FROM customer_contacts WHERE customer_id = ? ORDER BY name ASC", [$filter_customer_id])->results();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncDesk - Gerenciar Solicitantes de Clientes</title>
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

        .filter-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px -1px rgba(0, 0, 0, 0.01);
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

        .form-select, .form-control {
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            padding: 0.5rem 0.75rem;
            font-size: 0.9rem;
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
                        <li class="breadcrumb-item active" aria-current="page">Solicitantes (Clientes)</li>
                    </ol>
                </nav>
                <h2 class="fw-bold m-0 text-slate-800">Solicitantes de Clientes</h2>
            </div>
            
            <button class="btn btn-primary btn-primary-axis" data-bs-toggle="modal" data-bs-target="#addContactModal" <?= count($customers) === 0 ? 'disabled' : '' ?>>
                <i class="bi bi-plus-lg me-1"></i> Novo Solicitante
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

        <!-- Customer Select Filter -->
        <div class="filter-card">
            <form method="GET" action="" id="filterForm">
                <div class="row align-items-center g-3">
                    <div class="col-auto">
                        <label for="customer_id" class="col-form-label fw-semibold text-muted" style="font-size: 0.85rem;">Selecionar Cliente:</label>
                    </div>
                    <div class="col-sm-5 col-md-4">
                        <select name="customer_id" id="customer_id" class="form-select" onchange="document.getElementById('filterForm').submit()">
                            <?php if (count($customers) > 0): ?>
                                <?php foreach ($customers as $cust): ?>
                                    <option value="<?= $cust->id ?>" <?= $cust->id == $filter_customer_id ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cust->name) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="">Nenhum cliente disponível</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
            </form>
        </div>

        <!-- Contacts Data Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width: 80px;">ID</th>
                            <th>Nome do Solicitante</th>
                            <th>E-mail</th>
                            <th style="width: 180px; text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($contacts) > 0): ?>
                            <?php foreach ($contacts as $cnt): ?>
                                <tr>
                                    <td><strong>#<?= $cnt->id ?></strong></td>
                                    <td class="fw-semibold text-slate-700"><?= htmlspecialchars($cnt->name) ?></td>
                                    <td>
                                        <?php if ($cnt->email): ?>
                                            <a href="mailto:<?= htmlspecialchars($cnt->email) ?>" class="text-decoration-none text-primary">
                                                <i class="bi bi-envelope me-1"></i> <?= htmlspecialchars($cnt->email) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.85rem;"><i>Não cadastrado</i></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <div class="d-inline-flex gap-2">
                                            <!-- Edit Button -->
                                            <button class="btn btn-outline-secondary btn-sm btn-sm-axis" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editContactModal"
                                                    data-id="<?= $cnt->id ?>"
                                                    data-name="<?= htmlspecialchars($cnt->name) ?>"
                                                    data-email="<?= htmlspecialchars($cnt->email ?? '') ?>"
                                                    data-cust-id="<?= $cnt->customer_id ?>"
                                                    title="Editar Solicitante">
                                                <i class="bi bi-pencil"></i> Editar
                                            </button>
                                            
                                            <!-- Delete Button -->
                                            <button class="btn btn-outline-danger btn-sm btn-sm-axis" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteContactModal"
                                                    data-id="<?= $cnt->id ?>"
                                                    data-name="<?= htmlspecialchars($cnt->name) ?>"
                                                    data-cust-id="<?= $cnt->customer_id ?>"
                                                    title="Excluir Solicitante">
                                                <i class="bi bi-trash"></i> Excluir
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">
                                    <i class="bi bi-person-x fs-3 d-block mb-2"></i>
                                    Nenhum solicitante cadastrado para esta empresa.
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

    <!-- 1. Add Contact Modal -->
    <div class="modal fade" id="addContactModal" tabindex="-1" aria-labelledby="addContactModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border:none; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
                <form method="POST" action="">
                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="target_customer_id" value="<?= $filter_customer_id ?>">
                    
                    <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                        <h5 class="modal-title fw-bold" id="addContactModalLabel">Novo Solicitante</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-muted mb-1" style="font-size:0.8rem;">Empresa Alvo</label>
                            <input type="text" class="form-control rounded-3 bg-light" value="<?= htmlspecialchars($db->query("SELECT name FROM customers WHERE id = ?", [$filter_customer_id])->first()->name ?? '') ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="name" class="form-label fw-semibold text-muted" style="font-size:0.8rem;">Nome Completo</label>
                            <input type="text" name="name" id="name" class="form-control rounded-3" placeholder="Ex: Lucas Henrique Silva" required>
                        </div>
                        <div class="mb-2">
                            <label for="email" class="form-label fw-semibold text-muted" style="font-size:0.8rem;">E-mail (Opcional)</label>
                            <input type="email" name="email" id="email" class="form-control rounded-3" placeholder="Ex: lucas@cliente.com">
                        </div>
                    </div>
                    
                    <div class="modal-footer border-top-0 pt-0 pb-4 px-4 gap-2">
                        <button type="button" class="btn btn-light rounded-3 px-3 py-2 fw-semibold" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-primary-axis rounded-3 px-4 py-2">Salvar Solicitante</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 2. Edit Contact Modal -->
    <div class="modal fade" id="editContactModal" tabindex="-1" aria-labelledby="editContactModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border:none; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
                <form method="POST" action="">
                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="contact_id" id="edit_contact_id" value="">
                    <input type="hidden" name="target_customer_id" id="edit_target_customer_id" value="">
                    
                    <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                        <h5 class="modal-title fw-bold" id="editContactModalLabel">Editar Solicitante</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label fw-semibold text-muted" style="font-size:0.8rem;">Nome Completo</label>
                            <input type="text" name="name" id="edit_name" class="form-control rounded-3" required>
                        </div>
                        <div class="mb-2">
                            <label for="edit_email" class="form-label fw-semibold text-muted" style="font-size:0.8rem;">E-mail (Opcional)</label>
                            <input type="email" name="email" id="edit_email" class="form-control rounded-3">
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

    <!-- 3. Delete Contact Modal -->
    <div class="modal fade" id="deleteContactModal" tabindex="-1" aria-labelledby="deleteContactModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border:none; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
                <form method="POST" action="">
                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="contact_id" id="delete_contact_id" value="">
                    <input type="hidden" name="target_customer_id" id="delete_target_customer_id" value="">
                    
                    <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                        <h5 class="modal-title fw-bold" id="deleteContactModalLabel">Excluir Solicitante</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body p-4">
                        <p class="mb-0 text-muted">Tem certeza que deseja excluir o solicitante <strong id="delete_contact_name" class="text-dark"></strong>?</p>
                        <div class="alert alert-warning p-2.5 mt-3 mb-0" style="font-size:0.75rem; border-radius:8px;">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Esta ação é irreversível e só é permitida caso não existam chamados associados a este contato.
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
        const editModal = document.getElementById('editContactModal');
        if (editModal) {
            editModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const name = button.getAttribute('data-name');
                const email = button.getAttribute('data-email');
                const custId = button.getAttribute('data-cust-id');
                
                editModal.querySelector('#edit_contact_id').value = id;
                editModal.querySelector('#edit_name').value = name;
                editModal.querySelector('#edit_email').value = email;
                editModal.querySelector('#edit_target_customer_id').value = custId;
            });
        }

        // Populate delete modal fields
        const deleteModal = document.getElementById('deleteContactModal');
        if (deleteModal) {
            deleteModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const name = button.getAttribute('data-name');
                const custId = button.getAttribute('data-cust-id');
                
                deleteModal.querySelector('#delete_contact_id').value = id;
                deleteModal.querySelector('#delete_contact_name').textContent = name;
                deleteModal.querySelector('#delete_target_customer_id').value = custId;
            });
        }
    </script>
</body>
</html>
