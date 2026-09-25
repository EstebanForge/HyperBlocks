<?php

declare(strict_types=1);

use HyperBlocks\BlockOperations;
use HyperBlocks\Registry;
use HyperBlocks\Renderer;
use HyperBlocks\WordPress\Bootstrap;

beforeEach(function (): void {
    Registry::reset();
});

/*
 * InnerBlocks marker replacement. <InnerBlocks /> in a fluent template must
 * resolve to the block's real inner-blocks markup (the $content WordPress
 * passes to every dynamic render callback), never to the inert
 * <!-- wp:innerblocks /--> placeholder the old code emitted. Empty content
 * yields the editor split sentinel so the client can mount live inner blocks
 * between the preview halves.
 */

it('replaces the InnerBlocks marker with provided inner content', function (): void {
    $renderer = new Renderer();

    $html = $renderer->render('<section><h1>Hi</h1><InnerBlocks /></section>', [], '<p>Nested copy</p>');

    expect($html)->toBe('<section><h1>Hi</h1><p>Nested copy</p></section>');
});

it('emits the split sentinel when no inner content is available', function (): void {
    $renderer = new Renderer();

    $html = $renderer->render('<div><InnerBlocks /></div>', []);

    expect($html)->toBe('<div>' . Renderer::INNER_BLOCKS_SENTINEL . '</div>');
});

it('replaces every InnerBlocks marker occurrence', function (): void {
    $renderer = new Renderer();

    $html = $renderer->render('<div><InnerBlocks /><span>x</span><InnerBlocks /></div>', [], '<p>a</p>');

    expect($html)->toBe('<div><p>a</p><span>x</span><p>a</p></div>');
});

it('handles self-closing, paired, and attributed marker forms', function (): void {
    $renderer = new Renderer();

    expect($renderer->render('<i><InnerBlocks/></i>', [], 'X'))->toBe('<i>X</i>');
    expect($renderer->render('<i><InnerBlocks>junk</InnerBlocks></i>', [], 'X'))->toBe('<i>X</i>');
    expect($renderer->render('<i><InnerBlocks class="slot" /></i>', [], 'X'))->toBe('<i>X</i>');
});

it('replaces a bare unclosed open tag', function (): void {
    $renderer = new Renderer();

    expect($renderer->render('<section><InnerBlocks></section>', [], '<p>X</p>'))
        ->toBe('<section><p>X</p></section>');
    expect($renderer->render('<section><InnerBlocks></section>', []))
        ->toBe('<section>' . Renderer::INNER_BLOCKS_SENTINEL . '</section>');
});

it('matches lowercase and mixed-case marker tags', function (): void {
    $renderer = new Renderer();

    expect($renderer->render('<i><innerblocks /></i>', [], 'X'))->toBe('<i>X</i>');
    expect($renderer->render('<i><innerblocks>junk</innerblocks></i>', [], 'X'))->toBe('<i>X</i>');
    expect($renderer->render('<i><INNERBLOCKS>junk</INNERBLOCKS></i>', [], 'X'))->toBe('<i>X</i>');
});

it('tolerates quoted > inside marker attributes', function (): void {
    $renderer = new Renderer();

    // The attribute section must not terminate at the '>' inside a quoted
    // value; the tag and the surrounding markup stay intact.
    expect($renderer->render('<i><InnerBlocks data-label="a>b" /></i>', [], 'X'))
        ->toBe('<i>X</i>');
    expect($renderer->render('<i><InnerBlocks data-label="a>b">junk</InnerBlocks></i>', [], 'X'))
        ->toBe('<i>X</i>');
});

it('never interprets inner content as PCRE backreferences', function (): void {
    $renderer = new Renderer();

    // With preg_replace($pattern, $content, $html) these fragments would be
    // consumed as backreference tokens; preg_replace_callback must pass them
    // through byte for byte.
    foreach (['-$1-', '$0', '\\1', '${slot}', '\\\\', '$'] as $fragment) {
        $html = $renderer->render('<div><InnerBlocks /></div>', [], $fragment);
        expect($html)->toBe('<div>' . $fragment . '</div>');
    }
});

it('does not run RichText parsing over injected inner content', function (): void {
    $renderer = new Renderer();

    $content = '<RichText attribute="nested" tag="p" />';
    $html = $renderer->render('<div><RichText attribute="heading" tag="h2" /><InnerBlocks /></div>', [], $content);

    expect($html)->toBe('<div><h2></h2><RichText attribute="nested" tag="p" /></div>');
});

/*
 * End-to-end plumbing: the preview surface and the dynamic render callback
 * must forward the inner content to the renderer.
 */

it('forwards inner content through BlockOperations::preview', function (): void {
    Registry::getInstance()->registerFluentBlock(
        HyperBlocks\Block\Block::make('Slotted')
            ->setName('acme/slotted')
            ->setRenderTemplate('<main><InnerBlocks /></main>')
    );

    $result = BlockOperations::preview('acme/slotted', [], '<p>live</p>');

    expect($result['status'])->toBe('ok');
    expect($result['html'])->toBe('<main><p>live</p></main>');
});

it('renders the sentinel from preview when content is empty', function (): void {
    Registry::getInstance()->registerFluentBlock(
        HyperBlocks\Block\Block::make('Slotted')
            ->setName('acme/slotted')
            ->setRenderTemplate('<main><InnerBlocks /></main>')
    );

    $result = BlockOperations::preview('acme/slotted', []);

    expect($result['status'])->toBe('ok');
    expect($result['html'])->toBe('<main>' . Renderer::INNER_BLOCKS_SENTINEL . '</main>');
});

it('forwards inner content through the dynamic render callback', function (): void {
    Registry::getInstance()->registerFluentBlock(
        HyperBlocks\Block\Block::make('Slotted')
            ->setName('acme/slotted')
            ->setRenderTemplate('<main><InnerBlocks /></main>')
    );

    $wpBlock = new WP_Block();
    $wpBlock->name = 'acme/slotted';

    $html = Bootstrap::renderBlock([], '<p>saved</p>', $wpBlock);

    expect($html)->toBe('<main><p>saved</p></main>');
});

it('declares an optional content arg on the render-preview route', function (): void {
    HyperBlocks_Testing_Registry::reset();

    $api = new HyperBlocks\RestApi();
    $api->registerRoutes();

    $routes = HyperBlocks_Testing_Registry::getRestRoutes();
    $preview = null;
    foreach ($routes as $registered) {
        if ($registered['route'] === '/render-preview') {
            $preview = $registered;
        }
    }

    expect($preview)->not->toBeNull();
    // The mock records the outer register_rest_route args; field args sit one level deeper.
    expect($preview['args']['args'])->toHaveKey('content');
    expect($preview['args']['args']['content']['required'])->toBeFalse();
    expect($preview['args']['args']['content']['sanitize_callback'])->toBe('wp_kses_post');
});

it('forwards inner content through the hb_render helper', function (): void {
    $html = hb_render('<div><InnerBlocks /></div>', [], '<em>hi</em>');

    expect($html)->toBe('<div><em>hi</em></div>');
});
