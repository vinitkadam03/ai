<?php

describe('reasoning capture', function () {
    test('captures reasoning content into providerContentBlocks', function () {
        $client = $this->fakeBedrockConverse([
            'output' => [
                'message' => [
                    'content' => [
                        ['reasoningContent' => ['reasoningText' => ['text' => 'thinking...', 'signature' => 'sig-1']]],
                        ['text' => 'Hello'],
                    ],
                ],
            ],
            'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
            'stopReason' => 'end_turn',
        ]);

        $gateway = $this->gatewayWithClient($client);

        $response = $gateway->generateText(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
        );

        expect($response->providerContentBlocks)->toEqual([
            ['reasoningContent' => ['reasoningText' => ['text' => 'thinking...', 'signature' => 'sig-1']]],
            ['text' => 'Hello'],
        ]);
        expect($response->text)->toBe('Hello');
    });
});
