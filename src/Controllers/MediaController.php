<?php

namespace Onekana\Api\Controllers;

use finfo;
use Onekana\Api\Http\HttpException;
use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;
use Onekana\Api\Repositories\MediaRepository;
use Onekana\Api\Repositories\ResourceRepository;
use Onekana\Api\Repositories\UserRepository;

final class MediaController
{
    private const MAX_FILES = 8;
    private const MAX_SIZE = 8 * 1024 * 1024;
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly string $basePath,
        private readonly MediaRepository $media,
        private readonly ResourceRepository $resources,
        private readonly UserRepository $users,
    ) {}

    public function index(Request $request): Response
    {
        $entityType = (string) $request->query('entity_type', '');
        if (! isset(MediaRepository::ENTITY_RESOURCES[$entityType])) {
            throw new HttpException(422, 'The given data was invalid.', ['entity' => ['Type de ressource invalide.']]);
        }
        $rawEntityId = $request->query('entity_id');
        $entityId = $rawEntityId !== null && $rawEntityId !== '' ? (int) $rawEntityId : null;
        if ($entityId !== null && $entityId < 1) {
            throw new HttpException(422, 'The given data was invalid.', ['entity' => ['Ressource associee invalide.']]);
        }
        $this->assertAccess($request, $entityType, false);
        $tenantId = $this->tenantId($request);
        if ($entityId !== null) {
            $this->assertEntityExists($entityType, $entityId, $tenantId);
        }

        return Response::json(['data' => $this->media->list($tenantId, $entityType, $entityId)]);
    }

    public function store(Request $request): Response
    {
        [$entityType, $entityId] = $this->entityFromRequest($request);
        $this->assertAccess($request, $entityType, true);
        $tenantId = $this->tenantId($request);
        $this->assertEntityExists($entityType, $entityId, $tenantId);

        if ($this->media->count($tenantId, $entityType, $entityId) >= self::MAX_FILES) {
            throw new HttpException(422, 'The given data was invalid.', ['file' => ['Cette galerie contient deja huit images.']]);
        }

        $file = $request->file('file');
        if (! $file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'The given data was invalid.', ['file' => ['Une image est obligatoire.']]);
        }
        if (($file['size'] ?? 0) > self::MAX_SIZE) {
            throw new HttpException(422, 'The given data was invalid.', ['file' => ['L image ne doit pas depasser 8 Mo.']]);
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $mimeType = $temporaryPath !== '' && is_file($temporaryPath)
            ? (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath)
            : false;
        if (! is_string($mimeType) || ! isset(self::MIME_EXTENSIONS[$mimeType])) {
            throw new HttpException(422, 'The given data was invalid.', ['file' => ['Format image non autorise. Utilisez JPG, PNG ou WebP.']]);
        }

        $altText = trim((string) $request->input('altText', $request->input('alt_text', '')));
        if (mb_strlen($altText) > 255) {
            throw new HttpException(422, 'The given data was invalid.', ['altText' => ['Le texte alternatif ne doit pas depasser 255 caracteres.']]);
        }

        $extension = self::MIME_EXTENSIONS[$mimeType];
        $directory = "media/{$tenantId}/{$entityType}/{$entityId}";
        $filename = bin2hex(random_bytes(16)).'.'.$extension;
        $relativePath = $directory.'/'.$filename;
        $targetDirectory = $this->basePath.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);
        if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0775, true) && ! is_dir($targetDirectory)) {
            throw new HttpException(500, 'Impossible d enregistrer cette image.');
        }

        $target = $targetDirectory.DIRECTORY_SEPARATOR.$filename;
        if (! move_uploaded_file($temporaryPath, $target) && ! rename($temporaryPath, $target)) {
            throw new HttpException(500, 'Impossible d enregistrer cette image.');
        }

        $isCover = $this->boolean($request->input('isCover', $request->input('is_cover')))
            ?? $this->media->count($tenantId, $entityType, $entityId) === 0;
        $user = $request->get('user');

        try {
            $record = $this->media->create(
                $tenantId,
                $entityType,
                $entityId,
                $relativePath,
                $mimeType,
                $altText !== '' ? $altText : null,
                $isCover,
                (int) $request->input('sortOrder', $request->input('sort_order', 0)),
                isset($user['id']) ? (int) $user['id'] : null,
            );
        } catch (\Throwable $exception) {
            @unlink($target);
            throw $exception;
        }

        return Response::json(['data' => $record], 201);
    }

    public function update(Request $request, int $id): Response
    {
        $existing = $this->media->find($id, $this->tenantId($request));
        if (! $existing) {
            throw new HttpException(404, 'Image introuvable.');
        }
        $this->assertAccess($request, (string) $existing['entityType'], true);

        $altText = $request->input('altText', $request->input('alt_text'));
        if ($altText !== null && mb_strlen(trim((string) $altText)) > 255) {
            throw new HttpException(422, 'The given data was invalid.', ['altText' => ['Le texte alternatif ne doit pas depasser 255 caracteres.']]);
        }

        $record = $this->media->update(
            $id,
            $this->tenantId($request),
            $altText !== null ? trim((string) $altText) : null,
            $this->boolean($request->input('isCover', $request->input('is_cover'))),
            $request->input('sortOrder', $request->input('sort_order')) !== null
                ? (int) $request->input('sortOrder', $request->input('sort_order'))
                : null,
        );
        if (! $record) {
            throw new HttpException(404, 'Image introuvable.');
        }

        return Response::json(['data' => $record]);
    }

    public function destroy(Request $request, int $id): Response
    {
        $existing = $this->media->find($id, $this->tenantId($request));
        if (! $existing) {
            throw new HttpException(404, 'Image introuvable.');
        }
        $this->assertAccess($request, (string) $existing['entityType'], true);

        $record = $this->media->delete($id, $this->tenantId($request));
        if (! $record) {
            throw new HttpException(404, 'Image introuvable.');
        }

        $path = (string) ($record['path'] ?? '');
        if (str_starts_with($path, 'media/')) {
            $file = $this->basePath.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (is_file($file)) {
                @unlink($file);
            }
        }

        return Response::noContent();
    }

    private function entityFromRequest(Request $request, bool $query = false): array
    {
        $entityType = (string) ($query ? $request->query('entity_type', '') : $request->input('entityType', $request->input('entity_type', '')));
        $entityId = (int) ($query ? $request->query('entity_id', 0) : $request->input('entityId', $request->input('entity_id', 0)));
        if (! isset(MediaRepository::ENTITY_RESOURCES[$entityType]) || $entityId < 1) {
            throw new HttpException(422, 'The given data was invalid.', ['entity' => ['Ressource associee invalide.']]);
        }

        return [$entityType, $entityId];
    }

    private function assertEntityExists(string $entityType, int $entityId, int $tenantId): void
    {
        if (! $this->resources->find(MediaRepository::ENTITY_RESOURCES[$entityType], $entityId, $tenantId)) {
            throw new HttpException(404, 'Ressource associee introuvable.');
        }
    }

    private function boolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $request->get('user')['tenant_id'] ?? null;
        if (! $tenantId) {
            throw new HttpException(403, 'Organisation non disponible.');
        }

        return (int) $tenantId;
    }

    private function assertAccess(Request $request, string $entityType, bool $manage): void
    {
        $user = $request->get('user');
        $module = $entityType === 'material' ? 'team' : 'inventory';
        $permission = $module.'.'.($manage ? 'manage' : 'view');
        if (! $this->users->hasModule($user, $module) || ! $this->users->hasPermission($user, $permission)) {
            throw new HttpException(403, 'Acces non autorise.');
        }
    }
}
