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

// Capture success message from URL
if (Input::get('success')) {
    $success_msg = Input::get('success');
}
if (Input::get('error')) {
    $error_msg = Input::get('error');
}

// Auto-create customer_media table if not exists
$db->query("CREATE TABLE IF NOT EXISTS `customer_media` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_size` BIGINT NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_type` ENUM('image', 'video', 'document', 'audio', 'other') NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_cm_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

// Fetch accessible customers list
if ($is_admin) {
    $customers_query = $db->query("SELECT * FROM customers WHERE status = 1 ORDER BY name ASC");
} else {
    $customers_query = $db->query("
        SELECT c.* 
        FROM customers c 
        JOIN customer_agent ca ON c.id = ca.customer_id 
        WHERE ca.user_id = ? AND c.status = 1 
        ORDER BY c.name ASC", [$user_id]);
}

$accessible_customers = $customers_query->results();

// Determine current selected customer
$customer_id = (int)Input::get('customer_id');
$current_customer = null;

if ($customer_id > 0) {
    foreach ($accessible_customers as $cust) {
        if ($cust->id == $customer_id) {
            $current_customer = $cust;
            break;
        }
    }
}

// Default to first accessible customer if none selected or invalid ID
if (!$current_customer && !empty($accessible_customers)) {
    $current_customer = $accessible_customers[0];
}

// Base URL calculation for absolute public links
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$script_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$site_base_url = $protocol . '://' . $host . ($script_dir === '' || $script_dir === '/' ? '' : $script_dir) . '/';


// Helper function to classify file types
function get_media_category($ext, $mime) {
    $ext = strtolower($ext);
    $images = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];
    $videos = ['mp4', 'webm', 'mov', 'avi', 'mkv', '3gp', 'm4v', 'wmv'];
    $audio  = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma'];
    $docs   = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf', 'zip', 'rar', '7z'];

    if (in_array($ext, $images) || strpos($mime, 'image/') === 0) return 'image';
    if (in_array($ext, $videos) || strpos($mime, 'video/') === 0) return 'video';
    if (in_array($ext, $audio) || strpos($mime, 'audio/') === 0)  return 'audio';
    if (in_array($ext, $docs)) return 'document';

    return 'other';
}

// Helper function to format file size
function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}


// ==========================================
// POST ACTIONS (Upload / Delete)
// ==========================================
if (Input::exists()) {
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || Input::get('ajax') == 1;

    if (Token::check(Input::get('csrf'))) {
        $action = Input::get('action');

        // 1. UPLOAD MEDIA
        if ($action === 'upload_media' && $current_customer) {
            if (!empty($_FILES['media_files']['name'][0])) {
                $target_dir = __DIR__ . "/uploads/media/{$current_customer->id}/";
                if (!is_dir($target_dir)) {
                    @mkdir($target_dir, 0755, true);
                }

                $uploaded_count = 0;
                $upload_errors = [];

                $files = $_FILES['media_files'];
                $total_files = count($files['name']);

                for ($i = 0; $i < $total_files; $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $orig_name = $files['name'][$i];
                        $tmp_name  = $files['tmp_name'][$i];
                        $file_size = $files['size'][$i];
                        $mime_type = $files['type'][$i] ?: mime_content_type($tmp_name);
                        $ext       = pathinfo($orig_name, PATHINFO_EXTENSION);

                        // File type classification
                        $file_category = get_media_category($ext, $mime_type);

                        // Clean original filename for safe storage
                        $clean_name = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($orig_name, PATHINFO_FILENAME));
                        if (empty($clean_name)) {
                            $clean_name = 'file';
                        }

                        $unique_filename = "cust_{$current_customer->id}_" . uniqid() . '_' . $clean_name . ($ext ? '.' . strtolower($ext) : '');
                        $target_path = $target_dir . $unique_filename;
                        $relative_path = "uploads/media/{$current_customer->id}/" . $unique_filename;

                        if (move_uploaded_file($tmp_name, $target_path)) {
                            $db->insert('customer_media', [
                                'customer_id'   => $current_customer->id,
                                'user_id'       => $user_id,
                                'original_name' => $orig_name,
                                'file_name'     => $unique_filename,
                                'file_path'     => $relative_path,
                                'file_size'     => $file_size,
                                'mime_type'     => $mime_type,
                                'file_type'     => $file_category
                            ]);
                            $uploaded_count++;
                        } else {
                            $upload_errors[] = "Erro ao salvar arquivo: {$orig_name}";
                        }
                    } else if ($files['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                        $upload_errors[] = "Falha ao enviar arquivo '{$files['name'][$i]}' (Código do erro: {$files['error'][$i]}).";
                    }
                }

                if ($is_ajax) {
                    header('Content-Type: application/json');
                    if ($uploaded_count > 0) {
                        echo json_encode([
                            'success' => true,
                            'message' => "{$uploaded_count} mídia(s) enviada(s) com sucesso!"
                        ]);
                    } else {
                        echo json_encode([
                            'success' => false,
                            'message' => implode(' | ', $upload_errors) ?: "Nenhum arquivo pôde ser enviado."
                        ]);
                    }
                    exit;
                }

                if ($uploaded_count > 0) {
                    $msg = "{$uploaded_count} arquivo(s) enviado(s) com sucesso!";
                    if (!empty($upload_errors)) {
                        $msg .= " Avisos: " . implode(' | ', $upload_errors);
                    }
                    Redirect::to("manage_media.php?customer_id={$current_customer->id}&success=" . urlencode($msg));
                } else {
                    $error_msg = implode("<br>", $upload_errors) ?: "Nenhum arquivo enviado.";
                }
            } else {
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Nenhum arquivo selecionado.']);
                    exit;
                }
                $error_msg = "Por favor, selecione ao menos um arquivo para enviar.";
            }
        }

        // 2. DELETE MEDIA
        else if ($action === 'delete_media' && $current_customer) {
            $media_id = (int)Input::get('media_id');
            $media_query = $db->query("SELECT * FROM customer_media WHERE id = ? AND customer_id = ?", [$media_id, $current_customer->id]);

            if ($media_query->count() > 0) {
                $media_item = $media_query->first();
                $full_file_path = __DIR__ . '/' . $media_item->file_path;

                if (file_exists($full_file_path)) {
                    @unlink($full_file_path);
                }

                $db->query("DELETE FROM customer_media WHERE id = ?", [$media_id]);

                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Mídia excluída com sucesso.']);
                    exit;
                }

                Redirect::to("manage_media.php?customer_id={$current_customer->id}&success=" . urlencode("Mídia excluída com sucesso!"));
            } else {
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Mídia não encontrada ou permissão negada.']);
                    exit;
                }
                $error_msg = "Mídia não encontrada ou você não tem permissão para excluí-la.";
            }
        }
    } else {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Token de segurança inválido. Recarregue a página.']);
            exit;
        }
        $error_msg = "Token de validação inválido. Tente novamente.";
    }
}


// Fetch Media items for current customer
$media_list = [];
$media_counts = [
    'all'      => 0,
    'image'    => 0,
    'video'    => 0,
    'document' => 0,
    'audio'    => 0,
    'other'    => 0
];

if ($current_customer) {
    $media_query = $db->query("
        SELECT cm.*, u.username, u.fname, u.lname 
        FROM customer_media cm 
        LEFT JOIN users u ON cm.user_id = u.id 
        WHERE cm.customer_id = ? 
        ORDER BY cm.created_at DESC", [$current_customer->id]);

    $media_list = $media_query->results();

    foreach ($media_list as $m) {
        $media_counts['all']++;
        if (isset($media_counts[$m->file_type])) {
            $media_counts[$m->file_type]++;
        } else {
            $media_counts['other']++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mídias do Cliente - SyncDesk</title>

    <!-- Google Fonts & Bootstrap 5 CSS -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">

    <style>
        :root {
            --primary-color: #e11d48;
            --primary-hover: #be123c;
            --bg-body: #f8fafc;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: #1e293b;
        }

        .wrapper {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            padding: 2rem;
            background-color: var(--bg-body);
            min-width: 0;
        }

        /* Upload Drag & Drop Dropzone */
        .dropzone-area {
            border: 2px dashed #cbd5e1;
            background: #ffffff;
            border-radius: 16px;
            padding: 2.5rem 1.5rem;
            text-align: center;
            transition: all 0.25s ease;
            cursor: pointer;
            position: relative;
        }

        .dropzone-area:hover, .dropzone-area.dragover {
            border-color: var(--primary-color);
            background-color: #fff1f2;
            transform: translateY(-2px);
        }

        .dropzone-icon {
            font-size: 2.75rem;
            color: var(--primary-color);
            margin-bottom: 0.75rem;
        }

        /* Media Card styling */
        .media-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            overflow: hidden;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .media-card:hover {
            box-shadow: 0 12px 24px -10px rgba(0, 0, 0, 0.08);
            border-color: #cbd5e1;
            transform: translateY(-3px);
        }

        .media-preview-box {
            height: 160px;
            background-color: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        .media-preview-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .media-preview-box video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .media-icon-wrapper {
            width: 64px;
            height: 64px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
        }

        .icon-pdf { background-color: #fee2e2; color: #dc2626; }
        .icon-excel { background-color: #dcfce7; color: #16a34a; }
        .icon-word { background-color: #dbeafe; color: #2563eb; }
        .icon-archive { background-color: #fef9c3; color: #ca8a04; }
        .icon-audio { background-color: #fae8ff; color: #a855f7; }
        .icon-generic { background-color: #f1f5f9; color: #64748b; }

        .type-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 0.25rem 0.55rem;
            border-radius: 6px;
            backdrop-filter: blur(4px);
        }

        .badge-image { background: rgba(225, 29, 72, 0.9); color: #fff; }
        .badge-video { background: rgba(147, 51, 234, 0.9); color: #fff; }
        .badge-document { background: rgba(37, 99, 235, 0.9); color: #fff; }
        .badge-audio { background: rgba(13, 148, 136, 0.9); color: #fff; }
        .badge-other { background: rgba(71, 85, 105, 0.9); color: #fff; }

        /* Copy Button Toast */
        .toast-copy {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            background: #0f172a;
            color: #ffffff;
            padding: 0.75rem 1.25rem;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            font-weight: 500;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            pointer-events: none;
        }

        .toast-copy.show {
            opacity: 1;
            transform: translateY(0);
        }

        .custom-alert {
            border-radius: 12px;
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            font-size: 0.9rem;
        }

        .nav-pills .nav-link {
            color: #64748b;
            font-weight: 500;
            font-size: 0.88rem;
            border-radius: 8px;
            padding: 0.5rem 1rem;
        }

        .nav-pills .nav-link.active {
            background-color: var(--primary-color);
            color: #ffffff;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <!-- Sidebar Navigation -->
    <?php require_once 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <main class="main-content">

        <!-- Alerts -->
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

        <!-- Page Header & Customer Switcher -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
            <div>
                <a href="manage_assets.php" class="btn btn-link p-0 text-decoration-none text-muted mb-2 d-inline-flex align-items-center gap-1">
                    <i class="bi bi-arrow-left"></i> Voltar para Clientes
                </a>
                <h2 class="mb-1 fw-bold text-dark d-flex align-items-center gap-2">
                    <i class="bi bi-folder-symlink-fill text-danger"></i>
                    Mídias do Cliente
                </h2>
                <p class="text-muted small mb-0">Faça upload de imagens, documentos, vídeos e obtenha links públicos instantâneos para WhatsApp e integrações.</p>
            </div>

            <!-- Customer Selection Dropdown & Action -->
            <div class="d-flex align-items-center gap-2 w-100 w-md-auto">
                <?php if ($current_customer): ?>
                    <a href="manage_assets.php?customer_id=<?= $current_customer->id ?>" class="btn btn-outline-secondary btn-sm px-3 rounded-3 text-nowrap">
                        <i class="bi bi-hdd-network me-1"></i> Ver Ativos
                    </a>
                <?php endif; ?>

                <form method="GET" action="manage_media.php" class="d-flex align-items-center gap-2 m-0">
                    <select name="customer_id" class="form-select rounded-3 shadow-sm border-secondary-subtle" onchange="this.form.submit()" style="min-width: 220px; font-weight:500;">
                        <?php if (empty($accessible_customers)): ?>
                            <option value="">Nenhum cliente disponível</option>
                        <?php else: ?>
                            <?php foreach ($accessible_customers as $cust): ?>
                                <option value="<?= $cust->id ?>" <?= ($current_customer && $current_customer->id == $cust->id) ? 'selected' : '' ?>>
                                    🏢 <?= htmlspecialchars($cust->name) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </form>
            </div>
        </div>

        <?php if (!$current_customer): ?>
            <!-- No Customer Selected State -->
            <div class="card border-0 shadow-sm rounded-4 p-5 text-center my-4">
                <div class="py-4">
                    <i class="bi bi-building-exclamation fs-1 text-muted d-block mb-3"></i>
                    <h4 class="fw-bold">Nenhum Cliente Selecionado</h4>
                    <p class="text-muted">Você não possui permissão para visualizar clientes ou ainda não há empresas cadastradas.</p>
                    <a href="manage_assets.php" class="btn btn-primary rounded-3 px-4">Ir para Gerenciar Clientes</a>
                </div>
            </div>
        <?php else: ?>

            <!-- Customer Info Strip -->
            <div class="bg-white p-3 rounded-4 border shadow-sm mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-danger-subtle text-danger p-3 rounded-3 d-flex align-items-center justify-content-center" style="width:48px; height:48px;">
                        <i class="bi bi-building fs-4"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($current_customer->name) ?></h5>
                        <span class="text-muted small"><?= htmlspecialchars($current_customer->company_name) ?></span>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <div class="text-end">
                        <span class="badge bg-light text-dark border px-3 py-2 rounded-pill fw-semibold">
                            <i class="bi bi-paperclip me-1 text-primary"></i> <?= $media_counts['all'] ?> Arquivo(s) Armazenado(s)
                        </span>
                    </div>
                </div>
            </div>

            <!-- Upload Box (Drag & Drop Zone) -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3 d-flex align-items-center gap-2">
                        <i class="bi bi-cloud-arrow-up-fill text-primary"></i>
                        Fazer Upload de Novas Mídias
                    </h5>

                    <form id="upload-form" action="manage_media.php?customer_id=<?= $current_customer->id ?>" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                        <input type="hidden" name="action" value="upload_media">

                        <div class="dropzone-area" id="drop-zone">
                            <i class="bi bi-cloud-upload dropzone-icon"></i>
                            <h6 class="fw-bold mb-1">Arraste e solte seus arquivos aqui</h6>
                            <p class="text-muted small mb-3">ou clique para selecionar documentos, imagens, vídeos, PDFs ou áudios do seu computador</p>
                            <input type="file" id="file-input" name="media_files[]" multiple class="d-none">
                            <button type="button" class="btn btn-primary px-4 rounded-3 fw-semibold shadow-sm" onclick="document.getElementById('file-input').click()">
                                <i class="bi bi-folder-plus me-1"></i> Selecionar Arquivos
                            </button>
                            <div class="text-muted text-xs mt-3">
                                Suporta: PNG, JPG, GIF, WEBP, MP4, MOV, PDF, DOCX, XLSX, MP3, ZIP etc.
                            </div>
                        </div>

                        <!-- Upload Selected Files List Preview -->
                        <div id="file-list-container" class="mt-3 d-none">
                            <div class="p-3 bg-light rounded-3 border">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-semibold small text-dark"><i class="bi bi-files me-1"></i>Arquivos selecionados para envio:</span>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0 text-decoration-none" onclick="resetFileSelection()">Cancelar</button>
                                </div>
                                <ul id="selected-files-list" class="list-group list-group-flush mb-3 rounded-2 overflow-hidden border"></ul>
                                <div class="d-flex justify-content-end">
                                    <button type="submit" id="btn-submit-upload" class="btn btn-success px-4 rounded-3 fw-semibold shadow-sm">
                                        <i class="bi bi-send-fill me-1"></i> Iniciar Upload
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Media Section Header, Search & Category Filters -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-stretch align-items-md-center mb-4 gap-3">
                <!-- Category Pills -->
                <ul class="nav nav-pills bg-white p-1 rounded-3 border shadow-sm flex-nowrap overflow-x-auto" id="media-filter-pills">
                    <li class="nav-item">
                        <button class="nav-link active" data-filter="all" onclick="filterMedia('all', this)">
                            Todos <span class="badge bg-secondary-subtle text-dark ms-1"><?= $media_counts['all'] ?></span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-filter="image" onclick="filterMedia('image', this)">
                            <i class="bi bi-image me-1"></i> Imagens <span class="badge bg-secondary-subtle text-dark ms-1"><?= $media_counts['image'] ?></span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-filter="video" onclick="filterMedia('video', this)">
                            <i class="bi bi-play-btn me-1"></i> Vídeos <span class="badge bg-secondary-subtle text-dark ms-1"><?= $media_counts['video'] ?></span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-filter="document" onclick="filterMedia('document', this)">
                            <i class="bi bi-file-earmark-text me-1"></i> Documentos <span class="badge bg-secondary-subtle text-dark ms-1"><?= $media_counts['document'] ?></span>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-filter="audio" onclick="filterMedia('audio', this)">
                            <i class="bi bi-music-note-beamer me-1"></i> Áudios <span class="badge bg-secondary-subtle text-dark ms-1"><?= $media_counts['audio'] ?></span>
                        </button>
                    </li>
                </ul>

                <!-- Realtime Search Input -->
                <div class="position-relative" style="min-width: 250px;">
                    <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                    <input type="text" id="media-search" class="form-control ps-5 rounded-3 shadow-sm border-secondary-subtle" placeholder="Buscar por nome..." oninput="searchMedia()">
                </div>
            </div>

            <!-- Media Grid -->
            <?php if (empty($media_list)): ?>
                <div class="card border-0 shadow-sm rounded-4 text-center py-5">
                    <div class="card-body">
                        <i class="bi bi-folder2-open fs-1 text-muted d-block mb-3"></i>
                        <h5 class="fw-bold text-dark">Nenhuma mídia encontrada</h5>
                        <p class="text-muted small">Faça upload de arquivos acima para disponibilizá-los aos seus clientes e integrações.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="row g-3" id="media-grid">
                    <?php foreach ($media_list as $media): ?>
                        <?php
                            $public_url = $site_base_url . $media->file_path;
                            $ext = strtolower(pathinfo($media->original_name, PATHINFO_EXTENSION));
                            $uploader_name = trim(($media->fname ?? '') . ' ' . ($media->lname ?? '')) ?: ($media->username ?? 'Sistema');
                        ?>
                        <div class="col-12 col-sm-6 col-md-4 col-xl-3 media-item-col" data-type="<?= $media->file_type ?>" data-name="<?= strtolower(htmlspecialchars($media->original_name)) ?>">
                            <div class="media-card">
                                <!-- Preview Header Box -->
                                <div class="media-preview-box">
                                    <span class="type-badge badge-<?= $media->file_type ?>">
                                        <?= strtoupper($media->file_type) ?>
                                    </span>

                                    <?php if ($media->file_type === 'image'): ?>
                                        <img src="<?= htmlspecialchars($media->file_path) ?>" alt="<?= htmlspecialchars($media->original_name) ?>" loading="lazy">
                                    <?php elseif ($media->file_type === 'video'): ?>
                                        <video src="<?= htmlspecialchars($media->file_path) ?>#t=0.5" preload="metadata"></video>
                                        <div class="position-absolute top-50 start-50 translate-middle text-white bg-dark bg-opacity-50 p-2 rounded-circle">
                                            <i class="bi bi-play-fill fs-3"></i>
                                        </div>
                                    <?php else: ?>
                                        <!-- Document / Audio Icon representation -->
                                        <?php
                                            $icon_class = 'icon-generic';
                                            $bi_icon = 'bi-file-earmark';
                                            if ($ext === 'pdf') { $icon_class = 'icon-pdf'; $bi_icon = 'bi-file-earmark-pdf-fill'; }
                                            else if (in_array($ext, ['xls', 'xlsx', 'csv'])) { $icon_class = 'icon-excel'; $bi_icon = 'bi-file-earmark-excel-fill'; }
                                            else if (in_array($ext, ['doc', 'docx'])) { $icon_class = 'icon-word'; $bi_icon = 'bi-file-earmark-word-fill'; }
                                            else if (in_array($ext, ['zip', 'rar', '7z'])) { $icon_class = 'icon-archive'; $bi_icon = 'bi-file-earmark-zip-fill'; }
                                            else if ($media->file_type === 'audio') { $icon_class = 'icon-audio'; $bi_icon = 'bi-file-earmark-music-fill'; }
                                        ?>
                                        <div class="media-icon-wrapper <?= $icon_class ?>">
                                            <i class="bi <?= $bi_icon ?>"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Card Body Info -->
                                <div class="p-3 d-flex flex-column flex-grow-1">
                                    <h6 class="fw-bold text-dark text-truncate mb-1" title="<?= htmlspecialchars($media->original_name) ?>">
                                        <?= htmlspecialchars($media->original_name) ?>
                                    </h6>

                                    <div class="text-muted text-xs mb-3">
                                        <i class="bi bi-hdd me-1"></i> <?= format_bytes($media->file_size) ?> &bull;
                                        <i class="bi bi-clock me-1"></i> <?= date('d/m/Y H:i', strtotime($media->created_at)) ?>
                                    </div>

                                    <div class="mt-auto pt-2 border-top">
                                        <!-- Action Buttons Grid -->
                                        <div class="d-flex gap-1 mb-2">
                                            <button type="button" 
                                                    class="btn btn-primary btn-sm flex-grow-1 rounded-2 d-inline-flex align-items-center justify-content-center gap-1 fw-semibold"
                                                    onclick="copyPublicUrl('<?= htmlspecialchars($public_url, ENT_QUOTES) ?>')">
                                                <i class="bi bi-link-45deg fs-6"></i> Copiar Link Público
                                            </button>
                                        </div>
                                        
                                        <div class="d-flex gap-1">
                                            <button type="button" 
                                                    class="btn btn-outline-secondary btn-sm flex-grow-1 rounded-2 d-inline-flex align-items-center justify-content-center gap-1"
                                                    onclick="previewMedia('<?= htmlspecialchars($public_url, ENT_QUOTES) ?>', '<?= $media->file_type ?>', '<?= htmlspecialchars($media->original_name, ENT_QUOTES) ?>')">
                                                <i class="bi bi-eye"></i> Ver
                                            </button>

                                            <a href="<?= htmlspecialchars($media->file_path) ?>" 
                                               download="<?= htmlspecialchars($media->original_name) ?>" 
                                               class="btn btn-outline-secondary btn-sm px-2 rounded-2"
                                               title="Baixar Arquivo">
                                                <i class="bi bi-download"></i>
                                            </a>

                                            <button type="button" 
                                                    class="btn btn-outline-danger btn-sm px-2 rounded-2" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteMediaModal"
                                                    data-media-id="<?= $media->id ?>"
                                                    data-media-name="<?= htmlspecialchars($media->original_name) ?>"
                                                    title="Excluir Mídia">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </main>
</div>

<!-- ==========================================
      MODAL: PREVIEW MEDIA
     ========================================== -->
<div class="modal fade" id="previewMediaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-truncate" id="previewMediaTitle"><i class="bi bi-eye me-2 text-primary"></i>Visualizar Mídia</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-3 bg-dark rounded-bottom-4" id="previewMediaBody" style="min-height: 250px; display:flex; align-items:center; justify-content:center;">
                <!-- Content inserted via JavaScript -->
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
      MODAL: DELETE MEDIA CONFIRMATION
     ========================================== -->
<div class="modal fade" id="deleteMediaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="manage_media.php?customer_id=<?= $current_customer ? $current_customer->id : 0 ?>">
                <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                <input type="hidden" name="action" value="delete_media">
                <input type="hidden" name="media_id" id="delete_media_id">

                <div class="modal-body text-center p-4">
                    <i class="bi bi-exclamation-circle text-danger display-4 d-block mb-3"></i>
                    <h5 class="fw-bold mb-2">Excluir esta mídia?</h5>
                    <p class="text-muted small mb-3">Você tem certeza que deseja excluir <strong id="delete_media_name"></strong>? O link público deixará de funcionar.</p>

                    <div class="d-flex gap-2 justify-content-center">
                        <button type="button" class="btn btn-light px-3 rounded-3" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger px-3 rounded-3">Sim, Excluir</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toast for Copy Link Feedback -->
<div id="copy-toast" class="toast-copy">
    <i class="bi bi-check-circle-fill text-success fs-5"></i>
    <span>Link público copiado para a área de transferência!</span>
</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Drag & Drop & Upload handling
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const fileListContainer = document.getElementById('file-list-container');
    const selectedFilesList = document.getElementById('selected-files-list');

    if (dropZone && fileInput) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.add('dragover'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.remove('dragover'), false);
        });

        dropZone.addEventListener('drop', handleDrop, false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            fileInput.files = files;
            renderSelectedFiles(files);
        }

        fileInput.addEventListener('change', function() {
            renderSelectedFiles(this.files);
        });
    }

    function renderSelectedFiles(files) {
        if (!files || files.length === 0) {
            fileListContainer.classList.add('d-none');
            return;
        }

        selectedFilesList.innerHTML = '';
        Array.from(files).forEach((file, idx) => {
            const li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between align-items-center py-2 px-3 bg-white text-dark small';
            li.innerHTML = `
                <div class="text-truncate me-2">
                    <i class="bi bi-file-earmark me-1 text-primary"></i>
                    <strong>${file.name}</strong>
                </div>
                <span class="badge bg-light text-secondary border">${formatBytesJS(file.size)}</span>
            `;
            selectedFilesList.appendChild(li);
        });

        fileListContainer.classList.remove('d-none');
    }

    function resetFileSelection() {
        if (fileInput) fileInput.value = '';
        if (selectedFilesList) selectedFilesList.innerHTML = '';
        if (fileListContainer) fileListContainer.classList.add('d-none');
    }

    function formatBytesJS(bytes, precision = 2) {
        const units = ['B', 'KB', 'MB', 'GB'];
        let pow = Math.floor((bytes ? Math.log(bytes) : 0) / Math.log(1024));
        pow = Math.min(pow, units.length - 1);
        bytes /= Math.pow(1024, pow);
        return bytes.toFixed(precision) + ' ' + units[pow];
    }

    // Clipboard Copy Public Link
    function copyPublicUrl(url) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(showToast).catch(() => fallbackCopy(url));
        } else {
            fallbackCopy(url);
        }
    }

    function fallbackCopy(url) {
        const textArea = document.createElement("textarea");
        textArea.value = url;
        textArea.style.position = "fixed";
        textArea.style.left = "-999999px";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand('copy');
            showToast();
        } catch (err) {
            alert('Não foi possível copiar automaticamente. URL: ' + url);
        }
        document.body.removeChild(textArea);
    }

    function showToast() {
        const toast = document.getElementById('copy-toast');
        if (toast) {
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }
    }

    // Filter & Search Logic
    function filterMedia(type, btn) {
        document.querySelectorAll('#media-filter-pills .nav-link').forEach(l => l.classList.remove('active'));
        btn.classList.add('active');

        const items = document.querySelectorAll('.media-item-col');
        items.forEach(item => {
            const itemType = item.getAttribute('data-type');
            if (type === 'all' || itemType === type) {
                item.classList.remove('d-none');
            } else {
                item.classList.add('d-none');
            }
        });
    }

    function searchMedia() {
        const query = document.getElementById('media-search').value.toLowerCase().trim();
        const activeFilter = document.querySelector('#media-filter-pills .nav-link.active').getAttribute('data-filter');

        const items = document.querySelectorAll('.media-item-col');
        items.forEach(item => {
            const itemType = item.getAttribute('data-type');
            const itemName = item.getAttribute('data-name');

            const matchesCategory = (activeFilter === 'all' || itemType === activeFilter);
            const matchesSearch = itemName.includes(query);

            if (matchesCategory && matchesSearch) {
                item.classList.remove('d-none');
            } else {
                item.classList.add('d-none');
            }
        });
    }

    // Preview Modal Setup
    function previewMedia(url, type, name) {
        const modalTitle = document.getElementById('previewMediaTitle');
        const modalBody = document.getElementById('previewMediaBody');
        modalTitle.textContent = name;

        if (type === 'image') {
            modalBody.innerHTML = `<img src="${url}" class="img-fluid rounded-3" style="max-height: 70vh; object-fit: contain;">`;
        } else if (type === 'video') {
            modalBody.innerHTML = `<video src="${url}" controls autoplay class="w-100 rounded-3" style="max-height: 70vh;"></video>`;
        } else if (type === 'audio') {
            modalBody.innerHTML = `
                <div class="p-4 text-white">
                    <i class="bi bi-music-note-beamer display-1 d-block mb-3 text-danger"></i>
                    <audio src="${url}" controls autoplay class="w-100 mt-2"></audio>
                </div>`;
        } else {
            modalBody.innerHTML = `
                <div class="p-4 text-white">
                    <i class="bi bi-file-earmark-text display-1 d-block mb-3 text-primary"></i>
                    <p class="mb-3">Visualização direta indisponível para este tipo de arquivo.</p>
                    <a href="${url}" target="_blank" class="btn btn-primary px-4 rounded-3"><i class="bi bi-box-arrow-up-right me-1"></i> Abrir em nova aba</a>
                </div>`;
        }

        const previewModal = new bootstrap.Modal(document.getElementById('previewMediaModal'));
        previewModal.show();
    }

    // Delete Modal Event binding
    const deleteMediaModal = document.getElementById('deleteMediaModal');
    if (deleteMediaModal) {
        deleteMediaModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const mediaId = button.getAttribute('data-media-id');
            const mediaName = button.getAttribute('data-media-name');

            document.getElementById('delete_media_id').value = mediaId;
            document.getElementById('delete_media_name').textContent = mediaName;
        });
    }
</script>
</body>
</html>
