<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_workspace_renders_manifest_from_api(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/api/v1/modules' => Http::response(['data' => [['id' => 'catalog', 'name' => 'Katalog dan harga', 'prd' => '02', 'status' => 'planned']]])]);
        $this->get('/')->assertOk()->assertSee('Katalog dan harga')->assertSee('tersedia setelah masuk');
        Http::assertSentCount(1);
    }

    public function test_api_failure_is_visible_without_fake_metrics(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->get('/')->assertOk()->assertSee('API belum tersedia');
    }
}
