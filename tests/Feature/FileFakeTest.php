<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Responses\FileResponse;
use RuntimeException;
use Tests\TestCase;

class FileFakeTest extends TestCase
{
    public function test_files_can_be_faked(): void
    {
        Files::fake([
            'first-content',
            fn ($fileId) => "content-for-{$fileId}",
            new FileResponse('id', mime: 'application/json', content: 'third-content'),
        ]);

        $response = Files::get('file_1');
        $this->assertEquals('file_1', $response->id);
        $this->assertEquals('first-content', $response->content);

        $response = Files::get('file_2');
        $this->assertEquals('file_2', $response->id);
        $this->assertEquals('content-for-file_2', $response->content);

        $response = Files::get('file_3');
        $this->assertEquals('id', $response->id);
        $this->assertEquals('third-content', $response->content);
    }

    public function test_files_can_be_faked_with_no_predefined_responses(): void
    {
        Files::fake();

        $response = Files::get('file_1');
        $this->assertEquals('file_1', $response->id);
        $this->assertEquals('fake-content', $response->content);

        $response = Files::get('file_2');
        $this->assertEquals('file_2', $response->id);
        $this->assertEquals('fake-content', $response->content);
    }

    public function test_files_can_be_faked_with_a_closure(): void
    {
        Files::fake(fn ($fileId) => "content-for-{$fileId}");

        $response = Files::get('file_1');
        $this->assertEquals('file_1', $response->id);
        $this->assertEquals('content-for-file_1', $response->content);

        $response = Files::get('file_2');
        $this->assertEquals('file_2', $response->id);
        $this->assertEquals('content-for-file_2', $response->content);
    }

    public function test_files_can_prevent_stray_operations(): void
    {
        $this->expectException(RuntimeException::class);

        Files::fake()->preventStrayOperations();

        Files::get('file_1');
    }

    public function test_can_assert_file_was_uploaded(): void
    {
        Files::fake();

        Document::fromString('Hello, World!', 'text/plain')->as('document.txt')->put();
        Document::fromPath(__DIR__.'/files/document.txt')->put();
        Document::fromUpload(new UploadedFile(__DIR__.'/files/report.txt', 'report.txt'))->put();

        Files::assertUploaded(fn (StorableFile $file) => (string) $file === 'Hello, World!');

        Files::assertUploaded(fn (StorableFile $file) => trim((string) $file) === 'I am a local document.');
        Files::assertUploaded(fn (StorableFile $file) => $file->storableName() === 'document.txt');

        Files::assertUploaded(fn (StorableFile $file) => trim((string) $file) === 'I am an expense report.');
        Files::assertUploaded(fn (StorableFile $file) => $file->storableName() === 'report.txt');

        Files::assertUploaded(fn (StorableFile $file) => $file->storableMimeType() === 'text/plain');
        Files::assertNotUploaded(fn (StorableFile $file) => $file->storableMimeType() === 'application/json');
    }

    public function test_can_assert_no_files_were_uploaded(): void
    {
        Files::fake();

        Files::assertNothingUploaded();
    }

    public function test_can_assert_file_was_deleted(): void
    {
        Files::fake();

        Files::delete('file_123');

        Files::assertDeleted('file_123');
        Files::assertDeleted(fn ($id) => $id === 'file_123');
        Files::assertNotDeleted('file_456');
        Files::assertNotDeleted(fn ($id) => $id === 'file_456');
    }

    public function test_can_assert_no_files_were_deleted(): void
    {
        Files::fake();

        Files::assertNothingDeleted();
    }
}
