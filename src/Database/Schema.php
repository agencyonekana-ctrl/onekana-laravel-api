<?php

namespace Onekana\Api\Database;

use PDO;

final class Schema
{
    public const RESOURCE_TABLES = [
        'employees', 'departments', 'documents', 'schedules', 'materials', 'material_types',
        'reservations', 'reservation_types', 'job_titles', 'employee_statuses', 'ooh_sites',
        'ooh_supports', 'ooh_emplacements', 'ooh_pricing_rules', 'ooh_campaigns',
        'ooh_campaign_lines', 'ooh_tasks', 'packs', 'options', 'contact_messages',
        'campaign_types', 'campaign_prices', 'communes', 'quartiers', 'points_chauds',
        'transport_routes', 'route_coordinates', 'agenda_events', 'notifications', 'roadmap',
    ];

    public static function migrate(PDO $pdo): void
    {
        (new MigrationRunner($pdo))->migrate();
    }
}
