<?php

use Laravel\Ai\Responses\Data\FinishReason;

test('finish reason enum has expected cases', function () {
    expect(FinishReason::Stop->value)->toBe('stop')
        ->and(FinishReason::ToolCalls->value)->toBe('tool_calls')
        ->and(FinishReason::Continue->value)->toBe('continue')
        ->and(FinishReason::Length->value)->toBe('length')
        ->and(FinishReason::ContentFilter->value)->toBe('content_filter')
        ->and(FinishReason::Error->value)->toBe('error')
        ->and(FinishReason::Unknown->value)->toBe('unknown');
});
