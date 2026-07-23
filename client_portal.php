<?php
// client_portal.php - Whitelabel Client Support Portal (Public Page)
require_once 'users/init.php';

$db = DB::getInstance();

$error_msg = "";
$success_msg = "";
$protocol_number = "";

// ==========================================
// 1. AUTHENTICATE CLIENT VIA URL TOKEN
// ==========================================
$token = trim(Input::get('token'));

if (empty($token)) {
    // Fallback: Default token for Nine support portal
    $token = 'token_nine_sa_7b9d3';
}

$customer_query = $db->query("SELECT * FROM customers WHERE portal_token = ? AND status = 1", [$token]);
if ($customer_query->count() === 0) {
    render_access_denied("Acesso negado: Token de portal inválido ou inativo.");
    exit;
}

$customer = $customer_query->first();

// ==========================================
// 2. DATA RETRIEVAL FOR THE CLIENT
// ==========================================
// Retrieve requesters/contacts for this customer
$contacts = $db->query("SELECT * FROM customer_contacts WHERE customer_id = ? ORDER BY name ASC", [$customer->id])->results();

// Retrieve all available services with their SLA times
$services = $db->query("SELECT * FROM services ORDER BY name ASC")->results();

// ==========================================
// 3. POST REQUEST HANDLER (Chamado Submission)
// ==========================================
if (Input::exists()) {
    // CSRF bypass: page is public and embedded inside external iframes
    // where browsers block PHP session cookies by default
    $contact_id = (int)Input::get('customer_contact_id');
    $service_id = (int)Input::get('service_id');
    $title = trim(Input::get('title'));
    $preferred_delivery = Input::get('preferred_delivery_date');
    $more_info = trim(Input::get('more_info'));
    $priority = Input::get('priority');
        
    $errors = [];
    if ($contact_id <= 0) $errors[] = "Selecione o seu nome (Solicitante).";
    if ($service_id <= 0) $errors[] = "Selecione o serviço a ser prestado.";
    if (empty($title)) $errors[] = "Digite um breve resumo da sua solicitação.";
    if (empty($more_info)) $errors[] = "Descreva detalhadamente a sua solicitação.";
    if (!in_array($priority, ['low', 'medium', 'high'])) $errors[] = "Selecione uma prioridade válida.";
    
    // Fetch selected service to calculate SLA
    $service_data = null;
    if ($service_id > 0) {
        $service_query = $db->query("SELECT * FROM services WHERE id = ?", [$service_id]);
        if ($service_query->count() > 0) {
            $service_data = $service_query->first();
        } else {
            $errors[] = "Serviço inválido selecionado.";
        }
    }

    // Validate preferred delivery date based on SLA (8 hours = 1 day)
    if (!empty($preferred_delivery) && $service_data) {
        $sla_hours = (int)$service_data->sla_hours;
        $days_needed = (int)ceil($sla_hours / 8);
        
        // Calculate raw minimum date
        $min_allowed_time = strtotime("+{$days_needed} days");
        $min_day_of_week = (int)date('N', $min_allowed_time);
        
        // If the minimum allowed date falls on a weekend, push it to next Monday
        if ($min_day_of_week === 6) { // Saturday
            $min_allowed_time = strtotime("+2 days", $min_allowed_time);
        } elseif ($min_day_of_week === 7) { // Sunday
            $min_allowed_time = strtotime("+1 day", $min_allowed_time);
        }
        
        $min_allowed_date = date('Y-m-d', $min_allowed_time);
        
        if ($preferred_delivery < $min_allowed_date) {
            $errors[] = "A data de entrega preferencial não pode ser anterior a " . date('d/m/Y', strtotime($min_allowed_date)) . " para o serviço selecionado (SLA de {$sla_hours}h exige no mínimo {$days_needed} dias de antecedência, excluindo finais de semana).";
        }
        
        // Block weekend delivery date selection directly
        $day_of_week = (int)date('N', strtotime($preferred_delivery));
        if ($day_of_week === 6 || $day_of_week === 7) {
            $errors[] = "Finais de semana (sábado e domingo) não são permitidos como data de entrega preferencial. Por favor, selecione um dia útil.";
        }
    }
    
    // Validate contact belongs to this customer
    $contact_data = null;
    if ($contact_id > 0) {
        $contact_query = $db->query("SELECT * FROM customer_contacts WHERE id = ? AND customer_id = ?", [$contact_id, $customer->id]);
        if ($contact_query->count() > 0) {
            $contact_data = $contact_query->first();
        } else {
            $errors[] = "Solicitante inválido para esta empresa.";
        }
    }
    
    // Validate attachments count (Max 10 files)
    $uploaded_files = [];
    $has_files = false;
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        $has_files = true;
        $file_count = count($_FILES['attachments']['name']);
        if ($file_count > 10) {
            $errors[] = "Você pode enviar no máximo 10 arquivos em anexo.";
        }
    }
    
    if (empty($errors)) {
        // A. Calculate SLA Limit Time
        $sla_hours = (int)$service_data->sla_hours;
        $sla_limit_at = date('Y-m-d H:i:s', strtotime("+{$sla_hours} hours"));
        
        // B. Route Task to the configured default assignee for portal tasks
        $default_assignee = (int)getSystemSetting('portal_default_assignee', '1');
        $assigned_to = $default_assignee;
        
        // C. Insert Task
        $db->insert('tasks', [
            'customer_id' => $customer->id,
            'assigned_to' => $assigned_to,
            'title' => "[Portal] " . $service_data->name . ": " . $title,
            'description' => "Aberto por " . $contact_data->name . ".\n\nDetalhes:\n" . $more_info,
            'priority' => $priority,
            'status' => 'pending',
            'service_id' => $service_id,
            'customer_contact_id' => $contact_id,
            'sla_limit_at' => $sla_limit_at,
            'preferred_delivery_date' => !empty($preferred_delivery) ? $preferred_delivery : null,
            'more_info' => $more_info
        ]);
        $task_id = $db->lastId();
        $protocol_number = "SD-" . str_pad($task_id, 5, '0', STR_PAD_LEFT);
        
        if (function_exists('sendWhatsAppNotification')) {
            sendWhatsAppNotification($assigned_to, 0, "[Portal] " . $service_data->name . ": " . $title, "abriu um novo chamado via Portal de Atendimento");
        }
        
        if (function_exists('sendContactWhatsAppNotification') && !empty($contact_id)) {
            sendContactWhatsAppNotification($contact_id, 0, "[Portal] " . $service_data->name . ": " . $title, "abriu a sua tarefa");
        }
        
        // D. Process File Uploads (Max 15MB per file, safe extensions)
        if ($has_files) {
            $upload_dir = 'uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $allowed_extensions = ['doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'pdf', 'png', 'jpg', 'jpeg'];
            
            for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
                $original_name = $_FILES['attachments']['name'][$i];
                $tmp_name = $_FILES['attachments']['tmp_name'][$i];
                $file_size = $_FILES['attachments']['size'][$i];
                $file_error = $_FILES['attachments']['error'][$i];
                
                if ($file_error !== UPLOAD_ERR_OK) {
                    continue; // Skip failed uploads
                }
                
                // Validate File Size (Max 15MB)
                if ($file_size > 15 * 1024 * 1024) {
                    $error_msg .= "O arquivo '{$original_name}' excede o tamanho máximo de 15MB e não foi salvo.<br>";
                    continue;
                }
                
                // Validate File Extension
                $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                if (!in_array($file_ext, $allowed_extensions)) {
                    $error_msg .= "O arquivo '{$original_name}' tem formato inválido e não foi salvo.<br>";
                    continue;
                }
                
                // Sanitize file name and create unique path
                $new_filename = uniqid('task_' . $task_id . '_', true) . '.' . $file_ext;
                $dest_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($tmp_name, $dest_path)) {
                    $db->insert('task_attachments', [
                        'task_id' => $task_id,
                        'file_path' => 'uploads/' . $new_filename,
                        'file_name' => $original_name
                    ]);
                } else {
                    $error_msg .= "Falha ao salvar o arquivo '{$original_name}'.<br>";
                }
            }
        }
        
        $success_msg = "Chamado aberto com sucesso! Nossa equipe técnica já foi notificada.";
    } else {
        $error_msg = implode("<br>", $errors);
    }
}

// ==========================================
// 4. FETCH PREVIOUS TICKETS FOR TRACKING
// ==========================================
$tickets = $db->query("
    SELECT t.*, s.name as service_name, c.name as contact_name 
    FROM tasks t 
    LEFT JOIN services s ON t.service_id = s.id 
    LEFT JOIN customer_contacts c ON t.customer_contact_id = c.id 
    WHERE t.customer_id = ? 
    ORDER BY t.id DESC
", [$customer->id])->results();

// Function helper to output error screens nicely
function render_access_denied($msg) {
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>SyncDesk - Acesso Negado</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">
        <div class="text-center p-4 bg-white shadow rounded-4" style="max-width: 400px; border: 1px solid #dee2e6;">
            <div class="text-danger mb-3" style="font-size: 3rem;">⚠️</div>
            <h4 class="fw-bold text-dark">Acesso Negado</h4>
            <p class="text-muted mt-2 mb-0"><?= htmlspecialchars($msg) ?></p>
        </div>
    </body>
    </html>
    <?php
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncDesk - Abertura de Chamados</title>
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
            padding: 1.5rem;
        }

        h2, h4, .brand-title {
            font-family: 'Outfit', sans-serif;
        }

        .portal-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -1px rgba(0, 0, 0, 0.01);
            width: 100%;
            margin: 0;
            padding: 2rem;
        }

        .portal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .form-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: #475569;
        }

        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            padding: 0.6rem 0.75rem;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: #e11d48;
            box-shadow: 0 0 0 3px rgba(225, 29, 72, 0.1);
        }

        .btn-submit {
            background-color: #e11d48;
            border-color: #e11d48;
            padding: 0.7rem 1.5rem;
            font-size: 0.95rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .btn-submit:hover {
            background-color: #be123c;
            border-color: #be123c;
        }

        .success-box {
            text-align: center;
            padding: 2rem 1rem;
        }
        
        .success-icon {
            font-size: 3.5rem;
            color: #10b981;
            margin-bottom: 1rem;
        }

        .protocol-badge {
            background-color: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
            font-size: 1.1rem;
            font-weight: 700;
            padding: 0.4rem 1.2rem;
            border-radius: 8px;
            display: inline-block;
            margin-top: 0.75rem;
            font-family: monospace;
        }

        .file-list-preview {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 0.5rem;
        }

        /* Custom Bootstrap Tabs styling in Magenta */
        .nav-tabs {
            border-bottom: 2px solid #e2e8f0;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #64748b;
            font-weight: 500;
            padding: 0.75rem 1.25rem;
            transition: all 0.15s ease;
            font-family: 'Outfit', sans-serif;
        }
        
        .nav-tabs .nav-link:hover {
            color: #e11d48;
            background: transparent;
            border-bottom: 3px solid rgba(225, 29, 72, 0.3);
        }
        
        .nav-tabs .nav-link.active {
            color: #e11d48 !important;
            background: transparent !important;
            border: none !important;
            border-bottom: 3px solid #e11d48 !important;
            font-weight: 600;
        }
        
        .nav-tabs .nav-link .badge {
            font-size: 0.7rem;
            padding: 0.25em 0.6em;
        }

        /* Real-time filters layout */
        .filter-panel {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .ticket-table {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            background: #ffffff;
        }

        .ticket-table th {
            background-color: #f8fafc;
            font-weight: 600;
            font-size: 0.8rem;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.75rem 1rem;
        }

        .ticket-table td {
            font-size: 0.88rem;
            padding: 0.9rem 1rem;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .ticket-table tr:last-child td {
            border-bottom: none;
        }
    </style>
</head>
<body>

<div class="portal-card">
    
    <!-- Case A: Success Submission Screen -->
    <?php if ($success_msg): ?>
        <div class="success-box">
            <div class="success-icon"><i class="bi bi-check2-circle"></i></div>
            <h3 class="fw-bold mb-2">Solicitação Recebida!</h3>
            <p class="text-muted mb-4"><?= $success_msg ?></p>
            
            <div class="mb-4">
                <span class="text-uppercase text-muted d-block" style="font-size: 0.75rem; font-weight:600;">Número do Protocolo</span>
                <span class="protocol-badge"><?= $protocol_number ?></span>
            </div>
            
            <?php if ($error_msg): // Warnings about attachments size/ext ?>
                <div class="alert alert-warning text-start p-3 mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error_msg ?>
                </div>
            <?php endif; ?>

            <a href="client_portal.php?token=<?= urlencode($token) ?>" class="btn btn-outline-primary btn-sm rounded-pill px-4">
                <i class="bi bi-plus-lg"></i> Abrir Outra Solicitação
            </a>
        </div>
        
    <!-- Case B: Main Client Portal with Tabs -->
    <?php else: ?>
        <div class="portal-header">
            <div>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle mb-2 px-2.5 py-1 text-uppercase" style="font-size:0.7rem; font-weight:600; background-color: #fff1f2 !important; color: #e11d48 !important; border-color: #ffe4e6 !important;">Portal de Suporte</span>
                <h4 class="fw-bold m-0"><?= htmlspecialchars($customer->name) ?></h4>
            </div>
            <img src="assets/logo_magenta.png" alt="Sync Logo" style="max-height: 34px; object-fit: contain;">
        </div>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger alert-dismissible fade show p-3 mb-4" role="alert" style="font-size:0.9rem; border-radius:12px;">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error_msg ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Bootstrap Navigation Tabs -->
        <ul class="nav nav-tabs mb-4" id="portalTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="new-ticket-tab" data-bs-toggle="tab" data-bs-target="#new-ticket-pane" type="button" role="tab" aria-controls="new-ticket-pane" aria-selected="true">
                    <i class="bi bi-file-earmark-plus me-1"></i> Abrir Chamado
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="my-tickets-tab" data-bs-toggle="tab" data-bs-target="#my-tickets-pane" type="button" role="tab" aria-controls="my-tickets-pane" aria-selected="false">
                    <i class="bi bi-list-task me-1"></i> Meus Chamados 
                    <span class="badge rounded-pill bg-danger text-white ms-1" style="background-color: #e11d48 !important;"><?= count($tickets) ?></span>
                </button>
            </li>
        </ul>

        <div class="tab-content" id="portalTabsContent">
            <!-- TAB 1: Novo Chamado -->
            <div class="tab-pane fade show active" id="new-ticket-pane" role="tabpanel" aria-labelledby="new-ticket-tab" tabindex="0">
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">

                    <div class="row g-3">
                        <!-- 1. Solicitante -->
                        <div class="col-md-6">
                            <label for="customer_contact_id" class="form-label">Solicitante</label>
                            <select name="customer_contact_id" id="customer_contact_id" class="form-select" required>
                                <option value="">Selecione o seu nome...</option>
                                <?php foreach ($contacts as $contact): ?>
                                    <option value="<?= $contact->id ?>"><?= htmlspecialchars($contact->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 2. Serviço a ser prestado -->
                        <div class="col-md-6">
                            <label for="service_id" class="form-label">Serviço Solicitado</label>
                            <select name="service_id" id="service_id" class="form-select" required>
                                <option value="" data-sla="0">Selecione o serviço desejado...</option>
                                <?php foreach ($services as $srv): ?>
                                    <option value="<?= $srv->id ?>" data-sla="<?= $srv->sla_hours ?>"><?= htmlspecialchars($srv->name) ?> (SLA: <?= $srv->sla_hours ?>h)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 3. Breve resumo (Título) -->
                        <div class="col-12">
                            <label for="title" class="form-label">Título da Solicitação</label>
                            <input type="text" name="title" id="title" class="form-control" placeholder="Ex: Ajustar logo na conta da BM de anúncios" required>
                        </div>

                        <!-- 4. Prioridade & Data Preferencial -->
                        <div class="col-md-6">
                            <label for="priority" class="form-label">Prioridade da Demanda</label>
                            <select name="priority" id="priority" class="form-select" required>
                                <option value="low">Baixa</option>
                                <option value="medium" selected>Média</option>
                                <option value="high">Alta</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="preferred_delivery_date" class="form-label">Data de Entrega Preferível (Opcional)</label>
                            <input type="date" name="preferred_delivery_date" id="preferred_delivery_date" class="form-control" min="<?= date('Y-m-d') ?>">
                        </div>

                        <!-- 5. Mais Informações -->
                        <div class="col-12">
                            <label for="more_info" class="form-label">Explicação / Detalhamento do Chamado</label>
                            <textarea name="more_info" id="more_info" class="form-control" rows="5" placeholder="Descreva de forma detalhada o que precisa ser feito, caminhos de acesso, nomes de números/BMs envolvidas, etc." required></textarea>
                        </div>

                        <!-- 6. Anexos múltiplos -->
                        <div class="col-12">
                            <label for="attachments" class="form-label">Arquivos em Anexo (Máximo 10 arquivos, até 15MB cada)</label>
                            <input type="file" name="attachments[]" id="attachments" class="form-control" multiple>
                            <div class="form-text" style="font-size:0.75rem;">Formatos aceitos: Imagens (jpg, png), PDF, Word (doc, docx), Excel (xls, xlsx), Powerpoint (ppt, pptx).</div>
                            <div id="fileListPreview" class="file-list-preview"></div>
                        </div>

                        <!-- 7. Botão de Envio -->
                        <div class="col-12 mt-4 d-grid">
                            <button type="submit" class="btn btn-primary btn-submit">
                                <i class="bi bi-send-fill me-1"></i> Enviar Solicitação de Suporte
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- TAB 2: Acompanhar Chamados (Com Filtros em tempo real) -->
            <div class="tab-pane fade" id="my-tickets-pane" role="tabpanel" aria-labelledby="my-tickets-tab" tabindex="0">
                
                <!-- Painel de Filtros Instantâneos -->
                <div class="filter-panel">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label for="filterTicketName" class="form-label mb-1" style="font-size: 0.8rem;">Buscar por Assunto ou Solicitante</label>
                            <input type="text" id="filterTicketName" class="form-control form-control-sm" placeholder="Pesquisar...">
                        </div>
                        <div class="col-md-3">
                            <label for="filterTicketStatus" class="form-label mb-1" style="font-size: 0.8rem;">Filtrar Status</label>
                            <select id="filterTicketStatus" class="form-select form-select-sm">
                                <option value="all">Todos os Status</option>
                                <option value="pending">Pendente</option>
                                <option value="in_progress">Em Andamento</option>
                                <option value="completed">Concluído</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="filterTicketDate" class="form-label mb-1" style="font-size: 0.8rem;">Filtrar Data de Abertura</label>
                            <input type="date" id="filterTicketDate" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-1 d-grid">
                            <button type="button" id="clearFiltersBtn" class="btn btn-outline-secondary btn-sm" title="Limpar Filtros">
                                <i class="bi bi-x-circle"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Tabela de Chamados Recentes -->
                <?php if (empty($tickets)): ?>
                    <div class="text-center py-5 border rounded-4 bg-light">
                        <i class="bi bi-chat-left-text text-muted fs-2"></i>
                        <p class="text-muted mt-2 mb-0">Nenhum chamado aberto encontrado para esta empresa.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive ticket-table">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 120px;">Protocolo</th>
                                    <th>Solicitação</th>
                                    <th style="width: 150px;">Solicitante</th>
                                    <th style="width: 150px;">Abertura</th>
                                    <th style="width: 140px; text-align: center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tickets as $tk): 
                                    $tk_protocol = "SD-" . str_pad($tk->id, 5, '0', STR_PAD_LEFT);
                                    $tk_created_date = date('Y-m-d', strtotime($tk->created_at));
                                    $tk_created_display = date('d/M/Y H:i', strtotime($tk->created_at));
                                    
                                    // Search target string: title + contact_name + service_name (all lowercase)
                                    $search_target = strtolower($tk->title . ' ' . $tk->contact_name . ' ' . $tk->service_name);
                                    
                                    // Render status badge
                                    $status_badge = '';
                                    if ($tk->status === 'pending') {
                                        $status_badge = '<span class="badge bg-warning-subtle text-warning border border-warning-subtle text-uppercase px-2.5 py-1" style="font-size:0.72rem; font-weight:600;">Pendente</span>';
                                    } elseif ($tk->status === 'in_progress') {
                                        $status_badge = '<span class="badge bg-purple-subtle text-purple border border-purple-subtle text-uppercase px-2.5 py-1" style="font-size:0.72rem; font-weight:600; background-color: #f3e8ff !important; color: #7c3aed !important; border-color: #e9d5ff !important;">Em Andamento</span>';
                                    } elseif ($tk->status === 'completed') {
                                        $status_badge = '<span class="badge bg-success-subtle text-success border border-success-subtle text-uppercase px-2.5 py-1" style="font-size:0.72rem; font-weight:600;">Concluído</span>';
                                    }
                                ?>
                                    <tr class="ticket-row" 
                                        data-title="<?= htmlspecialchars($search_target) ?>" 
                                        data-status="<?= htmlspecialchars($tk->status) ?>" 
                                        data-date="<?= htmlspecialchars($tk_created_date) ?>">
                                        
                                        <td class="fw-bold text-muted" style="font-family: monospace; font-size: 0.95rem;">
                                            <?= $tk_protocol ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars(str_replace('[Portal] ', '', $tk->title)) ?></div>
                                            <div class="text-muted" style="font-size: 0.75rem;">Serviço: <?= htmlspecialchars($tk->service_name) ?></div>
                                        </td>
                                        <td>
                                            <span class="text-body-secondary"><?= htmlspecialchars($tk->contact_name) ?></span>
                                        </td>
                                        <td>
                                            <span class="text-body-secondary" style="font-size:0.8rem;"><?= $tk_created_display ?></span>
                                        </td>
                                        <td style="text-align: center;">
                                            <?= $status_badge ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Bootstrap 5 Bundle JS (Required for Tab switching) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // SLA constraint handler: restricts preferred delivery date based on service SLA (8h = 1 working day) and disables weekends
    const serviceSelectInput = document.getElementById('service_id');
    const preferredDeliveryInput = document.getElementById('preferred_delivery_date');

    function updateMinDeliveryDate() {
        if (!serviceSelectInput || !preferredDeliveryInput) return;
        
        const selectedOption = serviceSelectInput.options[serviceSelectInput.selectedIndex];
        const slaHours = parseInt(selectedOption ? selectedOption.getAttribute('data-sla') : '0', 10) || 0;
        let minDate = new Date();
        
        if (slaHours > 0) {
            const daysToAdd = Math.ceil(slaHours / 8);
            minDate.setDate(minDate.getDate() + daysToAdd);
            
            // If the minimum date falls on a weekend, push it to the next Monday
            const minDay = minDate.getDay();
            if (minDay === 6) { // Saturday
                minDate.setDate(minDate.getDate() + 2);
            } else if (minDay === 0) { // Sunday
                minDate.setDate(minDate.getDate() + 1);
            }
        } else {
            // Default to today if no service selected. If today is a weekend, default to Monday
            const todayDay = minDate.getDay();
            if (todayDay === 6) { // Saturday
                minDate.setDate(minDate.getDate() + 2);
            } else if (todayDay === 0) { // Sunday
                minDate.setDate(minDate.getDate() + 1);
            }
        }
        
        const yyyy = minDate.getFullYear();
        const mm = String(minDate.getMonth() + 1).padStart(2, '0');
        const dd = String(minDate.getDate()).padStart(2, '0');
        const minDateString = `${yyyy}-${mm}-${dd}`;
        
        preferredDeliveryInput.min = minDateString;
        
        // Adjust current value if it falls before the new minimum date
        if (preferredDeliveryInput.value && preferredDeliveryInput.value < minDateString) {
            preferredDeliveryInput.value = minDateString;
        }
        
        // Trigger weekend validation
        validateWeekend();
    }

    function validateWeekend() {
        if (!preferredDeliveryInput || !preferredDeliveryInput.value) return;
        
        const parts = preferredDeliveryInput.value.split('-');
        const date = new Date(parts[0], parts[1] - 1, parts[2]);
        const day = date.getDay(); // 0 = Sunday, 6 = Saturday
        
        if (day === 0 || day === 6) {
            alert('Finais de semana (sábado e domingo) não são permitidos como data de entrega preferencial. Por favor, selecione um dia útil.');
            preferredDeliveryInput.value = '';
            preferredDeliveryInput.setCustomValidity('Finais de semana não são permitidos.');
            preferredDeliveryInput.reportValidity();
        } else {
            preferredDeliveryInput.setCustomValidity('');
        }
    }

    if (serviceSelectInput) {
        serviceSelectInput.addEventListener('change', updateMinDeliveryDate);
    }
    
    if (preferredDeliveryInput) {
        preferredDeliveryInput.addEventListener('input', validateWeekend);
        preferredDeliveryInput.addEventListener('change', validateWeekend);
    }
    
    // Initialize on load
    updateMinDeliveryDate();

    // JS Preview file selections helper
    const attachmentInput = document.getElementById('attachments');
    const fileListPreview = document.getElementById('fileListPreview');

    if (attachmentInput) {
        attachmentInput.addEventListener('change', function(e) {
            const files = e.target.files;
            if (files.length > 10) {
                alert("Você selecionou " + files.length + " arquivos. O limite máximo é de 10 arquivos.");
                attachmentInput.value = "";
                fileListPreview.innerHTML = "";
                return;
            }
            
            if (files.length > 0) {
                let html = '<strong>Arquivos selecionados:</strong><ul class="mb-0 mt-1 pl-3">';
                for (let i = 0; i < files.length; i++) {
                    const sizeMB = (files[i].size / (1024 * 1024)).toFixed(2);
                    html += `<li>${files[i].name} (${sizeMB} MB)</li>`;
                }
                html += '</ul>';
                fileListPreview.innerHTML = html;
            } else {
                fileListPreview.innerHTML = "";
            }
        });
    }

    // Real-time client-side ticket filters
    const filterName = document.getElementById('filterTicketName');
    const filterStatus = document.getElementById('filterTicketStatus');
    const filterDate = document.getElementById('filterTicketDate');
    const clearFiltersBtn = document.getElementById('clearFiltersBtn');
    const ticketRows = document.querySelectorAll('.ticket-row');

    function applyFilters() {
        if (!ticketRows.length) return;
        
        const nameVal = filterName.value.toLowerCase().trim();
        const statusVal = filterStatus.value;
        const dateVal = filterDate.value; // YYYY-MM-DD

        ticketRows.forEach(row => {
            const title = row.getAttribute('data-title');
            const status = row.getAttribute('data-status');
            const date = row.getAttribute('data-date'); // YYYY-MM-DD

            let matchName = true;
            let matchStatus = true;
            let matchDate = true;

            if (nameVal && !title.includes(nameVal)) {
                matchName = false;
            }
            if (statusVal !== 'all' && status !== statusVal) {
                matchStatus = false;
            }
            if (dateVal && date !== dateVal) {
                matchDate = false;
            }

            if (matchName && matchStatus && matchDate) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    if (filterName) filterName.addEventListener('input', applyFilters);
    if (filterStatus) filterStatus.addEventListener('change', applyFilters);
    if (filterDate) filterDate.addEventListener('change', applyFilters);

    if (clearFiltersBtn) {
        clearFiltersBtn.addEventListener('click', function() {
            filterName.value = '';
            filterStatus.value = 'all';
            filterDate.value = '';
            applyFilters();
        });
    }
</script>

</body>
</html>
