<?php

namespace Onekana\Api\Controllers;

use Onekana\Api\Agency\AgencyApiClient;
use Onekana\Api\Agency\AgencyApiException;
use Onekana\Api\Http\HttpException;
use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;

final class AgencyController
{
    public function __construct(private readonly AgencyApiClient $agency) {}

    public function profile(): Response
    {
        return $this->respond(fn () => ['data' => $this->agency->profile()]);
    }

    public function summary(): Response
    {
        return $this->respond(fn () => $this->agency->summary());
    }

    public function users(Request $request): Response
    {
        return $this->respond(fn () => $this->agency->users($request->query() ?? []));
    }

    public function user(int $id): Response
    {
        return $this->respond(fn () => $this->agency->user($id));
    }

    public function storeUser(Request $request): Response
    {
        return $this->respond(fn () => $this->agency->writeUser('create', $request->input()), 201);
    }

    public function updateUser(Request $request, int $id): Response
    {
        return $this->respond(fn () => $this->agency->writeUser('update', $request->input(), $id));
    }

    public function deleteUser(int $id): Response
    {
        return $this->respond(fn () => $this->agency->writeUser('delete', [], $id));
    }

    public function campaigns(Request $request): Response
    {
        return $this->respond(fn () => $this->agency->campaigns($request->query() ?? []));
    }

    public function campaign(int $id): Response
    {
        return $this->respond(fn () => $this->agency->campaign($id));
    }

    public function storeCampaign(Request $request): Response
    {
        return $this->respond(fn () => $this->agency->writeCampaign('create', $request->input()), 201);
    }

    public function updateCampaign(Request $request, int $id): Response
    {
        return $this->respond(fn () => $this->agency->writeCampaign('update', $request->input(), $id));
    }

    public function deleteCampaign(int $id): Response
    {
        return $this->respond(fn () => $this->agency->writeCampaign('delete', [], $id));
    }

    public function contacts(Request $request): Response
    {
        return $this->respond(fn () => $this->agency->contacts($request->query() ?? []));
    }

    public function contact(int $id): Response
    {
        return $this->respond(fn () => $this->agency->contact($id));
    }

    public function storeContact(Request $request): Response
    {
        return $this->respond(fn () => $this->agency->createContact($request->input()), 201);
    }

    public function updateContact(Request $request, int $id): Response
    {
        return $this->respond(fn () => $this->agency->updateContact($id, $request->input()));
    }

    public function deleteContact(int $id): Response
    {
        return $this->respond(fn () => $this->agency->deleteContact($id));
    }

    public function geographic(Request $request, string $entity): Response
    {
        return $this->respond(fn () => $this->agency->geographic($this->agencyEntity($entity), $request->query() ?? []));
    }

    public function geographicItem(int $id, string $entity): Response
    {
        return $this->respond(fn () => $this->agency->geographic($this->agencyEntity($entity), ['id' => $id]));
    }

    public function storeGeographic(Request $request, string $entity): Response
    {
        return $this->respond(fn () => $this->agency->writeGeographic($this->agencyEntity($entity), 'create', $request->input()), 201);
    }

    public function updateGeographic(Request $request, string $entity, int $id): Response
    {
        return $this->respond(fn () => $this->agency->writeGeographic($this->agencyEntity($entity), 'update', $request->input(), $id));
    }

    public function deleteGeographic(string $entity, int $id): Response
    {
        return $this->respond(fn () => $this->agency->writeGeographic($this->agencyEntity($entity), 'delete', [], $id));
    }

    private function respond(callable $callback, int $successStatus = 200): Response
    {
        try {
            $payload = $callback();

            return Response::json($payload, $successStatus);
        } catch (AgencyApiException $exception) {
            throw new HttpException($exception->status, $exception->getMessage());
        }
    }

    private function agencyEntity(string $entity): string
    {
        return match ($entity) {
            'communes' => 'communes',
            'points-chauds' => 'points_chauds',
            'trajets' => 'trajets',
            default => throw new HttpException(404, 'Ressource introuvable.'),
        };
    }
}
