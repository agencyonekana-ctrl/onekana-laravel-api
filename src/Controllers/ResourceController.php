<?php

namespace Onekana\Api\Controllers;

use Onekana\Api\Http\HttpException;
use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;
use Onekana\Api\Repositories\ResourceRepository;
use Onekana\Api\Repositories\MediaRepository;
use Onekana\Api\Repositories\PrivateFileRepository;

final class ResourceController
{
    public function __construct(
        private readonly ResourceRepository $resources,
        private readonly ?MediaRepository $media = null,
        private readonly ?string $basePath = null,
        private readonly ?PrivateFileRepository $privateFiles = null,
    ) {}

    public function index(Request $request, string $resource): Response
    {
        return Response::json(['data' => $this->resources->list($resource, $this->tenantId($request))]);
    }

    public function show(Request $request, string $resource, int $id): Response
    {
        $row = $this->resources->find($resource, $id, $this->tenantId($request));
        if (! $row) {
            throw new HttpException(404, 'Not found.');
        }

        return Response::json(['data' => $row]);
    }

    public function store(Request $request, string $resource): Response
    {
        return Response::json(['data' => $this->resources->create($resource, $request->input(), $this->tenantId($request))], 201);
    }

    public function update(Request $request, string $resource, int $id): Response
    {
        $row = $this->resources->update($resource, $id, $request->input(), $this->tenantId($request));
        if (! $row) {
            throw new HttpException(404, 'Not found.');
        }

        return Response::json(['data' => $row]);
    }

    public function destroy(Request $request, string $resource, int $id): Response
    {
        $tenantId = $this->tenantId($request);
        $existing = $this->resources->find($resource, $id, $tenantId);
        if (! $this->resources->delete($resource, $id, $this->tenantId($request))) {
            throw new HttpException(404, 'Not found.');
        }

        if ($resource === 'documents' && $this->privateFiles && $tenantId && isset($existing['fileId'])) {
            $file = $this->privateFiles->delete((int) $existing['fileId'], $tenantId);
            if ($file) {
                $this->deletePrivateFile((string) $file['path']);
            }
        }

        $entityType = array_search($resource, MediaRepository::ENTITY_RESOURCES, true);
        if ($entityType !== false && $this->media && $tenantId) {
            foreach ($this->media->deleteForEntity($tenantId, $entityType, $id) as $item) {
                $this->deleteStoredMedia((string) ($item['path'] ?? ''));
            }
        }

        return Response::noContent();
    }

    public function markNotificationRead(Request $request, int $id): Response
    {
        $row = $this->resources->markNotificationRead($id, $this->tenantId($request));
        if (! $row) {
            throw new HttpException(404, 'Not found.');
        }

        return Response::json(['data' => $row]);
    }

    private function tenantId(Request $request): ?int
    {
        $user = $request->get('user');

        return isset($user['tenant_id']) ? (int) $user['tenant_id'] : null;
    }

    private function deleteStoredMedia(string $path): void
    {
        if (! $this->basePath || ! str_starts_with($path, 'media/')) {
            return;
        }

        $file = $this->basePath.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function deletePrivateFile(string $path): void
    {
        if (! $this->basePath || $path === '' || str_contains($path, '..')) {
            return;
        }
        $file = $this->basePath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
