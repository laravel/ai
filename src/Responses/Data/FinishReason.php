<?php

namespace Laravel\Ai\Responses\Data;

enum FinishReason: string
{
    case Stop = 'stop';
    case ToolCalls = 'tool_calls';
    case Length = 'length';
    case ContentFilter = 'content_filter';
    case Error = 'error';
    case Unknown = 'unknown';
}
