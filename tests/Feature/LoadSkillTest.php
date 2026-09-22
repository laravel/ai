<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Tools\LoadSkill;
use Laravel\Ai\Tools\Request;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    $this->skills = base_path('skills-'.Str::random(8));

    File::ensureDirectoryExists($this->skills);
});

afterEach(fn () => File::deleteDirectory($this->skills));

function skill(string $directory, string $frontmatter, string $body = 'Body.'): string
{
    File::ensureDirectoryExists($path = test()->skills.'/'.$directory);

    File::put($path.'/SKILL.md', "---\n{$frontmatter}\n---\n\n{$body}");

    return $path;
}

function bundle(string $path, string $file, string $contents): void
{
    File::ensureDirectoryExists(dirname($path.'/'.$file));

    File::put($path.'/'.$file, $contents);
}

test('it lists every discovered skill alphabetically in its description', function (): void {
    skill('pdf', "name: pdf\ndescription: Extract PDF text. Use when: handling PDFs.");
    skill('audit', "name: audit\ndescription: Review invoices.");

    expect((new LoadSkill([$this->skills]))->description())->toBe(
        "Load a skill's instructions before performing a task matching the skill's description. Pass a path to read one of the skill's bundled files instead.\n\n"
        ."Available skills:\n"
        ."- audit: Review invoices.\n"
        .'- pdf: Extract PDF text. Use when: handling PDFs.'
    );
});

test('it returns a skill body without its frontmatter and lists the bundled files', function (): void {
    $path = skill('pdf', "name: pdf\ndescription: Extract PDF text.", '# Extracting'."\n\n".'Run the script.');

    bundle($path, 'references/spec.md', 'Spec.');
    bundle($path, 'scripts/extract.py', 'print(1)');

    expect((new LoadSkill([$this->skills]))->handle(new Request(['name' => 'pdf'])))
        ->toContain('<skill_content name="pdf">')
        ->toContain('# Extracting')
        ->not->toContain('description: Extract PDF text.')
        ->toContain("<skill_resources>\nreferences/spec.md\nscripts/extract.py\n</skill_resources>");
});

test('it reads a file bundled with a skill', function (): void {
    $path = skill('pdf', "name: pdf\ndescription: Extract PDF text.");

    bundle($path, 'references/spec.md', 'The spec.');

    expect((new LoadSkill([$this->skills]))->handle(new Request(['name' => 'pdf', 'path' => 'references/spec.md'])))
        ->toBe('The spec.');
});

test('it will not read a file outside of the skill directory', function (): void {
    skill('pdf', "name: pdf\ndescription: Extract PDF text.");
    skill('secret', "name: secret\ndescription: Secrets.", 'Do not leak.');

    expect((new LoadSkill([$this->skills]))->handle(new Request(['name' => 'pdf', 'path' => '../secret/SKILL.md'])))
        ->toBe('File [../secret/SKILL.md] is not bundled with skill [pdf].');
});

test('it skips a skill without a description but loads one whose name does not match its directory', function (): void {
    skill('no-description', 'name: no-description');
    skill('renamed', "name: actual-name\ndescription: Still loads.");

    expect((new LoadSkill([$this->skills]))->schema(new JsonSchemaTypeFactory)['name']->toArray()['enum'])
        ->toBe(['actual-name']);
});

test('it resolves object and closure sources and lets the earliest source win a collision', function (): void {
    skill('pdf', "name: pdf\ndescription: From the directory.");

    $tool = new LoadSkill([
        new Skill('invoices', 'Review invoices.', 'Check the totals.', ['notes.md' => 'Notes.']),
        fn () => [new Skill('pdf', 'From the closure.', 'Closure body.')],
        $this->skills,
    ]);

    expect($tool->description())
        ->toContain('- pdf: From the closure.')
        ->not->toContain('From the directory.')
        ->and($tool->handle(new Request(['name' => 'invoices'])))
        ->toContain('Check the totals.')
        ->toContain("<skill_resources>\nnotes.md\n</skill_resources>")
        ->and($tool->handle(new Request(['name' => 'invoices', 'path' => 'notes.md'])))
        ->toBe('Notes.');
});

test('it reports an unknown skill and an empty source', function (): void {
    expect((new LoadSkill([$this->skills]))->handle(new Request(['name' => 'missing'])))
        ->toBe('Skill [missing] does not exist.')
        ->and((new LoadSkill([$this->skills]))->isEmpty())->toBeTrue()
        ->and((new LoadSkill([$this->skills]))->schema(new JsonSchemaTypeFactory)['name']->toArray())
        ->not->toHaveKey('enum');
});

test('it discovers skills in the resources directory by default', function (): void {
    File::ensureDirectoryExists(resource_path('skills/pdf'));

    File::put(resource_path('skills/pdf/SKILL.md'), "---\nname: pdf\ndescription: Extract PDF text.\n---\n\nBody.");

    try {
        expect((new LoadSkill)->description())->toContain('- pdf: Extract PDF text.');
    } finally {
        File::deleteDirectory(resource_path('skills'));
    }
});

test('it is sent to the provider as a LoadSkill function tool carrying the skill catalog', function (): void {
    skill('pdf', "name: pdf\ndescription: Extract PDF text.");

    config(['ai.providers.openai' => [...config('ai.providers.openai'), 'key' => 'test-key']]);

    Http::fake(['*' => fakeOpenAiResponse('ok')]);

    agent(tools: [new LoadSkill([$this->skills])])->prompt('Extract the text', provider: 'openai');

    Http::assertSent(function (Illuminate\Http\Client\Request $request): bool {
        $tool = collect(data_get(json_decode($request->body(), true), 'tools'))->firstWhere('name', 'LoadSkill');

        return str_contains($tool['description'], '- pdf: Extract PDF text.')
            && $tool['parameters']['properties']['name']['enum'] === ['pdf'];
    });
});

test('it unwraps a quoted description but keeps an unmatched quote', function (): void {
    skill('quoted', 'name: quoted'."\n".'description: "Review invoices."');
    skill('apostrophe', 'name: apostrophe'."\n".'description: Review the team\'s invoices\'');

    expect((new LoadSkill([$this->skills]))->description())
        ->toContain('- quoted: Review invoices.')
        ->toContain('- apostrophe: Review the team\'s invoices\'');
});

test('it lists no resources for a skill pointed at a missing directory', function (): void {
    $tool = new LoadSkill([new Skill('ghost', 'Gone.', 'Body.', path: $this->skills.'/missing')]);

    expect($tool->handle(new Request(['name' => 'ghost'])))
        ->toContain('Body.')
        ->not->toContain('<skill_resources>');
});
