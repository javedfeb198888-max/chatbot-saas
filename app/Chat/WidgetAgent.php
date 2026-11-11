<?php

namespace App\Chat;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * The model call behind one widget reply. Instructions, history and tools
 * are built per request by ChatResponder.
 */
#[MaxSteps(6)]
#[MaxTokens(800)]
#[Temperature(0.3)]
class WidgetAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function __construct(
        public string $instructions,
        public iterable $messages = [],
        public iterable $tools = [],
    ) {
    }

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function messages(): iterable
    {
        return $this->messages;
    }

    public function tools(): iterable
    {
        return $this->tools;
    }
}
