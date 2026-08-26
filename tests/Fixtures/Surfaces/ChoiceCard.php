<?php

namespace Tests\Fixtures\Surfaces;

use Laravel\Ai\Contracts\Surface;

class ChoiceCard implements Surface
{
    /**
     * @param  array<int, string>  $options
     */
    public function __construct(
        public readonly string $question,
        public readonly array $options,
    ) {}

    public static function name(): string
    {
        return 'choice-card';
    }

    public static function actions(): array
    {
        return ['select'];
    }

    public function toArray(): array
    {
        return ['question' => $this->question, 'options' => $this->options];
    }
}
