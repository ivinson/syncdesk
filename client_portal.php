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
            
            // B. Route Task to Assigned Agent (from pivot) or fallback to Admin
            $agent_query = $db->query("SELECT user_id FROM customer_agent WHERE customer_id = ? LIMIT 1", [$customer->id]);
            $assigned_to = ($agent_query->count() > 0) ? (int)$agent_query->first()->user_id : 1; // 1 = Admin Fallback
            
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
                        // We skip file and show warning, but task was created
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
                    $safe_name = "task_" . $task_id . "_" . time() . "_" . rand(1000, 9999) . "." . $file_ext;
                    $target_path = $upload_dir . $safe_name;
                    
                    if (move_uploaded_file($tmp_name, $target_path)) {
                        // Register attachment in DB
                        $db->insert('task_attachments', [
                            'task_id' => $task_id,
                            'file_name' => $original_name,
                            'file_path' => $target_path
                        ]);
                    }
                }
            }
            
            $success_msg = "Sua solicitação foi enviada com sucesso ao nosso suporte!";
        } else {
            $error_msg = implode("<br>", $errors);
        }
}

// Helper to render access denied layout cleanly
function render_access_denied($message) {
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>SyncDesk - Portal de Chamados</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Inter', sans-serif; background-color: #f8fafc; height: 100vh; display: flex; align-items: center; justify-content: center; }
            .card-denied { background: white; border: 1px solid #fee2e2; border-radius: 16px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); padding: 2.5rem; text-align: center; max-width: 450px; }
            h2 { font-family: 'Outfit', sans-serif; font-weight: 700; color: #dc2626; }
        </style>
    </head>
    <body>
        <div class="card-denied">
            <div class="mb-3 text-danger"><i class="bi bi-shield-x" style="font-size: 3rem;"></i></div>
            <h2>Acesso Negado</h2>
            <p class="text-muted mt-3"><?= htmlspecialchars($message) ?></p>
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
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .btn-submit {
            background-color: #2563eb;
            border-color: #2563eb;
            padding: 0.7rem 1.5rem;
            font-size: 0.95rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .btn-submit:hover {
            background-color: #1d4ed8;
            border-color: #1d4ed8;
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
        
    <!-- Case B: Form Screen -->
    <?php else: ?>
        <div class="portal-header">
            <div>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle mb-2 px-2.5 py-1 text-uppercase" style="font-size:0.7rem; font-weight:600;">Portal de Suporte</span>
                <h4 class="fw-bold m-0"><?= htmlspecialchars($customer->name) ?></h4>
            </div>
            <i class="bi bi-cpu text-primary fs-3"></i>
        </div>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger alert-dismissible fade show p-3 mb-4" role="alert" style="font-size:0.9rem; border-radius:12px;">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error_msg ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= Token::generate() ?>">

            <div class="row g-3">
                <!-- 1. Solicitante / Quem está abrindo -->
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
                        <option value="">Selecione o serviço desejado...</option>
                        <?php foreach ($services as $srv): ?>
                            <option value="<?= $srv->id ?>"><?= htmlspecialchars($srv->name) ?> (SLA: <?= $srv->sla_hours ?>h)</option>
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

                <!-- 5. Mais Informações (textarea) -->
                <div class="col-12">
                    <label for="more_info" class="form-label">Explicação / Detalhamento do Chamado</label>
                    <textarea name="more_info" id="more_info" class="form-control" rows="5" placeholder="Descreva de forma detalhada o que precisa ser feito, caminhos de acesso, nomes de números/BMs envolvidas, etc." required></textarea>
                </div>

                <!-- 6. Anexos múltiplos (máximo 10 arquivos) -->
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
    <?php endif; ?>
</div>

<script>
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
</script>

</body>
</html>
