<?php

namespace Onekana\Api\Controllers;

use Onekana\Api\Approvals\ApprovalImporter;
use Onekana\Api\Http\HttpException;
use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;
use Onekana\Api\Repositories\ApprovalRepository;
use Onekana\Api\Repositories\ResourceRepository;
use Onekana\Api\Repositories\UserRepository;
use Onekana\Api\Support\Clock;

final class ApprovalController
{
    private const TRANSITIONS = [
        'pending' => ['in_review'],
        'in_review' => ['needs_information', 'approved', 'rejected'],
        'needs_information' => ['in_review', 'rejected'],
        'approved' => ['archived'],
        'rejected' => ['archived'],
        'archived' => ['in_review'],
    ];

    public function __construct(
        private readonly ApprovalRepository $approvals,
        private readonly ApprovalImporter $importer,
        private readonly UserRepository $users,
        private readonly ResourceRepository $resources,
    ) {}

    public function overview(Request $request): Response
    {
        return Response::json(['data' => $this->approvals->overview($this->tenant($request))]);
    }

    public function index(Request $request): Response
    {
        $result = $this->approvals->list($this->tenant($request), is_array($request->query()) ? $request->query() : []);
        return Response::json($result);
    }

    public function show(Request $request, int $id): Response
    {
        $case = $this->case($request, $id);
        $case['comments'] = $this->approvals->comments($this->tenant($request), $id);
        $case['events'] = $this->approvals->events($this->tenant($request), $id);
        return Response::json(['data' => $case]);
    }

    public function store(Request $request): Response
    {
        $resourceType = (string) $request->input('resourceType', '');
        if (! in_array($resourceType, ApprovalRepository::RESOURCE_TYPES, true)) $this->invalid('resourceType', 'Type de dossier invalide.');
        $externalId = trim((string) $request->input('externalId', ''));
        $title = trim((string) $request->input('title', ''));
        if ($externalId === '') $this->invalid('externalId', 'La ressource est obligatoire.');
        if ($title === '') $this->invalid('title', 'Le titre est obligatoire.');
        $priority = (string) $request->input('priority', 'normal');
        if (! in_array($priority, ApprovalRepository::PRIORITIES, true)) $this->invalid('priority', 'Priorité invalide.');
        $tenantId = $this->tenant($request); $userId = $this->userId($request);
        $subject = $this->approvals->upsertSubject($tenantId, [
            'sourceSystem' => (string) $request->input('sourceSystem', str_starts_with($resourceType, 'agency_') ? 'agency' : 'internal'),
            'resourceType' => $resourceType, 'externalId' => $externalId, 'title' => $title,
            'subtitle' => $request->input('subtitle'), 'companyExternalId' => $request->input('companyExternalId'),
            'companyName' => $request->input('companyName'), 'snapshot' => is_array($request->input('snapshot')) ? $request->input('snapshot') : [],
            'sourceCreatedAt' => $request->input('sourceCreatedAt'),
        ]);
        $settings = $this->approvals->settings($tenantId);
        $hours = match ($resourceType) { 'agency_contact', 'agency_request' => $settings['requestDueHours'], 'agency_campaign' => $settings['campaignDueHours'], 'agency_user' => $settings['userDueHours'], default => $settings['documentDueHours'] };
        $dueAt = $request->input('dueAt') ?: gmdate('Y-m-d H:i:s', time() + ((int) $hours * 3600));
        $before = $this->approvals->findByResource($tenantId, $resourceType, $externalId);
        $case = $this->approvals->createCase($tenantId, (int) $subject['id'], $priority, null, (string) $dueAt, $userId, 'flagged');
        return Response::json(['data' => $case], $before ? 200 : 201);
    }

    public function update(Request $request, int $id): Response
    {
        $case = $this->case($request, $id);
        $changes = [];
        if ($request->input('priority') !== null) {
            $priority = (string) $request->input('priority');
            if (! in_array($priority, ApprovalRepository::PRIORITIES, true)) $this->invalid('priority', 'Priorité invalide.');
            $changes['priority'] = $priority;
        }
        if ($request->input('assignedTo') !== null) {
            $assignedTo = $request->input('assignedTo');
            if ($assignedTo === '' || $assignedTo === null) $changes['assignedTo'] = null;
            else {
                $assignee = $this->users->findById((int) $assignedTo);
                if (! $assignee || (int) ($assignee['tenant_id'] ?? 0) !== $this->tenant($request) || ! $this->users->isActive($assignee)) $this->invalid('assignedTo', 'Responsable invalide.');
                $changes['assignedTo'] = (int) $assignedTo;
            }
        }
        if ($request->input('dueAt') !== null) $changes['dueAt'] = $request->input('dueAt') ?: null;
        $updated = $this->approvals->updateCase($this->tenant($request), $id, $changes, (int) $request->input('version', $case['version']), $this->userId($request));
        if (($case['assignedTo'] ?? null) !== ($updated['assignedTo'] ?? null) && $updated['assignedTo']) $this->notify($this->tenant($request), $updated, 'Dossier assigné', 'Un dossier de validation vous a été confié.');
        return Response::json(['data' => $updated]);
    }

    public function transition(Request $request, int $id): Response
    {
        $case = $this->case($request, $id); $target = (string) $request->input('status', '');
        if (! in_array($target, self::TRANSITIONS[$case['status']] ?? [], true)) $this->invalid('status', 'Cette transition n’est pas autorisée.');
        $reason = trim((string) $request->input('reason', ''));
        if (in_array($target, ['needs_information', 'rejected'], true) && $reason === '') $this->invalid('reason', 'Un motif est obligatoire.');
        if ($case['status'] === 'archived' && $target === 'in_review') {
            if ($reason === '') $this->invalid('reason', 'Un motif de réouverture est obligatoire.');
            if (! $this->users->hasPermission($request->get('user'), 'approvals.manage')) throw new HttpException(403, 'Vos droits ne permettent pas de rouvrir ce dossier.');
        }
        if (in_array($target, ['approved', 'rejected'], true)) {
            $assigned = $case['assignedTo'];
            $canManage = $this->users->hasPermission($request->get('user'), 'approvals.manage');
            if ($assigned && (int) $assigned !== $this->userId($request) && ! $canManage) throw new HttpException(403, 'Seul le responsable du dossier peut prendre cette décision.');
            $domainPermission = match ($case['resourceType']) {
                'document' => 'operations.manage',
                'agency_user' => 'administration.manage',
                default => 'sales.manage',
            };
            if (! $this->users->hasPermission($request->get('user'), $domainPermission) && ! $canManage) throw new HttpException(403, 'Vos droits ne permettent pas cette décision.');
        }
        $updated = $this->approvals->transition($this->tenant($request), $id, $target, $reason, (int) $request->input('version', $case['version']), $this->userId($request));
        $this->notify($this->tenant($request), $updated, 'Dossier mis à jour', 'Le dossier « '.$updated['title'].' » est maintenant '.str_replace('_', ' ', $target).'.');
        return Response::json(['data' => $updated]);
    }

    public function comments(Request $request, int $id): Response
    {
        $this->case($request, $id);
        return Response::json(['data' => $this->approvals->comments($this->tenant($request), $id)]);
    }

    public function addComment(Request $request, int $id): Response
    {
        $body = trim((string) $request->input('body', ''));
        if ($body === '' || mb_strlen($body) > 3000) $this->invalid('body', 'Le commentaire doit contenir entre 1 et 3000 caractères.');
        return Response::json(['data' => $this->approvals->addComment($this->tenant($request), $id, $this->userId($request), $body)], 201);
    }

    public function events(Request $request, int $id): Response
    {
        $this->case($request, $id);
        return Response::json(['data' => $this->approvals->events($this->tenant($request), $id)]);
    }

    public function import(Request $request): Response
    {
        return Response::json(['data' => $this->importer->import($this->tenant($request), $this->userId($request))]);
    }

    public function settings(Request $request): Response { return Response::json(['data' => $this->approvals->settings($this->tenant($request))]); }

    public function saveSettings(Request $request): Response
    {
        $values = [];
        foreach (['requestDueHours', 'campaignDueHours', 'userDueHours', 'documentDueHours'] as $field) { $value = (int) $request->input($field, 0); if ($value < 1 || $value > 720) $this->invalid($field, 'Le délai doit être compris entre 1 et 720 heures.'); $values[$field] = $value; }
        $days = (int) $request->input('importSinceDays', 30); if ($days < 1 || $days > 365) $this->invalid('importSinceDays', 'La période doit être comprise entre 1 et 365 jours.'); $values['importSinceDays'] = $days;
        return Response::json(['data' => $this->approvals->saveSettings($this->tenant($request), $values)]);
    }

    public function audit(Request $request): Response { return Response::json(['data' => $this->approvals->audit($this->tenant($request), (int) $request->query('limit', 100))]); }

    public function assignees(Request $request): Response { return Response::json(['data' => $this->users->listForTenant($this->tenant($request))]); }

    private function case(Request $request, int $id): array { return $this->approvals->find($this->tenant($request), $id) ?? throw new HttpException(404, 'Dossier introuvable.'); }
    private function tenant(Request $request): int { return (int) ($request->get('user')['tenant_id'] ?? throw new HttpException(403, 'Organisation non disponible.')); }
    private function userId(Request $request): int { return (int) ($request->get('user')['id'] ?? throw new HttpException(401, 'Session expirée.')); }
    private function invalid(string $field, string $message): never { throw new HttpException(422, 'Les informations fournies sont invalides.', [$field => [$message]]); }

    private function notify(int $tenantId, array $case, string $title, string $message): void
    {
        $this->resources->create('notifications', ['title' => $title, 'message' => $message, 'caseId' => $case['id'], 'type' => 'approval', 'createdAt' => Clock::iso()], $tenantId);
    }
}
