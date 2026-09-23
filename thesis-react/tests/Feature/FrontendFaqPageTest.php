<?php

namespace Tests\Feature;

use Tests\TestCase;

class FrontendFaqPageTest extends TestCase
{
    public function test_footer_faq_link_has_an_accessible_routed_page(): void
    {
        $app = file_get_contents(base_path('../react/src/App.jsx'));
        $footer = file_get_contents(base_path('../react/src/components/Footer.jsx'));
        $page = file_get_contents(base_path('../react/src/pages/FaqPage.jsx'));
        $styles = file_get_contents(base_path('../react/src/pages/FaqPage.css'));
        $footerStyles = file_get_contents(base_path('../react/src/components/Footer.css'));
        $userStyles = file_get_contents(base_path('../react/src/pages/admin/UserManagement.css'));

        $this->assertIsString($app);
        $this->assertIsString($footer);
        $this->assertIsString($page);
        $this->assertIsString($styles);
        $this->assertIsString($footerStyles);
        $this->assertIsString($userStyles);

        $this->assertStringContainsString('<Route path="/faq"', $app);
        $this->assertStringContainsString('<FaqPage />', $app);
        $this->assertStringContainsString('to="/faq"', $footer);
        $this->assertStringContainsString('<details className="faq-item"', $page);
        $this->assertStringContainsString('aria-label="Filter questions by category"', $page);
        $this->assertStringContainsString("get('policy_checkin'", $page);
        $this->assertStringContainsString('@media (max-width: 520px)', $styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        $this->assertStringContainsString('.footer .contact-item', $footerStyles);
        $this->assertStringContainsString('.user-management-page .contact-item', $userStyles);
    }
}
