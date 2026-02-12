<?php

namespace Tests\Feature\Skills;

use Laravel\Ai\Skills\SkillMode;
use PHPUnit\Framework\TestCase;
use ValueError;

class SkillModeTest extends TestCase
{
    public function test_it_has_expected_cases(): void
    {
        $this->assertEquals('none', SkillMode::None->value);
        $this->assertEquals('lite', SkillMode::Lite->value);
        $this->assertEquals('full', SkillMode::Full->value);
    }

    public function test_from_value_handles_strings(): void
    {
        $this->assertEquals(SkillMode::None, SkillMode::fromValue('none'));
        $this->assertEquals(SkillMode::Lite, SkillMode::fromValue('lite'));
        $this->assertEquals(SkillMode::Full, SkillMode::fromValue('full'));
    }

    public function test_from_value_is_case_insensitive(): void
    {
        $this->assertEquals(SkillMode::None, SkillMode::fromValue('NONE'));
        $this->assertEquals(SkillMode::Lite, SkillMode::fromValue('Lite'));
        $this->assertEquals(SkillMode::Full, SkillMode::fromValue('fULL'));
    }

    public function test_from_value_handles_enum_instance(): void
    {
        $this->assertEquals(SkillMode::Lite, SkillMode::fromValue(SkillMode::Lite));
    }

    public function test_from_value_throws_exception_for_invalid_value(): void
    {
        $this->expectException(ValueError::class);
        SkillMode::fromValue('invalid');
    }
}
