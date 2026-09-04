<?php

beforeEach(fn () => is_dir(resource_path('instructions')) || mkdir(resource_path('instructions'), recursive: true));

afterEach(function () {
    array_map(unlink(...), glob(resource_path('instructions/*')));

    rmdir(resource_path('instructions'));
});

it('renders markdown instructions from the resources/instructions directory', function () {
    file_put_contents(resource_path('instructions/greeting.blade.md'), '# Hello, {{ $name }}.');

    expect(view('instructions::greeting', ['name' => 'Taylor'])->render())->toBe('# Hello, Taylor.');
});

it('does not html escape instruction data', function () {
    file_put_contents(resource_path('instructions/summarize.blade.md'), 'Summarize: {{ $text }}');

    expect(view('instructions::summarize', ['text' => 'Ben & Jerry\'s "best" <flavor>'])->render())
        ->toBe('Summarize: Ben & Jerry\'s "best" <flavor>');
});

it('renders blade directives in instructions', function () {
    file_put_contents(resource_path('instructions/tools.blade.md'), '@foreach ($tools as $tool)- {{ $tool }}
@endforeach');

    expect(view('instructions::tools', ['tools' => ['search', 'fetch']])->render())->toContain('- search', '- fetch');
});

it('does not affect html escaping in application views', function () {
    file_put_contents(resource_path('views/escaped.blade.php'), '{{ $text }}');

    expect(view('escaped', ['text' => '<b>'])->render())->toBe('&lt;b&gt;');

    unlink(resource_path('views/escaped.blade.php'));
});
