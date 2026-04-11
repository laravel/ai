<?php

use Laravel\Ai\Responses\Data\FinishReason;

dataset('geminiFinishReasons', [
    'STOP maps to Stop' => ['STOP', FinishReason::Stop],
    'MAX_TOKENS maps to Length' => ['MAX_TOKENS', FinishReason::Length],
    'SAFETY maps to ContentFilter' => ['SAFETY', FinishReason::ContentFilter],
    'MALFORMED_FUNCTION_CALL maps to ContentFilter' => ['MALFORMED_FUNCTION_CALL', FinishReason::ContentFilter],
    'RECITATION maps to ContentFilter' => ['RECITATION', FinishReason::ContentFilter],
]);

dataset('anthropicFinishReasons', [
    'end_turn maps to Stop' => ['end_turn', FinishReason::Stop],
    'max_tokens maps to Length' => ['max_tokens', FinishReason::Length],
    'tool_use maps to ToolCalls' => ['tool_use', FinishReason::ToolCalls],
]);
