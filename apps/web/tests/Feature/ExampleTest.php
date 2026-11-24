<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_landing_page_shows_the_hero_without_a_module_map_and_still_checks_the_api(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/api/v1/modules' => Http::response(['data' => [['id' => 'catalog', 'name' => 'Katalog dan harga', 'prd' => '02', 'status' => 'planned']]])]);
        $this->get('/')->assertOk()->assertSee('Operasional dalam satu ekosistem')->assertSee('Masuk workspace')->assertSee('tersedia setelah masuk')->assertDontSee('Peta modul')->assertDontSee('Katalog dan harga')->assertDontSee('API belum tersedia');
        Http::assertSentCount(1);
    }

    public function test_api_failure_is_visible_without_fake_metrics(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->get('/')->assertOk()->assertSee('API belum tersedia');
    }
}
