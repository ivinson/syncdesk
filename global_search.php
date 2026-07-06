<?php
require_once 'users/init.php';

// Strict session check
if (!$user->isLoggedIn()) {
    header('HTTP/1.1 401 Unauthorized');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Acesso não autorizado. Por favor, faça login.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$query = trim(Input::get('q'));
if (empty($query) || strlen($query) < 2) {
    echo json_encode([
        'shortcuts' => [],
        'customers' => [],
        'tasks' => [],
        'articles' => []
    ]);
    exit;
}

$db = DB::getInstance();
$user_id = $user->data()->id;
$is_admin = hasPerm([2]);

// 1. Fetch associated customers for multitenancy filtering
$associated_customers = [];
if (!$is_admin) {
    $ca_results = $db->query("SELECT customer_id FROM customer_agent WHERE user_id = ?", [$user_id])->results();
    foreach ($ca_results as $row) {
        $associated_customers[] = (int)$row->customer_id;
    }
}

// 2. Query System Navigation Shortcuts (Local static pool)
$shortcuts_pool = [
    ['title' => 'Dashboard - Painel Geral', 'url' => 'index.php', 'icon' => 'bi-grid-1x2-fill', 'admin_only' => false],
    ['title' => 'Clientes & Ativos', 'url' => 'manage_assets.php', 'icon' => 'bi-people', 'admin_only' => false],
    ['title' => 'Knowledge Base - Base de Conhecimento', 'url' => 'knowledge_base.php', 'icon' => 'bi-journal-text', 'admin_only' => false],
    ['title' => 'Fila de Tarefas', 'url' => 'tasks.php', 'icon' => 'bi-check2-square', 'admin_only' => false],
    ['title' => 'Configurações de Integração', 'url' => 'settings.php', 'icon' => 'bi-gear', 'admin_only' => true],
    ['title' => 'Gerenciar Serviços de TI', 'url' => 'manage_services.php', 'icon' => 'bi-cpu', 'admin_only' => true],
    ['title' => 'Gerenciar Contatos de Clientes', 'url' => 'manage_contacts.php', 'icon' => 'bi-telephone', 'admin_only' => true],
    ['title' => 'Vincular Atendentes a Clientes', 'url' => 'manage_customer_agents.php', 'icon' => 'bi-link-45deg', 'admin_only' => true],
];

$shortcuts = [];
foreach ($shortcuts_pool as $sh) {
    if ($sh['admin_only'] && !$is_admin) {
        continue;
    }
    // Simple case-insensitive search
    if (stripos($sh['title'], $query) !== false) {
        $shortcuts[] = [
            'title' => $sh['title'],
            'url' => $sh['url'],
            'icon' => $sh['icon']
        ];
    }
}

// 3. Query Customers (respecting multitenancy)
$customers = [];
if ($is_admin) {
    $cust_results = $db->query("
        SELECT id, name FROM customers 
        WHERE name LIKE ? 
        ORDER BY name ASC LIMIT 5
    ", ["%{$query}%"])->results();
} else {
    if (!empty($associated_customers)) {
        $in_clause = implode(',', array_map('intval', $associated_customers));
        $cust_results = $db->query("
            SELECT id, name FROM customers 
            WHERE id IN ({$in_clause}) AND name LIKE ? 
            ORDER BY name ASC LIMIT 5
        ", ["%{$query}%"])->results();
    } else {
        $cust_results = [];
    }
}
foreach ($cust_results as $row) {
    $customers[] = [
        'title' => $row->name,
        'url' => 'manage_assets.php?customer_id=' . $row->id,
        'icon' => 'bi-building'
    ];
}

// 4. Query Tasks (respecting multitenancy)
$tasks = [];
if ($is_admin) {
    $task_results = $db->query("
        SELECT id, title FROM tasks 
        WHERE title LIKE ? OR description LIKE ? 
        ORDER BY id DESC LIMIT 5
    ", ["%{$query}%", "%{$query}%"])->results();
} else {
    if (!empty($associated_customers)) {
        $in_clause = implode(',', array_map('intval', $associated_customers));
        $task_results = $db->query("
            SELECT id, title FROM tasks 
            WHERE (customer_id IN ({$in_clause}) OR assigned_to = ?) AND (title LIKE ? OR description LIKE ?) 
            ORDER BY id DESC LIMIT 5
        ", [$user_id, "%{$query}%", "%{$query}%"])->results();
    } else {
        $task_results = $db->query("
            SELECT id, title FROM tasks 
            WHERE assigned_to = ? AND (title LIKE ? OR description LIKE ?) 
            ORDER BY id DESC LIMIT 5
        ", [$user_id, "%{$query}%", "%{$query}%"])->results();
    }
}
foreach ($task_results as $row) {
    $tasks[] = [
        'title' => $row->title,
        'url' => 'tasks.php?search=' . urlencode($row->title),
        'icon' => 'bi-check2-circle'
    ];
}

// 5. Query Knowledge Base Articles
$articles = [];
$art_results = $db->query("
    SELECT id, title FROM kb_articles 
    WHERE title LIKE ? OR content LIKE ? 
    ORDER BY id DESC LIMIT 5
", ["%{$query}%", "%{$query}%"])->results();

foreach ($art_results as $row) {
    $articles[] = [
        'title' => $row->title,
        'url' => 'knowledge_base.php?article_id=' . $row->id,
        'icon' => 'bi-file-earmark-text'
    ];
}

// 6. Output structured JSON response
echo json_encode([
    'shortcuts' => $shortcuts,
    'customers' => $customers,
    'tasks' => $tasks,
    'articles' => $articles
]);
exit;
