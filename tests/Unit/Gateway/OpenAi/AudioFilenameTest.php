<?php

namespace Tests\Unit\Gateway\OpenAi;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AudioFilenameTest extends TestCase
{
    protected function callAudioFilename(TranscribableAudio $audio): string
    {
        $dispatcher = new class implements Dispatcher
        {
            public function listen($events, $listener = null) {}

            public function hasListeners($eventName) {}

            public function subscribe($subscriber) {}

            public function until($event, $payload = []) {}

            public function dispatch($event, $payload = [], $halt = false) {}

            public function push($event, $payload = []) {}

            public function flush($event) {}

            public function forget($event) {}

            public function forgetPushed() {}
        };

        $gateway = new OpenAiGateway($dispatcher);

        $method = new ReflectionMethod($gateway, 'audioFilename');

        return $method->invoke($gateway, $audio);
    }

    public function test_webm_audio_gets_webm_extension(): void
    {
        $audio = new Base64Audio(base64_encode('fake'), 'audio/webm');

        $this->assertEquals('audio.webm', $this->callAudioFilename($audio));
    }

    public function test_ogg_audio_gets_ogg_extension(): void
    {
        $audio = new Base64Audio(base64_encode('fake'), 'audio/ogg');

        $this->assertEquals('audio.ogg', $this->callAudioFilename($audio));
    }

    public function test_wav_audio_gets_wav_extension(): void
    {
        $audio = new Base64Audio(base64_encode('fake'), 'audio/wav');

        $this->assertEquals('audio.wav', $this->callAudioFilename($audio));
    }

    public function test_m4a_audio_gets_m4a_extension(): void
    {
        $audio = new Base64Audio(base64_encode('fake'), 'audio/m4a');

        $this->assertEquals('audio.m4a', $this->callAudioFilename($audio));
    }

    public function test_flac_audio_gets_flac_extension(): void
    {
        $audio = new Base64Audio(base64_encode('fake'), 'audio/flac');

        $this->assertEquals('audio.flac', $this->callAudioFilename($audio));
    }

    public function test_mp3_audio_gets_mp3_extension(): void
    {
        $audio = new Base64Audio(base64_encode('fake'), 'audio/mpeg');

        $this->assertEquals('audio.mp3', $this->callAudioFilename($audio));
    }

    public function test_unknown_mime_defaults_to_mp3(): void
    {
        $audio = new Base64Audio(base64_encode('fake'), 'audio/unknown');

        $this->assertEquals('audio.mp3', $this->callAudioFilename($audio));
    }

    public function test_null_mime_defaults_to_mp3(): void
    {
        $audio = new Base64Audio(base64_encode('fake'), null);

        $this->assertEquals('audio.mp3', $this->callAudioFilename($audio));
    }

    public function test_custom_name_via_as_method_is_respected(): void
    {
        $audio = (new Base64Audio(base64_encode('fake'), 'audio/webm'))->as('my-recording.webm');

        $this->assertEquals('my-recording.webm', $this->callAudioFilename($audio));
    }
}
