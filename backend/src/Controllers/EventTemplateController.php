<?php
/**
 * EventTemplateController.php
 * API for managing event templates (system + organizer-custom).
 *
 * Routes:
 *   GET    /event-templates                     → list all templates
 *   GET    /event-templates/:key                → get template detail with skills/agents
 *   POST   /event-templates/:key/apply          → apply template to an event
 *   POST   /event-templates/:key/clone          → clone system template for organizer
 *   POST   /event-templates/:key/toggle-skill   → toggle a skill in custom template
 */

require_once __DIR__ . '/../Services/EventTemplateService.php';

use EnjoyFun\Services\EventTemplateService;

function dispatch(string $method, ?string $id, ?string $sub, ?string $subId, array $body, array $query): void
{
    $user = authMiddleware();
    $db = Database::getInstance();
    $organizerId = (int)($user['organizer_id'] ?? 0);

    if ($organizerId <= 0) {
        jsonError('Organizador nao identificado.', 403);
    }

    setCurrentRequestActor($user);

    // GET /event-templates → list
    if ($method === 'GET' && $id === null) {
        $templates = EventTemplateService::listTemplates($db, $organizerId);
        jsonSuccess($templates, 'Templates carregados.');
    }

    // GET /event-templates/:key → detail
    if ($method === 'GET' && $id !== null && $sub === null) {
        $template = EventTemplateService::getTemplate($db, $id, $organizerId);
        if ($template === null) {
            jsonError('Template nao encontrado.', 404);
        }
        jsonSuccess($template, 'Template carregado.');
    }

    // POST /event-templates/:key/apply → apply to event
    if ($method === 'POST' && $id !== null && $sub === 'apply') {
        $eventId = (int)($body['event_id'] ?? 0);
        if ($eventId <= 0) {
            jsonError('event_id obrigatorio.', 422);
        }

        $result = EventTemplateService::applyTemplateToEvent($db, $organizerId, $eventId, $id);
        if (!$result['applied']) {
            jsonError('Nao foi possivel aplicar o template: ' . ($result['reason'] ?? 'unknown'), 400);
        }

        jsonSuccess($result, 'Template aplicado ao evento.');
    }

    // POST /event-templates/:key/clone → clone for organizer
    if ($method === 'POST' && $id !== null && $sub === 'clone') {
        $customLabel = trim((string)($body['label'] ?? ''));

        try {
            $result = EventTemplateService::cloneTemplateForOrganizer($db, $organizerId, $id, $customLabel);
            jsonSuccess($result, 'Template clonado com sucesso.', 201);
        } catch (\RuntimeException $e) {
            jsonError($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // POST /event-templates/:key/toggle-skill → toggle skill
    if ($method === 'POST' && $id !== null && $sub === 'toggle-skill') {
        $skillKey = trim((string)($body['skill_key'] ?? ''));
        $enable = (bool)($body['enable'] ?? true);

        if ($skillKey === '') {
            jsonError('skill_key obrigatorio.', 422);
        }

        try {
            $result = EventTemplateService::toggleSkillInCustomTemplate($db, $organizerId, $id, $skillKey, $enable);
            jsonSuccess($result, $enable ? 'Skill ativada.' : 'Skill desativada.');
        } catch (\RuntimeException $e) {
            jsonError($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    jsonError('Rota nao encontrada em event-templates.', 404);
}
