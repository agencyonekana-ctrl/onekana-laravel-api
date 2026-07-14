<?php

namespace Onekana\Api\Controllers;

use Onekana\Api\Http\HttpException;
use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;
use Onekana\Api\Repositories\GeographicReviewRepository;

final class GeographicReviewController
{
    public function __construct(private readonly GeographicReviewRepository $reviews) {}

    public function index(Request $request): Response
    {
        $entityType = $request->query('entity_type');
        if ($entityType !== null) {
            $this->assertEntityType((string) $entityType);
        }

        return Response::json(['data' => $this->reviews->list($this->tenantId($request), $entityType ? (string) $entityType : null)]);
    }

    public function update(Request $request, string $entityType, int $externalId): Response
    {
        $this->assertEntityType($entityType);
        $status = (string) $request->input('status', 'to_review');
        if (! in_array($status, GeographicReviewRepository::STATUSES, true)) {
            throw new HttpException(422, 'The given data was invalid.', ['status' => ['Statut de controle invalide.']]);
        }

        $note = trim((string) $request->input('note', ''));
        if (mb_strlen($note) > 2000) {
            throw new HttpException(422, 'The given data was invalid.', ['note' => ['La note ne doit pas depasser 2000 caracteres.']]);
        }

        $user = $request->get('user');
        return Response::json(['data' => $this->reviews->upsert(
            $this->tenantId($request),
            $entityType,
            (string) $externalId,
            $status,
            $note !== '' ? $note : null,
            isset($user['id']) ? (int) $user['id'] : null,
        )]);
    }

    private function assertEntityType(string $entityType): void
    {
        if (! in_array($entityType, GeographicReviewRepository::ENTITY_TYPES, true)) {
            throw new HttpException(404, 'Ressource geographique introuvable.');
        }
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $request->get('user')['tenant_id'] ?? null;
        if (! $tenantId) {
            throw new HttpException(403, 'Organisation non disponible.');
        }

        return (int) $tenantId;
    }
}
