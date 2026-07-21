-- ==========================================
-- SYNCDESK SYSTEM SCHEMA - PHASE 1
-- Compatibility: MySQL 8+
-- All tables and columns are defined in English
-- ==========================================

-- 1. Customers Table
CREATE TABLE IF NOT EXISTS `customers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `company_name` VARCHAR(255) NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1, -- 1 = Active, 0 = Inactive
  `support_login` VARCHAR(255) DEFAULT NULL,
  `support_password` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 2. Customer Agent Pivot Table (Many-to-Many relationship between agents and customers)
CREATE TABLE IF NOT EXISTS `customer_agent` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT NOT NULL,
  `user_id` INT NOT NULL, -- User ID from UserSpice 'users' table
  `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_ca_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ca_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_customer_user` (`customer_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Assets Table (Includes JSON meta-field for dynamic structures)
CREATE TABLE IF NOT EXISTS `assets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `type` ENUM('meta_bm', 'n8n_workflow', 'ia_instance', 'other') NOT NULL,
  `settings` JSON DEFAULT NULL, -- Storing webhooks, IDs, credentials etc.
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_assets_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tasks Table
CREATE TABLE IF NOT EXISTS `tasks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT NOT NULL,
  `assigned_to` INT NOT NULL, -- User ID from UserSpice 'users' table
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `priority` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  `status` ENUM('pending', 'in_progress', 'completed') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_tasks_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tasks_user` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- SEED DATA FOR DEMONSTRATION
-- ==========================================

-- Insert Agent permission if not exists
INSERT IGNORE INTO `permissions` (`id`, `name`) VALUES (3, 'Agent');

-- Create a Test Agent User (ID = 2) if not exists
-- The password hash corresponds to 'password' (hashed via PASSWORD_BCRYPT)
INSERT IGNORE INTO `users` (
  `id`, `permissions`, `email`, `username`, `password`, `fname`, `lname`, 
  `email_verified`, `account_owner`, `created`, `active`
) VALUES (
  2, 1, 'agent@syncdesk.com', 'agent', '$2y$10$oY753S0W.hQ09Q9HjF.T.ObwD0Y.V28iY.n8.2Zf5wL9B/R2YpW.e', 'Carlos', 'Oliveira',
  1, 1, NOW(), 1
);

-- Map permissions for the agent: User (1) and Agent (3)
INSERT IGNORE INTO `user_permission_matches` (`user_id`, `permission_id`) VALUES 
(2, 1),
(2, 3);

-- Insert sample customers
INSERT INTO `customers` (`id`, `name`, `company_name`, `status`) VALUES
(1, 'Empresa ABC', 'Empresa ABC Ltda', 1),
(2, 'Tech Solutions', 'Tech Solutions Internacional', 1),
(3, 'Inova Corp', 'Inova Corp Tecnologia', 1),
(4, 'Startup Tech', 'Startup Tech Aceleração', 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `company_name`=VALUES(`company_name`), `status`=VALUES(`status`);

-- Link the Agent (user_id = 2) to customers 1, 2, and 3
-- Customer 4 remains isolated (not visible to agent)
INSERT INTO `customer_agent` (`customer_id`, `user_id`) VALUES
(1, 2),
(2, 2),
(3, 2)
ON DUPLICATE KEY UPDATE `assigned_at`=NOW();

-- Insert assets for customers (various types with JSON metadata)
INSERT INTO `assets` (`id`, `customer_id`, `name`, `type`, `settings`) VALUES
(1, 1, 'Integração Lead CRM', 'n8n_workflow', '{"webhook_url": "https://n8n.syncdesk.com/webhook/abc-lead", "active": true, "trigger": "Webhook Lead"}'),
(2, 1, 'BM Principal Ads', 'meta_bm', '{"bm_id": "120938102938", "ad_accounts": ["Empresa ABC - Anúncios"], "business_manager": "ABC Ads Manager"}'),
(3, 2, 'Assistente WhatsApp GPT-4', 'ia_instance', '{"agent_id": "asst_89231892", "model": "gpt-4o", "phone": "+5511999999999", "status": "active"}'),
(4, 3, 'Acesso API Hubspot', 'other', '{"api_url": "https://api.hubapi.com", "scopes": ["contacts", "deals", "companies"], "rate_limit": 100}'),
(5, 4, 'BM Secundária Ads', 'meta_bm', '{"bm_id": "88371928371", "ad_accounts": ["Startup Tech - Principal"]}')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `type`=VALUES(`type`), `settings`=VALUES(`settings`);

-- Insert tasks and assign to either Agent (2) or Admin (1)
INSERT INTO `tasks` (`id`, `customer_id`, `assigned_to`, `title`, `description`, `priority`, `status`) VALUES
(1, 1, 2, 'Configurar integração com API', 'Ajustar os campos personalizados do n8n para a Empresa ABC de forma a garantir a entrada correta dos Leads.', 'high', 'pending'),
(2, 2, 2, 'Revisar documentação do sistema', 'Revisar e atualizar a documentação técnica do sistema incluindo novos módulos e APIs.', 'medium', 'in_progress'),
(3, 3, 2, 'Treinar equipe do cliente', 'Realizar treinamento sobre o uso dos novos fluxos de IA para a equipe da Inova Corp.', 'low', 'pending'),
(4, 4, 1, 'Testar funcionalidades novas', 'Homologar novos fluxos de IA e n8n na Startup Tech para o processo de onboarding.', 'low', 'pending'),
(5, 1, 2, 'Configurar relatórios personalizados', 'Gerar relatórios de desempenho dos fluxos n8n da Empresa ABC para exportação mensal.', 'medium', 'in_progress'),
(6, 2, 2, 'Migrar dados do sistema antigo', 'Importar dados de clientes antigos para a base de dados ativa da Tech Solutions de forma segura.', 'high', 'completed')
ON DUPLICATE KEY UPDATE `customer_id`=VALUES(`customer_id`), `assigned_to`=VALUES(`assigned_to`), `title`=VALUES(`title`), `description`=VALUES(`description`), `priority`=VALUES(`priority`), `status`=VALUES(`status`);

-- 5. System Settings Table (API Keys and Configurations)
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(255) NOT NULL UNIQUE,
  `setting_value` TEXT DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default API keys settings
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('openai_api_key', '', 'Chave de API da OpenAI para processamento de tarefas em lote'),
('gemini_api_key', '', 'Chave de API do Gemini para processamento de tarefas em lote');

-- 6. WhatsApp Numbers Table (Official Sync WhatsApp Numbers)
CREATE TABLE IF NOT EXISTS `whatsapp_numbers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `phone_number` VARCHAR(50) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `connected_to` VARCHAR(255) DEFAULT NULL,
  `connection_status` ENUM('connected', 'disconnected') NOT NULL DEFAULT 'connected',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default Sync WhatsApp Numbers
INSERT INTO `whatsapp_numbers` (`id`, `phone_number`, `name`, `connected_to`, `connection_status`, `notes`) VALUES
(1, '+55 11 98888-7777', 'Sync Desk - Atendimento Comercial', 'Evolution API - Instância Principal', 'connected', 'Número oficial para captação de novos clientes e vendas.'),
(2, '+55 11 97777-6666', 'Sync Desk - Suporte Técnico', 'n8n VPS - Workflow Ticket Bot', 'connected', 'Número integrado ao bot de abertura automatizada de chamados.'),
(3, '+55 11 96666-5555', 'Sync Desk - Notificações Internas', 'Z-API / Server 02', 'disconnected', 'Número em manutenção para envio de notificações de sistema.')
ON DUPLICATE KEY UPDATE `phone_number`=VALUES(`phone_number`), `name`=VALUES(`name`), `connected_to`=VALUES(`connected_to`), `connection_status`=VALUES(`connection_status`);



