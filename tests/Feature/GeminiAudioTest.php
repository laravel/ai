<?php

namespace Tests\Feature;

use Laravel\Ai\Audio;
use Laravel\Ai\Gateway\Enums\GeminiVoice;
use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use Tests\TestCase;

class GeminiAudioTest extends TestCase
{
    public function test_gemini_single_speaker_audio_generation(): void
    {
        Audio::fake([
            new AudioResponse(base64_encode('gemini-audio'), new Meta('gemini', 'gemini-2.5-flash-preview-tts'), 'audio/wav'),
        ]);

        $response = Audio::of('Hello! Welcome to Gemini text-to-speech.')
            ->voice('Kore')
            ->generate('gemini');

        $this->assertSame('gemini', $response->meta->provider);
        $this->assertSame('gemini-2.5-flash-preview-tts', $response->meta->model);
        $this->assertNotEmpty($response->audio);
        $this->assertSame('audio/wav', $response->mimeType());

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->text === 'Hello! Welcome to Gemini text-to-speech.');
    }

    public function test_gemini_default_female_voice_mapping(): void
    {
        Audio::fake();

        $response = Audio::of('Testing default female voice')
            ->voice('default-female')
            ->generate('gemini');

        $this->assertNotEmpty($response->audio);

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->voice === 'default-female');
    }

    public function test_gemini_default_male_voice_mapping(): void
    {
        Audio::fake();

        $response = Audio::of('Testing default male voice')
            ->voice('default-male')
            ->generate('gemini');

        $this->assertNotEmpty($response->audio);

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->voice === 'default-male');
    }

    public function test_gemini_multi_speaker_audio_generation(): void
    {
        Audio::fake();

        $speakers = json_encode([
            ['speaker' => 'Alice', 'voice' => 'Kore'],
            ['speaker' => 'Bob', 'voice' => 'Puck'],
        ]);

        $response = Audio::of('Alice: Hello Bob! Bob: Hi Alice, how are you?')
            ->voice($speakers)
            ->generate('gemini');

        $this->assertNotEmpty($response->audio);

        Audio::assertGenerated(function (AudioPrompt $prompt) use ($speakers) {
            return $prompt->voice === $speakers
                && $prompt->text === 'Alice: Hello Bob! Bob: Hi Alice, how are you?';
        });
    }

    public function test_gemini_audio_with_instructions(): void
    {
        Audio::fake();

        $response = Audio::of('Read this with enthusiasm!')
            ->voice('Kore')
            ->instructions('Speak with high energy and excitement')
            ->generate('gemini');

        $this->assertNotEmpty($response->audio);

        Audio::assertGenerated(function (AudioPrompt $prompt) {
            return $prompt->instructions === 'Speak with high energy and excitement'
                && $prompt->voice === 'Kore';
        });
    }

    public function test_gemini_multi_speaker_with_instructions(): void
    {
        Audio::fake();

        $speakers = json_encode([
            [
                'speaker' => 'Narrator',
                'voice' => 'Kore',
                'instructions' => 'Speak calmly and clearly',
            ],
            [
                'speaker' => 'Character',
                'voice' => 'Puck',
                'instructions' => 'Speak with excitement',
            ],
        ]);

        $response = Audio::of('Narrator: Once upon a time... Character: What an adventure!')
            ->voice($speakers)
            ->generate('gemini');

        $this->assertNotEmpty($response->audio);

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->voice === $speakers);
    }

    public function test_gemini_audio_uses_specific_model(): void
    {
        Audio::fake([
            new AudioResponse(base64_encode('pro-audio'), new Meta('gemini', 'gemini-2.5-pro-preview-tts'), 'audio/wav'),
        ]);

        $response = Audio::of('Testing specific model')
            ->voice('Kore')
            ->generate('gemini', 'gemini-2.5-pro-preview-tts');

        $this->assertSame('gemini-2.5-pro-preview-tts', $response->meta->model);
    }

    public function test_gemini_audio_with_different_voices(): void
    {
        Audio::fake();

        $voices = ['Zephyr', 'Puck', 'Charon', 'Kore', 'Fenrir', 'Aoede'];

        foreach (array_slice($voices, 0, 3) as $voice) {
            $response = Audio::of("Testing voice {$voice}")
                ->voice($voice)
                ->generate('gemini');

            $this->assertNotEmpty($response->audio);
        }

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->voice === 'Zephyr');
        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->voice === 'Puck');
        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->voice === 'Charon');
    }

    public function test_invalid_multi_speaker_json_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON format for multi-speaker configuration');

        // Create a real gateway instance to test validation
        $gateway = new \Laravel\Ai\Gateway\GeminiAudioGateway;

        // Use reflection to call protected method
        $reflection = new \ReflectionClass($gateway);
        $method = $reflection->getMethod('buildSpeechConfig');
        $method->setAccessible(true);

        // This should throw an exception
        $method->invoke($gateway, '{invalid json', null);
    }

    public function test_gemini_audio_with_voice_enum(): void
    {
        Audio::fake();

        $response = Audio::of('Testing voice enum')
            ->voice(GeminiVoice::AOEDE->value)
            ->generate('gemini');

        $this->assertNotEmpty($response->audio);

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->voice === 'Aoede');
    }

    public function test_gemini_audio_with_random_voice(): void
    {
        Audio::fake();

        $randomVoice = GeminiVoice::random();

        $response = Audio::of('Testing random voice')
            ->voice($randomVoice->value)
            ->generate('gemini');

        $this->assertNotEmpty($response->audio);

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->voice === $randomVoice->value);
    }
}
