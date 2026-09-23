<?php

namespace Tests\Feature;

use Tests\TestCase;

class FrontendLegalPagesTest extends TestCase
{
    public function test_public_legal_links_have_accessible_routed_pages(): void
    {
        $app = file_get_contents(base_path('../react/src/App.jsx'));
        $footer = file_get_contents(base_path('../react/src/components/Footer.jsx'));
        $page = file_get_contents(base_path('../react/src/pages/LegalPage.jsx'));
        $styles = file_get_contents(base_path('../react/src/pages/LegalPage.css'));

        $this->assertIsString($app);
        $this->assertIsString($footer);
        $this->assertIsString($page);
        $this->assertIsString($styles);

        foreach (['privacy', 'terms', 'cookies'] as $policy) {
            $this->assertStringContainsString("<Route path=\"/{$policy}\"", $app);
            $this->assertStringContainsString("<LegalPage policy=\"{$policy}\" />", $app);
            $this->assertStringContainsString("to=\"/{$policy}\"", $footer);
        }

        $this->assertStringContainsString('Data Privacy Act of 2012', $page);
        $this->assertStringContainsString('National Privacy Commission: Data Subject Rights', $page);
        $this->assertStringContainsString('We do not currently use them for behavioral advertising.', $page);
        $this->assertStringContainsString('aria-current', $page);
        $this->assertStringContainsString('@media (max-width: 600px)', $styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
    }
}
