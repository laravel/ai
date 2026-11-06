<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class DescribedApprovalTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Deletes the account after a confirmation the client renders.';
    }

    public function handle(Request $request): string
    {
        return 'deleted';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required('Deletes the account.', data: ['scope' => 'account:delete']);
    }
}
