<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\RequiresInput;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\Response;
use Stringable;

class AskUser implements RequiresInput, Tool
{
    public function description(): string
    {
        return 'Ask the user a multiple-choice question when you need their preference. Offer 2 to 6 short options. The user may pick several when multiple is true, or type their own answer.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()->required(),
            'options' => $schema->array()->items($schema->string())->min(2)->max(6)->required(),
            'multiple' => $schema->boolean()->required(),
        ];
    }

    public function input(JsonSchema $schema, Request $request): array
    {
        return [
            'answers' => $schema->array()
                ->items($schema->string()->max(200))
                ->min(1)
                ->max($request['multiple'] ? count($request['options']) + 1 : 1)
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $answers = $request['answers'];

        $typed = array_values(array_diff($answers, $request['options']));

        return new Response(
            'The user answered: '.implode(', ', $answers).($typed ? '. Typed by the user: '.implode(', ', $typed) : ''),
            data: ['question' => $request['question'], 'answers' => $answers, 'typed' => $typed],
        );
    }
}
