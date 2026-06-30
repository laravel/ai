<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\FileStorage;
use Laravel\Ai\Tools\Filesystem\CopyFile;
use Laravel\Ai\Tools\Filesystem\DeleteFile;
use Laravel\Ai\Tools\Filesystem\FileExists;
use Laravel\Ai\Tools\Filesystem\GetFileMetadata;
use Laravel\Ai\Tools\Filesystem\GetFileUrl;
use Laravel\Ai\Tools\Filesystem\ListFiles;
use Laravel\Ai\Tools\Filesystem\ReadFile;
use Laravel\Ai\Tools\Filesystem\WriteFile;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;

use function Laravel\Ai\agent;

beforeEach(function () {
    Storage::fake('local');
});

test('list files returns files and directories under a path', function () {
    Storage::disk('local')->put('docs/a.txt', 'A');
    Storage::disk('local')->put('docs/nested/b.txt', 'B');
    Storage::disk('local')->put('root.txt', 'R');

    $result = (new ListFiles('local'))->handle(new Request(['path' => 'docs']));

    expect($result)->toContain('docs/a.txt')
        ->toContain('docs/nested')
        ->not->toContain('root.txt');
});

test('list files can recurse into subdirectories', function () {
    Storage::disk('local')->put('docs/a.txt', 'A');
    Storage::disk('local')->put('docs/nested/b.txt', 'B');

    $result = (new ListFiles('local'))->handle(new Request(['path' => 'docs', 'recursive' => true]));

    expect($result)->toContain('docs/a.txt')->toContain('docs/nested/b.txt');
});

test('read file returns text contents', function () {
    Storage::disk('local')->put('note.txt', 'Hello world');

    $result = (new ReadFile('local'))->handle(new Request(['path' => 'note.txt']));

    expect($result)->toBe('Hello world');
});

test('read file reports a missing file', function () {
    $result = (new ReadFile('local'))->handle(new Request(['path' => 'missing.txt']));

    expect($result)->toBe('File [missing.txt] does not exist.');
});

test('read file rejects an oversized file', function () {
    Storage::disk('local')->put('big.txt', str_repeat('a', 300 * 1024));

    $result = (new ReadFile('local'))->handle(new Request(['path' => 'big.txt']));

    expect($result)->toContain('too large to read inline');
});

test('read file rejects a binary file', function () {
    Storage::disk('local')->put('image.bin', "\xff\xfe\x00\x01binary");

    $result = (new ReadFile('local'))->handle(new Request(['path' => 'image.bin']));

    expect($result)->toContain('appears to be binary');
});

test('file exists reports presence and absence', function () {
    Storage::disk('local')->put('there.txt', 'x');

    expect((new FileExists('local'))->handle(new Request(['path' => 'there.txt'])))
        ->toBe('File [there.txt] exists.');

    expect((new FileExists('local'))->handle(new Request(['path' => 'nope.txt'])))
        ->toBe('File [nope.txt] does not exist.');
});

test('file exists does not report directories as files', function () {
    Storage::disk('local')->makeDirectory('docs');

    $result = (new FileExists('local'))->handle(new Request(['path' => 'docs']));

    expect($result)->toBe('File [docs] does not exist.');
});

test('file metadata returns size and mime type', function () {
    Storage::disk('local')->put('data.txt', 'twelve bytes');

    $metadata = json_decode((new GetFileMetadata('local'))->handle(new Request(['path' => 'data.txt'])), true);

    expect($metadata['size'])->toBe(12)
        ->and($metadata)->toHaveKeys(['mime_type', 'last_modified', 'visibility']);
});

test('file metadata reports a missing file', function () {
    $result = (new GetFileMetadata('local'))->handle(new Request(['path' => 'missing.txt']));

    expect($result)->toBe('File [missing.txt] does not exist.');
});

test('file url returns a usable string and never throws', function () {
    Storage::disk('local')->put('pic.txt', 'x');

    expect((new GetFileUrl('local'))->handle(new Request(['path' => 'pic.txt'])))->toContain('pic.txt');

    expect((new GetFileUrl('local'))->handle(new Request(['path' => 'pic.txt', 'expires_in_minutes' => 5])))->toContain('pic.txt');
});

test('file url reports a missing file', function () {
    $result = (new GetFileUrl('local'))->handle(new Request(['path' => 'missing.txt']));

    expect($result)->toBe('File [missing.txt] does not exist.');
});

test('file url does not generate urls for directories', function () {
    Storage::disk('local')->makeDirectory('docs');

    $result = (new GetFileUrl('local'))->handle(new Request(['path' => 'docs']));

    expect($result)->toBe('File [docs] does not exist.');
});

test('write file creates a file', function () {
    $result = (new WriteFile('local'))->handle(new Request(['path' => 'out.txt', 'contents' => 'written']));

    expect($result)->toContain('Wrote');
    Storage::disk('local')->assertExists('out.txt');
    expect(Storage::disk('local')->get('out.txt'))->toBe('written');
});

test('write file reports write failures', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->with('out.txt', 'written')->andReturnFalse();

    $result = (new WriteFile($disk))->handle(new Request(['path' => 'out.txt', 'contents' => 'written']));

    expect($result)->toBe('Unable to write [out.txt].');
});

test('delete file removes a file', function () {
    Storage::disk('local')->put('gone.txt', 'x');

    $result = (new DeleteFile('local'))->handle(new Request(['path' => 'gone.txt']));

    expect($result)->toBe('Deleted [gone.txt].');
    Storage::disk('local')->assertMissing('gone.txt');
});

test('delete file reports a missing file', function () {
    $result = (new DeleteFile('local'))->handle(new Request(['path' => 'missing.txt']));

    expect($result)->toBe('File [missing.txt] does not exist.');
});

test('delete file does not report directories as files', function () {
    Storage::disk('local')->makeDirectory('docs');

    $result = (new DeleteFile('local'))->handle(new Request(['path' => 'docs']));

    expect($result)->toBe('File [docs] does not exist.');
    Storage::disk('local')->assertExists('docs');
});

test('delete file reports delete failures', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('size')->once()->with('gone.txt')->andReturn(1);
    $disk->shouldReceive('delete')->once()->with('gone.txt')->andReturnFalse();

    $result = (new DeleteFile($disk))->handle(new Request(['path' => 'gone.txt']));

    expect($result)->toBe('Unable to delete [gone.txt].');
});

test('copy file duplicates a file', function () {
    Storage::disk('local')->put('src.txt', 'data');

    $result = (new CopyFile('local'))->handle(new Request(['from' => 'src.txt', 'to' => 'dst.txt']));

    expect($result)->toBe('Copied [src.txt] to [dst.txt].');
    Storage::disk('local')->assertExists('dst.txt');
});

test('copy file reports a missing source', function () {
    $result = (new CopyFile('local'))->handle(new Request(['from' => 'missing.txt', 'to' => 'dst.txt']));

    expect($result)->toBe('Unable to copy [missing.txt] to [dst.txt]. The source file may not exist.');
});

test('file storage tools all returns every tool as a collection', function () {
    $tools = FileStorage::all('local');

    expect($tools)->toBeInstanceOf(Collection::class)
        ->toHaveCount(8)
        ->and($tools->contains(fn ($tool) => $tool instanceof WriteFile))->toBeTrue();
});

test('file storage tools can be filtered as a collection', function () {
    $tools = FileStorage::all('local')
        ->reject(fn ($tool) => $tool instanceof DeleteFile);

    expect($tools)->toHaveCount(7)
        ->and($tools->contains(fn ($tool) => $tool instanceof DeleteFile))->toBeFalse();
});

test('file storage tools readOnly returns only read tools', function () {
    $tools = FileStorage::readOnly('local');

    expect($tools)->toBeInstanceOf(Collection::class)
        ->toHaveCount(5)
        ->and($tools->contains(fn ($tool) => $tool instanceof ReadFile))->toBeTrue()
        ->and($tools->contains(fn ($tool) => $tool instanceof WriteFile))->toBeFalse()
        ->and($tools->contains(fn ($tool) => $tool instanceof DeleteFile))->toBeFalse()
        ->and($tools->contains(fn ($tool) => $tool instanceof CopyFile))->toBeFalse();
});

test('filesystem tool names resolve to class basenames', function () {
    expect(ToolNameResolver::resolve(new ReadFile('local')))->toBe('ReadFile')
        ->and(ToolNameResolver::resolve(new ListFiles('local')))->toBe('ListFiles')
        ->and(ToolNameResolver::resolve(new WriteFile('local')))->toBe('WriteFile');
});

test('filesystem tool schemas build', function () {
    $schema = (new CopyFile('local'))->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys(['from', 'to']);
});

test('every filesystem tool maps to a strict-compliant openai schema', function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);

    Http::fake(['*' => fakeOpenAiResponse('ok')]);

    agent(tools: FileStorage::all('local'))
        ->prompt('List the files', provider: 'openai');

    Http::assertSent(function (Illuminate\Http\Client\Request $request) {
        $tools = collect(data_get(json_decode($request->body(), true), 'tools'))->where('type', 'function');

        if ($tools->count() !== 8) {
            return false;
        }

        foreach ($tools as $tool) {
            $properties = array_keys($tool['parameters']['properties'] ?? []);

            if (($tool['strict'] ?? false) !== true
                || array_diff($properties, $tool['parameters']['required'] ?? [])
                || ($tool['parameters']['additionalProperties'] ?? null) !== false) {
                return false;
            }
        }

        return true;
    });
});

test('agent copies a file end to end', function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);

    Storage::disk('local')->putFileAs('photos', UploadedFile::fake()->image('photo1.jpg'), 'photo1.jpg');

    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiFileToolCall('CopyFile', ['from' => 'photos/photo1.jpg', 'to' => 'wallpapers/photo1.jpg']),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    (new FileStorageAgent)->prompt('Copy photo1 into the wallpapers folder', provider: 'openai');

    expect(Http::recorded())->toHaveCount(2);

    Storage::disk('local')->assertExists(['photos/photo1.jpg', 'wallpapers/photo1.jpg']);
    Storage::disk('local')->assertCount('wallpapers', 1);
});

test('agent deletes a file end to end', function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);

    Storage::disk('local')->putFileAs('photos', UploadedFile::fake()->image('photo1.jpg'), 'photo1.jpg');
    Storage::disk('local')->assertExists('photos/photo1.jpg');

    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiFileToolCall('DeleteFile', ['path' => 'photos/photo1.jpg']),
            fakeOpenAiResponse('Deleted'),
        ]),
    ]);

    (new FileStorageAgent)->prompt('Delete photo1', provider: 'openai');

    Storage::disk('local')->assertMissing('photos/photo1.jpg');
    Storage::disk('local')->assertDirectoryEmpty('photos');
});

function fakeOpenAiFileToolCall(string $name, array $arguments): PromiseInterface
{
    $id = uniqid();

    return Http::response([
        'id' => 'resp_tool_'.$id,
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_'.$id,
            'call_id' => 'call_'.$id,
            'name' => $name,
            'arguments' => json_encode($arguments),
            'status' => 'completed',
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}

class FileStorageAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You manage files on disk using the available tools.';
    }

    public function tools(): iterable
    {
        return FileStorage::all('local');
    }
}
