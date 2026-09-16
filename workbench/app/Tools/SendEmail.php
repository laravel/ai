<?php

namespace Workbench\App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SendEmail implements Approvable, Tool
{
    use InteractsWithApprovals;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Send an email on behalf of the user. Requires the user to approve the send.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return 'Email sent to '.$request->string('to').' with subject ['.$request->string('subject').'].';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'to' => $schema->string()->description('The recipient email address')->required(),
            'subject' => $schema->string()->description('The email subject line')->required(),
            'body' => $schema->string()->description('The plain-text email body')->required(),
        ];
    }
}
