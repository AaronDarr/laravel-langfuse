<?php

declare(strict_types=1);

namespace Laravel\Ai\Messages;

class UserMessage extends Message
{
    public function __construct(string $content)
    {
        parent::__construct('user', $content);
    }
}
