<?php
// integrations.php - API Integration and Keys Management Hub
require_once 'users/init.php';

// Force strict administrator session check
if (!isset($user) || !$user->isLoggedIn() || !hasPerm([2], $user->data()->id)) {
    Redirect::to('index.php');
}

$db = DB::getInstance();
$error_msg = "";
$success_msg = "";

// ==========================================
// SELF-HEALING DATABASE STRUCTURE
// ==========================================
$db->query("CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(255) NOT NULL UNIQUE,
  `setting_value` TEXT DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

// Seed default settings if not exists
$checkOpenAi = $db->query("SELECT id FROM system_settings WHERE setting_key = 'openai_api_key'");
if ($checkOpenAi->count() == 0) {
    $db->insert('system_settings', [
        'setting_key' => 'openai_api_key',
        'setting_value' => '',
        'description' => 'Chave de API da OpenAI para processamento de tarefas em lote'
    ]);
}

$checkGemini = $db->query("SELECT id FROM system_settings WHERE setting_key = 'gemini_api_key'");
if ($checkGemini->count() == 0) {
    $db->insert('system_settings', [
        'setting_key' => 'gemini_api_key',
        'setting_value' => '',
        'description' => 'Chave de API do Gemini para processamento de tarefas em lote'
    ]);
}

// ==========================================
// POST ACTION HANDLER
// ==========================================
if (Input::exists()) {
    if (Token::check(Input::get('csrf'))) {
        $openai_key = trim(Input::get('openai_api_key'));

        $updateOpenAi = $db->query("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'openai_api_key'", [$openai_key]);

        if ($updateOpenAi) {
            $success_msg = "Configurações de chaves de API salvas com sucesso!";
        } else {
            $error_msg = "Falha ao atualizar as configurações no banco de dados.";
        }
    } else {
        $error_msg = "Erro: Validação CSRF falhou. Recarregue a página.";
    }
}

// Fetch current keys
$openai_current = "";

$openai_query = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'openai_api_key' LIMIT 1");
if ($openai_query->count() > 0) {
    $openai_current = $openai_query->first()->setting_value;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncDesk - Integrações e Chaves de API</title>
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
        }

        .btn-primary-axis {
            background-color: #e11d48;
            border-color: #e11d48;
            font-weight: 600;
            border-radius: 8px;
            font-size: 0.9rem;
            padding: 0.6rem 1.5rem;
            transition: all 0.2s ease;
        }

        .btn-primary-axis:hover {
            background-color: #be123c;
            border-color: #be123c;
        }

        .input-group-text-axis {
            background-color: #f8fafc;
            border-color: #cbd5e1;
            color: #64748b;
            cursor: pointer;
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

        .provider-badge {
            font-size: 0.75rem;
            padding: 0.3rem 0.6rem;
            border-radius: 6px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-openai {
            background-color: #10a37f;
            color: #ffffff;
        }

        .badge-gemini {
            background-color: #4285f4;
            color: #ffffff;
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

        <!-- Key Management Card -->
        <div class="row">
            <div class="col-lg-8">
                <div class="integration-card">
                    <h5 class="fw-bold mb-3 text-slate-800"><i class="bi bi-key me-2 text-danger"></i> Chaves de API de Inteligência Artificial</h5>
                    <p class="text-muted small mb-4">
                        Configure as credenciais das IAs utilizadas no sistema. O SyncDesk utiliza as chaves abaixo para realizar a identificação e criação de tarefas em lote (via texto ou gravação de áudio).
                    </p>

                    <form method="POST" action="">
                        <input type="hidden" name="csrf" value="<?= Token::generate() ?>">

                        <!-- OpenAI API Key -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label for="openai_api_key" class="form-label fw-semibold text-slate-700 m-0">OpenAI API Key</label>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text input-group-text-axis"><i class="bi bi-key"></i></span>
                                <input type="password" name="openai_api_key" id="openai_api_key" class="form-control form-control-axis" placeholder="sk-proj-..." value="<?= htmlspecialchars($openai_current) ?>">
                                <button type="button" class="btn btn-outline-secondary input-group-text-axis" onclick="toggleVisibility('openai_api_key', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text text-muted" style="font-size:0.75rem;">
                                Utilizado para transcrição de áudio com o Whisper e extração estruturada de tarefas com GPT-4o-mini.
                            </div>
                        </div>

                        <hr class="my-4 text-slate-200">

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-primary-axis">
                                <i class="bi bi-save me-1"></i> Salvar Integrações
                            </button>
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
    </script>
</body>
</html>
