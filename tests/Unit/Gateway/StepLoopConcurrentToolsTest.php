<?php

use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\StepLoop;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Tests\Fixtures\Tools\CallableTool;
use Tests\Fixtures\Tools\ConcurrentCallableTool;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['concurrency.default' => 'sync']);
});

function makeStepLoopForToolExecution(): StepLoop
{
    $gateway = Mockery::mock(TextGateway::class);

    return new class($gateway, app('events')) extends StepLoop
    {
        /**
         * @param  ToolCall[]  $toolCalls
         * @param  Tool[]  $tools
         * @return ToolResult[]
         */
        public function runToolCalls(array $toolCalls, array $tools): array
        {
            return $this->executeToolCalls($toolCalls, $tools);
        }
    };
}

function makeTool(string $name, bool $concurrent, Closure $handle): Tool
{
    return $concurrent
        ? new ConcurrentCallableTool($name, $handle)
        : new CallableTool($name, $handle);
}

test('concurrent tools share one pool and results follow tool call order', function () {
    $loop = makeStepLoopForToolExecution();

    $a = makeTool('a', true, fn () => 'A');
    $b = makeTool('b', true, fn () => 'B');

    $calls = [
        new ToolCall('id-1', 'a', []),
        new ToolCall('id-2', 'b', []),
    ];

    $results = $loop->runToolCalls($calls, [$a, $b]);

    expect($results)->toHaveCount(2)
        ->and($results[0]->name)->toBe('a')
        ->and($results[0]->result)->toBe('A')
        ->and($results[1]->name)->toBe('b')
        ->and($results[1]->result)->toBe('B');
});

test('mixed sequential and concurrent tools use one concurrent pool and preserve result order', function () {
    $invokingOrder = [];
    $invokedOrder = [];

    $loop = makeStepLoopForToolExecution();
    $loop->onToolInvocation(
        invoking: function (Tool $tool) use (&$invokingOrder) {
            $invokingOrder[] = $tool->name();
        },
        invoked: function (Tool $tool, array $arguments, mixed $result) use (&$invokedOrder) {
            $invokedOrder[] = $tool->name().':'.(string) $result;
        },
    );

    $seq = makeTool('sequential', false, fn () => 'S');
    $c1 = makeTool('concurrent1', true, fn () => 'C1');
    $c2 = makeTool('concurrent2', true, fn () => 'C2');

    $calls = [
        new ToolCall('1', 'concurrent1', []),
        new ToolCall('2', 'sequential', []),
        new ToolCall('3', 'concurrent2', []),
    ];

    $results = $loop->runToolCalls($calls, [$seq, $c1, $c2]);

    expect($results)->toHaveCount(3)
        ->and($results[0]->name)->toBe('concurrent1')
        ->and($results[1]->name)->toBe('sequential')
        ->and($results[2]->name)->toBe('concurrent2');

    // Sequential tools run first (plan); then one concurrent pool. Callbacks: sequential, then concurrent indices in order.
    expect($invokingOrder)->toBe(['sequential', 'concurrent1', 'concurrent2']);
    expect($invokedOrder)->toBe(['sequential:S', 'concurrent1:C1', 'concurrent2:C2']);
});
