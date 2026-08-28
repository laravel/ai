<?php

use function Laravel\Ai\instructions;

beforeEach(fn () => is_dir(resource_path('agents/instructions')) || mkdir(resource_path('agents/instructions'), recursive: true));

afterEach(function () {
    array_map(unlink(...), glob(resource_path('agents/instructions/*')));

    rmdir(resource_path('agents/instructions'));
    rmdir(resource_path('agents'));
});

it('renders markdown instructions from the resources/agents/instructions directory', function () {
    file_put_contents(resource_path('agents/instructions/greeting.blade.md'), '# Hello, {{ $name }}.');

    expect(instructions('greeting', ['name' => 'Taylor']))->toBe('# Hello, Taylor.');
});

it('does not html escape instruction data', function () {
    file_put_contents(resource_path('agents/instructions/summarize.blade.md'), 'Summarize: {{ $text }}');

    expect(instructions('summarize', ['text' => 'Ben & Jerry\'s "best" <flavor>']))
        ->toBe('Summarize: Ben & Jerry\'s "best" <flavor>');
});

it('renders blade directives in instructions', function () {
    file_put_contents(resource_path('agents/instructions/tools.blade.md'), '@foreach ($tools as $tool)- {{ $tool }}
@endforeach');

    expect(instructions('tools', ['tools' => ['search', 'fetch']]))->toContain('- search', '- fetch');
});

it('does not affect html escaping in application views', function () {
    file_put_contents(resource_path('views/escaped.blade.php'), '{{ $text }}');

    expect(view('escaped', ['text' => '<b>'])->render())->toBe('&lt;b&gt;');

    unlink(resource_path('views/escaped.blade.php'));
});
