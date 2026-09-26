<?php

declare(strict_types=1);

namespace Laravel\Ai\Messages;

class Message
{
    public function __construct(
        public string $role,
        public ?string $content = '',
    ) {}
}
