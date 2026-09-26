<?php

declare(strict_types=1);

namespace Laravel\Ai\Responses\Data;

class TextUsage extends Usage
{
    public function __construct(
        int $inputTokens = 0,
        int $outputTokens = 0,
        public ?int $cacheReadInputTokens = null,
        public ?int $cacheWriteInputTokens = null,
        public ?int $reasoningTokens = null,
    ) {
        parent::__construct($inputTokens, $outputTokens);
    }

    public function uncachedInputTokens(): int
    {
        return $this->inputTokens - ($this->cacheReadInputTokens ?? 0) - ($this->cacheWriteInputTokens ?? 0);
    }
}
