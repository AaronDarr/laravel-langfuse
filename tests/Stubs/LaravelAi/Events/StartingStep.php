<?php

declare(strict_types=1);

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;

class StartingStep
{
    /**
     * @param  array<int, mixed>  $messages
     */
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public Agent $agent,
        public TextProvider $provider,
        public string $model,
        public bool $isFinalStep,
        public array $messages,
        public ?object $options = null,
    ) {}
}
