<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    public function test_openapi_document_describes_the_admin_api(): void
    {
        $spec = $this->getJson('/docs/api.json')->assertOk()->json();

        $this->assertSame('Rentflow API', $spec['info']['title']);
        foreach (['/admin/auth/token', '/admin/user', '/admin/payments', '/admin/invoices/{invoice}', '/admin/billing-runs'] as $path) {
            $this->assertArrayHasKey($path, $spec['paths']);
        }

        $this->assertSame(['type' => 'http', 'scheme' => 'bearer'], $spec['components']['securitySchemes']['http']);
        $this->assertSame([], $spec['paths']['/admin/auth/token']['post']['security']); // login is public
        $this->assertArrayHasKey('202', $spec['paths']['/admin/payments']['post']['responses']);
    }

    public function test_scalar_reference_page_loads_the_spec_without_a_proxy(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('"url":"\\/docs\\/api.json"', false)
            ->assertSee('@scalar/api-reference@1.72.2', false)
            ->assertSee('"proxyUrl":null', false)
            ->assertSee('"telemetry":false', false);
    }

    public function test_docs_can_be_switched_off(): void
    {
        config(['app.api_docs' => false]);

        $this->get('/docs')->assertForbidden();
        $this->get('/docs/api.json')->assertForbidden();
    }
}
