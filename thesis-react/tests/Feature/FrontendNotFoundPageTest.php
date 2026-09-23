<?php

namespace Tests\Feature;

use Tests\TestCase;

class FrontendNotFoundPageTest extends TestCase
{
    public function test_unknown_frontend_routes_render_the_not_found_page(): void
    {
        $app = file_get_contents(base_path('../react/src/App.jsx'));
        $page = file_get_contents(base_path('../react/src/pages/NotFound.jsx'));

        $this->assertStringContainsString("const NotFound = lazy(() => import('./pages/NotFound'));", $app);
        $this->assertStringContainsString('<Route path="*" element={', $app);
        $this->assertStringContainsString('<NotFound />', $app);
        $this->assertStringNotContainsString('<Route path="*" element={<Navigate to="/" replace />} />', $app);
        $this->assertStringContainsString('This page has checked out.', $page);
        $this->assertStringContainsString('location.pathname', $page);
    }
}
