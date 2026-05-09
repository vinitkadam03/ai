<?php

namespace Laravel\Ai\Gateway\Bedrock;

use Generator;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\Bedrock\Concerns\CreatesBedrockClient;
use Laravel\Ai\Gateway\Bedrock\Concerns\MapsAttachments;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\SingleTurnResponse;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Tools\ToolNameResolver;
use stdClass;
use Throwable;

class BedrockTextGateway implements EmbeddingGateway, TextGateway
{
    use CreatesBedrockClient;
    use HandlesFailoverErrors;
    use MapsAttachments;

    protected const STRUCTURED_OUTPUT_TOOL = 'structured_output';

    /**
     * {@inheritdoc}
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
        ?string $previousResponseId = null,
        ?StepContext $context = null,
    ): SingleTurnResponse {
        $client = $this->createBedrockClient($provider, $timeout);
        $schemaTools = $schema ? $this->buildSchemaTools($schema, $tools) : null;
        $formattedTools = $schemaTools === null && ! empty($tools) ? $this->formatTools($tools) : null;

        $parameters = $this->buildConverseParameters(
            $model,
            $instructions,
            $this->formatMessages($messages),
            $schemaTools,
            $formattedTools,
            empty($tools),
            $options,
            isFinalStep: $context?->isFinalStep ?? false,
        );

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $client->converse($parameters),
            );

            $result = $response->toArray();
        } catch (Throwable $e) {
            throw BedrockException::toAiException($e, $provider->name(), $model);
        }

        $text = '';
        $toolCalls = [];
        $structured = null;
        $providerContentBlocks = [];

        foreach ($result['output']['message']['content'] ?? [] as $block) {
            $providerContentBlocks[] = $block;

            if (isset($block['text'])) {
                $text .= $block['text'];

                continue;
            }

            if (! isset($block['toolUse'])) {
                continue;
            }

            if ($schemaTools && $block['toolUse']['name'] === self::STRUCTURED_OUTPUT_TOOL) {
                $structured = $block['toolUse']['input'] ?? [];

                continue;
            }

            $toolCalls[] = new ToolCall(
                $block['toolUse']['toolUseId'],
                $block['toolUse']['name'],
                $block['toolUse']['input'] ?? [],
            );
        }

        $finishReason = $this->extractFinishReason($result);

        if ($schemaTools && $finishReason === FinishReason::ToolCalls && empty($toolCalls)) {
            $finishReason = FinishReason::Stop;
        }

        return new SingleTurnResponse(
            text: $structured !== null ? json_encode($structured) : $text,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            usage: new Usage(
                promptTokens: $result['usage']['inputTokens'] ?? 0,
                completionTokens: $result['usage']['outputTokens'] ?? 0,
                cacheWriteInputTokens: $result['usage']['cacheWriteInputTokens'] ?? 0,
                cacheReadInputTokens: $result['usage']['cacheReadInputTokens'] ?? 0,
            ),
            meta: new Meta($provider->name(), $model),
            structured: $structured,
            providerContentBlocks: $providerContentBlocks,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
        ?string $previousResponseId = null,
        ?StepContext $context = null,
    ): Generator {
        $client = $this->createBedrockClient($provider, $timeout);
        $schemaTools = $schema ? $this->buildSchemaTools($schema, $tools) : null;
        $formattedTools = $schemaTools === null && ! empty($tools) ? $this->formatTools($tools) : null;

        $parameters = $this->buildConverseParameters(
            $model,
            $instructions,
            $this->formatMessages($messages),
            $schemaTools,
            $formattedTools,
            empty($tools),
            $options,
            isFinalStep: $context?->isFinalStep ?? false,
        );

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $client->converseStream($parameters),
            );
        } catch (Throwable $e) {
            throw BedrockException::toAiException($e, $provider->name(), $model);
        }

        $messageId = (string) Str::uuid();
        $timestamp = time();

        yield (new StreamStart(
            (string) Str::uuid(),
            $provider->name(),
            $model,
            $timestamp,
        ))->withInvocationId($invocationId);

        $pendingToolCalls = [];
        $toolCalls = [];
        $structuredOutput = null;
        $currentBlockIndex = null;
        $currentBlockType = '';
        $responseContent = [];
        $reasoningId = '';
        $textId = '';
        $currentText = '';
        $currentReasoningText = '';
        $currentReasoningSignature = '';
        $currentReasoningRedacted = '';
        $stopReason = 'stop';
        $inputTokens = 0;
        $outputTokens = 0;
        $cacheReadInputTokens = 0;
        $cacheWriteInputTokens = 0;

        $emitTextStart = function () use (&$textId, $invocationId, $timestamp) {
            if ($textId !== '') {
                return null;
            }

            $textId = (string) Str::uuid();

            return (new TextStart(
                (string) Str::uuid(),
                $textId,
                $timestamp,
            ))->withInvocationId($invocationId);
        };

        $emitReasoningStart = function () use (&$reasoningId, $invocationId, $timestamp) {
            if ($reasoningId !== '') {
                return null;
            }

            $reasoningId = (string) Str::uuid();

            return (new ReasoningStart(
                (string) Str::uuid(),
                $reasoningId,
                $timestamp,
            ))->withInvocationId($invocationId);
        };

        foreach ($response['stream'] as $event) {
            if (isset($event['contentBlockStart'])) {
                $currentBlockIndex = $event['contentBlockStart']['contentBlockIndex'] ?? 0;
                $start = $event['contentBlockStart']['start'] ?? [];
                $currentBlockType = isset($start['toolUse']) ? 'toolUse' : '';

                if (isset($start['toolUse'])) {
                    $pendingToolCalls[$currentBlockIndex] = [
                        'id' => $start['toolUse']['toolUseId'] ?? '',
                        'name' => $start['toolUse']['name'] ?? '',
                        'input' => '',
                    ];
                }

                continue;
            }

            if (isset($event['contentBlockDelta'])) {
                $index = $event['contentBlockDelta']['contentBlockIndex'] ?? $currentBlockIndex;
                $delta = $event['contentBlockDelta']['delta'] ?? [];

                if (isset($delta['text'])) {
                    $currentBlockType = 'text';

                    if ($emittedEvent = $emitTextStart()) {
                        yield $emittedEvent;
                    }

                    $currentText .= $delta['text'];

                    yield (new TextDelta(
                        (string) Str::uuid(),
                        $textId,
                        $delta['text'],
                        $timestamp,
                    ))->withInvocationId($invocationId);
                } elseif (isset($delta['reasoningContent']['text'])) {
                    $currentBlockType = 'reasoning';

                    if ($emittedEvent = $emitReasoningStart()) {
                        yield $emittedEvent;
                    }

                    $currentReasoningText .= $delta['reasoningContent']['text'];

                    yield (new ReasoningDelta(
                        (string) Str::uuid(),
                        $reasoningId,
                        $delta['reasoningContent']['text'],
                        $timestamp,
                    ))->withInvocationId($invocationId);
                } elseif (isset($delta['reasoningContent']['signature'])) {
                    $currentBlockType = 'reasoning';

                    if ($emittedEvent = $emitReasoningStart()) {
                        yield $emittedEvent;
                    }

                    $currentReasoningSignature .= $delta['reasoningContent']['signature'];
                } elseif (isset($delta['reasoningContent']['redactedContent'])) {
                    $currentBlockType = 'reasoning';

                    if ($emittedEvent = $emitReasoningStart()) {
                        yield $emittedEvent;
                    }

                    $currentReasoningRedacted .= $delta['reasoningContent']['redactedContent'];
                } elseif (isset($delta['toolUse']['input'], $pendingToolCalls[$index])) {
                    $pendingToolCalls[$index]['input'] .= $delta['toolUse']['input'];
                }

                continue;
            }

            if (isset($event['contentBlockStop'])) {
                $index = $event['contentBlockStop']['contentBlockIndex'] ?? $currentBlockIndex;

                if ($currentBlockType === 'reasoning') {
                    if ($currentReasoningRedacted !== '') {
                        $responseContent[$index] = [
                            'reasoningContent' => [
                                'redactedContent' => $currentReasoningRedacted,
                            ],
                        ];
                    } else {
                        $responseContent[$index] = [
                            'reasoningContent' => [
                                'reasoningText' => [
                                    'text' => $currentReasoningText,
                                    'signature' => $currentReasoningSignature,
                                ],
                            ],
                        ];
                    }

                    yield (new ReasoningEnd(
                        (string) Str::uuid(),
                        $reasoningId,
                        $timestamp,
                    ))->withInvocationId($invocationId);

                    $currentReasoningText = '';
                    $currentReasoningSignature = '';
                    $currentReasoningRedacted = '';
                    $reasoningId = '';
                } elseif ($currentBlockType === 'text' && $textId !== '') {
                    $responseContent[$index] = ['text' => $currentText];

                    yield (new TextEnd(
                        (string) Str::uuid(),
                        $textId,
                        $timestamp,
                    ))->withInvocationId($invocationId);

                    $currentText = '';
                    $textId = '';
                } elseif ($currentBlockType === 'toolUse' && isset($pendingToolCalls[$index])) {
                    $pending = $pendingToolCalls[$index];
                    $arguments = json_decode($pending['input'] !== '' ? $pending['input'] : '{}', true) ?? [];

                    if ($schemaTools && $pending['name'] === self::STRUCTURED_OUTPUT_TOOL) {
                        $structuredOutput = json_encode($arguments);
                    } else {
                        $toolCall = new ToolCall($pending['id'], $pending['name'], $arguments);
                        $toolCalls[] = $toolCall;
                        $responseContent[$index] = [
                            'toolUse' => [
                                'toolUseId' => $toolCall->id,
                                'name' => $toolCall->name,
                                'input' => $arguments,
                            ],
                        ];

                        yield (new ToolCallEvent(
                            (string) Str::uuid(),
                            $toolCall,
                            $timestamp,
                        ))->withInvocationId($invocationId);
                    }

                    unset($pendingToolCalls[$index]);
                }

                $currentBlockType = '';

                continue;
            }

            if (isset($event['messageStop'])) {
                $stopReason = $event['messageStop']['stopReason'] ?? 'stop';

                continue;
            }

            if (isset($event['metadata']['usage'])) {
                $inputTokens += $event['metadata']['usage']['inputTokens'] ?? 0;
                $outputTokens += $event['metadata']['usage']['outputTokens'] ?? 0;
                $cacheReadInputTokens += $event['metadata']['usage']['cacheReadInputTokens'] ?? 0;
                $cacheWriteInputTokens += $event['metadata']['usage']['cacheWriteInputTokens'] ?? 0;
            }
        }

        if ($structuredOutput !== null) {
            yield (new TextDelta(
                (string) Str::uuid(),
                $messageId,
                $structuredOutput,
                $timestamp,
            ))->withInvocationId($invocationId);
        }

        $finishReason = $this->extractFinishReason(['stopReason' => $stopReason]);

        if ($schemaTools && $finishReason === FinishReason::ToolCalls && empty($toolCalls)) {
            $finishReason = FinishReason::Stop;
        }

        yield (new StreamEnd(
            (string) Str::uuid(),
            $finishReason->value,
            new Usage(
                promptTokens: $inputTokens,
                completionTokens: $outputTokens,
                cacheWriteInputTokens: $cacheWriteInputTokens,
                cacheReadInputTokens: $cacheReadInputTokens,
            ),
            $timestamp,
            providerContentBlocks: array_values($responseContent),
        ))->withInvocationId($invocationId);
    }

    /**
     * Extract and map the finish reason from the Bedrock Converse response.
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        return match ($data['stopReason'] ?? '') {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'tool_use' => FinishReason::ToolCalls,
            'max_tokens' => FinishReason::Length,
            'content_filtered', 'guardrail_intervened' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }

    /**
     * {@inheritdoc}
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        $client = $this->createBedrockClient($provider, $timeout);

        if (str_starts_with($model, 'cohere.')) {
            return $this->generateCohereEmbeddings($provider, $model, $client, $inputs, $providerOptions);
        }

        $embeddings = [];
        $totalTokens = 0;

        foreach ($inputs as $input) {
            try {
                $response = $this->withErrorHandling(
                    $provider->name(),
                    fn () => $client->invokeModel([
                        'modelId' => $model,
                        'contentType' => 'application/json',
                        'accept' => 'application/json',
                        'body' => json_encode(array_merge($providerOptions, [
                            'inputText' => $input,
                            'dimensions' => $dimensions,
                        ])),
                    ]),
                );

                $result = json_decode($response->get('body')->getContents(), true);
            } catch (Throwable $e) {
                throw BedrockException::toAiException($e, $provider->name(), $model);
            }

            if (isset($result['embedding'])) {
                $embeddings[] = $result['embedding'];
            }

            $totalTokens += $result['inputTextTokenCount'] ?? 0;
        }

        return new EmbeddingsResponse(
            $embeddings,
            $totalTokens,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate embeddings using a Cohere Bedrock model in a single batched call.
     *
     * @param  array<string>  $inputs
     */
    protected function generateCohereEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        $client,
        array $inputs,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $client->invokeModel([
                    'modelId' => $model,
                    'contentType' => 'application/json',
                    'accept' => 'application/json',
                    'body' => json_encode(array_merge(
                        ['input_type' => 'search_document'],
                        $providerOptions,
                        ['texts' => array_values($inputs)],
                    )),
                ]),
            );

            $result = json_decode($response->get('body')->getContents(), true);
        } catch (Throwable $e) {
            throw BedrockException::toAiException($e, $provider->name(), $model);
        }

        $embeddings = array_values(array_filter(
            $result['embeddings'] ?? [],
            fn ($vector) => is_array($vector),
        ));

        return new EmbeddingsResponse(
            $embeddings,
            0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Build the request parameters for the Bedrock Converse API.
     *
     * @param  array<string, mixed>|null  $schemaTools
     * @param  array<string, mixed>|null  $formattedTools  Pre-formatted real tools (used when no schema is active).
     * @param  bool  $toolsEmpty  Whether the caller passed any real tools at all.
     */
    protected function buildConverseParameters(
        string $model,
        ?string $instructions,
        array $conversationMessages,
        ?array $schemaTools,
        ?array $formattedTools,
        bool $toolsEmpty,
        ?TextGenerationOptions $options,
        bool $isFinalStep,
    ): array {
        $parameters = [
            'modelId' => $model,
            'messages' => $conversationMessages,
        ];

        if ($instructions) {
            $parameters['system'] = [['text' => $instructions]];
        }

        $toolConfig = $this->buildToolConfig($schemaTools, $formattedTools, $toolsEmpty, $isFinalStep);

        if ($toolConfig !== null) {
            $parameters['toolConfig'] = $toolConfig;
        }

        $inferenceConfig = $this->buildInferenceConfig($options);

        if (! empty($inferenceConfig)) {
            $parameters['inferenceConfig'] = $inferenceConfig;
        }

        $providerOptions = $options?->providerOptions(Lab::Bedrock);

        if (! empty($providerOptions)) {
            $parameters = array_merge($parameters, $providerOptions);
        }

        return $parameters;
    }

    /**
     * Build the inferenceConfig block for Bedrock's Converse API.
     */
    protected function buildInferenceConfig(?TextGenerationOptions $options): array
    {
        if ($options === null) {
            return [];
        }

        return Arr::whereNotNull([
            'maxTokens' => $options->maxTokens,
            'temperature' => $options->temperature,
            'topP' => $options->topP,
        ]);
    }

    /**
     * Build the synthetic structured-output tool plus any real tools.
     */
    protected function buildSchemaTools(array $schema, array $tools): array
    {
        $schemaTools = [
            [
                'toolSpec' => [
                    'name' => self::STRUCTURED_OUTPUT_TOOL,
                    'description' => 'Return the response as a structured JSON object matching the provided schema.',
                    'inputSchema' => [
                        'json' => (new ObjectSchema($schema))->toArray(),
                    ],
                ],
            ],
        ];

        return array_merge($schemaTools, $this->formatTools($tools));
    }

    /**
     * Build Bedrock's toolConfig for the current step.
     *
     * When a schema is present, toolChoice is only forced to the synthetic tool on the
     * final step so real tools can be invoked on earlier iterations.
     */
    protected function buildToolConfig(?array $schemaTools, ?array $formattedTools, bool $toolsEmpty, bool $isFinalStep): ?array
    {
        if ($schemaTools !== null) {
            return [
                'tools' => $schemaTools,
                'toolChoice' => ($isFinalStep || $toolsEmpty)
                    ? ['tool' => ['name' => self::STRUCTURED_OUTPUT_TOOL]]
                    : ['auto' => []],
            ];
        }

        if ($formattedTools !== null) {
            return ['tools' => $formattedTools];
        }

        return null;
    }

    /**
     * Format Laravel AI messages for Bedrock's Converse API.
     */
    protected function formatMessages(array $messages): array
    {
        return (new Collection($messages))->map(fn ($message) => match (true) {
            $message instanceof AssistantMessage => $this->formatAssistantMessage($message),
            $message instanceof ToolResultMessage => $this->formatToolResultMessage($message),
            $message instanceof UserMessage => $this->formatUserMessage($message),
            $message instanceof Message => $this->formatGenericMessage($message),
            default => $this->formatArrayMessage($message),
        })->all();
    }

    /**
     * Format an AssistantMessage for the Converse API.
     */
    protected function formatAssistantMessage(AssistantMessage $message): array
    {
        if (filled($message->providerContentBlocks)) {
            return [
                'role' => 'assistant',
                'content' => $this->ensureToolInputIsObject($message->providerContentBlocks),
            ];
        }

        $content = [];

        if (! empty($message->content)) {
            $content[] = ['text' => $message->content];
        }

        foreach ($message->toolCalls as $toolCall) {
            $content[] = [
                'toolUse' => [
                    'toolUseId' => $toolCall->id,
                    'name' => $toolCall->name,
                    'input' => $toolCall->arguments ?: new stdClass,
                ],
            ];
        }

        return ['role' => 'assistant', 'content' => $content];
    }

    /**
     * Ensure tool_use input blocks have their input cast to object for JSON serialization.
     */
    protected function ensureToolInputIsObject(array $content): array
    {
        return array_map(function (array $block) {
            if (isset($block['toolUse'])) {
                $block['toolUse']['input'] = (object) ($block['toolUse']['input'] ?? []);
            }

            return $block;
        }, $content);
    }

    /**
     * Format a ToolResultMessage for the Converse API.
     */
    protected function formatToolResultMessage(ToolResultMessage $message): array
    {
        $content = [];

        foreach ($message->toolResults as $toolResult) {
            $content[] = [
                'toolResult' => [
                    'toolUseId' => $toolResult->id,
                    'content' => [
                        ['text' => is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result)],
                    ],
                ],
            ];
        }

        return ['role' => 'user', 'content' => $content];
    }

    /**
     * Format a UserMessage and its attachments for the Converse API.
     */
    protected function formatUserMessage(UserMessage $message): array
    {
        $content = [['text' => $message->content]];

        if ($message->attachments->isNotEmpty()) {
            $content = array_merge($content, $this->mapAttachments($message->attachments));
        }

        return ['role' => 'user', 'content' => $content];
    }

    /**
     * Format a generic Message (system/user/assistant) for the Converse API.
     */
    protected function formatGenericMessage(Message $message): array
    {
        return [
            'role' => $message->role === MessageRole::Assistant ? 'assistant' : 'user',
            'content' => [['text' => $message->content]],
        ];
    }

    /**
     * Format a raw array-shaped message for the Converse API.
     *
     * @param  array{role: string, content: string}  $message
     */
    protected function formatArrayMessage(array $message): array
    {
        return [
            'role' => $message['role'] === MessageRole::Assistant->value ? 'assistant' : 'user',
            'content' => [['text' => $message['content']]],
        ];
    }

    /**
     * Format tools for the Converse API.
     *
     * @param  array<Tool>  $tools
     */
    protected function formatTools(array $tools): array
    {
        return (new Collection($tools))
            ->filter(fn ($tool) => $tool instanceof Tool)
            ->map(fn (Tool $tool) => [
                'toolSpec' => [
                    'name' => ToolNameResolver::resolve($tool),
                    'description' => (string) $tool->description(),
                    'inputSchema' => [
                        'json' => (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toArray(),
                    ],
                ],
            ])
            ->values()
            ->all();
    }
}
