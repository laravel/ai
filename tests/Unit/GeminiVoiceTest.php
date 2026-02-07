<?php

namespace Tests\Unit;

use Laravel\Ai\Gateway\Enums\GeminiVoice;
use Tests\TestCase;

class GeminiVoiceTest extends TestCase
{
    public function test_enum_has_all_30_voices(): void
    {
        $voices = GeminiVoice::cases();

        $this->assertCount(30, $voices);
    }

    public function test_default_female_voice_is_kore(): void
    {
        $this->assertSame('Kore', GeminiVoice::defaultFemale()->value);
        $this->assertSame(GeminiVoice::KORE, GeminiVoice::defaultFemale());
    }

    public function test_default_male_voice_is_puck(): void
    {
        $this->assertSame('Puck', GeminiVoice::defaultMale()->value);
        $this->assertSame(GeminiVoice::PUCK, GeminiVoice::defaultMale());
    }

    public function test_all_method_returns_array_of_voice_names(): void
    {
        $all = GeminiVoice::all();

        $this->assertIsArray($all);
        $this->assertCount(30, $all);
        $this->assertContains('Kore', $all);
        $this->assertContains('Puck', $all);
        $this->assertContains('Aoede', $all);
    }

    public function test_random_returns_valid_voice(): void
    {
        $random = GeminiVoice::random();

        $this->assertInstanceOf(GeminiVoice::class, $random);
        $this->assertContains($random->value, GeminiVoice::all());
    }

    public function test_enum_values_match_gemini_api_names(): void
    {
        // Test a few specific voices to ensure correct casing
        $this->assertSame('Zephyr', GeminiVoice::ZEPHYR->value);
        $this->assertSame('Charon', GeminiVoice::CHARON->value);
        $this->assertSame('Aoede', GeminiVoice::AOEDE->value);
        $this->assertSame('Zubenelgenubi', GeminiVoice::ZUBENELGENUBI->value);
    }

    public function test_voice_can_be_used_in_match_statement(): void
    {
        $voice = GeminiVoice::KORE;

        $result = match ($voice) {
            GeminiVoice::KORE => 'matched',
            default => 'not matched',
        };

        $this->assertSame('matched', $result);
    }
}
