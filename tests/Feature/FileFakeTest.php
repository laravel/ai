<?php

namespace Tests\Feature;

use Laravel\Ai\Files;
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
            new FileResponse('id', 'third-content', 'application/json'),
        ]);

        $response = Files::get('file_1');
        $this->assertEquals('first-content', $response->content);

        $response = Files::get('file_2');
        $this->assertEquals('content-for-file_2', $response->content);

        $response = Files::get('file_3');
        $this->assertEquals('third-content', $response->content);
    }

    public function test_files_can_be_faked_with_no_predefined_responses(): void
    {
        Files::fake();

        $response = Files::get('file_1');
        $this->assertEquals('fake-content', $response->content);

        $response = Files::get('file_2');
        $this->assertEquals('fake-content', $response->content);
    }

    public function test_files_can_be_faked_with_a_closure(): void
    {
        Files::fake(fn ($fileId) => "content-for-{$fileId}");

        $response = Files::get('file_1');
        $this->assertEquals('content-for-file_1', $response->content);

        $response = Files::get('file_2');
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

        Files::put('Hello, World!', 'text/plain');

        Files::assertUploaded(fn ($file, $mime) => $file === 'Hello, World!');
        Files::assertUploaded(fn ($file, $mime) => $mime === 'text/plain');
        Files::assertNotUploaded(fn ($file, $mime) => $mime === 'application/json');
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
