<?php
// user_notifications.php - User Notification Preferences & Admin Management Screen
require_once 'users/init.php';

if (!isset($user) || !$user->isLoggedIn()) {
    Redirect::to('users/login.php');
}

$db = DB::getInstance();
$logged_user_id = $user->data()->id;
$is_admin = hasPerm([2], $logged_user_id);

$error_msg = "";
$success_msg = "";

// Self-healing database tables check
if (function_exists('initNotificationTables')) {
    initNotificationTables();
}

// Ensure logged user has a row in user_notification_settings
$checkLogged = $db->query("SELECT id FROM user_notification_settings WHERE user_id = ?", [$logged_user_id]);
if ($checkLogged->count() === 0) {
    $db->insert('user_notification_settings', [
        'user_id' => $logged_user_id,
        'phone' => '',
        'notify_whatsapp' => 1,
        'notify_email' => 0,
        'notify_sms' => 0
    ]);
}

// ==========================================
// POST HANDLER (Save Preferences)
// ==========================================
if (Input::exists()) {
    if (Token::check(Input::get('csrf'))) {
        $action = Input::get('action');

        // 1. Save Personal Preferences
        if ($action === 'save_personal_prefs') {
            $phone = trim(Input::get('phone'));
            $notify_whatsapp = Input::get('notify_whatsapp') ? 1 : 0;
            $notify_email = Input::get('notify_email') ? 1 : 0;
            $notify_sms = Input::get('notify_sms') ? 1 : 0;

            // Sanitize phone
            $clean_phone = sanitizePhoneNumber($phone);

            $update = $db->query(
                "UPDATE user_notification_settings SET phone = ?, notify_whatsapp = ?, notify_email = ?, notify_sms = ? WHERE user_id = ?",
                [$clean_phone, $notify_whatsapp, $notify_email, $notify_sms, $logged_user_id]
            );

            if ($update) {
                $success_msg = "Suas preferências de notificação foram salvas com sucesso!";
            } else {
                $error_msg = "Falha ao salvar preferências no banco de dados.";
            }
        }

        // 2. Save Admin Collaborator Notification Settings
        else if ($action === 'admin_save_user_pref' && $is_admin) {
            $target_user_id = (int)Input::get('target_user_id');
            $target_phone = trim(Input::get('target_phone'));
            $notify_whatsapp = Input::get('notify_whatsapp') ? 1 : 0;
            $notify_email = Input::get('notify_email') ? 1 : 0;
            $notify_sms = Input::get('notify_sms') ? 1 : 0;

            $clean_phone = sanitizePhoneNumber($target_phone);

            // Check if row exists
            $chk = $db->query("SELECT id FROM user_notification_settings WHERE user_id = ?", [$target_user_id]);
            if ($chk->count() > 0) {
                $upd = $db->query(
                    "UPDATE user_notification_settings SET phone = ?, notify_whatsapp = ?, notify_email = ?, notify_sms = ? WHERE user_id = ?",
                    [$clean_phone, $notify_whatsapp, $notify_email, $notify_sms, $target_user_id]
                );
            } else {
                $upd = $db->insert('user_notification_settings', [
                    'user_id' => $target_user_id,
                    'phone' => $clean_phone,
                    'notify_whatsapp' => $notify_whatsapp,
                    'notify_email' => $notify_email,
                    'notify_sms' => $notify_sms
                ]);
            }

            if ($upd) {
                $success_msg = "Configurações de notificação do colaborador atualizadas!";
            } else {
                $error_msg = "Falha ao atualizar preferências do colaborador.";
            }
        }
    } else {
        $error_msg = "Erro: Validação CSRF falhou. Recarregue a página.";
    }
}

// Fetch personal notification settings
$personal_pref_q = $db->query("SELECT * FROM user_notification_settings WHERE user_id = ? LIMIT 1", [$logged_user_id]);
$personal_pref = $personal_pref_q->first();

// Fetch all users for Admin View
$all_collaborators = [];
if ($is_admin) {
    $collab_query = $db->query("
        SELECT u.id, u.fname, u.lname, u.username, u.email, 
               uns.phone, uns.notify_whatsapp, uns.notify_email, uns.notify_sms 
        FROM users u 
        LEFT JOIN user_notification_settings uns ON u.id = uns.user_id 
        ORDER BY u.fname ASC, u.username ASC
    ");
    if ($collab_query->count() > 0) {
        $all_collaborators = $collab_query->results();
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncDesk - Notificações do Sistema</title>
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

        .notify-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .btn-primary-axis {
            background-color: #e11d48;
            border-color: #e11d48;
            color: #ffffff;
            font-weight: 600;
            border-radius: 8px;
            font-size: 0.9rem;
            padding: 0.6rem 1.5rem;
            transition: all 0.2s ease;
        }

        .btn-primary-axis:hover {
            background-color: #be123c;
            border-color: #be123c;
            color: #ffffff;
        }

        .form-control-axis {
            border-color: #cbd5e1;
            padding: 0.6rem 0.8rem;
            font-size: 0.9rem;
            border-radius: 8px;
        }

        .form-control-axis:focus {
            border-color: #e11d48;
            box-shadow: 0 0 0 0.2rem rgba(225, 29, 72, 0.15);
        }

        .channel-row {
            padding: 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 0.75rem;
            background-color: #ffffff;
            transition: background-color 0.2s ease;
        }

        .channel-row:hover {
            background-color: #f8fafc;
        }

        .form-check-input:checked {
            background-color: #10b981;
            border-color: #10b981;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: #e11d48;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
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
                        <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Notificações</li>
                    </ol>
                </nav>
                <h2 class="fw-bold m-0 text-slate-800">Notificações do Sistema</h2>
            </div>
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

        <div class="row">
            <!-- Left Column: Personal Notification Preferences -->
            <div class="col-lg-6 mb-4">
                <div class="notify-card h-100">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h5 class="fw-bold m-0 text-slate-800">
                            <i class="bi bi-bell-fill me-2 text-danger"></i> Minhas Preferências de Notificação
                        </h5>
                    </div>
                    <p class="text-muted small mb-4">
                        Defina como você deseja ser avisado quando houver novas tarefas atribuídas a você, alterações de status ou novos comentários em tarefas que você está executando.
                    </p>

                    <form method="POST" action="">
                        <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                        <input type="hidden" name="action" value="save_personal_prefs">

                        <!-- Phone Number -->
                        <div class="mb-4">
                            <label for="phone" class="form-label fw-semibold text-slate-700">Seu Telefone com WhatsApp</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-whatsapp text-success"></i></span>
                                <input type="text" name="phone" id="phone" class="form-control form-control-axis border-start-0" placeholder="5511999999999" value="<?= htmlspecialchars($personal_pref->phone ?? '') ?>">
                            </div>
                            <div class="form-text" style="font-size: 0.75rem;">
                                Digite no formato internacional com DDI (55) + DDD + Número. Ex: <code>5511999999999</code>.
                            </div>
                        </div>

                        <label class="form-label fw-semibold text-slate-700 mb-2">Canais de Notificação Ativos</label>
                        
                        <!-- 1. WhatsApp Channel -->
                        <div class="channel-row d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-3">
                                <div class="p-2 bg-success-subtle text-success rounded-3 fs-5">
                                    <i class="bi bi-whatsapp"></i>
                                </div>
                                <div>
                                    <h6 class="m-0 fw-semibold text-slate-800">WhatsApp</h6>
                                    <span class="text-muted" style="font-size:0.75rem;">Receber alertas de tarefas via mensagem oficial do WhatsApp.</span>
                                </div>
                            </div>
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" role="switch" name="notify_whatsapp" id="notify_whatsapp" value="1" <?= ($personal_pref->notify_whatsapp ?? 1) == 1 ? 'checked' : '' ?>>
                            </div>
                        </div>

                        <!-- 2. Email Channel (Placeholder) -->
                        <div class="channel-row d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-3">
                                <div class="p-2 bg-primary-subtle text-primary rounded-3 fs-5">
                                    <i class="bi bi-envelope"></i>
                                </div>
                                <div>
                                    <h6 class="m-0 fw-semibold text-slate-800">E-mail <span class="badge bg-secondary ms-1" style="font-size:0.65rem;">Em Breve</span></h6>
                                    <span class="text-muted" style="font-size:0.75rem;">Receber resumos e alertas importantes da equipe por e-mail.</span>
                                </div>
                            </div>
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" role="switch" name="notify_email" id="notify_email" value="1" <?= ($personal_pref->notify_email ?? 0) == 1 ? 'checked' : '' ?>>
                            </div>
                        </div>

                        <!-- 3. SMS Channel (Placeholder) -->
                        <div class="channel-row d-flex align-items-center justify-content-between mb-4">
                            <div class="d-flex align-items-center gap-3">
                                <div class="p-2 bg-warning-subtle text-warning rounded-3 fs-5">
                                    <i class="bi bi-chat-text"></i>
                                </div>
                                <div>
                                    <h6 class="m-0 fw-semibold text-slate-800">SMS <span class="badge bg-secondary ms-1" style="font-size:0.65rem;">Em Breve</span></h6>
                                    <span class="text-muted" style="font-size:0.75rem;">Receber mensagens SMS de urgência no seu celular.</span>
                                </div>
                            </div>
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" role="switch" name="notify_sms" id="notify_sms" value="1" <?= ($personal_pref->notify_sms ?? 0) == 1 ? 'checked' : '' ?>>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-primary-axis">
                                <i class="bi bi-save me-1"></i> Salvar Minhas Preferências
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Column: Admin Management of All Collaborators -->
            <?php if ($is_admin): ?>
                <div class="col-lg-6 mb-4">
                    <div class="notify-card h-100">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h5 class="fw-bold m-0 text-slate-800">
                                <i class="bi bi-people-fill me-2 text-primary"></i> Notificações da Equipe (Painel Admin)
                            </h5>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1">Administrador</span>
                        </div>
                        <p class="text-muted small mb-4">
                            Como administrador, você pode visualizar e configurar o número de WhatsApp e ativar/desativar as notificações dos colaboradores diretamente na lista abaixo.
                        </p>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle border" style="font-size: 0.85rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th>Colaborador</th>
                                        <th>Telefone WhatsApp</th>
                                        <th class="text-center">WhatsApp</th>
                                        <th class="text-end">Ação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_collaborators as $collab): 
                                        $c_name = trim(($collab->fname ?? '') . ' ' . ($collab->lname ?? '')) ?: $collab->username;
                                        $c_wa_active = ($collab->notify_whatsapp ?? 1) == 1;
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="user-avatar flex-shrink-0">
                                                        <?= strtoupper(substr($c_name, 0, 1)) ?>
                                                    </div>
                                                    <div>
                                                        <strong class="d-block text-slate-800"><?= htmlspecialchars($c_name) ?></strong>
                                                        <span class="text-muted" style="font-size:0.75rem;"><?= htmlspecialchars($collab->email) ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (!empty($collab->phone)): ?>
                                                    <span class="font-monospace text-dark"><i class="bi bi-whatsapp text-success me-1"></i><?= htmlspecialchars($collab->phone) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted fst-italic" style="font-size:0.75rem;">Não cadastrado</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($c_wa_active && !empty($collab->phone)): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2">Ativo</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2">Inativo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-primary" style="border-radius:6px; font-size:0.75rem;" 
                                                        onclick="openEditUserModal(<?= $collab->id ?>, '<?= htmlspecialchars(addslashes($c_name)) ?>', '<?= htmlspecialchars(addslashes($collab->phone ?? '')) ?>', <?= ($collab->notify_whatsapp ?? 1) ?>, <?= ($collab->notify_email ?? 0) ?>, <?= ($collab->notify_sms ?? 0) ?>)">
                                                    <i class="bi bi-pencil-square me-1"></i> Editar
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Admin Edit User Notification Modal -->
    <?php if ($is_admin): ?>
        <div class="modal fade" id="editUserNotifyModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius: 16px;">
                    <div class="modal-header border-bottom-0 pb-0">
                        <h5 class="modal-title fw-bold text-slate-800" id="modalUserName">Editar Notificações</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                        <input type="hidden" name="action" value="admin_save_user_pref">
                        <input type="hidden" name="target_user_id" id="modal_target_user_id" value="">

                        <div class="modal-body pt-3">
                            <div class="mb-3">
                                <label for="target_phone" class="form-label fw-semibold text-slate-700">Telefone WhatsApp</label>
                                <input type="text" name="target_phone" id="modal_target_phone" class="form-control form-control-axis" placeholder="5511999999999">
                                <div class="form-text" style="font-size:0.75rem;">Digite apenas números com DDI (55) + DDD + Número.</div>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" name="notify_whatsapp" id="modal_notify_whatsapp" value="1">
                                <label class="form-check-label fw-semibold text-slate-700" for="modal_notify_whatsapp">Notificações por WhatsApp Ativas</label>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" name="notify_email" id="modal_notify_email" value="1">
                                <label class="form-check-label fw-semibold text-slate-700" for="modal_notify_email">Notificações por E-mail Ativas</label>
                            </div>

                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" name="notify_sms" id="modal_notify_sms" value="1">
                                <label class="form-check-label fw-semibold text-slate-700" for="modal_notify_sms">Notificações por SMS Ativas</label>
                            </div>
                        </div>

                        <div class="modal-footer border-top-0 pt-0">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="border-radius:8px;">Cancelar</button>
                            <button type="submit" class="btn btn-primary btn-primary-axis">Salvar Alterações</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        function openEditUserModal(userId, userName, phone, notifyWa, notifyEm, notifySms) {
            document.getElementById('modal_target_user_id').value = userId;
            document.getElementById('modalUserName').innerText = 'Editar Notificações: ' + userName;
            document.getElementById('modal_target_phone').value = phone;
            document.getElementById('modal_notify_whatsapp').checked = (notifyWa == 1);
            document.getElementById('modal_notify_email').checked = (notifyEm == 1);
            document.getElementById('modal_notify_sms').checked = (notifySms == 1);

            const modal = new bootstrap.Modal(document.getElementById('editUserNotifyModal'));
            modal.show();
        }
    </script>
</body>
</html>
