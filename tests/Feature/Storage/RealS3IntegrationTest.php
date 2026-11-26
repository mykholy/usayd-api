<?php

namespace Tests\Feature\Storage;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealS3IntegrationTest extends TestCase
{
    public function test_private_disk_round_trip_and_denied_anonymous_read(): void
    {
        if (getenv('USAYD_REQUIRE_S3_TESTS') !== '1') {
            $this->markTestSkipped('Requires the isolated loopback S3-compatible test service.');
        }

        // Intentionally fixed to isolated CI infrastructure, never production AWS.
        $bucket = 'usayd-ci-private-'.Str::uuid();
        $endpoint = 'http://127.0.0.1:9000';
        $key = 'recitations/1/ci-fixture.txt';
        $content = "Synthetic S3 integration fixture.\n";

        config(['filesystems.disks.s3_private' => [
            'driver' => 's3',
            'key' => 'usayd-ci-access',
            'secret' => 'usayd-ci-test-secret',
            'region' => 'us-east-1',
            'bucket' => $bucket,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'throw' => true,
        ]]);
        Storage::forgetDisk('s3_private');

        $disk = Storage::disk('s3_private');
        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);
        $client = $disk->getClient();
        $client->createBucket(['Bucket' => $bucket]);

        try {
            $this->assertTrue($disk->put($key, $content));
            $this->assertTrue($disk->exists($key));
            $this->assertSame($content, $disk->get($key));
            $this->assertFalse($disk->exists('recitations/1/missing.txt'));

            $response = Http::timeout(5)->get($endpoint.'/'.$bucket.'/'.$key);
            $this->assertSame(403, $response->status());
            $this->assertStringNotContainsString($content, $response->body());
        } finally {
            $client->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            $client->deleteBucket(['Bucket' => $bucket]);
            Storage::forgetDisk('s3_private');
        }
    }
}
