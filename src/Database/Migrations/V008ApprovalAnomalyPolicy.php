<?php

namespace Onekana\Api\Database\Migrations;

use Onekana\Api\Support\Clock;
use PDO;

final class V008ApprovalAnomalyPolicy
{
    public static function up(PDO $pdo): void
    {
        $now = Clock::now();

        // Imported contacts are business requests. Manually flagged contacts keep their anomaly type.
        $pdo->exec("UPDATE approval_subjects SET resource_type = 'agency_request'
            WHERE resource_type = 'agency_contact'
            AND id IN (
                SELECT c.subject_id FROM approval_cases c
                INNER JOIN approval_events e ON e.case_id = c.id
                WHERE e.event_type = 'imported'
            )");

        $cases = $pdo->query("SELECT c.id, c.tenant_id FROM approval_cases c
            INNER JOIN approval_subjects s ON s.id = c.subject_id
            WHERE s.resource_type = 'agency_user'
            AND c.status IN ('pending', 'in_review', 'needs_information')
            AND EXISTS (SELECT 1 FROM approval_events imported WHERE imported.case_id = c.id AND imported.event_type = 'imported')
            AND NOT EXISTS (SELECT 1 FROM approval_events flagged WHERE flagged.case_id = c.id AND flagged.event_type = 'flagged')")->fetchAll();

        $event = $pdo->prepare("INSERT INTO approval_events
            (tenant_id, case_id, user_id, event_type, previous_values, new_values, reason, created_at)
            VALUES (:tenant_id, :case_id, NULL, 'policy_changed', :previous_values, :new_values, :reason, :created_at)");
        $archive = $pdo->prepare("UPDATE approval_cases SET status = 'archived', decision_reason = :reason,
            version = version + 1, updated_at = :updated_at WHERE id = :id AND tenant_id = :tenant_id");
        foreach ($cases as $case) {
            $event->execute([
                'tenant_id' => $case['tenant_id'],
                'case_id' => $case['id'],
                'previous_values' => '{"automaticReview":true}',
                'new_values' => '{"automaticReview":false,"status":"archived"}',
                'reason' => 'Les comptes ordinaires ne nécessitent plus de validation automatique.',
                'created_at' => $now,
            ]);
            $archive->execute([
                'reason' => 'Contrôle automatique retiré. Le compte reste signalable en cas d’anomalie.',
                'updated_at' => $now,
                'id' => $case['id'],
                'tenant_id' => $case['tenant_id'],
            ]);
        }
    }
}
