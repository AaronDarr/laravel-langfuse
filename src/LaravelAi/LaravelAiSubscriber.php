<?php

declare(strict_types=1);

namespace Axyr\Langfuse\LaravelAi;

use Axyr\Langfuse\Contracts\LangfuseClientInterface;
use Axyr\Langfuse\Dto\GenerationBody;
use Axyr\Langfuse\Dto\SpanBody;
use Axyr\Langfuse\Dto\TraceBody;
use Axyr\Langfuse\Dto\Usage;
use Axyr\Langfuse\Objects\LangfuseSpan;
use Axyr\Langfuse\Objects\LangfuseTrace;
use Axyr\Langfuse\Objects\NullLangfuseTrace;
use DateTimeImmutable;
use DateTimeZone;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\TextUsage;

class LaravelAiSubscriber
{
    /** @var array<string, float> */
    private array $startTimes = [];

    /** @var array<string, LangfuseTrace> */
    private array $traces = [];

    /** @var array<string, LangfuseSpan> */
    private array $toolSpans = [];

    /** @var array<string, float> */
    private array $toolStartTimes = [];

    /**
     * The user message each run sent on its first step, as the model received it.
     *
     * @var array<string, string>
     */
    private array $firstStepInputs = [];

    public function __construct(
        private readonly LangfuseClientInterface $langfuse,
    ) {}

    /**
     * Starts a fallback clock only. laravel/ai 1.0 runs agent middleware around
     * each generation step, AFTER this event — so a trace that middleware opens
     * does not exist yet, and resolving one here would open a second trace for
     * the same run. The run is bound, and its clock restarted, on its first
     * step instead.
     */
    public function handlePromptingAgent(PromptingAgent $event): void
    {
        $this->startTimes[$event->invocationId] = microtime(true);
    }

    /**
     * Fires once the first step's middleware has run, so the current trace is
     * the one the application opened for this run, if it opened one. Also keeps
     * the user message as the model received it — middleware edits included —
     * for the generation input: laravel/ai 1.0's AgentPrompted carries the
     * prompt as it was passed in, before any middleware touched it.
     */
    public function handleStartingStep(StartingStep $event): void
    {
        if ($event->stepNumber !== 0) {
            return;
        }

        // A provider failover retries the same invocation as a new run, which
        // starts here again: drop the binding the failed attempt left, so the
        // retry nests under the trace that is current now.
        unset($this->traces[$event->invocationId]);

        // The generation's clock starts here, not on PromptingAgent: the
        // application's step middleware (retrieval, say) ran in between.
        $this->startTimes[$event->invocationId] = microtime(true);

        $input = $this->currentMessage($event->messages);

        $this->resolveTrace($event->invocationId, new TraceBody(
            name: 'laravel-ai-' . $this->getShortClassName($event->agent),
            input: $input,
            metadata: [
                'model' => $event->model,
                'source' => 'laravel-ai-auto-instrumentation',
            ],
        ));

        if ($input !== null) {
            $this->firstStepInputs[$event->invocationId] = $input;
        }
    }

    public function handleAgentPrompted(AgentPrompted $event): void
    {
        $startTime = $this->startTimes[$event->invocationId] ?? microtime(true);
        $endTime = microtime(true);

        $trace = $this->getOrCreateTrace($event);
        $response = $event->response;

        $model = $response->meta->model ?? $event->prompt->model;

        $generation = $trace->generation(new GenerationBody(
            name: $model,
            model: $model,
            input: $this->firstStepInputs[$event->invocationId] ?? $event->prompt->prompt,
            startTime: $this->formatTime($startTime),
        ));

        $generation->end(
            endTime: $this->formatTime($endTime),
            output: $response->text,
            usage: $this->mapUsage($response->usage),
        );

        unset($this->startTimes[$event->invocationId], $this->firstStepInputs[$event->invocationId]);
    }

    public function handleInvokingTool(InvokingTool $event): void
    {
        $this->toolStartTimes[$event->toolInvocationId] = microtime(true);

        $trace = $this->getOrCreateTraceFromTool($event);
        $toolName = $this->getShortClassName($event->tool);

        $span = $trace->span(new SpanBody(
            name: "tool-{$toolName}",
            startTime: $this->formatTime($this->toolStartTimes[$event->toolInvocationId]),
            input: $event->arguments,
        ));

        $this->toolSpans[$event->toolInvocationId] = $span;
    }

    public function handleToolInvoked(ToolInvoked $event): void
    {
        $span = $this->toolSpans[$event->toolInvocationId] ?? null;

        if ($span === null) {
            return;
        }

        $span->end(
            endTime: $this->formatTime(microtime(true)),
            output: $event->result,
        );

        unset($this->toolSpans[$event->toolInvocationId], $this->toolStartTimes[$event->toolInvocationId]);
    }

    /**
     * @return array<string, string>
     */
    public function subscribe(): array
    {
        return [
            PromptingAgent::class => 'handlePromptingAgent',
            StreamingAgent::class => 'handlePromptingAgent',
            StartingStep::class => 'handleStartingStep',
            AgentPrompted::class => 'handleAgentPrompted',
            AgentStreamed::class => 'handleAgentPrompted',
            InvokingTool::class => 'handleInvokingTool',
            ToolInvoked::class => 'handleToolInvoked',
        ];
    }

    private function getOrCreateTrace(AgentPrompted $event): LangfuseTrace
    {
        return $this->resolveTrace($event->invocationId, new TraceBody(
            name: 'laravel-ai-' . $this->getShortClassName($event->prompt->agent),
            input: $event->prompt->prompt,
            metadata: [
                'model' => $event->prompt->model,
                'source' => 'laravel-ai-auto-instrumentation',
            ],
        ));
    }

    private function getOrCreateTraceFromTool(InvokingTool $event): LangfuseTrace
    {
        return $this->resolveTrace($event->invocationId, new TraceBody(
            name: 'laravel-ai-' . $this->getShortClassName($event->agent),
            metadata: [
                'source' => 'laravel-ai-auto-instrumentation',
            ],
        ));
    }

    private function resolveTrace(string $invocationId, TraceBody $body): LangfuseTrace
    {
        if (isset($this->traces[$invocationId])) {
            return $this->traces[$invocationId];
        }

        $existing = $this->langfuse->currentTrace();

        if (! $existing instanceof NullLangfuseTrace) {
            $this->traces[$invocationId] = $existing;

            return $existing;
        }

        $trace = $this->langfuse->trace($body);
        $this->langfuse->setCurrentTrace($trace);
        $this->traces[$invocationId] = $trace;

        return $trace;
    }

    private function getShortClassName(object $object): string
    {
        $className = get_class($object);
        $parts = explode('\\', $className);

        return end($parts);
    }

    /**
     * @param  array<int, mixed>  $messages
     */
    private function currentMessage(array $messages): ?string
    {
        foreach (array_reverse($messages) as $message) {
            if ($message instanceof UserMessage) {
                return $message->content;
            }
        }

        return null;
    }

    /**
     * laravel/ai 1.0 counts cache reads and writes inside `inputTokens`. They
     * are left out here, as 0.x's OpenAI and Anthropic gateways left them out
     * of `promptTokens`: this Usage has no field for cached tokens, and
     * Langfuse would price them at the full input rate.
     */
    private function mapUsage(\Laravel\Ai\Responses\Data\Usage $usage): Usage
    {
        $input = $usage instanceof TextUsage ? $usage->uncachedInputTokens() : $usage->inputTokens;

        return new Usage(
            input: $input,
            output: $usage->outputTokens,
            total: $input + $usage->outputTokens,
        );
    }

    private function formatTime(float $microtime): string
    {
        $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', $microtime));

        if ($dt === false) {
            return now()->toIso8601ZuluString();
        }

        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
