<?php

declare(strict_types=1);

use Axyr\Langfuse\Cache\PromptCache;
use Axyr\Langfuse\Config\LangfuseConfig;
use Axyr\Langfuse\Contracts\PromptApiClientInterface;
use Axyr\Langfuse\Contracts\ScoreApiClientInterface;
use Axyr\Langfuse\Dto\IngestionEvent;
use Axyr\Langfuse\LangfuseClient;
use Axyr\Langfuse\LaravelAi\LaravelAiSubscriber;
use Axyr\Langfuse\Objects\NullLangfuseTrace;
use Axyr\Langfuse\Prompt\PromptManager;
use Axyr\Langfuse\Testing\RecordingEventBatcher;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamedAgentResponse;

function makeLangfuseClient(): array
{
    $batcher = new RecordingEventBatcher();

    $config = new LangfuseConfig(publicKey: 'pk', secretKey: 'sk');
    $promptApiClient = Mockery::mock(PromptApiClientInterface::class);
    $promptManager = new PromptManager(
        $promptApiClient,
        new PromptCache(),
    );

    $client = new LangfuseClient(
        $batcher,
        $config,
        $promptManager,
        Mockery::mock(ScoreApiClientInterface::class),
        $promptApiClient,
    );

    return [$client, $batcher];
}

function makeAgentPrompt(string $model = 'gpt-4', ?Agent $agent = null): AgentPrompt
{
    return new AgentPrompt(
        agent: $agent ?? makeTestAgent(),
        prompt: 'Tell me a joke',
        provider: makeTestProvider(),
        model: $model,
    );
}

function makeAgentResponse(
    string $invocationId = 'inv-1',
    string $text = 'Hello world',
    int $inputTokens = 10,
    int $outputTokens = 20,
    ?string $model = 'gpt-4',
    ?string $provider = 'openai',
    ?int $cacheReadInputTokens = null,
): AgentResponse {
    return new AgentResponse(
        invocationId: $invocationId,
        text: $text,
        usage: new TextUsage(inputTokens: $inputTokens, outputTokens: $outputTokens, cacheReadInputTokens: $cacheReadInputTokens),
        meta: new Meta(provider: $provider, model: $model),
    );
}

/**
 * A run's first step starting, after its agent middleware has run: the
 * messages are the ones the model receives, middleware edits included.
 *
 * @param  array<int, mixed>|null  $messages
 */
function makeFirstStep(string $invocationId, AgentPrompt $prompt, ?array $messages = null, int $stepNumber = 0): StartingStep
{
    return new StartingStep(
        invocationId: $invocationId,
        stepNumber: $stepNumber,
        agent: $prompt->agent,
        provider: makeTestProvider(),
        model: $prompt->model,
        isFinalStep: false,
        messages: $messages ?? [new UserMessage($prompt->prompt)],
    );
}

/**
 * What laravel/ai 1.0 dispatches as a run starts: PromptingAgent, then the
 * first step once its middleware has run.
 */
function startRun(LaravelAiSubscriber $subscriber, string $invocationId, AgentPrompt $prompt, ?array $messages = null): void
{
    $subscriber->handlePromptingAgent(new PromptingAgent(invocationId: $invocationId, prompt: $prompt));
    $subscriber->handleStartingStep(makeFirstStep($invocationId, $prompt, $messages));
}

function makeTestAgent(): Agent
{
    return new class () implements Agent {};
}

function makeTestProvider(): TextProvider
{
    return new class () implements TextProvider {};
}

function makeTestTool(): Tool
{
    return new class () implements Tool {};
}

it('registers correct event mappings in subscribe', function () {
    [$client] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);

    $dispatcher = Mockery::mock(\Illuminate\Events\Dispatcher::class);

    $result = $subscriber->subscribe($dispatcher);

    expect($result)->toBe([
        PromptingAgent::class => 'handlePromptingAgent',
        StreamingAgent::class => 'handlePromptingAgent',
        StartingStep::class => 'handleStartingStep',
        AgentPrompted::class => 'handleAgentPrompted',
        AgentStreamed::class => 'handleAgentPrompted',
        InvokingTool::class => 'handleInvokingTool',
        ToolInvoked::class => 'handleToolInvoked',
    ]);
});

it('creates trace and generation on agent prompt', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);

    $prompt = makeAgentPrompt();

    startRun($subscriber, 'inv-1', $prompt);

    $subscriber->handleAgentPrompted(new AgentPrompted(
        invocationId: 'inv-1',
        prompt: $prompt,
        response: makeAgentResponse(),
    ));

    expect($batcher->events())->toHaveCount(3); // trace-create, generation-create, generation-update

    $types = array_map(fn(IngestionEvent $e) => $e->type->value, $batcher->events());
    expect($types)->toContain('trace-create')
        ->and($types)->toContain('generation-create')
        ->and($types)->toContain('generation-update');
});

it('captures usage data in generation', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);

    $prompt = makeAgentPrompt();

    startRun($subscriber, 'inv-1', $prompt);

    $subscriber->handleAgentPrompted(new AgentPrompted(
        invocationId: 'inv-1',
        prompt: $prompt,
        response: makeAgentResponse(inputTokens: 15, outputTokens: 25),
    ));

    $updateEvent = collect($batcher->events())->first(
        fn(IngestionEvent $e) => $e->type->value === 'generation-update',
    );
    $body = $updateEvent->body->toArray();

    expect($body)->toHaveKey('usage')
        ->and($body['usage']['input'])->toBe(15)
        ->and($body['usage']['output'])->toBe(25)
        ->and($body['usage']['total'])->toBe(40);
});

it('captures model name from response meta', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);

    $prompt = makeAgentPrompt('gpt-4');

    startRun($subscriber, 'inv-1', $prompt);

    $subscriber->handleAgentPrompted(new AgentPrompted(
        invocationId: 'inv-1',
        prompt: $prompt,
        response: makeAgentResponse(model: 'gpt-4-turbo'),
    ));

    $createEvent = collect($batcher->events())->first(
        fn(IngestionEvent $e) => $e->type->value === 'generation-create',
    );
    $body = $createEvent->body->toArray();

    expect($body['model'])->toBe('gpt-4-turbo');
});

it('creates trace with agent class name', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);

    $prompt = makeAgentPrompt();

    startRun($subscriber, 'inv-1', $prompt);

    $traceEvent = collect($batcher->events())->first(
        fn(IngestionEvent $e) => $e->type->value === 'trace-create',
    );
    $body = $traceEvent->body->toArray();

    // Anonymous class gets a generated name, but it should start with 'laravel-ai-'
    expect($body['name'])->toStartWith('laravel-ai-');
});

it('creates trace with correct metadata', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);

    $prompt = makeAgentPrompt('claude-3-opus');

    startRun($subscriber, 'inv-1', $prompt);

    $traceEvent = collect($batcher->events())->first(
        fn(IngestionEvent $e) => $e->type->value === 'trace-create',
    );
    $body = $traceEvent->body->toArray();

    expect($body['metadata']['model'])->toBe('claude-3-opus')
        ->and($body['metadata']['source'])->toBe('laravel-ai-auto-instrumentation');
});

it('creates span for tool invocation', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);

    // First create a trace via the run's first step
    $prompt = makeAgentPrompt();
    startRun($subscriber, 'inv-1', $prompt);

    $agent = makeTestAgent();
    $tool = makeTestTool();

    $subscriber->handleInvokingTool(new InvokingTool(
        invocationId: 'inv-1',
        toolInvocationId: 'tool-inv-1',
        agent: $agent,
        tool: $tool,
        arguments: ['query' => 'test'],
    ));

    $subscriber->handleToolInvoked(new ToolInvoked(
        invocationId: 'inv-1',
        toolInvocationId: 'tool-inv-1',
        agent: $agent,
        tool: $tool,
        arguments: ['query' => 'test'],
        result: 'Tool result',
    ));

    $spanCreateEvents = collect($batcher->events())->filter(
        fn(IngestionEvent $e) => $e->type->value === 'span-create',
    );
    $spanUpdateEvents = collect($batcher->events())->filter(
        fn(IngestionEvent $e) => $e->type->value === 'span-update',
    );

    expect($spanCreateEvents)->toHaveCount(1)
        ->and($spanUpdateEvents)->toHaveCount(1);

    $spanBody = $spanCreateEvents->first()->body->toArray();
    expect($spanBody['name'])->toStartWith('tool-')
        ->and($spanBody['input'])->toBe(['query' => 'test']);

    $spanUpdateBody = $spanUpdateEvents->first()->body->toArray();
    expect($spanUpdateBody['output'])->toBe('Tool result');
});

it('reuses existing trace across multiple prompts', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);

    $prompt = makeAgentPrompt();

    // First prompt
    startRun($subscriber, 'inv-1', $prompt);
    $subscriber->handleAgentPrompted(new AgentPrompted(
        invocationId: 'inv-1',
        prompt: $prompt,
        response: makeAgentResponse(invocationId: 'inv-1'),
    ));

    // Second prompt (same invocation ID pattern, but the trace is set as current)
    startRun($subscriber, 'inv-2', $prompt);
    $subscriber->handleAgentPrompted(new AgentPrompted(
        invocationId: 'inv-2',
        prompt: $prompt,
        response: makeAgentResponse(invocationId: 'inv-2'),
    ));

    $traceEvents = collect($batcher->events())->filter(
        fn(IngestionEvent $e) => $e->type->value === 'trace-create',
    );
    $generationEvents = collect($batcher->events())->filter(
        fn(IngestionEvent $e) => $e->type->value === 'generation-create',
    );

    // Only 1 trace created, but 2 generations
    expect($traceEvents)->toHaveCount(1)
        ->and($generationEvents)->toHaveCount(2);

    // Both generations reference the same trace
    $traceId = $traceEvents->first()->body->toArray()['id'];
    $genTraceIds = $generationEvents->map(fn(IngestionEvent $e) => $e->body->toArray()['traceId'])->all();

    expect($genTraceIds)->each->toBe($traceId);
});

it('sets current trace on langfuse client', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);

    $prompt = makeAgentPrompt();

    startRun($subscriber, 'inv-1', $prompt);

    expect($client->currentTrace())->not->toBeInstanceOf(NullLangfuseTrace::class);
});

it('handles streaming events same as non-streaming', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);

    $prompt = makeAgentPrompt();

    $subscriber->handlePromptingAgent(new StreamingAgent(
        invocationId: 'inv-1',
        prompt: $prompt,
    ));
    $subscriber->handleStartingStep(makeFirstStep('inv-1', $prompt));

    $subscriber->handleAgentPrompted(new AgentStreamed(
        invocationId: 'inv-1',
        prompt: $prompt,
        response: new StreamedAgentResponse(
            invocationId: 'inv-1',
            text: 'Streamed response',
            usage: new TextUsage(inputTokens: 5, outputTokens: 10),
            meta: new Meta(provider: 'openai', model: 'gpt-4'),
        ),
    ));

    expect($batcher->events())->toHaveCount(3);

    $updateEvent = collect($batcher->events())->first(
        fn(IngestionEvent $e) => $e->type->value === 'generation-update',
    );
    $body = $updateEvent->body->toArray();

    expect($body['output'])->toBe('Streamed response')
        ->and($body['usage']['input'])->toBe(5)
        ->and($body['usage']['output'])->toBe(10);
});

it('handles tool invoked without prior invoking tool gracefully', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);

    $subscriber->handleToolInvoked(new ToolInvoked(
        invocationId: 'inv-1',
        toolInvocationId: 'tool-inv-1',
        agent: makeTestAgent(),
        tool: makeTestTool(),
        arguments: [],
        result: 'result',
    ));

    // No span events should be created since we never called handleInvokingTool
    $spanEvents = collect($batcher->events())->filter(
        fn(IngestionEvent $e) => str_contains($e->type->value, 'span'),
    );

    expect($spanEvents)->toBeEmpty();
});

it('falls back to prompt model when response meta model is null', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);

    $prompt = makeAgentPrompt('claude-3-sonnet');

    startRun($subscriber, 'inv-1', $prompt);

    $subscriber->handleAgentPrompted(new AgentPrompted(
        invocationId: 'inv-1',
        prompt: $prompt,
        response: makeAgentResponse(model: null),
    ));

    $createEvent = collect($batcher->events())->first(
        fn(IngestionEvent $e) => $e->type->value === 'generation-create',
    );
    $body = $createEvent->body->toArray();

    expect($body['model'])->toBe('claude-3-sonnet');
});

it('captures prompt text as generation input', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);

    $prompt = makeAgentPrompt();

    startRun($subscriber, 'inv-1', $prompt);

    $subscriber->handleAgentPrompted(new AgentPrompted(
        invocationId: 'inv-1',
        prompt: $prompt,
        response: makeAgentResponse(),
    ));

    $createEvent = collect($batcher->events())->first(
        fn(IngestionEvent $e) => $e->type->value === 'generation-create',
    );
    $body = $createEvent->body->toArray();

    expect($body['input'])->toBe('Tell me a joke');
});

it('captures response text as generation output', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);

    $prompt = makeAgentPrompt();

    startRun($subscriber, 'inv-1', $prompt);

    $subscriber->handleAgentPrompted(new AgentPrompted(
        invocationId: 'inv-1',
        prompt: $prompt,
        response: makeAgentResponse(text: 'Why did the chicken cross the road?'),
    ));

    $updateEvent = collect($batcher->events())->first(
        fn(IngestionEvent $e) => $e->type->value === 'generation-update',
    );
    $body = $updateEvent->body->toArray();

    expect($body['output'])->toBe('Why did the chicken cross the road?');
});

// =========================================================================
// laravel/ai 1.0: agent middleware wraps each generation step
// =========================================================================

it('opens no trace until the run\'s first step starts', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);

    $subscriber->handlePromptingAgent(new PromptingAgent(invocationId: 'inv-1', prompt: makeAgentPrompt()));

    expect($batcher->events())->toBeEmpty()
        ->and($client->currentTrace())->toBeInstanceOf(NullLangfuseTrace::class);
});

it('binds a run to the trace the application opened in its first step\'s middleware', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);
    $prompt = makeAgentPrompt();

    $subscriber->handlePromptingAgent(new PromptingAgent(invocationId: 'inv-1', prompt: $prompt));

    // The application's step middleware opens its own trace for the turn,
    // after PromptingAgent and before the step starts.
    $appTrace = $client->trace(new \Axyr\Langfuse\Dto\TraceBody(name: 'app-turn'));
    $client->setCurrentTrace($appTrace);

    $subscriber->handleStartingStep(makeFirstStep('inv-1', $prompt));
    $subscriber->handleAgentPrompted(new AgentPrompted(invocationId: 'inv-1', prompt: $prompt, response: makeAgentResponse()));

    $traces = collect($batcher->events())->filter(fn(IngestionEvent $e) => $e->type->value === 'trace-create');
    $generation = collect($batcher->events())->first(fn(IngestionEvent $e) => $e->type->value === 'generation-create');

    expect($traces)->toHaveCount(1)
        ->and($traces->first()->body->toArray()['name'])->toBe('app-turn')
        ->and($generation->body->toArray()['traceId'])->toBe($traces->first()->body->toArray()['id']);
});

it('records the user message the model received on the first step as the generation input', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);
    $prompt = makeAgentPrompt();

    // laravel/ai 1.0's AgentPrompted carries the prompt as passed in; the
    // model got it with the middleware's additions.
    startRun($subscriber, 'inv-1', $prompt, [new UserMessage("# Knowledge\n\nJokes are allowed.\n\nTell me a joke")]);
    $subscriber->handleStartingStep(makeFirstStep('inv-1', $prompt, [new UserMessage('a later step')], stepNumber: 1));
    $subscriber->handleAgentPrompted(new AgentPrompted(invocationId: 'inv-1', prompt: $prompt, response: makeAgentResponse()));

    $generation = collect($batcher->events())->first(fn(IngestionEvent $e) => $e->type->value === 'generation-create');

    expect($generation->body->toArray()['input'])->toBe("# Knowledge\n\nJokes are allowed.\n\nTell me a joke");
});

it('reports input tokens without cache reads, as laravel/ai 0.x did', function () {
    [$client, $batcher] = makeLangfuseClient();
    $subscriber = new LaravelAiSubscriber($client);
    $prompt = makeAgentPrompt();

    startRun($subscriber, 'inv-1', $prompt);
    $subscriber->handleAgentPrompted(new AgentPrompted(
        invocationId: 'inv-1',
        prompt: $prompt,
        response: makeAgentResponse(inputTokens: 250, outputTokens: 30, cacheReadInputTokens: 50),
    ));

    $update = collect($batcher->events())->first(fn(IngestionEvent $e) => $e->type->value === 'generation-update');

    expect($update->body->toArray()['usage'])->toBe(['input' => 200, 'output' => 30, 'total' => 230]);
});
