<?php

namespace Onekana\Api\Controllers;

use Onekana\Api\Http\HttpException;
use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;

final class UploadController
{
    private const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'csv'];

    public function __construct(private readonly string $basePath) {}

    public function store(Request $request): Response
    {
        $file = $request->file('file');
        if (! $file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'The given data was invalid.', ['file' => ['Le fichier est obligatoire.']]);
        }

        if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
            throw new HttpException(422, 'The given data was invalid.', ['file' => ['Le fichier ne doit pas depasser 20 Mo.']]);
        }

        $original = (string) ($file['name'] ?? 'file');
        $extension = mb_strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new HttpException(422, 'The given data was invalid.', ['file' => ['Type de fichier non autorise.']]);
        }

        $pathInput = (string) $request->input('path', 'uploads');
        $directory = trim(dirname($pathInput), '.\\/');
        $directory = $this->safeDirectory($directory ?: 'uploads');
        $filename = bin2hex(random_bytes(12)).'.'.$extension;
        $relative = $directory.'/'.$filename;
        $targetDirectory = $this->basePath.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);

        if (! is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0775, true);
        }

        $target = $targetDirectory.DIRECTORY_SEPARATOR.$filename;
        if (! move_uploaded_file((string) $file['tmp_name'], $target) && ! rename((string) $file['tmp_name'], $target)) {
            throw new HttpException(500, 'Upload failed.');
        }

        $url = '/storage/'.$relative;

        return Response::json(['data' => [
            'path' => $relative,
            'publicUrl' => $url,
            'public_url' => $url,
            'url' => $url,
        ]], 201);
    }

    private function safeDirectory(string $directory): string
    {
        $directory = str_replace('\\', '/', $directory);
        $parts = array_values(array_filter(explode('/', $directory), fn (string $part) => $part !== '' && $part !== '..' && $part !== '.'));

        return implode('/', $parts) ?: 'uploads';
    }
}
