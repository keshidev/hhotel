<?php

namespace Tests\Feature;

use Tests\TestCase;

class FrontendRewriteSafetyTest extends TestCase
{
    public function test_spa_rewrite_preserves_laravel_api_routes(): void
    {
        $contents = file_get_contents(base_path('../react/public/.htaccess'));
        $apiGuardPosition = strpos($contents, 'RewriteCond %{REQUEST_URI} !^/api/public/');
        $apiRulePosition = strpos($contents, 'RewriteRule ^api(?:/.*)?$ api/public/index.php [L,QSA]');
        $spaRulePosition = strpos($contents, 'RewriteRule ^ index.html [L]');

        $this->assertNotFalse($apiGuardPosition);
        $this->assertNotFalse($apiRulePosition);
        $this->assertNotFalse($spaRulePosition);
        $this->assertLessThan($apiRulePosition, $apiGuardPosition);
        $this->assertLessThan($spaRulePosition, $apiRulePosition);
    }
}
