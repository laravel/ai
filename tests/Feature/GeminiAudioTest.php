<?php

namespace Tests\Feature;

use Laravel\Ai\Audio;
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

    public function test_gemini_voice_alias_female(): void
    {
        Audio::fake();

        $response = Audio::of('Testing female alias')
            ->voice('female')
            ->generate('gemini');

        $this->assertNotEmpty($response->audio);

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->voice === 'female');
    }

    public function test_gemini_voice_alias_male(): void
    {
        Audio::fake();

        $response = Audio::of('Testing male alias')
            ->voice('male')
            ->generate('gemini');

        $this->assertNotEmpty($response->audio);

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->voice === 'male');
    }

    public function test_gemini_multi_speaker_audio_generation(): void
    {
        Audio::fake();

        $speakers = json_encode([
            ['speaker' => 'host', 'voice' => 'female'],
            ['speaker' => 'guest', 'voice' => 'male'],
        ]);

        $response = Audio::of('host: Hello everyone! guest: Thanks for having me.')
            ->voice($speakers)
            ->generate('gemini');

        $this->assertNotEmpty($response->audio);

        Audio::assertGenerated(function (AudioPrompt $prompt) use ($speakers) {
            return $prompt->voice === $speakers
                && $prompt->text === 'host: Hello everyone! guest: Thanks for having me.';
        });
    }

    public function test_gemini_multi_speaker_same_gender(): void
    {
        Audio::fake();

        $speakers = json_encode([
            ['speaker' => 'host', 'voice' => 'female_one'],
            ['speaker' => 'guest', 'voice' => 'female_two'],
        ]);

        $response = Audio::of('host: Hello! guest: Hi there!')
            ->voice($speakers)
            ->generate('gemini');

        $this->assertNotEmpty($response->audio);

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->voice === $speakers);
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
                'voice' => 'female',
                'instructions' => 'Speak calmly and clearly',
            ],
            [
                'speaker' => 'Character',
                'voice' => 'male',
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

        $voices = ['Zephyr', 'Puck', 'Charon'];

        foreach ($voices as $voice) {
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
        $gateway = new \Laravel\Ai\Gateway\Gemini\GeminiAudioGateway;

        // Use reflection to call protected method
        $reflection = new \ReflectionClass($gateway);
        $method = $reflection->getMethod('buildSpeechConfig');
        $method->setAccessible(true);

        // This should throw an exception
        $method->invoke($gateway, '{invalid json', null);
    }

    public function test_gemini_voice_alias_resolves_correctly(): void
    {
        $gateway = new \Laravel\Ai\Gateway\Gemini\GeminiAudioGateway;

        $reflection = new \ReflectionClass($gateway);
        $method = $reflection->getMethod('resolveVoiceName');
        $method->setAccessible(true);

        $this->assertSame('Kore', $method->invoke($gateway, 'female'));
        $this->assertSame('Puck', $method->invoke($gateway, 'male'));
        $this->assertSame('Kore', $method->invoke($gateway, 'female_one'));
        $this->assertSame('Aoede', $method->invoke($gateway, 'female_two'));
        $this->assertSame('Puck', $method->invoke($gateway, 'male_one'));
        $this->assertSame('Charon', $method->invoke($gateway, 'male_two'));
        $this->assertSame('Kore', $method->invoke($gateway, 'default-female'));
        $this->assertSame('Puck', $method->invoke($gateway, 'default-male'));
        // Direct Gemini voice names pass through unchanged
        $this->assertSame('Zephyr', $method->invoke($gateway, 'Zephyr'));
        $this->assertSame('Aoede', $method->invoke($gateway, 'Aoede'));
    }
}
