<?php

namespace Tests\Feature;

use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\SimilaritySearch;
use Tests\TestCase;

class SimilaritySearchTest extends TestCase
{
    public function test_search_results_are_returned(): void
    {
        $data = [
            [
                'id' => 1,
                'query' => 'Test query',
            ],
            [
                'id' => 2,
                'query' => 'Test query',
            ],
        ];

        $search = new SimilaritySearch(function (string $query) use ($data) {
            return $data;
        });

        $results = $search->handle(new Request([
            'query' => 'Test query',
        ]));

        $this->assertTrue(str_contains($results, json_encode($data, JSON_PRETTY_PRINT)));
    }
}
