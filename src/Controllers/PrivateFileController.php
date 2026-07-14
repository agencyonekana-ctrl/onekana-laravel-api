<?php

namespace Onekana\Api\Controllers;

use finfo;
use Onekana\Api\Http\HttpException;
use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;
use Onekana\Api\Repositories\PrivateFileRepository;

final class PrivateFileController
{
    private const MAX_SIZE = 20 * 1024 * 1024;
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function __construct(
        private readonly string $basePath,
        private readonly PrivateFileRepository $files,
    ) {}

    public function store(Request $request): Response
    {
        $file = $request->file('file');
        if (! $file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'Le fichier est obligatoire.');
        }
        if (($file['size'] ?? 0) > self::MAX_SIZE) {
            throw new HttpException(422, 'Le fichier ne doit pas depasser 20 Mo.');
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $mimeType = $temporaryPath !== '' && is_file($temporaryPath)
            ? (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath)
            : false;
        if (! is_string($mimeType) || ! isset(self::MIME_EXTENSIONS[$mimeType])) {
            throw new HttpException(422, 'Format non autorise. Utilisez PDF, JPG ou PNG.');
        }

        $tenantId = $this->tenantId($request);
        $relativePath = $tenantId.'/'.bin2hex(random_bytes(20)).'.'.self::MIME_EXTENSIONS[$mimeType];
        $directory = $this->basePath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.$tenantId;
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new HttpException(500, 'Impossible d enregistrer le document.');
        }

        $target = $this->absolutePath($relativePath);
        if (! move_uploaded_file($temporaryPath, $target) && ! rename($temporaryPath, $target)) {
            throw new HttpException(500, 'Impossible d enregistrer le document.');
        }

        $user = $request->get('user');
        try {
            $record = $this->files->create(
                $tenantId,
                $relativePath,
                basename((string) ($file['name'] ?? 'document.'.self::MIME_EXTENSIONS[$mimeType])),
                $mimeType,
                (int) ($file['size'] ?? 0),
                isset($user['id']) ? (int) $user['id'] : null,
            );
        } catch (\Throwable $exception) {
            @unlink($target);
            throw $exception;
        }

        return Response::json(['data' => [
            'id' => (string) $record['id'],
            'fileId' => (string) $record['id'],
            'path' => $relativePath,
            'downloadUrl' => '/api/files/'.$record['id'],
        ]], 201);
    }

    public function download(Request $request, int $id): Response
    {
        $record = $this->files->find($id, $this->tenantId($request));
        if (! $record || ! is_file($this->absolutePath((string) $record['path']))) {
            throw new HttpException(404, 'Document introuvable.');
        }

        $body = file_get_contents($this->absolutePath((string) $record['path']));
        if ($body === false) {
            throw new HttpException(500, 'Impossible de lire le document.');
        }

        return Response::binary($body, (string) $record['mime_type'], (string) $record['original_name']);
    }

    public function destroy(Request $request, int $id): Response
    {
        $record = $this->files->delete($id, $this->tenantId($request));
        if (! $record) {
            throw new HttpException(404, 'Document introuvable.');
        }
        $path = $this->absolutePath((string) $record['path']);
        if (is_file($path)) {
            @unlink($path);
        }

        return Response::noContent();
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $request->get('user')['tenant_id'] ?? null;
        if (! $tenantId) {
            throw new HttpException(403, 'Organisation non disponible.');
        }
        return (int) $tenantId;
    }

    private function absolutePath(string $relativePath): string
    {
        return $this->basePath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
