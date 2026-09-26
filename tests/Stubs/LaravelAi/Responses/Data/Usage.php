<?php

declare(strict_types=1);

namespace Laravel\Ai\Responses\Data;

class Usage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}
}
