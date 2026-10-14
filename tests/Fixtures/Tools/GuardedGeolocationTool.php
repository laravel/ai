<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\NeedsInput;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GuardedGeolocationTool implements Approvable, NeedsInput, Tool
{
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Reads the browser location unless it already has one, and always asks before using it.';
    }

    public function needsInput(JsonSchema $schema, Request $request): array
    {
        return ['latitude' => $schema->string()->required()];
    }

    public function handle(Request $request): Stringable|string
    {
        return 'at: '.$request['latitude'];
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required('Uses your location.');
    }
}
