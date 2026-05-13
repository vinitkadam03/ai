<?php

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Tests\Fixtures\Tools\CallableTool;

test('tool invocation callbacks are restored after nested tool invocations', function () {
    $events = [];

    $gateway = new class
    {
        use InvokesTools;

        public function invoke(Tool $tool, array $arguments = []): string
        {
            return $this->executeTool($tool, $arguments);
        }
    };

    $makeTool = fn (string $name, Closure $handler): Tool => new CallableTool($name, $handler);

    $gateway->onToolInvocation(
        invoking: function (Tool $tool) use (&$events) {
            $events[] = 'parent invoking '.((string) $tool->description());
        },
        invoked: function (Tool $tool, array $arguments, mixed $result) use (&$events) {
            $events[] = 'parent invoked '.((string) $tool->description()).':'.$result;
        },
    );

    $nestedTool = $makeTool('nested', fn () => 'nested result');

    $delegatingTool = $makeTool('delegating', function () use ($gateway, $nestedTool, &$events) {
        $gateway->onToolInvocation(
            invoking: function (Tool $tool) use (&$events) {
                $events[] = 'sub invoking '.((string) $tool->description());
            },
            invoked: function (Tool $tool, array $arguments, mixed $result) use (&$events) {
                $events[] = 'sub invoked '.((string) $tool->description()).':'.$result;
            },
        );

        $gateway->invoke($nestedTool);

        return 'delegated result';
    });

    $siblingTool = $makeTool('sibling', fn () => 'sibling result');

    $gateway->invoke($delegatingTool);
    $gateway->invoke($siblingTool);

    expect($events)->toBe([
        'parent invoking delegating',
        'sub invoking nested',
        'sub invoked nested:nested result',
        'parent invoked delegating:delegated result',
        'parent invoking sibling',
        'parent invoked sibling:sibling result',
    ]);
});
