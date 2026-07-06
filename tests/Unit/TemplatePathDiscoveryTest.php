<?php

declare(strict_types=1);

namespace HyperBlocks\Tests\Unit;

use HyperBlocks\Block\Block;
use HyperBlocks\Config;
use HyperBlocks\Registry;
use HyperBlocks\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the split between block-discovery paths and template-only paths.
 *
 * Background: registerBlockPath() used to conflate two behaviors — glob-based
 * block auto-discovery (Registry::discoverAndLoadFluentBlocks) and template
 * validation (Block::validateTemplatePath / Renderer::validateTemplatePath).
 * A consumer that registered a directory merely so setRenderTemplateFile()
 * could resolve render templates stored there would also have every .hb.php /
 * .php in it require_once'd as a block definition on init, fatalling when a
 * template expected a render context.
 *
 * The fix is additive: block_paths stays the discovery+validation set; a new
 * template_paths set is validation-only and never scanned. These tests pin
 * both halves of the split and the backwards-compat default.
 *
 * Fixture layout relies on the discovery glob matching only files one
 * directory deep (basePath followed by two stars, slash, star dot hb dot php).
 * Canary files live at `<dir>/sub/canary.hb.php`
 * so that IF a path were scanned, the canary WOULD load — making "not loaded"
 * a meaningful assertion. Render templates live directly in the base, where the
 * glob never reaches, so they are resolved only via the explicit render path.
 */
class TemplatePathDiscoveryTest extends TestCase
{
    private string $tmpRoot;
    private string $discDir;
    private string $tplDir;

    protected function setUp(): void
    {
        Config::reset();
        Registry::reset();
        unset($GLOBALS['__hb_disc_canary'], $GLOBALS['__hb_tpl_canary']);

        $this->tmpRoot = rtrim(sys_get_temp_dir(), '/\\') . '/hb-tpl-test-' . uniqid('', true);
        $this->discDir = $this->tmpRoot . '/disc';
        $this->tplDir = $this->tmpRoot . '/tpl';

        foreach ([
            $this->discDir . '/sub',
            $this->tplDir . '/sub',
        ] as $dir) {
            mkdir($dir, 0777, true);
        }

        // Discovery canary: one subdir deep so the glob WOULD find it.
        file_put_contents(
            $this->discDir . '/sub/canary.hb.php',
            "<?php\n\$GLOBALS['__hb_disc_canary'] = (\$GLOBALS['__hb_disc_canary'] ?? 0) + 1;\n"
        );

        // Template-only canary: identical layout; loaded only if the template
        // path is wrongly scanned.
        file_put_contents(
            $this->tplDir . '/sub/canary.hb.php',
            "<?php\n\$GLOBALS['__hb_tpl_canary'] = (\$GLOBALS['__hb_tpl_canary'] ?? 0) + 1;\n"
        );

        // Render templates live directly in the base (never globbed).
        file_put_contents(
            $this->tplDir . '/hello.hb.php',
            '<h1 class="hb"><?= esc_html($heading ?? "") ?></h1>'
        );
        file_put_contents(
            $this->discDir . '/render.hb.php',
            '<p class="hb"><?= esc_html($heading ?? "") ?></p>'
        );

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpRoot);
        Config::reset();
        Registry::reset();
        unset($GLOBALS['__hb_disc_canary'], $GLOBALS['__hb_tpl_canary']);
        parent::tearDown();
    }

    /**
     * A template-only path (registerTemplatePath) resolves render templates
     * via setRenderTemplateFile() and the Renderer, but is NOT scanned by
     * discoverAndLoadFluentBlocks().
     */
    public function testTemplateOnlyPathResolvesTemplatesButIsNotScanned(): void
    {
        Config::registerTemplatePath($this->tplDir);

        // Routed to template_paths only.
        $this->assertContains($this->tplDir, Config::getTemplatePaths());
        $this->assertNotContains($this->tplDir, Config::getBlockPaths());

        // Resolves a render template (validation allowlist includes template_paths).
        $block = Block::make('Tpl Block')
            ->setName('test/tpl-block')
            ->setRenderTemplateFile('hello.hb.php');
        $this->assertSame('file:hello.hb.php', $block->render_template);

        // Discovery must NOT scan the template-only path.
        $loaded = Registry::getInstance()->discoverAndLoadFluentBlocks();
        $this->assertNotContains($this->tplDir . '/sub/canary.hb.php', $loaded);
        $this->assertArrayNotHasKey('__hb_tpl_canary', $GLOBALS);

        // Renderer resolves + renders the template from the template-only path.
        $html = (new Renderer())->render('file:hello.hb.php', ['heading' => 'World']);
        $this->assertSame('<h1 class="hb">World</h1>', $html);
    }

    /**
     * A default discovery-enabled path (registerBlockPath with no options) is
     * both scanned for block definitions AND kept in the validation allowlist.
     * Regression guard for existing consumers.
     */
    public function testDefaultDiscoveryPathIsScannedAndValidatesTemplates(): void
    {
        Config::registerBlockPath($this->discDir);

        // Default registration routes to block_paths (discovery set).
        $this->assertContains($this->discDir, Config::getBlockPaths());

        // Discovery scans it.
        $loaded = Registry::getInstance()->discoverAndLoadFluentBlocks();
        $this->assertContains($this->discDir . '/sub/canary.hb.php', $loaded);
        $this->assertSame(1, $GLOBALS['__hb_disc_canary'] ?? null);

        // block_paths is still in the validation allowlist.
        $block = Block::make('Disc Block')
            ->setName('test/disc-block')
            ->setRenderTemplateFile('render.hb.php');
        $this->assertSame('file:render.hb.php', $block->render_template);

        $html = (new Renderer())->render('file:render.hb.php', ['heading' => 'Hi']);
        $this->assertSame('<p class="hb">Hi</p>', $html);
    }

    /**
     * registerBlockPath($path, ['discover' => false]) excludes the path from
     * the discovery glob but keeps it in the validation allowlist. Functionally
     * equivalent to registerTemplatePath().
     */
    public function testDiscoverFalseOptionExcludesFromGlobButKeepsInAllowlist(): void
    {
        Config::registerBlockPath($this->tplDir, ['discover' => false]);

        // Routed to template_paths, not block_paths.
        $this->assertContains($this->tplDir, Config::getTemplatePaths());
        $this->assertNotContains($this->tplDir, Config::getBlockPaths());

        // Union allowlist still contains it.
        $this->assertContains($this->tplDir, Config::getTemplateValidationPaths());

        // Not scanned.
        $loaded = Registry::getInstance()->discoverAndLoadFluentBlocks();
        $this->assertNotContains($this->tplDir . '/sub/canary.hb.php', $loaded);
        $this->assertArrayNotHasKey('__hb_tpl_canary', $GLOBALS);

        // Still resolves templates.
        $block = Block::make('Opt Block')
            ->setName('test/opt-block')
            ->setRenderTemplateFile('hello.hb.php');
        $this->assertSame('file:hello.hb.php', $block->render_template);
    }

    /**
     * The validation allowlist is the union of block_paths and template_paths,
     * deduplicated, and excludes non-existent directories implicitly via the
     * caller (is_dir check). A path present in both sets is listed once.
     */
    public function testTemplateValidationPathsIsUnionAndDeduplicated(): void
    {
        Config::registerBlockPath($this->discDir);
        Config::registerTemplatePath($this->tplDir);
        // Register the same path via both APIs to exercise dedup.
        Config::registerBlockPath($this->discDir, ['discover' => false]);

        $union = Config::getTemplateValidationPaths();

        $this->assertContains($this->discDir, $union);
        $this->assertContains($this->tplDir, $union);
        $this->assertSame(
            count(array_unique($union)),
            count($union),
            'Validation paths must be deduplicated.'
        );
    }

    /**
     * Recursive best-effort fixture cleanup.
     */
    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->rmrf($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }
}
