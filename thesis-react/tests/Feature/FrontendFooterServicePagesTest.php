<?php

namespace Tests\Feature;

use Tests\TestCase;

class FrontendFooterServicePagesTest extends TestCase
{
    public function test_every_footer_service_link_has_a_page_and_route_top_reset(): void
    {
        $app = file_get_contents(base_path('../react/src/App.jsx'));
        $footer = file_get_contents(base_path('../react/src/components/Footer.jsx'));
        $scroll = file_get_contents(base_path('../react/src/components/ScrollToTop.jsx'));
        $policies = file_get_contents(base_path('../react/src/pages/PoliciesPage.jsx'));
        $offers = file_get_contents(base_path('../react/src/pages/SpecialOffersPage.jsx'));

        foreach (['booking', 'bookings', 'offers', 'policies', 'faq'] as $path) {
            $this->assertStringContainsString("to=\"/{$path}\"", $footer);
            $this->assertStringContainsString("<Route path=\"/{$path}\"", $app);
        }

        $this->assertStringContainsString("import { useLocation } from 'react-router-dom'", $scroll);
        $this->assertStringContainsString("behavior: 'auto'", $scroll);
        $this->assertStringContainsString('[pathname, hash]', $scroll);
        $this->assertStringContainsString('policiesItems.map', $policies);
        $this->assertStringContainsString("api.get('/client/promo-codes/offers')", $offers);
        $this->assertStringContainsString("sessionStorage.setItem('promoCode', code)", $offers);
    }
}
