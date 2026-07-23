<?php
// integrations.php - API Integration and Keys Management Hub
require_once 'users/init.php';

// Force strict administrator session check
if (!isset($user) || !$user->isLoggedIn() || !hasPerm([2], $user->data()->id)) {
    Redirect::to('index.php');
}

$db = DB::getInstance();
$error_msg = Input::get('err') ?: "";
$success_msg = Input::get('success') ?: "";

// Ensure notification tables and default settings exist
if (function_exists('initNotificationTables')) {
    initNotificationTables();
}

// ==========================================
// POST ACTION HANDLER
// ==========================================
if (Input::exists()) {
    if (Token::check(Input::get('csrf'))) {
        $action = Input::get('action');

        if ($action === 'test_whatsapp') {
            $test_phone = trim(Input::get('test_phone'));
            if (empty($test_phone)) {
                Redirect::to('integrations.php?err=' . urlencode("Informe um número de telefone com DDI e DDD para o teste."));
            } else {
                $res = dispatchWhatsAppApiMessage($test_phone, "Administrador (Teste)", "Tarefa de Teste SyncDesk", "efetuou um teste de notificação");
                if ($res['success']) {
                    Redirect::to('integrations.php?success=' . urlencode("Mensagem de teste enviada com sucesso para {$test_phone}!"));
                } else {
                    Redirect::to('integrations.php?err=' . urlencode("Falha no envio do teste: " . $res['message']));
                }
            }
        } else {
            // Save main settings
            $openai_key = trim(Input::get('openai_api_key'));
            $wa_mode = trim(Input::get('whatsapp_api_mode'));
            $wa_backend = trim(Input::get('whatsapp_backend_url'));
            $wa_token = trim(Input::get('whatsapp_api_token'));
            $wa_tpl_name = trim(Input::get('whatsapp_meta_template_name'));
            $wa_tpl_lang = trim(Input::get('whatsapp_meta_template_lang'));
            $wa_open_ticket = trim(Input::get('whatsapp_open_ticket'));
            $wa_queue_id = trim(Input::get('whatsapp_queue_id'));
            $wa_notify_actor = trim(Input::get('whatsapp_notify_actor'));
            $portal_assignee = trim(Input::get('portal_default_assignee'));

            $settings_to_update = [
                'openai_api_key' => $openai_key,
                'whatsapp_api_mode' => $wa_mode,
                'whatsapp_backend_url' => $wa_backend,
                'whatsapp_api_token' => $wa_token,
                'whatsapp_meta_template_name' => $wa_tpl_name,
                'whatsapp_meta_template_lang' => $wa_tpl_lang,
                'whatsapp_open_ticket' => $wa_open_ticket,
                'whatsapp_queue_id' => $wa_queue_id,
                'whatsapp_notify_actor' => $wa_notify_actor,
                'portal_default_assignee' => $portal_assignee
            ];

            $all_ok = true;
            foreach ($settings_to_update as $key => $val) {
                $check = $db->query("SELECT id FROM system_settings WHERE setting_key = ?", [$key]);
                if ($check->count() > 0) {
                    $upd = $db->query("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?", [$val, $key]);
                    if (!$upd) $all_ok = false;
                } else {
                    $ins = $db->insert('system_settings', ['setting_key' => $key, 'setting_value' => $val]);
                    if (!$ins) $all_ok = false;
                }
            }

            if ($all_ok) {
                Redirect::to('integrations.php?success=' . urlencode("Configurações de integrações salvas com sucesso!"));
            } else {
                Redirect::to('integrations.php?err=' . urlencode("Falha ao atualizar algumas configurações no banco de dados."));
            }
        }
    } else {
        Redirect::to('integrations.php?err=' . urlencode("Erro: Validação CSRF falhou. Recarregue a página."));
    }
}

// Fetch current setting values
$openai_current = getSystemSetting('openai_api_key', '');
$wa_mode_current = getSystemSetting('whatsapp_api_mode', 'official');
$wa_backend_current = getSystemSetting('whatsapp_backend_url', 'https://sync.triadgroup.com.br');
$wa_token_current = getSystemSetting('whatsapp_api_token', '##triad@##neurosculpt');
$wa_tpl_name_current = getSystemSetting('whatsapp_meta_template_name', 'vars_001');
$wa_tpl_lang_current = getSystemSetting('whatsapp_meta_template_lang', 'pt_BR');
$wa_open_ticket_current = getSystemSetting('whatsapp_open_ticket', '0');
$wa_queue_id_current = getSystemSetting('whatsapp_queue_id', '0');
$wa_notify_actor_current = getSystemSetting('whatsapp_notify_actor', '0');
$portal_default_assignee_current = getSystemSetting('portal_default_assignee', '1');

// Fetch active users to populate assignee select
$users_list = $db->query("SELECT id, fname, lname, username FROM users WHERE active = 1 ORDER BY fname ASC, username ASC")->results();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncDesk - Integrações e APIs</title>
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

        .integration-card {
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

        .input-group-text-axis {
            background-color: #f8fafc;
            border-color: #cbd5e1;
            color: #64748b;
            cursor: pointer;
        }
        
        .form-control-axis, .form-select-axis {
            border-color: #cbd5e1;
            padding: 0.6rem 0.8rem;
            font-size: 0.9rem;
            border-radius: 8px;
        }

        .form-control-axis:focus, .form-select-axis:focus {
            border-color: #e11d48;
            box-shadow: 0 0 0 0.2rem rgba(225, 29, 72, 0.15);
        }

        .api-mode-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.25rem;
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
                        <li class="breadcrumb-item active" aria-current="page">Integrações</li>
                    </ol>
                </nav>
                <h2 class="fw-bold m-0 text-slate-800">Integrações e APIs</h2>
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
            <div class="col-lg-10">
                <form method="POST" action="">
                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                    <input type="hidden" name="action" value="save_settings">

                    <!-- 1. API WhatsApp Settings Card -->
                    <div class="integration-card">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h5 class="fw-bold m-0 text-slate-800">
                                <i class="bi bi-whatsapp me-2 text-success"></i> Gateway de Notificações WhatsApp
                            </h5>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1">Ativo</span>
                        </div>
                        <p class="text-muted small mb-4">
                            Configure o servidor e a chave de autenticação para envio automático de alertas via WhatsApp quando houver movimentações em tarefas (criação, troca de status, comentários e finalização).
                        </p>

                        <!-- Radio Choice API Mode -->
                        <div class="api-mode-box mb-4">
                            <label class="form-label fw-semibold text-slate-700 d-block mb-2">Tipo de API de WhatsApp</label>
                            <div class="d-flex flex-wrap gap-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="whatsapp_api_mode" id="mode_official" value="official" <?= $wa_mode_current === 'official' ? 'checked' : '' ?> onchange="toggleApiModeFields()">
                                    <label class="form-check-label fw-medium" for="mode_official">
                                        <i class="bi bi-patch-check-fill text-primary me-1"></i> API Oficial Meta Custom Template (`sendMetaCustom`)
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="whatsapp_api_mode" id="mode_qrcode" value="qrcode" <?= $wa_mode_current === 'qrcode' ? 'checked' : '' ?> onchange="toggleApiModeFields()">
                                    <label class="form-check-label fw-medium" for="mode_qrcode">
                                        <i class="bi bi-qr-code text-dark me-1"></i> API Não-Oficial / QR Code (`send`)
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <!-- Backend URL -->
                            <div class="col-md-7">
                                <label for="whatsapp_backend_url" class="form-label fw-semibold text-slate-700">URL Backend da API</label>
                                <div class="input-group">
                                    <span class="input-group-text input-group-text-axis"><i class="bi bi-link-45deg"></i></span>
                                    <input type="url" name="whatsapp_backend_url" id="whatsapp_backend_url" class="form-control form-control-axis" placeholder="https://sync.triadgroup.com.br" value="<?= htmlspecialchars($wa_backend_current) ?>" required>
                                </div>
                                <div class="form-text" style="font-size:0.75rem;">Endereço base do seu servidor de mensageria (sem barra no final).</div>
                            </div>

                            <!-- API Token -->
                            <div class="col-md-5">
                                <label for="whatsapp_api_token" class="form-label fw-semibold text-slate-700">Token de Autenticação (Bearer)</label>
                                <div class="input-group">
                                    <span class="input-group-text input-group-text-axis"><i class="bi bi-shield-lock"></i></span>
                                    <input type="password" name="whatsapp_api_token" id="whatsapp_api_token" class="form-control form-control-axis" value="<?= htmlspecialchars($wa_token_current) ?>" placeholder="##triad@##neurosculpt" required>
                                    <button type="button" class="btn btn-outline-secondary input-group-text-axis" onclick="toggleVisibility('whatsapp_api_token', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Open Ticket & Queue ID -->
                            <div class="col-md-6">
                                <label for="whatsapp_open_ticket" class="form-label fw-semibold text-slate-700">Abrir Ticket no Envio?</label>
                                <select name="whatsapp_open_ticket" id="whatsapp_open_ticket" class="form-select form-select-axis">
                                    <option value="0" <?= $wa_open_ticket_current == '0' ? 'selected' : '' ?>>0 - Não abre ticket (Recomendado para Notificações)</option>
                                    <option value="1" <?= $wa_open_ticket_current == '1' ? 'selected' : '' ?>>1 - Abre ticket de atendimento</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="whatsapp_queue_id" class="form-label fw-semibold text-slate-700">ID da Fila (Queue ID)</label>
                                <input type="number" name="whatsapp_queue_id" id="whatsapp_queue_id" class="form-control form-control-axis" value="<?= htmlspecialchars($wa_queue_id_current) ?>" placeholder="0">
                                <div class="form-text" style="font-size:0.75rem;">Defina o ID caso queira direcionar o ticket a uma fila.</div>
                            </div>

                            <div class="col-12">
                                <label for="whatsapp_notify_actor" class="form-label fw-semibold text-slate-700">Notificar quem criou ou alterou a tarefa?</label>
                                <select name="whatsapp_notify_actor" id="whatsapp_notify_actor" class="form-select form-select-axis">
                                    <option value="0" <?= $wa_notify_actor_current == '0' ? 'selected' : '' ?>>0 - Não notificar quem executou a ação (Apenas para o responsável alocado)</option>
                                    <option value="1" <?= $wa_notify_actor_current == '1' ? 'selected' : '' ?>>1 - Notificar também o autor da criação/alteração</option>
                                </select>
                                <div class="form-text" style="font-size:0.75rem;">Define se a pessoa que criou ou alterou a tarefa também deve receber a notificação no celular dela.</div>
                            </div>

                            <div class="col-12 mt-3">
                                <label for="portal_default_assignee" class="form-label fw-semibold text-slate-700">Responsável Padrão pelas Tarefas do Portal</label>
                                <select name="portal_default_assignee" id="portal_default_assignee" class="form-select form-select-axis" autocomplete="off">
                                    <?php foreach ($users_list as $usr): 
                                        $u_name = trim($usr->fname . ' ' . $usr->lname) ?: $usr->username;
                                    ?>
                                        <option value="<?= $usr->id ?>" <?= $usr->id == $portal_default_assignee_current ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($u_name) ?> (<?= htmlspecialchars($usr->username) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" style="font-size:0.75rem;">Define qual atendente receberá as tarefas criadas pelo portal de suporte quando o cliente não tiver um atendente específico associado.</div>
                            </div>
                        </div>

                        <!-- Meta Official Template Specific Section -->
                        <div id="meta_template_fields" class="mt-4 p-3 bg-light border rounded-3" style="<?= $wa_mode_current === 'official' ? '' : 'display:none;' ?>">
                            <h6 class="fw-bold text-slate-800 mb-3"><i class="bi bi-sliders me-1 text-primary"></i> Parâmetros do Template Oficial da Meta</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="whatsapp_meta_template_name" class="form-label fw-semibold text-slate-700">Nome do Template Meta (`name`)</label>
                                    <input type="text" name="whatsapp_meta_template_name" id="whatsapp_meta_template_name" class="form-control form-control-axis" value="<?= htmlspecialchars($wa_tpl_name_current) ?>" placeholder="vars_001">
                                </div>
                                <div class="col-md-6">
                                    <label for="whatsapp_meta_template_lang" class="form-label fw-semibold text-slate-700">Idioma (`language`)</label>
                                    <input type="text" name="whatsapp_meta_template_lang" id="whatsapp_meta_template_lang" class="form-control form-control-axis" value="<?= htmlspecialchars($wa_tpl_lang_current) ?>" placeholder="pt_BR">
                                </div>
                            </div>
                            <div class="alert alert-info mb-0 mt-3 p-2.5 d-flex align-items-center gap-2" style="font-size: 0.8rem;">
                                <i class="bi bi-info-circle-fill fs-5 flex-shrink-0 text-info"></i>
                                <div>
                                    <strong>Estrutura de Variáveis do Body Meta:</strong> O SyncDesk preencherá automaticamente as 2 variáveis exigidas na ordem:
                                    <br>• Variável 1 (`{{1}}`): Nome de quem alterou o status / criou o comentário / finalizou a tarefa.
                                    <br>• Variável 2 (`{{2}}`): Título / Nome da Tarefa.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. AI Key Management Card -->
                    <div class="integration-card">
                        <h5 class="fw-bold mb-3 text-slate-800"><i class="bi bi-key me-2 text-danger"></i> Chaves de API de Inteligência Artificial</h5>
                        <p class="text-muted small mb-4">
                            Configure as credenciais das IAs utilizadas no sistema. O SyncDesk utiliza as chaves abaixo para realizar a identificação e criação de tarefas em lote (via texto ou gravação de áudio).
                        </p>

                        <!-- OpenAI API Key -->
                        <div class="mb-3">
                            <label for="openai_api_key" class="form-label fw-semibold text-slate-700">OpenAI API Key</label>
                            <div class="input-group">
                                <span class="input-group-text input-group-text-axis"><i class="bi bi-key"></i></span>
                                <input type="password" name="openai_api_key" id="openai_api_key" class="form-control form-control-axis" placeholder="sk-proj-..." value="<?= htmlspecialchars($openai_current) ?>">
                                <button type="button" class="btn btn-outline-secondary input-group-text-axis" onclick="toggleVisibility('openai_api_key', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <hr class="my-4 text-slate-200">

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-primary-axis">
                                <i class="bi bi-save me-1"></i> Salvar Todas as Integrações
                            </button>
                        </div>
                    </div>
                </form>

                <!-- 3. WhatsApp Test Dispatch Card -->
                <div class="integration-card">
                    <h5 class="fw-bold mb-2 text-slate-800"><i class="bi bi-send-check me-2 text-primary"></i> Testar Conexão do WhatsApp</h5>
                    <p class="text-muted small mb-3">
                        Envie uma mensagem de teste para verificar se o backend e o token configurados acima estão enviando as notificações corretamente.
                    </p>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                        <input type="hidden" name="action" value="test_whatsapp">

                        <div class="row align-items-end g-3">
                            <div class="col-md-7">
                                <label for="test_phone" class="form-label fw-semibold text-slate-700">Telefone Destino (DDI + DDD + Número)</label>
                                <input type="text" name="test_phone" id="test_phone" class="form-control form-control-axis" placeholder="5511999999999" required>
                            </div>
                            <div class="col-md-5">
                                <button type="submit" class="btn btn-outline-success w-100 fw-semibold" style="border-radius:8px; padding:0.6rem;">
                                    <i class="bi bi-whatsapp me-1"></i> Enviar Mensagem de Teste
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function toggleVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }

        function toggleApiModeFields() {
            const isOfficial = document.getElementById('mode_official').checked;
            const metaFields = document.getElementById('meta_template_fields');
            if (metaFields) {
                metaFields.style.display = isOfficial ? 'block' : 'none';
            }
        }
    </script>
</body>
</html>
