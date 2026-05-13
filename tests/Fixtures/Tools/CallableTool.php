<?php

namespace Tests\Fixtures\Tools;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CallableTool implements Tool
{
    public function __construct(
        private readonly string $toolName,
        private readonly Closure $handler,
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return $this->toolName;
    }

    public function handle(Request $request): string
    {
        return (string) ($this->handler)($request);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
