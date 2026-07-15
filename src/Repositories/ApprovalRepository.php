<?php

namespace Onekana\Api\Repositories;

use Onekana\Api\Http\HttpException;
use Onekana\Api\Support\Clock;
use Onekana\Api\Support\Json;
use PDO;

final class ApprovalRepository
{
    public const STATUSES = ['pending', 'in_review', 'needs_information', 'approved', 'rejected', 'archived'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const RESOURCE_TYPES = ['agency_user', 'agency_contact', 'agency_request', 'agency_campaign', 'document'];

    public function __construct(private readonly PDO $pdo) {}

    public function settings(int $tenantId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM approval_settings WHERE tenant_id = :tenant_id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId]);
        $row = $statement->fetch();
        if (! $row) {
            $now = Clock::now();
            $this->pdo->prepare('INSERT INTO approval_settings (tenant_id, request_due_hours, campaign_due_hours, user_due_hours, document_due_hours, import_since_days, created_at, updated_at) VALUES (:tenant_id, 24, 24, 48, 48, 30, :created_at, :updated_at)')
                ->execute(['tenant_id' => $tenantId, 'created_at' => $now, 'updated_at' => $now]);
            return $this->settings($tenantId);
        }

        return $this->presentSettings($row);
    }

    public function saveSettings(int $tenantId, array $values): array
    {
        $this->settings($tenantId);
        $statement = $this->pdo->prepare('UPDATE approval_settings SET request_due_hours = :request, campaign_due_hours = :campaign, user_due_hours = :user, document_due_hours = :document, import_since_days = :days, updated_at = :updated_at WHERE tenant_id = :tenant_id');
        $statement->execute([
            'request' => $values['requestDueHours'], 'campaign' => $values['campaignDueHours'],
            'user' => $values['userDueHours'], 'document' => $values['documentDueHours'],
            'days' => $values['importSinceDays'], 'updated_at' => Clock::now(), 'tenant_id' => $tenantId,
        ]);
        return $this->settings($tenantId);
    }

    public function recordImportStatus(int $tenantId, array $unavailable): void
    {
        $this->settings($tenantId);
        $this->pdo->prepare('UPDATE approval_settings SET last_import_at = :last_import_at, import_unavailable = :unavailable, updated_at = :updated_at WHERE tenant_id = :tenant_id')->execute([
            'last_import_at' => Clock::now(),
            'unavailable' => Json::encode(array_values($unavailable)),
            'updated_at' => Clock::now(),
            'tenant_id' => $tenantId,
        ]);
    }

    public function deadlineCandidates(int $tenantId): array
    {
        $soon = gmdate('Y-m-d H:i:s', time() + 21600);
        $statement = $this->pdo->prepare("SELECT c.id, c.due_at, s.title FROM approval_cases c INNER JOIN approval_subjects s ON s.id = c.subject_id WHERE c.tenant_id = :tenant_id AND c.due_at IS NOT NULL AND c.due_at <= :soon AND c.status NOT IN ('approved','rejected','archived') ORDER BY c.due_at");
        $statement->execute(['tenant_id' => $tenantId, 'soon' => $soon]);
        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $type = strtotime((string) $row['due_at']) < time() ? 'deadline_overdue' : 'deadline_soon';
            $exists = $this->pdo->prepare('SELECT 1 FROM approval_events WHERE tenant_id = :tenant_id AND case_id = :case_id AND event_type = :event_type LIMIT 1');
            $exists->execute(['tenant_id' => $tenantId, 'case_id' => $row['id'], 'event_type' => $type]);
            if (! $exists->fetchColumn()) {
                $items[] = ['caseId' => (int) $row['id'], 'title' => $row['title'], 'dueAt' => $row['due_at'], 'type' => $type];
            }
        }
        return $items;
    }

    public function recordDeadlineEvent(int $tenantId, int $caseId, string $type): void
    {
        $this->event($tenantId, $caseId, null, $type, null, ['notifiedAt' => Clock::now()], null);
    }

    public function upsertSubject(int $tenantId, array $subject): array
    {
        $existing = $this->findSubject($tenantId, $subject['sourceSystem'], $subject['resourceType'], (string) $subject['externalId']);
        $now = Clock::now();
        $params = [
            'tenant_id' => $tenantId, 'source_system' => $subject['sourceSystem'], 'resource_type' => $subject['resourceType'],
            'external_id' => (string) $subject['externalId'], 'title' => $subject['title'], 'subtitle' => $subject['subtitle'] ?? null,
            'company_external_id' => $subject['companyExternalId'] ?? null, 'company_name' => $subject['companyName'] ?? null,
            'snapshot' => Json::encode($subject['snapshot'] ?? []), 'source_created_at' => $subject['sourceCreatedAt'] ?? null,
            'last_seen_at' => $now,
        ];
        if ($existing) {
            $this->pdo->prepare('UPDATE approval_subjects SET title = :title, subtitle = :subtitle, company_external_id = :company_external_id, company_name = :company_name, snapshot = :snapshot, source_created_at = :source_created_at, last_seen_at = :last_seen_at WHERE id = :id AND tenant_id = :tenant_id')->execute([
                'title' => $params['title'], 'subtitle' => $params['subtitle'], 'company_external_id' => $params['company_external_id'],
                'company_name' => $params['company_name'], 'snapshot' => $params['snapshot'], 'source_created_at' => $params['source_created_at'],
                'last_seen_at' => $params['last_seen_at'], 'id' => $existing['id'], 'tenant_id' => $tenantId,
            ]);
        } else {
            $params['first_seen_at'] = $now;
            $this->pdo->prepare('INSERT INTO approval_subjects (tenant_id, source_system, resource_type, external_id, title, subtitle, company_external_id, company_name, snapshot, source_created_at, first_seen_at, last_seen_at) VALUES (:tenant_id, :source_system, :resource_type, :external_id, :title, :subtitle, :company_external_id, :company_name, :snapshot, :source_created_at, :first_seen_at, :last_seen_at)')->execute($params);
        }
        return $this->presentSubject($this->findSubject($tenantId, $subject['sourceSystem'], $subject['resourceType'], (string) $subject['externalId']));
    }

    public function createCase(int $tenantId, int $subjectId, string $priority, ?int $assignedTo, ?string $dueAt, ?int $createdBy, string $eventType = 'created'): array
    {
        $existing = $this->findCaseBySubject($tenantId, $subjectId);
        if ($existing) return $existing;

        $now = Clock::now();
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("INSERT INTO approval_cases (tenant_id, subject_id, status, priority, assigned_to, due_at, sync_status, version, created_by, created_at, updated_at) VALUES (:tenant_id, :subject_id, 'pending', :priority, :assigned_to, :due_at, 'local_only', 1, :created_by, :created_at, :updated_at)")->execute([
                'tenant_id' => $tenantId, 'subject_id' => $subjectId, 'priority' => $priority, 'assigned_to' => $assignedTo,
                'due_at' => $dueAt, 'created_by' => $createdBy, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->event($tenantId, $id, $createdBy, $eventType, null, ['status' => 'pending', 'priority' => $priority], null);
            $this->pdo->commit();
            return $this->find($tenantId, $id) ?? throw new \RuntimeException('Dossier introuvable après création.');
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function list(int $tenantId, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));
        [$where, $params] = $this->filters($tenantId, $filters);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM approval_cases c INNER JOIN approval_subjects s ON s.id = c.subject_id {$where}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $sql = "SELECT c.*, s.source_system, s.resource_type, s.external_id, s.title, s.subtitle, s.company_external_id, s.company_name, s.snapshot, s.source_created_at, u.name AS assignee_name FROM approval_cases c INNER JOIN approval_subjects s ON s.id = c.subject_id LEFT JOIN users u ON u.id = c.assigned_to {$where} ORDER BY CASE c.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END, CASE WHEN c.due_at IS NULL THEN 1 ELSE 0 END, c.due_at, c.id DESC LIMIT {$perPage} OFFSET ".(($page - 1) * $perPage);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return [
            'data' => array_map([$this, 'presentCase'], $statement->fetchAll()),
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    public function find(int $tenantId, int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT c.*, s.source_system, s.resource_type, s.external_id, s.title, s.subtitle, s.company_external_id, s.company_name, s.snapshot, s.source_created_at, u.name AS assignee_name FROM approval_cases c INNER JOIN approval_subjects s ON s.id = c.subject_id LEFT JOIN users u ON u.id = c.assigned_to WHERE c.tenant_id = :tenant_id AND c.id = :id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $row = $statement->fetch();
        return $row ? $this->presentCase($row) : null;
    }

    public function findByResource(int $tenantId, string $resourceType, string $externalId): ?array
    {
        $statement = $this->pdo->prepare('SELECT c.id FROM approval_cases c INNER JOIN approval_subjects s ON s.id = c.subject_id WHERE c.tenant_id = :tenant_id AND s.resource_type = :resource_type AND s.external_id = :external_id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId, 'resource_type' => $resourceType, 'external_id' => $externalId]);
        $id = $statement->fetchColumn();
        return $id === false ? null : $this->find($tenantId, (int) $id);
    }

    public function updateCase(int $tenantId, int $id, array $changes, int $expectedVersion, int $userId): array
    {
        $before = $this->find($tenantId, $id) ?? throw new HttpException(404, 'Dossier introuvable.');
        if ((int) $before['version'] !== $expectedVersion) throw new HttpException(409, 'Ce dossier a été modifié par un autre administrateur. Rechargez la page.');
        $allowed = ['priority' => 'priority', 'assignedTo' => 'assigned_to', 'dueAt' => 'due_at'];
        $sets = []; $params = ['tenant_id' => $tenantId, 'id' => $id, 'version' => $expectedVersion, 'updated_at' => Clock::now()];
        foreach ($allowed as $input => $column) {
            if (array_key_exists($input, $changes)) { $sets[] = "{$column} = :{$column}"; $params[$column] = $changes[$input]; }
        }
        if ($sets === []) return $before;
        $sets[] = 'version = version + 1'; $sets[] = 'updated_at = :updated_at';
        $statement = $this->pdo->prepare('UPDATE approval_cases SET '.implode(', ', $sets).' WHERE tenant_id = :tenant_id AND id = :id AND version = :version');
        $statement->execute($params);
        if ($statement->rowCount() !== 1) throw new HttpException(409, 'Ce dossier a été modifié par un autre administrateur. Rechargez la page.');
        $after = $this->find($tenantId, $id);
        $this->event($tenantId, $id, $userId, 'updated', $this->eventValues($before), $this->eventValues($after), null);
        return $after;
    }

    public function transition(int $tenantId, int $id, string $status, string $reason, int $expectedVersion, int $userId): array
    {
        $before = $this->find($tenantId, $id) ?? throw new HttpException(404, 'Dossier introuvable.');
        if ((int) $before['version'] !== $expectedVersion) throw new HttpException(409, 'Ce dossier a été modifié par un autre administrateur. Rechargez la page.');
        $decided = in_array($status, ['approved', 'rejected'], true);
        $assignedTo = $before['assignedTo'] ?: ($status === 'in_review' ? (string) $userId : null);
        $statement = $this->pdo->prepare('UPDATE approval_cases SET status = :status, assigned_to = :assigned_to, decision_reason = :reason, decided_by = :decided_by, decided_at = :decided_at, version = version + 1, updated_at = :updated_at WHERE tenant_id = :tenant_id AND id = :id AND version = :version');
        $statement->execute([
            'status' => $status, 'assigned_to' => $assignedTo, 'reason' => $reason !== '' ? $reason : null,
            'decided_by' => $decided ? $userId : null, 'decided_at' => $decided ? Clock::now() : null,
            'updated_at' => Clock::now(), 'tenant_id' => $tenantId, 'id' => $id, 'version' => $expectedVersion,
        ]);
        if ($statement->rowCount() !== 1) throw new HttpException(409, 'Ce dossier a été modifié par un autre administrateur. Rechargez la page.');
        $after = $this->find($tenantId, $id);
        $this->event($tenantId, $id, $userId, 'transitioned', $this->eventValues($before), $this->eventValues($after), $reason !== '' ? $reason : null);
        return $after;
    }

    public function addComment(int $tenantId, int $caseId, int $userId, string $body): array
    {
        if (! $this->find($tenantId, $caseId)) throw new HttpException(404, 'Dossier introuvable.');
        $now = Clock::now();
        $this->pdo->prepare('INSERT INTO approval_comments (tenant_id, case_id, user_id, body, created_at) VALUES (:tenant_id, :case_id, :user_id, :body, :created_at)')->execute(['tenant_id' => $tenantId, 'case_id' => $caseId, 'user_id' => $userId, 'body' => $body, 'created_at' => $now]);
        $id = (int) $this->pdo->lastInsertId();
        $this->event($tenantId, $caseId, $userId, 'commented', null, ['commentId' => (string) $id], null);
        return ['id' => (string) $id, 'caseId' => (string) $caseId, 'userId' => (string) $userId, 'body' => $body, 'createdAt' => $now];
    }

    public function comments(int $tenantId, int $caseId): array
    {
        $statement = $this->pdo->prepare('SELECT c.*, u.name AS user_name FROM approval_comments c INNER JOIN users u ON u.id = c.user_id WHERE c.tenant_id = :tenant_id AND c.case_id = :case_id ORDER BY c.id DESC');
        $statement->execute(['tenant_id' => $tenantId, 'case_id' => $caseId]);
        return array_map(fn (array $row) => ['id' => (string) $row['id'], 'caseId' => (string) $row['case_id'], 'userId' => (string) $row['user_id'], 'userName' => $row['user_name'], 'body' => $row['body'], 'createdAt' => $row['created_at']], $statement->fetchAll());
    }

    public function events(int $tenantId, int $caseId): array
    {
        $statement = $this->pdo->prepare('SELECT e.*, u.name AS user_name FROM approval_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.tenant_id = :tenant_id AND e.case_id = :case_id ORDER BY e.id DESC');
        $statement->execute(['tenant_id' => $tenantId, 'case_id' => $caseId]);
        return array_map(fn (array $row) => ['id' => (string) $row['id'], 'caseId' => (string) $row['case_id'], 'userId' => $row['user_id'] ? (string) $row['user_id'] : null, 'userName' => $row['user_name'], 'type' => $row['event_type'], 'previousValues' => Json::decode($row['previous_values']), 'newValues' => Json::decode($row['new_values']), 'reason' => $row['reason'], 'createdAt' => $row['created_at']], $statement->fetchAll());
    }

    public function overview(int $tenantId): array
    {
        $now = Clock::now();
        $counts = [];
        foreach (self::STATUSES as $status) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM approval_cases WHERE tenant_id = :tenant_id AND status = :status');
            $statement->execute(['tenant_id' => $tenantId, 'status' => $status]); $counts[$status] = (int) $statement->fetchColumn();
        }
        $overdue = $this->pdo->prepare("SELECT COUNT(*) FROM approval_cases WHERE tenant_id = :tenant_id AND due_at < :now AND status NOT IN ('approved','rejected','archived')");
        $overdue->execute(['tenant_id' => $tenantId, 'now' => $now]);
        $urgent = $this->pdo->prepare("SELECT COUNT(*) FROM approval_cases WHERE tenant_id = :tenant_id AND priority = 'urgent' AND status NOT IN ('approved','rejected','archived')");
        $urgent->execute(['tenant_id' => $tenantId]);
        $workload = $this->pdo->prepare("SELECT u.id, u.name, COUNT(c.id) AS total FROM users u LEFT JOIN approval_cases c ON c.assigned_to = u.id AND c.status NOT IN ('approved','rejected','archived') WHERE u.tenant_id = :tenant_id GROUP BY u.id, u.name HAVING COUNT(c.id) > 0 ORDER BY total DESC LIMIT 5");
        $workload->execute(['tenant_id' => $tenantId]);
        $recent = $this->pdo->prepare("SELECT e.id, e.case_id, e.event_type, e.reason, e.created_at, u.name AS user_name, s.title FROM approval_events e INNER JOIN approval_cases c ON c.id = e.case_id INNER JOIN approval_subjects s ON s.id = c.subject_id LEFT JOIN users u ON u.id = e.user_id WHERE e.tenant_id = :tenant_id AND e.event_type = 'transitioned' ORDER BY e.id DESC LIMIT 8");
        $recent->execute(['tenant_id' => $tenantId]);
        $resources = $this->pdo->prepare("SELECT s.resource_type, COUNT(*) AS total FROM approval_cases c INNER JOIN approval_subjects s ON s.id = c.subject_id WHERE c.tenant_id = :tenant_id AND c.status NOT IN ('approved','rejected','archived') GROUP BY s.resource_type");
        $resources->execute(['tenant_id' => $tenantId]);
        $byResource = array_fill_keys(self::RESOURCE_TYPES, 0);
        foreach ($resources->fetchAll() as $row) $byResource[$row['resource_type']] = (int) $row['total'];
        $settings = $this->settings($tenantId);
        return ['counts' => $counts, 'byResource' => $byResource, 'urgent' => (int) $urgent->fetchColumn(), 'overdue' => (int) $overdue->fetchColumn(), 'workload' => array_map(fn ($row) => ['userId' => (string) $row['id'], 'name' => $row['name'], 'total' => (int) $row['total']], $workload->fetchAll()), 'recentDecisions' => array_map(fn ($row) => ['id' => (string) $row['id'], 'caseId' => (string) $row['case_id'], 'type' => $row['event_type'], 'reason' => $row['reason'], 'createdAt' => $row['created_at'], 'userName' => $row['user_name'], 'title' => $row['title']], $recent->fetchAll()), 'lastImportAt' => $settings['lastImportAt'], 'unavailable' => $settings['importUnavailable']];
    }

    public function audit(int $tenantId, int $limit = 100): array
    {
        $statement = $this->pdo->prepare('SELECT e.*, u.name AS user_name, s.title FROM approval_events e INNER JOIN approval_cases c ON c.id = e.case_id INNER JOIN approval_subjects s ON s.id = c.subject_id LEFT JOIN users u ON u.id = e.user_id WHERE e.tenant_id = :tenant_id ORDER BY e.id DESC LIMIT '.min(max($limit, 1), 200));
        $statement->execute(['tenant_id' => $tenantId]);
        return array_map(fn ($row) => ['id' => (string) $row['id'], 'caseId' => (string) $row['case_id'], 'title' => $row['title'], 'type' => $row['event_type'], 'userName' => $row['user_name'], 'reason' => $row['reason'], 'createdAt' => $row['created_at']], $statement->fetchAll());
    }

    private function filters(int $tenantId, array $filters): array
    {
        $conditions = ['c.tenant_id = :tenant_id']; $params = ['tenant_id' => $tenantId];
        foreach (['status' => 'c.status', 'priority' => 'c.priority', 'resource_type' => 's.resource_type', 'source_system' => 's.source_system', 'assigned_to' => 'c.assigned_to'] as $key => $column) {
            if (! empty($filters[$key]) && $filters[$key] !== 'all') { $conditions[] = "{$column} = :{$key}"; $params[$key] = $filters[$key]; }
        }
        if (! empty($filters['q'])) { $conditions[] = '(s.title LIKE :q OR s.subtitle LIKE :q OR s.company_name LIKE :q OR s.external_id LIKE :q)'; $params['q'] = '%'.trim((string) $filters['q']).'%'; }
        if (($filters['overdue'] ?? null) === '1') { $conditions[] = "c.due_at < :now AND c.status NOT IN ('approved','rejected','archived')"; $params['now'] = Clock::now(); }
        if (($filters['open'] ?? null) === '1') { $conditions[] = "c.status NOT IN ('approved','rejected','archived')"; }
        return ['WHERE '.implode(' AND ', $conditions), $params];
    }

    private function findSubject(int $tenantId, string $sourceSystem, string $resourceType, string $externalId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM approval_subjects WHERE tenant_id = :tenant_id AND source_system = :source_system AND resource_type = :resource_type AND external_id = :external_id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId, 'source_system' => $sourceSystem, 'resource_type' => $resourceType, 'external_id' => $externalId]);
        $row = $statement->fetch(); return $row ?: null;
    }

    private function findCaseBySubject(int $tenantId, int $subjectId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id FROM approval_cases WHERE tenant_id = :tenant_id AND subject_id = :subject_id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId, 'subject_id' => $subjectId]); $id = $statement->fetchColumn();
        return $id === false ? null : $this->find($tenantId, (int) $id);
    }

    private function event(int $tenantId, int $caseId, ?int $userId, string $type, ?array $before, ?array $after, ?string $reason): void
    {
        $this->pdo->prepare('INSERT INTO approval_events (tenant_id, case_id, user_id, event_type, previous_values, new_values, reason, created_at) VALUES (:tenant_id, :case_id, :user_id, :event_type, :previous_values, :new_values, :reason, :created_at)')->execute(['tenant_id' => $tenantId, 'case_id' => $caseId, 'user_id' => $userId, 'event_type' => $type, 'previous_values' => $before ? Json::encode($before) : null, 'new_values' => $after ? Json::encode($after) : null, 'reason' => $reason, 'created_at' => Clock::now()]);
    }

    private function eventValues(?array $case): array
    {
        return ['status' => $case['status'] ?? null, 'priority' => $case['priority'] ?? null, 'assignedTo' => $case['assignedTo'] ?? null, 'dueAt' => $case['dueAt'] ?? null, 'version' => $case['version'] ?? null];
    }

    private function presentSubject(array $row): array
    {
        return ['id' => (string) $row['id'], 'sourceSystem' => $row['source_system'], 'resourceType' => $row['resource_type'], 'externalId' => (string) $row['external_id'], 'title' => $row['title'], 'subtitle' => $row['subtitle'], 'companyExternalId' => $row['company_external_id'], 'companyName' => $row['company_name'], 'snapshot' => Json::decode($row['snapshot']), 'sourceCreatedAt' => $row['source_created_at'], 'firstSeenAt' => $row['first_seen_at'], 'lastSeenAt' => $row['last_seen_at']];
    }

    private function presentCase(array $row): array
    {
        return ['id' => (string) $row['id'], 'tenantId' => (string) $row['tenant_id'], 'subjectId' => (string) $row['subject_id'], 'sourceSystem' => $row['source_system'] ?? null, 'resourceType' => $row['resource_type'] ?? null, 'externalId' => isset($row['external_id']) ? (string) $row['external_id'] : null, 'title' => $row['title'] ?? null, 'subtitle' => $row['subtitle'] ?? null, 'companyExternalId' => $row['company_external_id'] ?? null, 'companyName' => $row['company_name'] ?? null, 'snapshot' => Json::decode($row['snapshot'] ?? null), 'status' => $row['status'], 'priority' => $row['priority'], 'assignedTo' => $row['assigned_to'] !== null ? (string) $row['assigned_to'] : null, 'assigneeName' => $row['assignee_name'] ?? null, 'dueAt' => $row['due_at'], 'decisionReason' => $row['decision_reason'], 'syncStatus' => $row['sync_status'], 'version' => (int) $row['version'], 'decidedBy' => $row['decided_by'] !== null ? (string) $row['decided_by'] : null, 'decidedAt' => $row['decided_at'], 'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at']];
    }

    private function presentSettings(array $row): array
    {
        return ['requestDueHours' => (int) $row['request_due_hours'], 'campaignDueHours' => (int) $row['campaign_due_hours'], 'userDueHours' => (int) $row['user_due_hours'], 'documentDueHours' => (int) $row['document_due_hours'], 'importSinceDays' => (int) $row['import_since_days'], 'lastImportAt' => $row['last_import_at'] ?? null, 'importUnavailable' => Json::decode($row['import_unavailable'] ?? null)];
    }
}
