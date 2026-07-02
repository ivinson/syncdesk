# Contexto do Sistema: Gestão Interna de Agência de projetso : o nome é SyncDesk

## 1. Visão Geral
Este sistema(SyncDesk) destina-se à gestão interna de uma empresa de tecnologia focada em serviços, automações (n8n), Inteligência Artificial e gestão de Business Managers (BM) da Meta para clientes. O objetivo principal é centralizar a operação, dando aos atendentes uma visão clara das suas obrigações diárias e dos ativos de cada cliente.

## 2. Stack Tecnológica (Mandatória)
- **Core & Auth:** UserSpice (PHP 8+) - Reutilizar o máximo de estrutura nativa: gestão de utilizadores, níveis de permissão (Permissions/User Levels), segurança de páginas e APIs internas.
- **Backend:** PHP 8+ nativo, estruturado de forma simples, limpa e performativa.
- **Base de Dados:** MySQL 8 (tirando partido de JSON fields se necessário para configurações dinâmicas de ativos).
- **Frontend:** Bootstrap 5 (Layout minimalista, focado em usabilidade, tabelas densas e dashboards limpos).

## 3. Arquitetura de Permissões & Multitenancy (Visão do Atendente)
- **Nível Administrador/Gestor:** Visão macro de todos os clientes, projetos, tarefas e cargas de trabalho de todos os atendentes.
- **Nível Atendente:** Visão estrita e isolada. O atendente X apenas visualiza os clientes aos quais está explicitamente associado e as respetivas tarefas e ativos.

## 4. Entidades Principais (Fase 1)
- **Clientes (Users/Companies):** Cadastro do cliente corporativo.
- **Ativos (Assets):** Vinculados ao cliente. Podem ser do tipo: `BM (Meta)`, `Fluxo n8n`, `Instância de IA`, `Acesso de Serviço`. Guardar metadados úteis (ID da BM, Links de Webhooks, credenciais de monitorização).
- **Tarefas (Tasks):** Vinculadas a um Cliente e atribuídas a um Atendente. Possuem status, prioridade e descrição.