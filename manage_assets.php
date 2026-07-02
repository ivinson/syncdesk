<?php
// Initialize UserSpice security and environment
require_once 'users/init.php';

// Secure the page
if (!isset($bypass_secure_page) && !securePage(Server::get('PHP_SELF'))) {
    Redirect::to($us_url_root . 'users/login.php');
    exit;
}

$user_id = $user->data()->id;
$username = htmlspecialchars($user->data()->username);
$fname = htmlspecialchars($user->data()->fname);
$lname = htmlspecialchars($user->data()->lname);
$full_name = trim($fname . ' ' . $lname) ?: $username;

$is_admin = hasPerm([2], $user_id);
$role_title = $is_admin ? 'Administrador' : 'Atendente';

$db = DB::getInstance();
$error_msg = "";
$success_msg = "";

// Capture success message from redirect URL
if (Input::get('success')) {
    $success_msg = Input::get('success');
}

// Check if a specific customer was requested
$customer_id = (int)Input::get('customer_id');
$current_customer = null;

if ($customer_id > 0) {
    // Validate customer existance and access
    if ($is_admin) {
        $cust_query = $db->query("SELECT * FROM customers WHERE id = ?", [$customer_id]);
    } else {
        $cust_query = $db->query("
            SELECT c.* 
            FROM customers c 
            JOIN customer_agent ca ON c.id = ca.customer_id 
            WHERE c.id = ? AND ca.user_id = ? AND c.status = 1", [$customer_id, $user_id]);
    }
    
    if ($cust_query->count() > 0) {
        $current_customer = $cust_query->first();
    } else {
        // Access denied or not found, redirect to list
        Redirect::to('manage_assets.php?success=' . urlencode("Acesso negado ou cliente não encontrado."));
        exit;
    }
}

// ==========================================
// ASSET CRUD OPERATIONS (POST Requests)
// ==========================================
if (Input::exists()) {
    if (Token::check(Input::get('csrf'))) {
        $action = Input::get('action');
        
        // 1. ADD / CREATE ASSET
        if ($action === 'create_asset' && $current_customer) {
            $name = trim(Input::get('name'));
            $type = Input::get('type');
            $settings_post = Input::get('settings'); // Associative array from dynamic inputs
            
            $errors = [];
            if (empty($name)) $errors[] = "O nome do ativo é obrigatório.";
            if (!in_array($type, ['meta_bm', 'n8n_workflow', 'ia_instance', 'other'])) $errors[] = "Tipo de ativo inválido.";
            
            if (empty($errors)) {
                // Structure JSON settings
                $settings_data = [];
                if (is_array($settings_post)) {
                    foreach ($settings_post as $key => $value) {
                        if ($key === 'ad_accounts') {
                            $settings_data[$key] = array_map('trim', explode(',', $value));
                        } else if ($key === 'active') {
                            $settings_data[$key] = $value === 'true' || $value === '1';
                        } else {
                            $settings_data[$key] = trim($value);
                        }
                    }
                }
                $settings_json = json_encode($settings_data);
                
                $db->insert('assets', [
                    'customer_id' => $current_customer->id,
                    'name' => $name,
                    'type' => $type,
                    'settings' => $settings_json
                ]);
                
                Redirect::to("manage_assets.php?customer_id={$current_customer->id}&success=" . urlencode("Ativo '{$name}' cadastrado com sucesso!"));
            } else {
                $error_msg = implode("<br>", $errors);
            }
        }
        
        // 2. EDIT / UPDATE ASSET
        else if ($action === 'edit_asset' && $current_customer) {
            $asset_id = (int)Input::get('asset_id');
            $name = trim(Input::get('name'));
            $type = Input::get('type');
            $settings_post = Input::get('settings');
            
            $errors = [];
            if (empty($name)) $errors[] = "O nome do ativo é obrigatório.";
            if (!in_array($type, ['meta_bm', 'n8n_workflow', 'ia_instance', 'other'])) $errors[] = "Tipo de ativo inválido.";
            
            // Check authorization to edit this asset
            $asset_query = $db->query("SELECT * FROM assets WHERE id = ? AND customer_id = ?", [$asset_id, $current_customer->id]);
            if ($asset_query->count() > 0) {
                if (empty($errors)) {
                    // Structure JSON settings
                    $settings_data = [];
                    if (is_array($settings_post)) {
                        foreach ($settings_post as $key => $value) {
                            if ($key === 'ad_accounts') {
                                $settings_data[$key] = array_map('trim', explode(',', $value));
                            } else if ($key === 'active') {
                                $settings_data[$key] = $value === 'true' || $value === '1';
                            } else {
                                $settings_data[$key] = trim($value);
                            }
                        }
                    }
                    $settings_json = json_encode($settings_data);
                    
                    $db->query("UPDATE assets SET name = ?, type = ?, settings = ? WHERE id = ?", [
                        $name, $type, $settings_json, $asset_id
                    ]);
                    
                    Redirect::to("manage_assets.php?customer_id={$current_customer->id}&success=" . urlencode("Ativo #{$asset_id} atualizado com sucesso!"));
                } else {
                    $error_msg = implode("<br>", $errors);
                }
            } else {
                $error_msg = "Ativo não encontrado ou não pertence a este cliente.";
            }
        }
        
        // 3. DELETE ASSET
        else if ($action === 'delete_asset' && $current_customer) {
            $asset_id = (int)Input::get('asset_id');
            
            $asset_query = $db->query("SELECT * FROM assets WHERE id = ? AND customer_id = ?", [$asset_id, $current_customer->id]);
            if ($asset_query->count() > 0) {
                $db->query("DELETE FROM assets WHERE id = ?", [$asset_id]);
                Redirect::to("manage_assets.php?customer_id={$current_customer->id}&success=" . urlencode("Ativo excluído com sucesso!"));
            } else {
                $error_msg = "Ativo não encontrado ou permissão negada.";
            }
        }
    } else {
        $error_msg = "Erro: Validação de token CSRF falhou.";
    }
}

// ==========================================
// DATA RETRIEVAL
// ==========================================

if ($current_customer) {
    // Fetch assets for specific customer
    $assets = $db->query("SELECT * FROM assets WHERE customer_id = ? ORDER BY type ASC, name ASC", [$current_customer->id])->results();
} else {
    // Fetch customers list with asset counts (Multitenant)
    if ($is_admin) {
        $customers_sql = "
            SELECT c.*, COUNT(a.id) as total_assets 
            FROM customers c 
            LEFT JOIN assets a ON c.id = a.customer_id 
            WHERE c.status = 1 
            GROUP BY c.id 
            ORDER BY c.name ASC";
        $customers = $db->query($customers_sql)->results();
    } else {
        $customers_sql = "
            SELECT c.*, COUNT(a.id) as total_assets 
            FROM customers c 
            JOIN customer_agent ca ON c.id = ca.customer_id 
            LEFT JOIN assets a ON c.id = a.customer_id 
            WHERE ca.user_id = ? AND c.status = 1 
            GROUP BY c.id 
            ORDER BY c.name ASC";
        $customers = $db->query($customers_sql, [$user_id])->results();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncDesk - Gestão de Ativos</title>
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

        h1, h2, h3, h4, .brand-title {
            font-family: 'Outfit', sans-serif;
        }

        .wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styling */
        .sidebar {
            width: 260px;
            background-color: var(--sb-bg);
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            padding: 1.5rem 1rem;
            color: var(--sb-text);
            transition: all 0.3s ease;
            box-shadow: 4px 0 10px rgba(0,0,0,0.05);
        }

        .brand-section {
            padding: 0.5rem 0.75rem 2rem 0.75rem;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            margin-bottom: 1.5rem;
        }

        .brand-logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .tenant-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 0.75rem;
            margin-top: 1rem;
            font-size: 0.85rem;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
            flex-grow: 1;
        }

        .menu-item {
            margin-bottom: 0.4rem;
        }

        .menu-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0.75rem 1rem;
            color: var(--sb-text);
            text-decoration: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .menu-link:hover {
            background: rgba(255, 255, 255, 0.05);
            color: #ffffff;
        }

        .menu-link.active {
            background-color: var(--sb-active-bg);
            color: var(--sb-active-text);
            font-weight: 600;
        }

        .menu-link i {
            font-size: 1.15rem;
        }

        .sidebar-footer {
            border-top: 1px solid rgba(255,255,255,0.05);
            padding-top: 1.25rem;
            margin-top: auto;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .profile-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #3b82f6;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
        }

        .profile-info {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .profile-name {
            color: #ffffff;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .profile-role {
            font-size: 0.75rem;
            color: var(--sb-text);
        }

        /* Main Content Styling */
        .main-content {
            flex-grow: 1;
            padding: 2rem 2.5rem;
            overflow-y: auto;
            max-height: 100vh;
        }

        /* Cards and Components */
        .premium-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: transform 0.2s, box-shadow 0.2s;
            height: 100%;
        }

        .customer-card {
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            padding: 1.5rem;
        }

        .customer-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            border-color: #cbd5e1;
        }

        .asset-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .asset-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 0.75rem;
        }

        .asset-type-badge {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            text-transform: uppercase;
        }

        .type-meta_bm { background-color: #eff6ff; color: #2563eb; }
        .type-n8n_workflow { background-color: #fdf2f8; color: #db2777; }
        .type-ia_instance { background-color: #faf5ff; color: #9333ea; }
        .type-other { background-color: #f8fafc; color: #475569; }

        .settings-table {
            font-size: 0.85rem;
            margin: 0;
            background: #f8fafc;
            border-radius: 8px;
        }

        .settings-table td {
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .settings-table tr:last-child td {
            border-bottom: none;
        }

        .custom-alert {
            border-radius: 12px;
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <!-- Sidebar Navigation -->
    <?php require_once 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <main class="main-content">
        
        <!-- Alerts Block -->
        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show custom-alert p-3 mb-4" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($success_msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger alert-dismissible fade show custom-alert p-3 mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error_msg ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- VIEW SCENARIO A: ASSETS LIST FOR A SINGLE CUSTOMER -->
        <?php if ($current_customer): ?>
            <!-- Back Button and Header -->
            <div class="d-flex justify-content-between align-items-end mb-4">
                <div>
                    <a href="manage_assets.php" class="btn btn-link p-0 text-decoration-none text-muted mb-2 d-inline-flex align-items-center gap-1">
                        <i class="bi bi-arrow-left"></i> Voltar para Clientes
                    </a>
                    <h2 class="mb-1 fw-bold">Ativos de <?= htmlspecialchars($current_customer->name) ?></h2>
                    <p class="text-muted mb-0"><?= htmlspecialchars($current_customer->company_name) ?></p>
                </div>
                <button class="btn btn-primary btn-sm px-3 rounded-3 d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#createAssetModal" style="font-weight: 500;">
                    <i class="bi bi-plus-lg"></i> Novo Ativo
                </button>
            </div>

            <!-- Assets Cards Grid -->
            <div class="row g-3">
                <div class="col-12">
                    <?php if (empty($assets)): ?>
                        <div class="text-center py-5 text-muted bg-white rounded-4 border">
                            <i class="bi bi-hdd-rack fs-1 d-block mb-3 text-secondary"></i>
                            Nenhum ativo cadastrado para este cliente.
                            <button class="btn btn-outline-primary btn-sm d-block mx-auto mt-3 rounded-3" data-bs-toggle="modal" data-bs-target="#createAssetModal">
                                Adicionar Primeiro Ativo
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($assets as $asset): 
                            $type_label = $asset->type;
                            $type_class = 'type-' . $asset->type;
                            if ($asset->type === 'meta_bm') $type_label = 'Meta Business Manager';
                            if ($asset->type === 'n8n_workflow') $type_label = 'Fluxo n8n';
                            if ($asset->type === 'ia_instance') $type_label = 'Instância de IA';
                            if ($asset->type === 'other') $type_label = 'Outros Acessos';
                            
                            $settings_arr = json_decode($asset->settings, true) ?: [];
                        ?>
                            <div class="asset-card">
                                <div class="asset-card-header">
                                    <div class="d-flex align-items-center gap-2">
                                        <h5 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($asset->name) ?></h5>
                                        <span class="asset-type-badge <?= $type_class ?>"><?= $type_label ?></span>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-outline-secondary btn-sm rounded-3 py-1 edit-asset-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editAssetModal"
                                                data-asset-id="<?= $asset->id ?>"
                                                data-asset-name="<?= htmlspecialchars($asset->name) ?>"
                                                data-asset-type="<?= $asset->type ?>"
                                                data-asset-settings='<?= htmlspecialchars($asset->settings, ENT_QUOTES, 'UTF-8') ?>'>
                                            <i class="bi bi-pencil me-1"></i> Editar
                                        </button>
                                        <button class="btn btn-outline-danger btn-sm rounded-3 py-1 delete-asset-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteAssetModal"
                                                data-asset-id="<?= $asset->id ?>"
                                                data-asset-name="<?= htmlspecialchars($asset->name) ?>">
                                            <i class="bi bi-trash me-1"></i> Excluir
                                        </button>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table settings-table table-sm">
                                        <tbody>
                                            <?php if (empty($settings_arr)): ?>
                                                <tr>
                                                    <td class="text-muted">Nenhuma configuração registrada para este ativo.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($settings_arr as $key => $val): 
                                                    $formatted_key = str_replace('_', ' ', $key);
                                                    $rendered_val = $val;
                                                    
                                                    if (is_array($val)) {
                                                        $rendered_val = implode(', ', $val);
                                                    } else if (is_bool($val)) {
                                                        $rendered_val = $val ? '<span class="text-success fw-semibold">Ativo</span>' : '<span class="text-danger fw-semibold">Inativo</span>';
                                                    } else if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) {
                                                        $rendered_val = "<a href='{$val}' target='_blank' class='text-primary text-break'>{$val} <i class='bi bi-box-arrow-up-right' style='font-size:0.7rem;'></i></a>";
                                                    }
                                                ?>
                                                    <tr>
                                                        <td width="200" class="fw-semibold text-muted text-capitalize"><?= htmlspecialchars($formatted_key) ?>:</td>
                                                        <td><?= $rendered_val ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        <!-- VIEW SCENARIO B: CLIENTS LIST FOR ASSET MANAGEMENT -->
        <?php else: ?>
            <div class="mb-4">
                <h2 class="mb-1 fw-bold">Gerenciamento de Ativos</h2>
                <p class="text-muted mb-0">Selecione o cliente para visualizar e gerenciar suas credenciais, webhooks e instâncias.</p>
            </div>

            <div class="row g-3">
                <?php if (empty($customers)): ?>
                    <div class="col-12">
                        <div class="text-center py-5 text-muted bg-white rounded-4 border">
                            <i class="bi bi-people fs-1 d-block mb-2 text-secondary"></i>
                            Nenhum cliente associado ou disponível.
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($customers as $cust): ?>
                        <div class="col-md-4 col-sm-6 col-12">
                            <a href="manage_assets.php?customer_id=<?= $cust->id ?>" class="premium-card customer-card">
                                <h5 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($cust->name) ?></h5>
                                <p class="text-muted small mb-3"><?= htmlspecialchars($cust->company_name) ?></p>
                                <div class="mt-auto d-flex justify-content-between align-items-center">
                                    <span class="badge bg-light text-secondary border rounded-pill">
                                        <i class="bi bi-hdd-network-fill me-1"></i><?= $cust->total_assets ?> Ativos
                                    </span>
                                    <span class="text-primary fw-semibold small d-inline-flex align-items-center gap-1">
                                        Gerenciar <i class="bi bi-chevron-right"></i>
                                    </span>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php if ($current_customer): ?>
<!-- ==========================================
      MODAL: CREATE ASSET (Bootstrap 5)
     ========================================== -->
<div class="modal fade" id="createAssetModal" tabindex="-1" aria-labelledby="createAssetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                <input type="hidden" name="action" value="create_asset">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="createAssetModalLabel"><i class="bi bi-plus-circle text-primary me-2"></i>Novo Ativo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-white rounded-bottom-4">
                    <div class="mb-3">
                        <label for="create_name" class="form-label fw-semibold" style="font-size:0.9rem;">Nome do Ativo</label>
                        <input type="text" class="form-control rounded-3" id="create_name" name="name" required placeholder="Ex: BM Operacional / Assistente Hubspot">
                    </div>
                    <div class="mb-3">
                        <label for="create_type" class="form-label fw-semibold" style="font-size:0.9rem;">Tipo de Ativo</label>
                        <select class="form-select rounded-3" id="create_type" name="type" required>
                            <option value="" disabled selected>Selecionar tipo...</option>
                            <option value="meta_bm">Meta Business Manager</option>
                            <option value="n8n_workflow">Fluxo n8n</option>
                            <option value="ia_instance">Instância de IA</option>
                            <option value="other">Outros Acessos (Notas/API)</option>
                        </select>
                    </div>
                    
                    <!-- Dynamic Settings Inputs Container -->
                    <div id="create_settings_container" class="mt-3 p-3 bg-light rounded-3 border d-none">
                        <h6 class="fw-bold mb-3 border-bottom pb-2 text-secondary" style="font-size:0.85rem;">Configurações Específicas</h6>
                        <div id="create_dynamic_fields"></div>
                    </div>
                    
                    <div class="d-flex gap-2 justify-content-end mt-4">
                        <button type="button" class="btn btn-light rounded-3 px-4" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary rounded-3 px-4">Salvar Ativo</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
      MODAL: EDIT ASSET (Bootstrap 5)
     ========================================== -->
<div class="modal fade" id="editAssetModal" tabindex="-1" aria-labelledby="editAssetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                <input type="hidden" name="action" value="edit_asset">
                <input type="hidden" name="asset_id" id="edit_asset_id">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="editAssetModalLabel"><i class="bi bi-pencil-square text-primary me-2"></i>Editar Ativo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-white rounded-bottom-4">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label fw-semibold" style="font-size:0.9rem;">Nome do Ativo</label>
                        <input type="text" class="form-control rounded-3" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_type" class="form-label fw-semibold" style="font-size:0.9rem;">Tipo de Ativo</label>
                        <select class="form-select rounded-3" id="edit_type" name="type" required>
                            <option value="meta_bm">Meta Business Manager</option>
                            <option value="n8n_workflow">Fluxo n8n</option>
                            <option value="ia_instance">Instância de IA</option>
                            <option value="other">Outros Acessos (Notas/API)</option>
                        </select>
                    </div>
                    
                    <!-- Dynamic Settings Inputs Container -->
                    <div id="edit_settings_container" class="mt-3 p-3 bg-light rounded-3 border d-none">
                        <h6 class="fw-bold mb-3 border-bottom pb-2 text-secondary" style="font-size:0.85rem;">Configurações Específicas</h6>
                        <div id="edit_dynamic_fields"></div>
                    </div>
                    
                    <div class="d-flex gap-2 justify-content-end mt-4">
                        <button type="button" class="btn btn-light rounded-3 px-4" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary rounded-3 px-4">Salvar Alterações</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
      MODAL: DELETE ASSET CONFIRMATION
     ========================================== -->
<div class="modal fade" id="deleteAssetModal" tabindex="-1" aria-labelledby="deleteAssetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                <input type="hidden" name="action" value="delete_asset">
                <input type="hidden" name="asset_id" id="delete_asset_id">
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger" id="deleteAssetModalLabel"><i class="bi bi-exclamation-triangle me-2"></i>Excluir Ativo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-white rounded-bottom-4 text-center">
                    <p class="mb-3">Tem certeza que deseja excluir permanentemente o ativo:</p>
                    <p class="fw-bold mb-4 text-dark" id="delete_asset_name"></p>
                    
                    <div class="d-flex gap-2 justify-content-center">
                        <button type="button" class="btn btn-light rounded-3 px-3" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger rounded-3 px-3">Sim, Excluir</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Bootstrap 5 Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Dynamic Asset Fields and Modals Logic -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const createTypeSelect = document.getElementById('create_type');
        const editTypeSelect = document.getElementById('edit_type');
        
        // Settings definitions for dynamic fields
        const fieldTemplates = {
            meta_bm: `
                <div class="mb-2">
                    <label class="form-label small fw-semibold">ID do Business Manager (BM ID)</label>
                    <input type="text" class="form-control form-control-sm rounded-2" name="settings[bm_id]" required placeholder="Ex: 120938102938">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Nome do Business Manager</label>
                    <input type="text" class="form-control form-control-sm rounded-2" name="settings[business_manager]" placeholder="Ex: Ads Manager Principal">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Contas de Anúncio (separadas por vírgula)</label>
                    <input type="text" class="form-control form-control-sm rounded-2" name="settings[ad_accounts]" placeholder="Ex: Conta Anuncios 1, Conta Anuncios 2">
                </div>
            `,
            n8n_workflow: `
                <div class="mb-2">
                    <label class="form-label small fw-semibold">URL do Webhook</label>
                    <input type="url" class="form-control form-control-sm rounded-2" name="settings[webhook_url]" required placeholder="https://n8n.exemplo.com/webhook/...">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Gatilho do Fluxo / Trigger</label>
                    <input type="text" class="form-control form-control-sm rounded-2" name="settings[trigger]" placeholder="Ex: Webhook Entrada Lead">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Status do Fluxo</label>
                    <select class="form-select form-select-sm rounded-2" name="settings[active]">
                        <option value="true" selected>Ativo / Ligado</option>
                        <option value="false">Inativo / Desligado</option>
                    </select>
                </div>
            `,
            ia_instance: `
                <div class="mb-2">
                    <label class="form-label small fw-semibold">ID do Assistente (Agent/Assistant ID)</label>
                    <input type="text" class="form-control form-control-sm rounded-2" name="settings[agent_id]" required placeholder="Ex: asst_982398">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Modelo de Linguagem (LLM)</label>
                    <input type="text" class="form-control form-control-sm rounded-2" name="settings[model]" placeholder="Ex: gpt-4o / gemini-1.5-pro">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Número de Telefone da Instância</label>
                    <input type="text" class="form-control form-control-sm rounded-2" name="settings[phone]" placeholder="Ex: +5511999999999">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Status do Agente</label>
                    <input type="text" class="form-control form-control-sm rounded-2" name="settings[status]" placeholder="Ex: active / maintenance">
                </div>
            `,
            other: `
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Notas de Acesso / API Keys</label>
                    <textarea class="form-control form-control-sm rounded-2" name="settings[notes]" rows="4" placeholder="Adicione credenciais, URLs ou anotações extras..."></textarea>
                </div>
            `
        };

        // Render dynamic fields based on selected type
        function renderFields(type, containerId, fieldsContainerId) {
            const container = document.getElementById(containerId);
            const fieldsContainer = document.getElementById(fieldsContainerId);
            
            if (type && fieldTemplates[type]) {
                fieldsContainer.innerHTML = fieldTemplates[type];
                container.classList.remove('d-none');
            } else {
                fieldsContainer.innerHTML = '';
                container.classList.add('d-none');
            }
        }

        // Listener for Create Asset Select change
        if (createTypeSelect) {
            createTypeSelect.addEventListener('change', function() {
                renderFields(this.value, 'create_settings_container', 'create_dynamic_fields');
            });
        }

        // Listener for Edit Asset Select change
        if (editTypeSelect) {
            editTypeSelect.addEventListener('change', function() {
                renderFields(this.value, 'edit_settings_container', 'edit_dynamic_fields');
            });
        }
        
        // POPULATE EDIT MODAL VIA DATA ATTRIBUTES
        const editAssetModal = document.getElementById('editAssetModal');
        if (editAssetModal) {
            editAssetModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-asset-id');
                const name = button.getAttribute('data-asset-name');
                const type = button.getAttribute('data-asset-type');
                const settingsData = JSON.parse(button.getAttribute('data-asset-settings') || '{}');
                
                document.getElementById('edit_asset_id').value = id;
                document.getElementById('edit_name').value = name;
                
                // Select type and render dynamic fields
                editTypeSelect.value = type;
                renderFields(type, 'edit_settings_container', 'edit_dynamic_fields');
                
                // Populate dynamic fields with values
                setTimeout(() => {
                    const fields = editAssetModal.querySelectorAll('[name^="settings["]');
                    fields.forEach(field => {
                        // Extract key name from settings[key]
                        const match = field.name.match(/settings\[(.*?)\]/);
                        if (match && match[1]) {
                            const key = match[1];
                            let value = settingsData[key];
                            
                            if (value !== undefined) {
                                if (key === 'ad_accounts' && Array.isArray(value)) {
                                    field.value = value.join(', ');
                                } else if (key === 'active') {
                                    field.value = value ? 'true' : 'false';
                                } else {
                                    field.value = value;
                                }
                            }
                        }
                    });
                }, 50); // Small timeout to ensure inputs are rendered in DOM
            });
        }
        
        // POPULATE DELETE MODAL
        const deleteAssetModal = document.getElementById('deleteAssetModal');
        if (deleteAssetModal) {
            deleteAssetModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-asset-id');
                const name = button.getAttribute('data-asset-name');
                
                document.getElementById('delete_asset_id').value = id;
                document.getElementById('delete_asset_name').textContent = name;
            });
        }
    });
</script>
</body>
</html>
