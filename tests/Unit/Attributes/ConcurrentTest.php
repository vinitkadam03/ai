<?php

use Laravel\Ai\Attributes\Concurrent;
use Tests\Fixtures\Tools\CallableTool;
use Tests\Fixtures\Tools\ConcurrentCallableTool;

test('isAppliedTo returns true when target has the attribute', function () {
    expect(Concurrent::isAppliedTo(new ConcurrentCallableTool('x', fn () => 'ok')))->toBeTrue();
});

test('isAppliedTo returns false when target does not have the attribute', function () {
    expect(Concurrent::isAppliedTo(new CallableTool('x', fn () => 'ok')))->toBeFalse();
});

test('isAppliedTo returns false when target is null', function () {
    expect(Concurrent::isAppliedTo(null))->toBeFalse();
});
