<?php

namespace Tests\Feature;

use Onekana\Api\Support\Clock;
use Tests\ApiTestCase;

final class GeographicReviewMediaTest extends ApiTestCase
{
    public function test_admin_can_record_an_internal_geographic_review(): void
    {
        $this->seedAdmin();
        $token = $this->loginToken();

        $created = $this->request('PUT', '/api/geographic-reviews/point_chaud/42', [
            'status' => 'verified',
            'note' => 'Coordonnees controlees.',
        ], $this->bearer($token));

        $this->assertSame(200, $created->status);
        $this->assertSame('point_chaud', $created->payload['data']['entityType']);
        $this->assertSame('42', $created->payload['data']['externalId']);
        $this->assertSame('verified', $created->payload['data']['status']);
        $this->assertNotNull($created->payload['data']['reviewedAt']);

        $listed = $this->request('GET', '/api/geographic-reviews', [], $this->bearer($token), [], ['entity_type' => 'point_chaud']);
        $this->assertSame(200, $listed->status);
        $this->assertCount(1, $listed->payload['data']);

        $updated = $this->request('PUT', '/api/geographic-reviews/point_chaud/42', [
            'status' => 'to_review',
            'note' => 'Frequentation a confirmer.',
        ], $this->bearer($token));
        $this->assertSame(200, $updated->status);
        $this->assertSame('to_review', $updated->payload['data']['status']);
        $this->assertNull($updated->payload['data']['reviewedAt']);
    }

    public function test_media_must_belong_to_an_existing_resource_in_the_same_tenant(): void
    {
        $this->seedAdmin();
        $token = $this->loginToken();

        $createdSupport = $this->request('POST', '/api/ooh/supports', ['name' => 'Panneau 4x3'], $this->bearer($token));
        $supportId = $createdSupport->payload['data']['id'];

        $image = tempnam(sys_get_temp_dir(), 'onekana-image-');
        file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nNwAAAAASUVORK5CYII='));

        $uploaded = $this->request('POST', '/api/media', [
            'entityType' => 'ooh_support',
            'entityId' => $supportId,
            'altText' => 'Vue du panneau',
        ], $this->bearer($token), [
            'file' => [
                'name' => 'panneau.png',
                'type' => 'image/png',
                'tmp_name' => $image,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($image),
            ],
        ]);

        $this->assertSame(201, $uploaded->status);
        $this->assertTrue($uploaded->payload['data']['isCover']);
        $this->assertStringStartsWith('http://localhost/storage/media/', $uploaded->payload['data']['publicUrl']);

        $listed = $this->request('GET', '/api/media', [], $this->bearer($token), [], [
            'entity_type' => 'ooh_support',
            'entity_id' => $supportId,
        ]);
        $this->assertSame(200, $listed->status);
        $this->assertCount(1, $listed->payload['data']);

        $path = $uploaded->payload['data']['path'];
        $deleted = $this->request('DELETE', '/api/media/'.$uploaded->payload['data']['id'], [], $this->bearer($token));
        $this->assertSame(204, $deleted->status);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/public/storage/'.$path);

        $foreignNow = Clock::now();
        $this->pdo->prepare('INSERT INTO materials (tenant_id, payload, created_at, updated_at) VALUES (999, :payload, :created_at, :updated_at)')
            ->execute(['payload' => '{"name":"Materiel externe"}', 'created_at' => $foreignNow, 'updated_at' => $foreignNow]);

        $otherImage = tempnam(sys_get_temp_dir(), 'onekana-image-');
        file_put_contents($otherImage, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nNwAAAAASUVORK5CYII='));
        $rejected = $this->request('POST', '/api/media', [
            'entityType' => 'material',
            'entityId' => (string) $this->pdo->lastInsertId(),
        ], $this->bearer($token), [
            'file' => [
                'name' => 'materiel.png',
                'type' => 'image/png',
                'tmp_name' => $otherImage,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($otherImage),
            ],
        ]);
        $this->assertSame(404, $rejected->status);
        @unlink($otherImage);
    }

    public function test_media_rejects_non_image_content(): void
    {
        $this->seedAdmin();
        $token = $this->loginToken();
        $material = $this->request('POST', '/api/materials', ['name' => 'Camera'], $this->bearer($token));

        $file = tempnam(sys_get_temp_dir(), 'onekana-fake-image-');
        file_put_contents($file, 'not an image');
        $response = $this->request('POST', '/api/media', [
            'entityType' => 'material',
            'entityId' => $material->payload['data']['id'],
        ], $this->bearer($token), [
            'file' => [
                'name' => 'camera.png',
                'type' => 'image/png',
                'tmp_name' => $file,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($file),
            ],
        ]);

        $this->assertSame(422, $response->status);
        @unlink($file);
    }
}
