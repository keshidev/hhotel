<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomepageDiscoverySectionsTest extends TestCase
{
    public function test_homepage_includes_cms_driven_gallery_and_nearby_places(): void
    {
        $home = file_get_contents(base_path('../react/src/pages/Home.jsx'));
        $context = file_get_contents(base_path('../react/src/context/CmsContext.jsx'));
        $styles = file_get_contents(base_path('../react/src/pages/Home.css'));

        $this->assertStringContainsString('section-gallery', $home);
        $this->assertStringContainsString('section-nearby', $home);
        $this->assertStringContainsString('No guest feedback yet', $home);
        $this->assertStringContainsString('testimonialCarouselRef', $home);
        $this->assertStringContainsString('testimonial-mobile-controls', $home);
        $this->assertStringContainsString('scroll-snap-type: x mandatory', $styles);
        $this->assertStringContainsString('galleryItems', $context);
        $this->assertStringContainsString('nearbyItems', $context);
        $this->assertStringContainsString('role="dialog"', $home);
        $this->assertStringContainsString("event.key === 'Escape'", $home);
        $this->assertStringContainsString('rel="noopener noreferrer"', $home);
        $this->assertStringContainsString('@media (max-width: 480px)', $styles);
    }
}
