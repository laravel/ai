<?php

namespace Laravel\Ai\Harness;

use Generator;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Harness\Runtime;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use PHPUnit\Framework\Assert;

use function Laravel\Ai\ulid;

class FakeRuntime implements Runtime
{
    public array $runs = [];

    public function __construct(protected array $responses = []) {}

    public function run(HarnessRun $run): Generator
    {
        $this->runs[] = $run;
        $response = array_shift($this->responses) ?? '';
        $response = is_callable($response) ? $response($run) : $response;
        $meta = new Meta($run->harness, $run->model, sessionId: $run->sessionId);
        $id = ulid();
        $events = is_array($response) ? $response : [
            new StreamStart(ulid(), $run->harness, $run->model, time(), ['session_id' => $run->sessionId]),
            new TextStart(ulid(), $id, time()),
            new TextDelta(ulid(), $id, (string) $response, time()),
            new TextEnd(ulid(), $id, time()),
            new StreamEnd(ulid(), 'stop', new Usage, time(), $meta),
        ];

        foreach ($events as $event) {
            yield (clone $event)->withInvocationId($run->id);
        }

        $events = new Collection($events);

        return new HarnessResult(
            TextDelta::combine($events), StreamEnd::combineUsage($events), $meta, $run->sessionId,
            $events->whereInstanceOf(ToolCall::class)->map(fn ($event) => $event->toolCall)->values(),
            $events->whereInstanceOf(ToolApprovalRequest::class)->flatMap(fn ($event) => $event->pendingApprovals)->values(),
            $events->whereInstanceOf(StreamEnd::class)->last()?->reason ?? 'stop',
        );
    }

    public function assertRan(callable $callback): void
    {
        Assert::assertTrue((new Collection($this->runs))->contains($callback), 'No matching harness run was recorded.');
    }

    public function assertRunCount(int $count): void
    {
        Assert::assertCount($count, $this->runs);
    }

    public function assertNothingRan(): void
    {
        $this->assertRunCount(0);
    }
}
