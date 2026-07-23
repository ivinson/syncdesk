<?php
// includes/notifications_helper.php - SyncDesk Notification Helper Service

if (!function_exists('getNotificationDb')) {
    function getNotificationDb() {
        return DB::getInstance();
    }
}

// Ensure database tables exist
function initNotificationTables() {
    $db = getNotificationDb();
    
    // 1. Table for User Notification Preferences
    $db->query("CREATE TABLE IF NOT EXISTS `user_notification_settings` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NOT NULL UNIQUE,
      `phone` VARCHAR(30) DEFAULT NULL,
      `notify_whatsapp` TINYINT DEFAULT 1,
      `notify_email` TINYINT DEFAULT 0,
      `notify_sms` TINYINT DEFAULT 0,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT `fk_uns_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // 2. Table system_settings self-healing
    $db->query("CREATE TABLE IF NOT EXISTS `system_settings` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `setting_key` VARCHAR(255) NOT NULL UNIQUE,
      `setting_value` TEXT DEFAULT NULL,
      `description` VARCHAR(255) DEFAULT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $defaults = [
        'whatsapp_api_mode' => ['official', 'Modo do Gateway WhatsApp: official ou qrcode'],
        'whatsapp_backend_url' => ['https://sync.triadgroup.com.br', 'URL Backend da API WhatsApp'],
        'whatsapp_api_token' => ['##triad@##neurosculpt', 'Token Bearer de autenticação da API WhatsApp'],
        'whatsapp_meta_template_name' => ['vars_001', 'Nome da template aprovada na Meta'],
        'whatsapp_meta_template_lang' => ['pt_BR', 'Idioma da template aprovada na Meta'],
        'whatsapp_open_ticket' => ['0', 'Opção de abrir ticket (0=Não abre ticket, 1=Abre ticket)'],
        'whatsapp_queue_id' => ['0', 'ID da fila do ticket (0=Mantém status)'],
        'whatsapp_notify_actor' => ['0', 'Enviar WhatsApp para quem criou/alterou (0=Não enviar para criador, 1=Enviar para criador)'],
        'portal_default_assignee' => ['1', 'ID do usuário padrão para recebimento de tarefas do portal de suporte']
    ];

    foreach ($defaults as $key => $val) {
        $check = $db->query("SELECT id FROM system_settings WHERE setting_key = ?", [$key]);
        if ($check->count() == 0) {
            $db->insert('system_settings', [
                'setting_key' => $key,
                'setting_value' => $val[0],
                'description' => $val[1]
            ]);
        }
    }
}

// Get setting value by key
function getSystemSetting($key, $default = '') {
    $db = getNotificationDb();
    $q = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1", [$key]);
    if ($q->count() > 0) {
        return $q->first()->setting_value;
    }
    return $default;
}

// Sanitize phone number to keep only numeric digits
function sanitizePhoneNumber($phone) {
    return preg_replace('/[^0-9]/', '', (string)$phone);
}

// Main function to dispatch WhatsApp notification to a user
function sendWhatsAppNotification($targetUserId, $actorUserId, $taskTitle, $actionType = 'alterou o status da tarefa', $extraDetail = '') {
    initNotificationTables();

    // 1. Check notify actor setting (0 = don't notify creator/actor, 1 = notify actor as well)
    $notifyActor = getSystemSetting('whatsapp_notify_actor', '0');
    if ($notifyActor == '0' && !empty($actorUserId) && (int)$targetUserId === (int)$actorUserId) {
        return ['success' => false, 'message' => 'Usuário executou a própria ação e a notificação de auto-ação está desativada.'];
    }

    $db = getNotificationDb();

    // 2. Fetch target user notification preferences
    $prefQ = $db->query("SELECT * FROM user_notification_settings WHERE user_id = ? LIMIT 1", [$targetUserId]);
    if ($prefQ->count() === 0) {
        return ['success' => false, 'message' => 'Configurações de notificação do usuário não encontradas.'];
    }

    $userPref = $prefQ->first();
    if (empty($userPref->notify_whatsapp) || (int)$userPref->notify_whatsapp !== 1) {
        return ['success' => false, 'message' => 'Notificações via WhatsApp desativadas pelo usuário.'];
    }

    $rawPhone = trim($userPref->phone ?? '');
    $phone = sanitizePhoneNumber($rawPhone);
    if (empty($phone)) {
        return ['success' => false, 'message' => 'Telefone do usuário não informado ou inválido.'];
    }

    // 3. Fetch actor name
    $actorName = 'Sistema';
    if (!empty($actorUserId)) {
        $actorQ = $db->query("SELECT fname, lname, username FROM users WHERE id = ? LIMIT 1", [$actorUserId]);
        if ($actorQ->count() > 0) {
            $u = $actorQ->first();
            $fullName = trim(($u->fname ?? '') . ' ' . ($u->lname ?? ''));
            $actorName = !empty($fullName) ? $fullName : $u->username;
        }
    }

    return dispatchWhatsAppApiMessage($phone, $actorName, $taskTitle, $actionType, $extraDetail);
}

// Dispatch WhatsApp notification to a customer contact (solicitante)
function sendContactWhatsAppNotification($contactId, $actorUserId, $taskTitle, $actionType = 'alterou o status da tarefa', $extraDetail = '') {
    initNotificationTables();
    $db = getNotificationDb();
    
    // Fetch contact details
    $contactQ = $db->query("SELECT * FROM customer_contacts WHERE id = ? LIMIT 1", [$contactId]);
    if ($contactQ->count() === 0) {
        return ['success' => false, 'message' => 'Solicitante não encontrado.'];
    }
    
    $contact = $contactQ->first();
    
    // Check if contact has a phone number
    $rawPhone = trim($contact->whatsapp ?? '');
    if (empty($rawPhone)) {
        return ['success' => false, 'message' => 'WhatsApp do solicitante não informado.'];
    }
    
    $phone = sanitizePhoneNumber($rawPhone);
    if (empty($phone)) {
        return ['success' => false, 'message' => 'WhatsApp do solicitante é inválido.'];
    }
    
    // Fetch actor name
    $actorName = 'Sistema';
    if (!empty($actorUserId)) {
        $actorQ = $db->query("SELECT fname, lname, username FROM users WHERE id = ? LIMIT 1", [$actorUserId]);
        if ($actorQ->count() > 0) {
            $u = $actorQ->first();
            $fullName = trim(($u->fname ?? '') . ' ' . ($u->lname ?? ''));
            $actorName = !empty($fullName) ? $fullName : $u->username;
        }
    }
    
    return dispatchWhatsAppApiMessage($phone, $actorName, $taskTitle, $actionType, $extraDetail);
}


// Low level API dispatcher
function dispatchWhatsAppApiMessage($phone, $var1Name, $var2TaskTitle, $actionDescription = '', $extraDetail = '') {
    $backendUrl = rtrim(getSystemSetting('whatsapp_backend_url', 'https://sync.triadgroup.com.br'), '/');
    $apiToken = getSystemSetting('whatsapp_api_token', '');
    $apiMode = getSystemSetting('whatsapp_api_mode', 'official');
    $templateName = getSystemSetting('whatsapp_meta_template_name', 'vars_001');
    $templateLang = getSystemSetting('whatsapp_meta_template_lang', 'pt_BR');
    $openTicket = (int)getSystemSetting('whatsapp_open_ticket', '0');
    $queueId = (int)getSystemSetting('whatsapp_queue_id', '0');

    if (empty($backendUrl) || empty($apiToken)) {
        return ['success' => false, 'message' => 'URL do Backend ou Token do WhatsApp não configurados nas Integrações.'];
    }

    $curl = curl_init();

    if ($apiMode === 'official') {
        $endpoint = $backendUrl . '/api/messages/sendMetaCustom';
        
        // Construct official template payload with 2 body parameters: 
        // 1st param: Name of who changed/action, 2nd param: Task name
        $payloadData = [
            'number' => $phone,
            'name' => $templateName,
            'language' => $templateLang,
            'openTicket' => $openTicket,
            'queueId' => $queueId,
            'template' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        [
                            'type' => 'text',
                            'text' => $var1Name
                        ],
                        [
                            'type' => 'text',
                            'text' => $var2TaskTitle
                        ]
                    ]
                ]
            ]
        ];
    } else {
        // Non-official QR Code API endpoint: /api/messages/send
        $endpoint = $backendUrl . '/api/messages/send';
        $messageText = "*SyncDesk* 🔔\n\n*{$var1Name}* {$actionDescription}: *{$var2TaskTitle}*";
        
        // Include comment/detail text if present
        if (!empty($extraDetail)) {
            $messageText .= "\n\n💬 _\"" . trim($extraDetail) . "\"_";
        }
        
        $payloadData = [
            'number' => $phone,
            'openTicket' => (string)$openTicket,
            'queueId' => (string)$queueId,
            'body' => $messageText
        ];
    }

    curl_setopt_array($curl, [
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($payloadData, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiToken
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($err) {
        return ['success' => false, 'message' => 'Erro cURL: ' . $err];
    }

    $json = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'message' => 'Notificação enviada com sucesso!', 'response' => $json];
    } else {
        return ['success' => false, 'message' => 'Erro na API WhatsApp (HTTP ' . $httpCode . '): ' . $response, 'response' => $json];
    }
}
