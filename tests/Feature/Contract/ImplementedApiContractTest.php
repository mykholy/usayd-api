<?php

namespace Tests\Feature\Contract;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ImplementedApiContractTest extends TestCase
{
    public function test_implemented_spec_matches_registered_public_api_routes(): void
    {
        $spec = json_decode((string) file_get_contents(base_path('docs/api/implemented.openapi.json')), true, 512, JSON_THROW_ON_ERROR);

        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $this->assertArrayHasKey('operationId', $operation);
                $this->assertNotEmpty($operation['responses']);
                $documented[] = strtoupper($method).' '.$path;
            }
        }

        $registered = [];
        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');

            if (! str_starts_with($uri, '/api/') || str_starts_with($uri, '/api/v1/test/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD' && $method !== 'OPTIONS') {
                    $registered[] = $method.' '.$uri;
                }
            }
        }

        sort($documented);
        sort($registered);

        $this->assertSame($registered, $documented);
    }
}
