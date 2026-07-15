<?php

namespace Onekana\Api\Approvals;

use Onekana\Api\Agency\AgencyApiClient;
use Onekana\Api\Agency\AgencyApiException;
use Onekana\Api\Repositories\ApprovalRepository;
use Onekana\Api\Repositories\ResourceRepository;

final class ApprovalImporter
{
    public function __construct(
        private readonly ApprovalRepository $approvals,
        private readonly ResourceRepository $resources,
        private readonly AgencyApiClient $agency,
    ) {}

    public function import(int $tenantId, ?int $userId = null): array
    {
        $settings = $this->approvals->settings($tenantId);
        $result = ['indexed' => 0, 'created' => 0, 'existing' => 0, 'unavailable' => []];

        $this->importAgency($result, 'users', fn () => $this->agency->users(), function (array $row) use ($tenantId, &$result): void {
            $externalId = $this->id($row, ['user_id', 'id']);
            if ($externalId === '') return;
            $name = trim(implode(' ', array_filter([(string) ($row['first_name'] ?? ''), (string) ($row['last_name'] ?? '')]))) ?: (string) ($row['name'] ?? $row['email'] ?? 'Utilisateur Agency');
            $subject = $this->approvals->upsertSubject($tenantId, [
                'sourceSystem' => 'agency', 'resourceType' => 'agency_user', 'externalId' => $externalId,
                'title' => $name, 'subtitle' => (string) ($row['email'] ?? ''),
                'companyExternalId' => $row['company_id'] ?? null, 'companyName' => $row['company'] ?? $row['company_name'] ?? null,
                'snapshot' => $this->safeSnapshot($row), 'sourceCreatedAt' => $this->date($row),
            ]);
            $result['indexed']++;
        });

        $this->importAgency($result, 'contacts', fn () => $this->agency->contacts(), function (array $row) use ($tenantId, $userId, $settings, &$result): void {
            $externalId = $this->id($row, ['contact_id', 'id']);
            if ($externalId === '') return;
            $title = (string) ($row['contact_person'] ?? $row['name'] ?? $row['company_name'] ?? 'Demande client');
            $subject = $this->approvals->upsertSubject($tenantId, [
                'sourceSystem' => 'agency', 'resourceType' => 'agency_request', 'externalId' => $externalId,
                'title' => $title, 'subtitle' => (string) ($row['support_solicited'] ?? $row['fonction'] ?? 'Demande client'),
                'companyExternalId' => $row['company_id'] ?? null, 'companyName' => $row['company_name'] ?? $row['company'] ?? null,
                'snapshot' => $this->safeSnapshot($row), 'sourceCreatedAt' => $this->date($row),
            ]);
            $result['indexed']++;
            if ($this->recent($this->date($row), $settings['importSinceDays']) && $this->needsContactReview($row)) {
                $this->create($result, $tenantId, $subject, 'normal', $settings['requestDueHours'], $userId);
            }
        });

        $this->importAgency($result, 'campaigns', fn () => $this->agency->campaigns(), function (array $row) use ($tenantId, $userId, $settings, &$result): void {
            $externalId = $this->id($row, ['campaign_id', 'id']);
            if ($externalId === '') return;
            $subject = $this->approvals->upsertSubject($tenantId, [
                'sourceSystem' => 'agency', 'resourceType' => 'agency_campaign', 'externalId' => $externalId,
                'title' => (string) ($row['name'] ?? $row['campaign_name'] ?? $row['title'] ?? 'Campagne reçue'),
                'subtitle' => (string) ($row['client_name'] ?? $row['company_name'] ?? $row['status'] ?? ''),
                'companyExternalId' => $row['company_id'] ?? $row['client_id'] ?? null, 'companyName' => $row['company_name'] ?? $row['client_name'] ?? null,
                'snapshot' => $this->safeSnapshot($row), 'sourceCreatedAt' => $this->date($row),
            ]);
            $result['indexed']++;
            if (($this->recent($this->date($row), $settings['importSinceDays']) && $this->needsCampaignReview($row)) || $this->startsSoon($row)) {
                $priority = $this->startsWithin($row, 2) ? 'urgent' : ($this->startsWithin($row, 7) ? 'high' : 'normal');
                $this->create($result, $tenantId, $subject, $priority, $settings['campaignDueHours'], $userId);
            }
        });

        foreach ($this->resources->list('documents', $tenantId) as $document) {
            $externalId = (string) ($document['id'] ?? '');
            if ($externalId === '') continue;
            $subject = $this->approvals->upsertSubject($tenantId, [
                'sourceSystem' => 'internal', 'resourceType' => 'document', 'externalId' => $externalId,
                'title' => (string) ($document['name'] ?? $document['title'] ?? $document['fileName'] ?? 'Document administratif'),
                'subtitle' => (string) ($document['type'] ?? $document['category'] ?? 'Document'),
                'companyExternalId' => $document['companyId'] ?? null, 'companyName' => $document['companyName'] ?? null,
                'snapshot' => $this->safeSnapshot($document), 'sourceCreatedAt' => $document['createdAt'] ?? null,
            ]);
            $result['indexed']++;
            if ($this->recent((string) ($document['createdAt'] ?? ''), $settings['importSinceDays']) && ! in_array(strtolower((string) ($document['reviewStatus'] ?? 'pending')), ['verified', 'approved'], true)) {
                $this->create($result, $tenantId, $subject, 'normal', $settings['documentDueHours'], $userId);
            }
        }

        foreach ($this->approvals->deadlineCandidates($tenantId) as $candidate) {
            $overdue = $candidate['type'] === 'deadline_overdue';
            $this->resources->create('notifications', [
                'title' => $overdue ? 'Échéance dépassée' : 'Échéance proche',
                'message' => 'Le dossier « '.$candidate['title'].' » demande votre attention.',
                'caseId' => (string) $candidate['caseId'],
                'type' => 'approval',
                'createdAt' => gmdate('c'),
            ], $tenantId);
            $this->approvals->recordDeadlineEvent($tenantId, $candidate['caseId'], $candidate['type']);
            $result['alerts'] = (int) ($result['alerts'] ?? 0) + 1;
        }
        $this->approvals->recordImportStatus($tenantId, $result['unavailable']);
        $result['alerts'] = (int) ($result['alerts'] ?? 0);

        return $result;
    }

    private function importAgency(array &$result, string $name, callable $load, callable $consume): void
    {
        try {
            $response = $load();
            foreach (($response['data'] ?? []) as $row) if (is_array($row)) $consume($row);
        } catch (AgencyApiException) {
            $result['unavailable'][] = $name;
        }
    }

    private function create(array &$result, int $tenantId, array $subject, string $priority, int $hours, ?int $userId): void
    {
        $before = $this->approvals->findByResource($tenantId, $subject['resourceType'], $subject['externalId']);
        $this->approvals->createCase($tenantId, (int) $subject['id'], $priority, null, gmdate('Y-m-d H:i:s', time() + ($hours * 3600)), $userId, 'imported');
        $result[$before ? 'existing' : 'created']++;
    }

    private function id(array $row, array $keys): string
    {
        foreach ($keys as $key) if (isset($row[$key]) && (string) $row[$key] !== '') return (string) $row[$key];
        return '';
    }

    private function date(array $row): string
    {
        return (string) ($row['created_at'] ?? $row['createdAt'] ?? $row['submitted_at'] ?? '');
    }

    private function recent(string $date, int $days): bool
    {
        if ($date === '') return true;
        $timestamp = strtotime($date);
        return $timestamp !== false && $timestamp >= strtotime("-{$days} days");
    }

    private function needsContactReview(array $row): bool
    {
        return in_array(strtolower((string) ($row['etape_achat'] ?? $row['etape'] ?? 'prospect')), ['prospect', 'qualification', 'new', 'nouveau'], true);
    }

    private function needsCampaignReview(array $row): bool
    {
        return in_array(strtolower((string) ($row['status'] ?? 'pending')), ['pending', 'submitted', 'draft', 'verification', 'a_verifier', 'en_attente'], true);
    }

    private function startsSoon(array $row): bool { return $this->startsWithin($row, 7); }

    private function startsWithin(array $row, int $days): bool
    {
        $date = (string) ($row['start_date'] ?? $row['startDate'] ?? '');
        $timestamp = $date !== '' ? strtotime($date) : false;
        return $timestamp !== false && $timestamp >= strtotime('today') && $timestamp <= strtotime("+{$days} days");
    }

    private function safeSnapshot(array $row): array
    {
        foreach (['password', 'token', 'access_token', 'refresh_token', 'secret'] as $field) unset($row[$field]);
        return $row;
    }
}
