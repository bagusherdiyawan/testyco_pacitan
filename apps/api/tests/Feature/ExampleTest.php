<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_public_module_manifest_has_no_business_data(): void
    {
        $this->getJson('/api/v1/modules')->assertOk()->assertJsonCount(14, 'data')->assertJsonPath('data.0.status', 'available');
        $this->getJson('/api/v1/sales')->assertNotFound();
    }

    public function test_health(): void
    {
        $this->get('/up')->assertOk();
    }
}
