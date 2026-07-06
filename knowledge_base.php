<?php
// knowledge_base.php - Knowledge Base Portal for SyncDesk (Axis360 Design)
require_once 'users/init.php';

// Secure the page - redirect if not authenticated
if (!$user->isLoggedIn()) {
    Redirect::to($us_url_root . 'users/login.php');
    exit;
}

$user_id = $user->data()->id;
$fname = htmlspecialchars($user->data()->fname);
$lname = htmlspecialchars($user->data()->lname);
$username = htmlspecialchars($user->data()->username);
$full_name = trim($fname . ' ' . $lname) ?: $username;
$is_admin = hasPerm([2], $user_id);

$db = DB::getInstance();
$success_msg = '';
$error_msg = '';

// Handle POST actions for Admin CRUD
if (Input::exists() && $is_admin) {
    if (Token::check(Input::get('csrf'))) {
        $action = Input::get('action');
        
        if ($action === 'add') {
            $validation = new Validate();
            $validation->check($_POST, [
                'title' => ['required' => true, 'min' => 3, 'max' => 255],
                'category_id' => ['required' => true],
                'content' => ['required' => true, 'min' => 10]
            ]);
            
            if ($validation->passed()) {
                try {
                    $attachment_path = null;
                    $attachment_name = null;
                    
                    // Handle file upload
                    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                        $file = $_FILES['attachment'];
                        $allowed_exts = ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'png', 'jpg', 'jpeg'];
                        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        
                        if (in_array($file_ext, $allowed_exts)) {
                            if ($file['size'] <= 15 * 1024 * 1024) { // 15MB max
                                $upload_dir = 'c:/xampp/htdocs/syncdesk/uploads/';
                                if (!is_dir($upload_dir)) {
                                    mkdir($upload_dir, 0777, true);
                                }
                                
                                $new_filename = uniqid('kb_', true) . '.' . $file_ext;
                                $dest_path = $upload_dir . $new_filename;
                                
                                if (move_uploaded_file($file['tmp_name'], $dest_path)) {
                                    $attachment_path = 'uploads/' . $new_filename;
                                    $attachment_name = $file['name'];
                                } else {
                                    throw new Exception('Erro ao transferir arquivo para o diretório de uploads.');
                                }
                            } else {
                                throw new Exception('O arquivo de anexo ultrapassa o limite de 15MB.');
                            }
                        } else {
                            throw new Exception('Formato de anexo não permitido (Use PDF, PPT, Word, Excel, ZIP ou Imagens).');
                        }
                    }
                    
                    $db->insert('kb_articles', [
                        'category_id' => (int)Input::get('category_id'),
                        'title' => trim(Input::get('title')),
                        'content' => trim(Input::get('content')),
                        'video_url' => trim(Input::get('video_url')),
                        'external_link' => trim(Input::get('external_link')),
                        'attachment_path' => $attachment_path,
                        'attachment_name' => $attachment_name
                    ]);
                    $success_msg = 'Artigo publicado com sucesso!';
                } catch (Exception $e) {
                    $error_msg = 'Erro ao salvar artigo: ' . $e->getMessage();
                }
            } else {
                $error_msg = 'Validação falhou: ' . implode(', ', $validation->errors());
            }
        }
        
        elseif ($action === 'edit') {
            $id = (int)Input::get('id');
            $validation = new Validate();
            $validation->check($_POST, [
                'title' => ['required' => true, 'min' => 3, 'max' => 255],
                'category_id' => ['required' => true],
                'content' => ['required' => true, 'min' => 10]
            ]);
            
            if ($validation->passed()) {
                try {
                    // Fetch current data
                    $current = $db->query("SELECT attachment_path, attachment_name FROM kb_articles WHERE id = ?", [$id])->first();
                    $attachment_path = $current->attachment_path;
                    $attachment_name = $current->attachment_name;
                    
                    // Handle file upload
                    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                        $file = $_FILES['attachment'];
                        $allowed_exts = ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'png', 'jpg', 'jpeg'];
                        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        
                        if (in_array($file_ext, $allowed_exts)) {
                            if ($file['size'] <= 15 * 1024 * 1024) { // 15MB
                                $upload_dir = 'c:/xampp/htdocs/syncdesk/uploads/';
                                if (!is_dir($upload_dir)) {
                                    mkdir($upload_dir, 0777, true);
                                }
                                
                                $new_filename = uniqid('kb_', true) . '.' . $file_ext;
                                $dest_path = $upload_dir . $new_filename;
                                
                                if (move_uploaded_file($file['tmp_name'], $dest_path)) {
                                    // Delete old file if exists
                                    if ($current->attachment_path && file_exists('c:/xampp/htdocs/syncdesk/' . $current->attachment_path)) {
                                        @unlink('c:/xampp/htdocs/syncdesk/' . $current->attachment_path);
                                    }
                                    $attachment_path = 'uploads/' . $new_filename;
                                    $attachment_name = $file['name'];
                                } else {
                                    throw new Exception('Erro ao transferir novo arquivo para uploads.');
                                }
                            } else {
                                throw new Exception('O arquivo excede o limite de 15MB.');
                            }
                        } else {
                            throw new Exception('Formato de anexo não permitido.');
                        }
                    }
                    
                    $db->update('kb_articles', $id, [
                        'category_id' => (int)Input::get('category_id'),
                        'title' => trim(Input::get('title')),
                        'content' => trim(Input::get('content')),
                        'video_url' => trim(Input::get('video_url')),
                        'external_link' => trim(Input::get('external_link')),
                        'attachment_path' => $attachment_path,
                        'attachment_name' => $attachment_name
                    ]);
                    $success_msg = 'Artigo atualizado com sucesso!';
                } catch (Exception $e) {
                    $error_msg = 'Erro ao atualizar artigo: ' . $e->getMessage();
                }
            } else {
                $error_msg = 'Validação falhou: ' . implode(', ', $validation->errors());
            }
        }
        
        elseif ($action === 'delete') {
            $id = (int)Input::get('id');
            try {
                $current = $db->query("SELECT attachment_path FROM kb_articles WHERE id = ?", [$id])->first();
                if ($current && $current->attachment_path && file_exists('c:/xampp/htdocs/syncdesk/' . $current->attachment_path)) {
                    @unlink('c:/xampp/htdocs/syncdesk/' . $current->attachment_path);
                }
                
                $db->query("DELETE FROM kb_articles WHERE id = ?", [$id]);
                $success_msg = 'Artigo removido com sucesso!';
            } catch (Exception $e) {
                $error_msg = 'Erro ao excluir artigo: ' . $e->getMessage();
            }
        }
        
        elseif ($action === 'add_category') {
            $validation = new Validate();
            $validation->check($_POST, [
                'name' => ['required' => true, 'min' => 2, 'max' => 100],
                'icon' => ['required' => true]
            ]);
            
            if ($validation->passed()) {
                try {
                    $db->insert('kb_categories', [
                        'name' => trim(Input::get('name')),
                        'icon' => trim(Input::get('icon'))
                    ]);
                    $success_msg = 'Categoria criada com sucesso!';
                } catch (Exception $e) {
                    $error_msg = 'Erro ao cadastrar categoria: ' . $e->getMessage();
                }
            } else {
                $error_msg = 'Validação falhou: ' . implode(', ', $validation->errors());
            }
        }
    } else {
        $error_msg = 'Token de segurança CSRF inválido!';
    }
}

// Fetch categories and articles
$categories = $db->query("SELECT * FROM kb_categories ORDER BY name ASC")->results();
$articles = $db->query("
    SELECT a.*, c.name as category_name, c.icon as category_icon 
    FROM kb_articles a 
    JOIN kb_categories c ON a.category_id = c.id 
    ORDER BY a.created_at DESC
")->results();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SyncDesk - Base de Conhecimento</title>
    <!-- Fonts -->
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
            --sb-active-bg: #e11d48;
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

        h1, h2, h3, h4, h5, .brand-title {
            font-family: 'Outfit', sans-serif;
        }

        .wrapper {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex-grow: 1;
            padding: 2rem 2.5rem;
            margin-left: 260px;
            min-height: 100vh;
            overflow-y: auto;
        }

        .top-search-bar {
            display: flex;
            align-items: center;
            padding: 0.75rem 0rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid #e2e8f0;
            gap: 12px;
        }

        .top-search-input {
            border: none;
            background: transparent;
            font-size: 0.9rem;
            width: 300px;
            outline: none;
        }

        .profile-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: #e11d48;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.8rem;
        }

        .kb-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: all 0.2s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .kb-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            border-color: #cbd5e1;
        }

        .btn-primary-axis {
            background-color: #e11d48;
            border-color: #e11d48;
            font-weight: 500;
            padding: 0.5rem 1.25rem;
            border-radius: 10px;
        }

        .btn-primary-axis:hover {
            background-color: #be123c;
            border-color: #be123c;
        }

        .category-pill {
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.85rem;
            font-weight: 500;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: var(--text-muted);
            border-radius: 50px;
            padding: 0.4rem 1rem;
        }

        .category-pill:hover, .category-pill.active {
            background-color: #e11d48;
            color: #ffffff;
            border-color: #e11d48;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <!-- Sidebar Navigation Include -->
    <?php require_once 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <main class="main-content">
        <!-- Top Search Header Bar -->
        <header class="top-search-bar">
            <i class="bi bi-search text-muted"></i>
            <input type="text" class="top-search-input" placeholder="Buscar na base de conhecimento... (Ctrl + K)">
            <span class="badge bg-light text-muted border ms-2" style="font-size: 0.7rem;">Ctrl + K</span>
            
            <div class="d-flex align-items-center gap-3 ms-auto">
                <div class="profile-avatar">
                    <?= strtoupper(substr($full_name, 0, 1)) ?>
                </div>
            </div>
        </header>

        <!-- Hub Title and Admin Actions -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-1">Knowledge Base 📚</h2>
                <p class="text-muted mb-0">Base de conhecimento técnica, playbooks de vendas e diretrizes operacionais.</p>
            </div>
            <?php if ($is_admin): ?>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary" style="border-radius: 10px;" data-bs-toggle="modal" data-bs-target="#categoriesModal">
                        <i class="bi bi-tags me-1"></i> Categorias
                    </button>
                    <button class="btn btn-primary btn-primary-axis" data-bs-toggle="modal" data-bs-target="#addArticleModal">
                        <i class="bi bi-plus-lg me-1"></i> Novo Artigo
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Success/Error Alerts -->
        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show p-3 mb-4" role="alert" style="border-radius: 12px;">
                <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($success_msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger alert-dismissible fade show p-3 mb-4" role="alert" style="border-radius: 12px;">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error_msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Search & Filter Badges -->
        <div class="row mb-4">
            <div class="col-md-5 mb-2 mb-md-0">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0" style="border-radius: 10px 0 0 10px;"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="kbSearchInput" class="form-control border-start-0" placeholder="Buscar por título ou palavras..." style="border-radius: 0 10px 10px 0; font-size: 0.9rem;">
                </div>
            </div>
            <div class="col-md-7">
                <div class="d-flex flex-wrap gap-2 justify-content-md-end align-items-center">
                    <span class="category-pill active" data-category-id="all">
                        <i class="bi bi-collection me-1"></i> Todos
                    </span>
                    <?php foreach ($categories as $cat): ?>
                        <span class="category-pill" data-category-id="<?= $cat->id ?>">
                            <i class="bi <?= htmlspecialchars($cat->icon) ?> me-1"></i> <?= htmlspecialchars($cat->name) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Articles Grid -->
        <div class="row g-4" id="articlesGrid">
            <?php if (empty($articles)): ?>
                <div class="col-12 text-center py-5 text-muted">
                    <i class="bi bi-journal-x fs-1 d-block mb-2"></i>
                    Nenhum artigo publicado na base de conhecimento.
                </div>
            <?php else: ?>
                <?php foreach ($articles as $art): ?>
                    <div class="col-lg-4 col-md-6 col-12 kb-article-card" data-cat-id="<?= $art->category_id ?>" data-search-text="<?= strtolower(htmlspecialchars($art->title . ' ' . $art->category_name)) ?>">
                        <div class="kb-card p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="badge bg-light text-primary border px-2.5 py-1.5" style="font-size: 0.75rem; border-radius: 6px;">
                                    <i class="bi <?= htmlspecialchars($art->category_icon) ?> me-1"></i>
                                    <?= htmlspecialchars($art->category_name) ?>
                                </span>
                                <?php if ($is_admin): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-link text-muted p-0" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end border shadow-sm" style="border-radius: 10px; font-size: 0.85rem;">
                                            <li>
                                                <button class="dropdown-item edit-btn" 
                                                        data-id="<?= $art->id ?>"
                                                        data-title="<?= htmlspecialchars($art->title) ?>"
                                                        data-cat-id="<?= $art->category_id ?>"
                                                        data-video-url="<?= htmlspecialchars($art->video_url ?? '') ?>"
                                                        data-external-link="<?= htmlspecialchars($art->external_link ?? '') ?>"
                                                        data-attachment-name="<?= htmlspecialchars($art->attachment_name ?? '') ?>"
                                                        data-content="<?= htmlspecialchars($art->content) ?>">
                                                    <i class="bi bi-pencil me-2"></i> Editar Artigo
                                                </button>
                                            </li>
                                            <li>
                                                <button class="dropdown-item text-danger delete-btn" 
                                                        data-id="<?= $art->id ?>" 
                                                        data-title="<?= htmlspecialchars($art->title) ?>">
                                                    <i class="bi bi-trash me-2"></i> Excluir
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <h5 class="fw-bold mb-2 text-slate-800" style="font-size: 1.05rem;"><?= htmlspecialchars($art->title) ?></h5>
                            
                            <!-- Display File Attachment Tag if exists -->
                            <?php if ($art->attachment_name): ?>
                                <div class="mb-2" style="font-size:0.75rem; color:#059669;">
                                    <i class="bi bi-paperclip"></i> Anexo: <?= htmlspecialchars($art->attachment_name) ?>
                                </div>
                            <?php endif; ?>

                            <p class="text-muted flex-grow-1 mb-4" style="font-size: 0.85rem; line-height: 1.5;">
                                <?= mb_strimwidth(htmlspecialchars(strip_tags($art->content)), 0, 120, '...') ?>
                            </p>
                            
                            <div class="d-flex align-items-center justify-content-between mt-auto">
                                <span class="text-muted" style="font-size: 0.75rem;"><i class="bi bi-calendar-event me-1"></i> <?= date('d/m/Y', strtotime($art->created_at)) ?></span>
                                <button class="btn btn-link text-primary fw-semibold p-0 text-decoration-none read-more-btn"
                                        data-title="<?= htmlspecialchars($art->title) ?>"
                                        data-category="<?= htmlspecialchars($art->category_name) ?>"
                                        data-category-icon="<?= htmlspecialchars($art->category_icon) ?>"
                                        data-video-url="<?= htmlspecialchars($art->video_url ?? '') ?>"
                                        data-external-link="<?= htmlspecialchars($art->external_link ?? '') ?>"
                                        data-attachment-path="<?= htmlspecialchars($art->attachment_path ?? '') ?>"
                                        data-attachment-name="<?= htmlspecialchars($art->attachment_name ?? '') ?>"
                                        data-content="<?= htmlspecialchars($art->content) ?>"
                                        style="font-size: 0.85rem;">
                                    Ler Artigo <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- ==========================================
     MODALS SECTION
     ========================================== -->

<!-- 1. View Article Reading Modal (Admin and Agent) -->
<div class="modal fade" id="viewArticleModal" tabindex="-1" aria-labelledby="viewArticleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 16px; border:none; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <div>
                    <span class="badge bg-light text-primary border mb-2" id="viewArtBadge" style="font-size: 0.75rem; border-radius: 6px;"></span>
                    <h4 class="modal-title fw-bold" id="viewArticleModalLabel"></h4>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body px-4 py-3">
                <!-- Video Container -->
                <div class="mb-4 d-none" id="viewArtVideoContainer">
                    <div class="ratio ratio-16x9" style="border-radius: 12px; overflow:hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                        <iframe src="" id="viewArtVideoFrame" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
                    </div>
                </div>
                
                <!-- Text Content -->
                <div class="lh-base text-slate-700 mb-3" id="viewArtContent" style="white-space: pre-wrap; font-size: 0.95rem;"></div>
            </div>
            
            <div class="modal-footer border-top-0 px-4 pb-4 pt-0 d-flex justify-content-between align-items-center">
                <div class="d-flex gap-2">
                    <a href="" target="_blank" class="btn btn-outline-primary" id="viewArtExternalLinkBtn" style="border-radius: 10px;">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Referência Externa
                    </a>
                    
                    <!-- File Attachment Download Button -->
                    <a href="" download class="btn btn-success d-none" id="viewArtAttachmentBtn" style="border-radius: 10px;">
                        <i class="bi bi-file-earmark-arrow-down me-1"></i> Baixar Anexo (<span id="viewArtAttachmentLabel"></span>)
                    </a>
                </div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 10px;">Fechar</button>
            </div>
        </div>
    </div>
</div>

<?php if ($is_admin): ?>
    <!-- 2. Add Article Modal -->
    <div class="modal fade" id="addArticleModal" tabindex="-1" aria-labelledby="addArticleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius: 16px; border:none;">
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                        <h5 class="modal-title fw-bold" id="addArticleModalLabel">Publicar Novo Artigo / Playbook</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body px-4 py-3">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label for="addTitle" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Título do Artigo</label>
                                <input type="text" class="form-control" name="title" id="addTitle" required placeholder="Ex: Playbook Comercial de Vendas">
                            </div>
                            <div class="col-md-4">
                                <label for="addCategory" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Categoria</label>
                                <select class="form-select" name="category_id" id="addCategory" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat->id ?>"><?= htmlspecialchars($cat->name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="addVideoUrl" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">URL de Vídeo (Embed do YouTube/Vimeo)</label>
                                <input type="url" class="form-control" name="video_url" id="addVideoUrl" placeholder="Ex: https://www.youtube.com/embed/...">
                            </div>
                            <div class="col-md-6">
                                <label for="addExternalLink" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Link Externo Auxiliar</label>
                                <input type="url" class="form-control" name="external_link" id="addExternalLink" placeholder="Ex: https://docs.n8n.io">
                            </div>
                            <div class="col-12">
                                <label for="addAttachment" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Anexar Arquivo (Playbooks, PPTs, Apresentações - Máx 15MB)</label>
                                <input type="file" class="form-control" name="attachment" id="addAttachment">
                            </div>
                            <div class="col-12">
                                <label for="addContent" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Instruções / Conteúdo do Artigo</label>
                                <textarea class="form-control" name="content" id="addContent" rows="7" required placeholder="Escreva as diretrizes do playbook ou a descrição do arquivo..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer border-top-0 px-4 pb-4">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:10px;">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-primary-axis">Publicar Artigo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 3. Edit Article Modal -->
    <div class="modal fade" id="editArticleModal" tabindex="-1" aria-labelledby="editArticleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius: 16px; border:none;">
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="editId">
                    
                    <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                        <h5 class="modal-title fw-bold" id="editArticleModalLabel">Editar Artigo / Playbook</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body px-4 py-3">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label for="editTitle" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Título do Artigo</label>
                                <input type="text" class="form-control" name="title" id="editTitle" required>
                            </div>
                            <div class="col-md-4">
                                <label for="editCategory" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Categoria</label>
                                <select class="form-select" name="category_id" id="editCategory" required>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat->id ?>"><?= htmlspecialchars($cat->name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="editVideoUrl" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">URL de Vídeo (Embed)</label>
                                <input type="url" class="form-control" name="video_url" id="editVideoUrl">
                            </div>
                            <div class="col-md-6">
                                <label for="editExternalLink" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Link Externo</label>
                                <input type="url" class="form-control" name="external_link" id="editExternalLink">
                            </div>
                            <div class="col-12">
                                <label for="editAttachment" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Substituir Arquivo Anexo (Deixe em branco para manter o atual)</label>
                                <input type="file" class="form-control" name="attachment" id="editAttachment">
                                <div class="mt-2 text-muted" style="font-size: 0.8rem;" id="editCurrentAttachmentInfo"></div>
                            </div>
                            <div class="col-12">
                                <label for="editContent" class="form-label fw-semibold text-muted" style="font-size:0.85rem;">Conteúdo</label>
                                <textarea class="form-control" name="content" id="editContent" rows="7" required></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer border-top-0 px-4 pb-4">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:10px;">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-primary-axis">Salvar Alterações</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 4. Delete Article Modal -->
    <div class="modal fade" id="deleteArticleModal" tabindex="-1" aria-labelledby="deleteArticleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border:none;">
                <form method="POST" action="">
                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteId">
                    
                    <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                        <h5 class="modal-title fw-bold text-danger" id="deleteArticleModalLabel">Excluir Artigo</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body px-4 py-3">
                        <p class="mb-0 text-muted">Tem certeza absoluta de que deseja excluir o artigo <strong class="text-slate-800" id="deleteTitleText"></strong>? Esta ação removerá o artigo e seu arquivo anexo permanentemente.</p>
                    </div>
                    
                    <div class="modal-footer border-top-0 px-4 pb-4">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:10px;">Cancelar</button>
                        <button type="submit" class="btn btn-danger" style="border-radius:10px;">Excluir Permanentemente</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 5. Manage Categories Modal -->
    <div class="modal fade" id="categoriesModal" tabindex="-1" aria-labelledby="categoriesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border:none;">
                <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                    <h5 class="modal-title fw-bold" id="categoriesModalLabel">Gerenciar Categorias</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body px-4 py-3">
                    <!-- Current Categories List -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold text-muted mb-2" style="font-size:0.85rem;">Categorias Cadastradas</label>
                        <div class="list-group border-0">
                            <?php foreach ($categories as $cat): ?>
                                <div class="list-group-item d-flex align-items-center justify-content-between px-3 py-2.5 mb-2 border rounded-3 bg-light">
                                    <div class="d-flex align-items-center gap-2.5">
                                        <i class="bi <?= htmlspecialchars($cat->icon) ?> text-primary fs-5"></i>
                                        <span class="fw-semibold text-slate-800" style="font-size:0.9rem;"><?= htmlspecialchars($cat->name) ?></span>
                                    </div>
                                    <span class="badge bg-white text-muted border border-light-subtle rounded-pill font-monospace" style="font-size:0.75rem;">ID #<?= $cat->id ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <hr class="my-4 text-muted">
                    
                    <!-- Add Category Form -->
                    <form method="POST" action="">
                        <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                        <input type="hidden" name="action" value="add_category">
                        
                        <label class="form-label fw-semibold text-muted mb-2" style="font-size:0.85rem;">Nova Categoria</label>
                        <div class="row g-2">
                            <div class="col-md-7 col-12">
                                <input type="text" class="form-control form-control-sm" name="name" required placeholder="Nome, ex: Playbooks Comerciais">
                            </div>
                            <div class="col-md-5 col-12">
                                <input type="text" class="form-control form-control-sm" name="icon" required placeholder="Ícone, ex: bi-briefcase">
                            </div>
                            <div class="col-12 mt-2">
                                <button type="submit" class="btn btn-primary btn-sm btn-primary-axis w-100">
                                    <i class="bi bi-plus-lg me-1"></i> Cadastrar Categoria
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // 1. Text Search & Category Pill Filters
    const searchInput = document.getElementById('kbSearchInput');
    const categoryPills = document.querySelectorAll('.category-pill');
    const articleCards = document.querySelectorAll('.kb-article-card');

    let currentCategoryId = 'all';
    let searchQuery = '';

    function filterArticles() {
        articleCards.forEach(card => {
            const cardCatId = card.getAttribute('data-cat-id');
            const cardSearchText = card.getAttribute('data-search-text');
            
            const matchesCategory = (currentCategoryId === 'all' || cardCatId === currentCategoryId);
            const matchesSearch = (searchQuery === '' || cardSearchText.includes(searchQuery));
            
            if (matchesCategory && matchesSearch) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    }

    // Search input handler
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            searchQuery = e.target.value.toLowerCase().trim();
            filterArticles();
        });
    }

    // Category pills handler
    categoryPills.forEach(pill => {
        pill.addEventListener('click', () => {
            categoryPills.forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            
            currentCategoryId = pill.getAttribute('data-category-id');
            filterArticles();
        });
    });

    // 2. View Article Reader Populator
    const viewArticleModal = new bootstrap.Modal(document.getElementById('viewArticleModal'));
    const readMoreBtns = document.querySelectorAll('.read-more-btn');
    
    const viewArtTitle = document.getElementById('viewArticleModalLabel');
    const viewArtBadge = document.getElementById('viewArtBadge');
    const viewArtContent = document.getElementById('viewArtContent');
    const viewArtVideoContainer = document.getElementById('viewArtVideoContainer');
    const viewArtVideoFrame = document.getElementById('viewArtVideoFrame');
    const viewArtExternalLinkBtn = document.getElementById('viewArtExternalLinkBtn');
    const viewArtAttachmentBtn = document.getElementById('viewArtAttachmentBtn');
    const viewArtAttachmentLabel = document.getElementById('viewArtAttachmentLabel');

    readMoreBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const title = btn.getAttribute('data-title');
            const category = btn.getAttribute('data-category');
            const icon = btn.getAttribute('data-category-icon');
            const videoUrl = btn.getAttribute('data-video-url');
            const extLink = btn.getAttribute('data-external-link');
            const attPath = btn.getAttribute('data-attachment-path');
            const attName = btn.getAttribute('data-attachment-name');
            const content = btn.getAttribute('data-content');

            // Populate text fields
            viewArtTitle.textContent = title;
            viewArtBadge.innerHTML = `<i class="bi ${icon} me-1"></i> ${category}`;
            viewArtContent.textContent = content;

            // Handle Video Player Embed
            if (videoUrl && videoUrl.trim() !== '') {
                viewArtVideoFrame.src = videoUrl;
                viewArtVideoContainer.classList.remove('d-none');
            } else {
                viewArtVideoFrame.src = '';
                viewArtVideoContainer.classList.add('d-none');
            }

            // Handle External Reference Button
            if (extLink && extLink.trim() !== '') {
                viewArtExternalLinkBtn.href = extLink;
                viewArtExternalLinkBtn.classList.remove('d-none');
            } else {
                viewArtExternalLinkBtn.href = '#';
                viewArtExternalLinkBtn.classList.add('d-none');
            }

            // Handle Attachment Download Button
            if (attPath && attPath.trim() !== '') {
                viewArtAttachmentBtn.href = attPath;
                viewArtAttachmentLabel.textContent = attName;
                viewArtAttachmentBtn.classList.remove('d-none');
            } else {
                viewArtAttachmentBtn.href = '#';
                viewArtAttachmentBtn.classList.add('d-none');
            }

            viewArticleModal.show();
        });
    });

    // Stop video iframe playing on modal close
    document.getElementById('viewArticleModal').addEventListener('hide.bs.modal', () => {
        viewArtVideoFrame.src = '';
    });

    <?php if ($is_admin): ?>
        // 3. Edit Article Populator
        const editArticleModal = new bootstrap.Modal(document.getElementById('editArticleModal'));
        const editBtns = document.querySelectorAll('.edit-btn');
        
        editBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('editId').value = btn.getAttribute('data-id');
                document.getElementById('editTitle').value = btn.getAttribute('data-title');
                document.getElementById('editCategory').value = btn.getAttribute('data-cat-id');
                document.getElementById('editVideoUrl').value = btn.getAttribute('data-video-url');
                document.getElementById('editExternalLink').value = btn.getAttribute('data-external-link');
                document.getElementById('editContent').value = btn.getAttribute('data-content');
                
                // Show current attachment info
                const attName = btn.getAttribute('data-attachment-name');
                const infoDiv = document.getElementById('editCurrentAttachmentInfo');
                if (attName && attName.trim() !== '') {
                    infoDiv.innerHTML = `<i class="bi bi-paperclip text-success"></i> Anexo atual: <strong>${attName}</strong>`;
                } else {
                    infoDiv.innerHTML = `Nenhum anexo cadastrado para este artigo.`;
                }
                
                editArticleModal.show();
            });
        });

        // 4. Delete Article Populator
        const deleteArticleModal = new bootstrap.Modal(document.getElementById('deleteArticleModal'));
        const deleteBtns = document.querySelectorAll('.delete-btn');
        
        deleteBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('deleteId').value = btn.getAttribute('data-id');
                document.getElementById('deleteTitleText').textContent = btn.getAttribute('data-title');
                
                deleteArticleModal.show();
            });
        });
    <?php endif; ?>
</script>

</body>
</html>
