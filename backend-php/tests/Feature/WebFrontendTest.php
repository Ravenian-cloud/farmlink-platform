<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebFrontendTest extends TestCase
{
    public function test_root_redirects_to_the_php_marketplace(): void
    {
        $this->get('/')
            ->assertRedirect('/marketplace');
    }

    public function test_each_frontend_page_renders_the_shared_blade_shell(): void
    {
        foreach (['marketplace', 'dashboard', 'community', 'impact', 'feedback', 'account', 'community-admin', 'ads'] as $page) {
            $this->get('/' . $page)
                ->assertOk()
                ->assertSee('id="app-main"', false)
                ->assertSee('data-page="' . $page . '"', false);
        }
    }

    public function test_unknown_frontend_pages_return_not_found(): void
    {
        $this->get('/not-a-page')->assertNotFound();
    }

    public function test_frontend_and_api_are_served_by_the_same_laravel_application(): void
    {
        $this->get('/marketplace')
            ->assertOk()
            ->assertSee('/js/farmlink.js', false);

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }
}
