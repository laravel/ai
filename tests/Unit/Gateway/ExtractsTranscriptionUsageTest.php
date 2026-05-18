<?php

use Laravel\Ai\Gateway\Concerns\ExtractsTranscriptionUsage;

test('transcription usage prefers output tokens over total tokens', function () {
    $gateway = new class
    {
        use ExtractsTranscriptionUsage;

        public function usage(array $data)
        {
            return $this->transcriptionUsage($data);
        }
    };

    $usage = $gateway->usage([
        'usage' => [
            'input_tokens' => 100,
            'output_tokens' => 40,
            'total_tokens' => 150,
        ],
    ]);

    expect($usage->promptTokens)->toBe(100)
        ->and($usage->completionTokens)->toBe(40);
});

test('transcription usage derives completion tokens from total when output tokens are absent', function () {
    $gateway = new class
    {
        use ExtractsTranscriptionUsage;

        public function usage(array $data)
        {
            return $this->transcriptionUsage($data);
        }
    };

    $usage = $gateway->usage([
        'usage' => [
            'input_tokens' => 100,
            'total_tokens' => 150,
        ],
    ]);

    expect($usage->promptTokens)->toBe(100)
        ->and($usage->completionTokens)->toBe(50);
});
