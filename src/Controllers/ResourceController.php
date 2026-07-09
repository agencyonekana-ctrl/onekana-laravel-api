<?php

namespace Onekana\Api\Controllers;

use Onekana\Api\Http\HttpException;
use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;
use Onekana\Api\Repositories\ResourceRepository;

final class ResourceController
{
    public function __construct(private readonly ResourceRepository $resources) {}

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
        if (! $this->resources->delete($resource, $id, $this->tenantId($request))) {
            throw new HttpException(404, 'Not found.');
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
}
