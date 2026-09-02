<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Presentable;
use Laravel\Ai\Contracts\Surface;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Surfaces\ReceiptCard;

class PresentableReceiptTool implements Presentable, Tool
{
    public function description(): string
    {
        return 'Shows a receipt.';
    }

    public function present(Request $request): Surface
    {
        return new ReceiptCard($request['total']);
    }

    public function handle(Request $request): string
    {
        return 'Receipt shown.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
