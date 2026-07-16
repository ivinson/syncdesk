<?php
// Initialize UserSpice security and environment
require_once 'users/init.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($user) || !$user->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado.']);
    exit;
}

$user_id = $user->data()->id;
$is_admin = hasPerm([2], $user_id);
$db = DB::getInstance();

// Parse input body
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);
if (empty($data)) {
    $data = $_POST;
}

$action = isset($data['action']) ? trim($data['action']) : '';

if ($action === 'analyze') {
    $text = isset($data['text']) ? trim($data['text']) : '';
    $audio = isset($data['audio']) ? trim($data['audio']) : '';
    $audio_mime = isset($data['audio_mime']) ? trim($data['audio_mime']) : '';
    $default_customer_id = isset($data['default_customer_id']) ? (int)$data['default_customer_id'] : 0;
    $default_assigned_to = isset($data['default_assigned_to']) ? (int)$data['default_assigned_to'] : 0;

    if (empty($text) && empty($audio)) {
        echo json_encode(['success' => false, 'message' => 'O texto ou o áudio para análise não pode estar vazio.']);
        exit;
    }

    // Get active customers for the current user
    if ($is_admin) {
        $customers_query = $db->query("SELECT id, name, company_name FROM customers WHERE status = 1 ORDER BY name ASC");
    } else {
        $customers_query = $db->query("SELECT c.id, c.name, c.company_name FROM customers c JOIN customer_agent ca ON c.id = ca.customer_id WHERE c.status = 1 AND ca.user_id = ? ORDER BY c.name ASC", [$user_id]);
    }
    $customers = $customers_query->results();

    // Get active agents
    $agents_query = $db->query("SELECT id, fname, lname, username FROM users WHERE active = 1 ORDER BY fname ASC, username ASC");
    $agents = $agents_query->results();

    // Retrieve keys from system_settings database table
    $openaiKey = "";
    $geminiKey = "";

    // Check if table system_settings exists
    $table_check = $db->query("SHOW TABLES LIKE 'system_settings'");
    if ($table_check->count() > 0) {
        $openai_query = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'openai_api_key' LIMIT 1");
        if ($openai_query->count() > 0) {
            $openaiKey = trim($openai_query->first()->setting_value);
        }
        
        $gemini_query = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_api_key' LIMIT 1");
        if ($gemini_query->count() > 0) {
            $geminiKey = trim($gemini_query->first()->setting_value);
        }
    }

    // Fallbacks
    if (empty($openaiKey)) {
        $openaiKey = getenv('OPENAI_API_KEY');
        if (empty($openaiKey) && defined('OPENAI_API_KEY')) {
            $openaiKey = OPENAI_API_KEY;
        }
    }

    if (empty($geminiKey)) {
        $geminiKey = getenv('GEMINI_API_KEY');
        if (empty($geminiKey) && defined('GEMINI_API_KEY')) {
            $geminiKey = GEMINI_API_KEY;
        }
    }

    if (empty($openaiKey) && empty($geminiKey)) {
        echo json_encode([
            'success' => false,
            'message' => 'Nenhuma chave de API configurada. Por favor, acesse a página de Integrações e cadastre suas chaves da OpenAI ou do Gemini.'
        ]);
        exit;
    }

    // Format customers and agents lists for the prompt
    $customers_list = [];
    foreach ($customers as $c) {
        $customers_list[] = "ID: {$c->id} - Nome: {$c->name} (Empresa: {$c->company_name})";
    }
    $customers_context = implode("\n", $customers_list);

    $agents_list = [];
    foreach ($agents as $a) {
        $fullName = trim($a->fname . ' ' . $a->lname) ?: $a->username;
        $agents_list[] = "ID: {$a->id} - Nome: {$fullName} (Username: {$a->username})";
    }
    $agents_context = implode("\n", $agents_list);

    // Build the prompt for Gemini
    $prompt = "Você é um assistente especializado em gestão de projetos da agência SyncDesk.\n";
    if (!empty($audio)) {
        $prompt .= "Sua tarefa é analisar o áudio fornecido e extrair todas as tarefas acionáveis contidas nele.\n\n";
    } else {
        $prompt .= "Sua tarefa é analisar o texto abaixo (como atas de reuniões, e-mails, notas ou conversas) e extrair todas as tarefas acionáveis.\n\n";
    }
    $prompt .= "Para cada tarefa identificada, você deve mapear o Cliente (Customer) e o Atendente Responsável (Agent) usando estritamente as listas abaixo. Se você não conseguir identificar com clareza o cliente ou o responsável para uma tarefa específica, use null ou os IDs padrões informados.\n\n" .
              "CLIENTES DISPONÍVEIS (Selecione apenas a partir desta lista):\n" . (empty($customers_context) ? "Nenhum cliente disponível" : $customers_context) . "\n\n" .
              "ATENDENTES/RESPONSÁVEIS DISPONÍVEIS (Selecione apenas a partir desta lista):\n" . (empty($agents_context) ? "Nenhum responsável disponível" : $agents_context) . "\n\n" .
              "PADRÕES EM CASO DE DÚVIDA:\n" .
              "- ID de Cliente Padrão: " . ($default_customer_id ?: "Nenhum") . "\n" .
              "- ID de Responsável Padrão: " . ($default_assigned_to ?: "Nenhum") . "\n\n" .
              "DIRETRIZES DE RETORNO:\n" .
              "1. Retorne APENAS um array JSON válido. Sem explicações, sem texto extra, sem formatação markdown (como blocos ```json).\n" .
              "2. Cada objeto no array deve conter exatamente estas propriedades:\n" .
              "   - 'title': Título curto e direto da tarefa em português (máx 80 caracteres).\n" .
              "   - 'description': Descrição detalhada do que fazer, em português.\n" .
              "   - 'priority': Prioridade sendo 'low' (baixa), 'medium' (média) ou 'high' (alta) com base no tom do texto.\n" .
              "   - 'customer_id': ID numérico do cliente selecionado da lista. Se não identificado e não houver padrão, retorne null.\n" .
              "   - 'assigned_to': ID numérico do responsável selecionado da lista. Se não identificado e não houver padrão, retorne null.\n\n";

    $aiText = "";

    if (!empty($openaiKey)) {
        // ==========================================
        // OPENAI INTEGRATION
        // ==========================================
        
        // 1. Check if we need to transcribe audio
        if (!empty($audio)) {
            // Save base64 audio to temp file
            $ext = 'webm';
            if (!empty($audio_mime)) {
                $mime_parts = explode('/', $audio_mime);
                if (count($mime_parts) === 2) {
                    $ext = $mime_parts[1];
                    $ext = explode(';', $ext)[0];
                }
            }

            $audio_data = base64_decode($audio);
            if ($audio_data === false) {
                echo json_encode(['success' => false, 'message' => 'Falha ao decodificar dados de áudio em Base64 para OpenAI.']);
                exit;
            }

            $temp_dir = __DIR__ . '/uploads/temp';
            if (!is_dir($temp_dir)) {
                mkdir($temp_dir, 0777, true);
            }
            $temp_filename = $temp_dir . '/transcription_' . uniqid() . '.' . $ext;
            file_put_contents($temp_filename, $audio_data);

            // Call Whisper API
            $whisper_url = "https://api.openai.com/v1/audio/transcriptions";
            $cFile = new CURLFile($temp_filename, $audio_mime ?: 'audio/webm', basename($temp_filename));
            
            $whisper_payload = [
                'file' => $cFile,
                'model' => 'whisper-1'
            ];

            $ch = curl_init($whisper_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $whisper_payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $openaiKey
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $whisper_response = curl_exec($ch);
            $whisper_httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $whisper_curlError = curl_error($ch);
            curl_close($ch);

            // Delete temp file
            if (file_exists($temp_filename)) {
                unlink($temp_filename);
            }

            if ($whisper_curlError) {
                echo json_encode(['success' => false, 'message' => 'Erro de conexão com o OpenAI Whisper: ' . $whisper_curlError]);
                exit;
            }

            if ($whisper_httpCode !== 200) {
                echo json_encode(['success' => false, 'message' => 'Erro da API OpenAI Whisper (HTTP ' . $whisper_httpCode . '): ' . $whisper_response]);
                exit;
            }

            $whisper_data = json_decode($whisper_response, true);
            $text = isset($whisper_data['text']) ? trim($whisper_data['text']) : '';

            if (empty($text)) {
                echo json_encode(['success' => false, 'message' => 'O áudio fornecido foi processado, mas nenhuma fala foi identificada.']);
                exit;
            }
        }

        // 2. Call OpenAI Chat Completion
        $openai_url = "https://api.openai.com/v1/chat/completions";
        
        // Adapt the prompt to ensure OpenAI returns a tasks object
        $openai_prompt = $prompt . "\n\nRetorne as tarefas no formato JSON com um objeto contendo uma chave 'tasks' que é um array com as tarefas extraídas.";
        $openai_prompt .= "\n\nTEXTO PARA ANÁLISE:\n" . $text;

        $messages = [
            [
                'role' => 'system',
                'content' => 'Você é um assistente especializado em gestão de projetos da agência SyncDesk. Retorne sempre um objeto JSON contendo um array de tarefas na chave \'tasks\'.'
            ],
            [
                'role' => 'user',
                'content' => $openai_prompt
            ]
        ];

        $openai_payload = [
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.1
        ];

        $ch = curl_init($openai_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($openai_payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $openaiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            echo json_encode(['success' => false, 'message' => 'Erro de conexão com a API da OpenAI: ' . $curlError]);
            exit;
        }

        if ($httpCode !== 200) {
            echo json_encode(['success' => false, 'message' => 'Erro da API da OpenAI (HTTP ' . $httpCode . '): ' . $response]);
            exit;
        }

        $resDecoded = json_decode($response, true);
        if (!isset($resDecoded['choices'][0]['message']['content'])) {
            echo json_encode(['success' => false, 'message' => 'A resposta da API da OpenAI veio em formato inesperado.']);
            exit;
        }

        $aiText = trim($resDecoded['choices'][0]['message']['content']);

    } else {
        // ==========================================
        // GEMINI INTEGRATION (FALLBACK)
        // ==========================================
        
        if (empty($audio)) {
            $prompt .= "TEXTO PARA ANÁLISE:\n" . $text;
        }

        $model = "gemini-3.1-flash-lite";
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($geminiKey);

        $parts = [];
        if (!empty($audio)) {
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $audio_mime ?: 'audio/webm',
                    'data' => $audio
                ]
            ];
        }
        $parts[] = ['text' => $prompt];

        $payload = [
            'contents' => [
                [
                    'parts' => $parts
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json'
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            echo json_encode(['success' => false, 'message' => 'Erro de conexão com a API do Gemini: ' . $curlError]);
            exit;
        }

        if ($httpCode !== 200) {
            echo json_encode(['success' => false, 'message' => 'Erro da API do Gemini (HTTP ' . $httpCode . '): ' . $response]);
            exit;
        }

        $resDecoded = json_decode($response, true);
        if (!isset($resDecoded['candidates'][0]['content']['parts'][0]['text'])) {
            echo json_encode(['success' => false, 'message' => 'A resposta da API do Gemini veio em formato inesperado.']);
            exit;
        }

        $aiText = trim($resDecoded['candidates'][0]['content']['parts'][0]['text']);
        if (preg_match('/^```json(.*)```$/s', $aiText, $matches)) {
            $aiText = trim($matches[1]);
        }
    }

    // ==========================================
    // PARSING RESULTS
    // ==========================================
    $tasks = json_decode($aiText, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro ao decodificar o JSON retornado pela IA: ' . json_last_error_msg(),
            'raw' => $aiText
        ]);
        exit;
    }

    // Extract list from wrapper object if needed (e.g. from OpenAI)
    if (isset($tasks['tasks']) && is_array($tasks['tasks'])) {
        $tasks = $tasks['tasks'];
    }

    // Return tasks, plus customer and agent list so the frontend can populate selections
    echo json_encode([
        'success' => true,
        'tasks' => $tasks,
        'customers' => $customers,
        'agents' => $agents
    ]);
    exit;
}

else if ($action === 'save') {
    $tasks = isset($data['tasks']) ? $data['tasks'] : [];
    if (empty($tasks) || !is_array($tasks)) {
        echo json_encode(['success' => false, 'message' => 'Nenhuma tarefa informada para salvar.']);
        exit;
    }

    // Retrieve lists of valid customer IDs and agent IDs for the logged-in user to prevent injection
    if ($is_admin) {
        $allowed_cust_res = $db->query("SELECT id FROM customers WHERE status = 1")->results();
    } else {
        $allowed_cust_res = $db->query("SELECT customer_id as id FROM customer_agent WHERE user_id = ?", [$user_id])->results();
    }
    $allowed_agent_res = $db->query("SELECT id FROM users WHERE active = 1")->results();

    $allowed_cust_ids = array_map(function($item) { return (int)$item->id; }, $allowed_cust_res);
    $allowed_agent_ids = array_map(function($item) { return (int)$item->id; }, $allowed_agent_res);

    $inserted_count = 0;
    $errors = [];

    // Save
    foreach ($tasks as $index => $t) {
        $title = isset($t['title']) ? trim($t['title']) : '';
        $description = isset($t['description']) ? trim($t['description']) : '';
        $priority = isset($t['priority']) ? trim($t['priority']) : 'medium';
        $customer_id = isset($t['customer_id']) ? (int)$t['customer_id'] : 0;
        $assigned_to = isset($t['assigned_to']) ? (int)$t['assigned_to'] : 0;

        $task_num = $index + 1;

        if (empty($title)) {
            $errors[] = "Tarefa #{$task_num}: Título é obrigatório.";
            continue;
        }

        if (!in_array($priority, ['low', 'medium', 'high'])) {
            $errors[] = "Tarefa #{$task_num}: Prioridade '{$priority}' inválida.";
            continue;
        }

        if (!in_array($customer_id, $allowed_cust_ids)) {
            $errors[] = "Tarefa #{$task_num}: Cliente selecionado inválido ou sem permissão de acesso.";
            continue;
        }

        if (!in_array($assigned_to, $allowed_agent_ids)) {
            // For non-admin, force assigned_to to themselves
            if (!$is_admin) {
                $assigned_to = $user_id;
            } else {
                $errors[] = "Tarefa #{$task_num}: Responsável selecionado inválido.";
                continue;
            }
        }

        // Insert task
        $insert_data = [
            'customer_id' => $customer_id,
            'assigned_to' => $assigned_to,
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
            'status' => 'pending'
        ];

        $ok = $db->insert('tasks', $insert_data);
        if ($ok) {
            $inserted_count++;
        } else {
            $errors[] = "Tarefa #{$task_num} ('{$title}'): Falha ao salvar no banco de dados.";
        }
    }

    if ($inserted_count > 0) {
        $msg = "{$inserted_count} tarefa(s) criada(s) com sucesso em lote!";
        if (!empty($errors)) {
            $msg .= " Porém ocorreram alguns erros: " . implode(" | ", $errors);
        }
        echo json_encode(['success' => true, 'message' => $msg]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Nenhuma tarefa pôde ser criada. Erros: ' . implode("<br>", $errors)]);
    }
    exit;
}

else {
    echo json_encode(['success' => false, 'message' => 'Ação inválida.']);
    exit;
}
