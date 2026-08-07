<?php

declare(strict_types=1);

use HyperBlocks\Renderer;
use HyperBlocks\RestApi;

/*
 * Finding 3.1: a template that throws \Error (TypeError, ParseError) escapes
 * every catch (\Exception) in Renderer, leaking the temp file, the error
 * handler, and bypassing graceful degradation. After the fix (finally +
 * catch \Throwable), render() returns error HTML instead of fataling.
 */
it('returns error HTML instead of fataling when a template throws a TypeError', function (): void {
    $renderer = new Renderer();

    $html = $renderer->render('<?php throw new \TypeError("template boom"); ?>', []);

    expect($html)->toContain('hyperblocks-error');
});

/*
 * Finding 3.2: JSON block preview attributes were passed to Renderer::render()
 * unsanitized. After the fix, they are sanitized by their declared block.json
 * types before rendering (defense-in-depth behind edit_posts).
 */
it('sanitizes JSON block preview attributes by their declared types', function (): void {
    $api = new RestApi();
    $method = new \ReflectionMethod($api, 'sanitizeJsonBlockAttributes');

    $declared = [
        'heading' => ['type' => 'string'],
        'count' => ['type' => 'number'],
        'visible' => ['type' => 'boolean'],
    ];

    $result = $method->invoke($api, [
        'heading' => '<b>hello</b>',
        'count' => '42',
        'visible' => 1,
    ], $declared);

    expect($result['heading'])->toBe('hello');
    expect($result['count'])->toBe(42);
    expect($result['visible'])->toBeTrue();
});
