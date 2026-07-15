<?php

namespace Onekana\Api\Controllers;

use Onekana\Api\Auth\PasswordResetStore;
use Onekana\Api\Http\HttpException;
use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;
use Onekana\Api\Http\Validator;
use Onekana\Api\Mail\Mailer;
use Onekana\Api\Repositories\UserRepository;
use Throwable;

final class AdminUserController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetStore $resets,
        private readonly Mailer $mailer,
    ) {}

    public function index(Request $request): Response
    {
        return Response::json(['data' => $this->users->listForTenant($this->tenantId($request))]);
    }

    public function roles(): Response
    {
        return Response::json(['data' => $this->users->allRoles()]);
    }

    public function store(Request $request): Response
    {
        $data = $request->input();
        Validator::require($data, [
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
            'roleId' => ['required'],
        ]);

        $email = strtolower(trim((string) $data['email']));
        $roleId = (int) $data['roleId'];
        if ($this->users->findByEmail($email)) {
            throw new HttpException(422, 'Cette adresse e-mail est déjà utilisée.');
        }
        if (! $this->users->roleExists($roleId)) {
            throw new HttpException(422, 'Rôle invalide.');
        }

        $user = $this->users->createInvited($this->tenantId($request), trim((string) $data['name']), $email, $roleId);
        $this->sendInvitation($user);

        return Response::json(['data' => $this->users->payload($user)], 201);
    }

    public function update(Request $request, int $id): Response
    {
        $data = $request->input();
        $current = $request->get('user');
        if (array_key_exists('isActive', $data) && ! (bool) $data['isActive'] && (int) $current['id'] === $id) {
            throw new HttpException(422, 'Vous ne pouvez pas désactiver votre propre compte.');
        }
        if (isset($data['roleId']) && ! $this->users->roleExists((int) $data['roleId'])) {
            throw new HttpException(422, 'Rôle invalide.');
        }

        $tenantId = $this->tenantId($request);
        $target = $this->users->findById($id);
        if (! $target || (int) ($target['tenant_id'] ?? 0) !== $tenantId) {
            throw new HttpException(404, 'Utilisateur introuvable.');
        }
        if (array_key_exists('isActive', $data) && ! (bool) $data['isActive'] && in_array('admin', $this->users->payload($target)['roles'], true) && $this->users->countActiveAdmins($tenantId) <= 1) {
            throw new HttpException(422, 'Le dernier administrateur actif ne peut pas être désactivé.');
        }
        if (isset($data['roleId']) && $this->users->roleKey((int) $data['roleId']) !== 'admin' && in_array('admin', $this->users->payload($target)['roles'], true) && $this->users->isActive($target) && $this->users->countActiveAdmins($tenantId) <= 1) {
            throw new HttpException(422, 'Le dernier administrateur actif ne peut pas changer de rôle.');
        }

        $updated = $this->users->updateAdmin($tenantId, $id, array_filter([
            'name' => isset($data['name']) ? trim((string) $data['name']) : null,
            'is_active' => array_key_exists('isActive', $data) ? ((bool) $data['isActive'] ? 1 : 0) : null,
            'role_id' => isset($data['roleId']) ? (int) $data['roleId'] : null,
        ], static fn ($value) => $value !== null));

        return Response::json(['data' => $this->users->payload($updated)]);
    }

    public function invite(Request $request, int $id): Response
    {
        $user = $this->users->findById($id);
        if (! $user || (int) ($user['tenant_id'] ?? 0) !== $this->tenantId($request)) {
            throw new HttpException(404, 'Utilisateur introuvable.');
        }
        $this->sendInvitation($user);
        return Response::json(['data' => ['message' => 'Invitation envoyée.']]);
    }

    private function sendInvitation(array $user): void
    {
        $token = $this->resets->issue((int) $user['id']);
        try {
            $this->mailer->sendPasswordLink((string) $user['email'], (string) $user['name'], $token, true);
        } catch (Throwable $exception) {
            error_log(json_encode(['event' => 'admin_invitation_failed', 'user_id' => $user['id'], 'message' => $exception->getMessage()]));
            throw new HttpException(503, 'L’invitation n’a pas pu être envoyée.');
        }
    }

    private function tenantId(Request $request): int
    {
        return (int) ($request->get('user')['tenant_id'] ?? 0);
    }
}
