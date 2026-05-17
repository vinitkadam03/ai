<?php

namespace Tests\Fixtures\Tools;

use Closure;
use Laravel\Ai\Attributes\Concurrent;

#[Concurrent]
final class ConcurrentCallableTool extends CallableTool
{
    public function __construct(string $toolName, Closure $handler)
    {
        parent::__construct($toolName, $handler);
    }
}
