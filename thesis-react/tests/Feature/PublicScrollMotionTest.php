<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicScrollMotionTest extends TestCase
{
    public function test_public_scroll_motion_is_accessible_and_excludes_staff_routes(): void
    {
        $app = file_get_contents(base_path('../react/src/App.jsx'));
        $component = file_get_contents(base_path('../react/src/components/PublicScrollMotion.jsx'));
        $styles = file_get_contents(base_path('../react/src/components/PublicScrollMotion.css'));

        $this->assertStringContainsString("import PublicScrollMotion from './components/PublicScrollMotion';", $app);
        $this->assertStringContainsString('<PublicScrollMotion />', $app);
        $this->assertStringContainsString("'/admin'", $component);
        $this->assertStringContainsString("'/receptionist'", $component);
        $this->assertStringContainsString("'/login'", $component);
        $this->assertStringContainsString('IntersectionObserver', $component);
        $this->assertStringContainsString('MutationObserver', $component);
        $this->assertStringContainsString("prefers-reduced-motion: reduce", $component);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
    }
}
