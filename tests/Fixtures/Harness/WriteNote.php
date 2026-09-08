<?php

namespace Tests\Fixtures\Harness;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class WriteNote implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct()
    {
        $this->requireApproval('Writing requires permission.');
    }

    public function description(): string
    {
        return 'Write a note.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['text' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        $data = $request->validate(['text' => 'required|string']);
        app('cache')->put('harness-test-note', $data['text']);

        return 'Wrote: '.$data['text'];
    }
}
