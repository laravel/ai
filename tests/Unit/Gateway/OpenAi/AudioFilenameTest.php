<?php

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;

function callAudioFilename(TranscribableAudio $audio): string
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

test('webm audio gets webm extension', function () {
    $audio = new Base64Audio(base64_encode('fake'), 'audio/webm');

    expect(callAudioFilename($audio))->toEqual('audio.webm');
});

test('ogg audio gets ogg extension', function () {
    $audio = new Base64Audio(base64_encode('fake'), 'audio/ogg');

    expect(callAudioFilename($audio))->toEqual('audio.ogg');
});

test('wav audio gets wav extension', function () {
    $audio = new Base64Audio(base64_encode('fake'), 'audio/wav');

    expect(callAudioFilename($audio))->toEqual('audio.wav');
});

test('m4a audio gets m4a extension', function () {
    $audio = new Base64Audio(base64_encode('fake'), 'audio/m4a');

    expect(callAudioFilename($audio))->toEqual('audio.m4a');
});

test('flac audio gets flac extension', function () {
    $audio = new Base64Audio(base64_encode('fake'), 'audio/flac');

    expect(callAudioFilename($audio))->toEqual('audio.flac');
});

test('mp3 audio gets mp3 extension', function () {
    $audio = new Base64Audio(base64_encode('fake'), 'audio/mpeg');

    expect(callAudioFilename($audio))->toEqual('audio.mp3');
});

test('unknown mime defaults to mp3', function () {
    $audio = new Base64Audio(base64_encode('fake'), 'audio/unknown');

    expect(callAudioFilename($audio))->toEqual('audio.mp3');
});

test('null mime defaults to mp3', function () {
    $audio = new Base64Audio(base64_encode('fake'), null);

    expect(callAudioFilename($audio))->toEqual('audio.mp3');
});

test('custom name via as method is respected', function () {
    $audio = (new Base64Audio(base64_encode('fake'), 'audio/webm'))->as('my-recording.webm');

    expect(callAudioFilename($audio))->toEqual('my-recording.webm');
});
