<?php

namespace Tests\Unit\Gateway\Prism;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\LocalAudio;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Gateway\Prism\PrismMessages;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Media\Audio as PrismAudio;
use Prism\Prism\ValueObjects\Media\Document as PrismDocument;
use Prism\Prism\ValueObjects\Media\Image as PrismImage;

class PrismMessagesTest extends TestCase
{
    private function convertAttachment(mixed $attachment): mixed
    {
        $ref = new \ReflectionMethod(PrismMessages::class, 'fromLaravelAttachments');
        $ref->setAccessible(true);

        return $ref->invoke(null, collect([$attachment]))->first();
    }

    public function test_base64_audio_converts_to_prism_audio(): void
    {
        $attachment = new Base64Audio(base64_encode('fake-audio'), 'audio/mpeg');

        $result = $this->convertAttachment($attachment);

        $this->assertInstanceOf(PrismAudio::class, $result);
    }

    public function test_remote_audio_converts_to_prism_audio(): void
    {
        $attachment = new RemoteAudio('https://example.com/audio.mp3');

        $result = $this->convertAttachment($attachment);

        $this->assertInstanceOf(PrismAudio::class, $result);
    }

    public function test_base64_image_converts_to_prism_image(): void
    {
        $attachment = new Base64Image(base64_encode('fake-image'), 'image/jpeg');

        $result = $this->convertAttachment($attachment);

        $this->assertInstanceOf(PrismImage::class, $result);
    }

    public function test_base64_document_converts_to_prism_document(): void
    {
        $attachment = new Base64Document(base64_encode('fake-doc'), 'application/pdf');

        $result = $this->convertAttachment($attachment);

        $this->assertInstanceOf(PrismDocument::class, $result);
    }

    public function test_unsupported_attachment_type_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported attachment type/');

        $notAFile = new class {};

        $ref = new \ReflectionMethod(PrismMessages::class, 'fromLaravelAttachments');
        $ref->setAccessible(true);
        $ref->invoke(null, collect([$notAFile]));
    }

    public function test_multiple_audio_attachments_all_convert(): void
    {
        $attachments = collect([
            new Base64Audio(base64_encode('audio1'), 'audio/mpeg'),
            new RemoteAudio('https://example.com/audio2.mp3'),
        ]);

        $ref = new \ReflectionMethod(PrismMessages::class, 'fromLaravelAttachments');
        $ref->setAccessible(true);
        $results = $ref->invoke(null, $attachments);

        $this->assertCount(2, $results);
        $results->each(fn ($r) => $this->assertInstanceOf(PrismAudio::class, $r));
    }
}
