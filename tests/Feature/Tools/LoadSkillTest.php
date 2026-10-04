<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\HasSkills;
use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Tools\LoadSkill;
use Laravel\Ai\Tools\Request;
use Symfony\Component\Yaml\Exception\ParseException;

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
    skill('pdf', "name: pdf\ndescription: 'Extract PDF text. Use when: handling PDFs.'");
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
        ->toContain("Run the script.\n\n<skill_resources>\nreferences/spec.md\nscripts/extract.py\n</skill_resources>");
});

test('it reads a file bundled with a skill', function (): void {
    $path = skill('pdf', "name: pdf\ndescription: Extract PDF text.");

    bundle($path, 'references/spec.md', 'The spec.');

    expect((new LoadSkill([$this->skills]))->handle(new Request(['name' => 'pdf', 'path' => 'references/spec.md'])))
        ->toBe('The spec.');
});

test('it will not read a binary or oversized bundled file', function (): void {
    $path = skill('pdf', "name: pdf\ndescription: Extract PDF text.");

    bundle($path, 'assets/logo.png', "\x89PNG\x00\xff");
    bundle($path, 'references/huge.md', str_repeat('a', 256 * 1024 + 1));

    $tool = new LoadSkill([$this->skills]);

    expect($tool->handle(new Request(['name' => 'pdf', 'path' => 'assets/logo.png'])))
        ->toBe('File [assets/logo.png] appears to be binary and cannot be read as text.')
        ->and($tool->handle(new Request(['name' => 'pdf', 'path' => 'references/huge.md'])))
        ->toBe('File [references/huge.md] is too large to read inline.');
});

test('it will not read a binary or oversized in-memory file', function (): void {
    $tool = new LoadSkill([new Skill('pdf', 'Extract PDF text.', 'Body.', [
        'logo.png' => "\x89PNG\x00\xff",
        'huge.md' => str_repeat('a', 256 * 1024 + 1),
    ])]);

    expect($tool->handle(new Request(['name' => 'pdf', 'path' => 'logo.png'])))
        ->toBe('File [logo.png] appears to be binary and cannot be read as text.')
        ->and($tool->handle(new Request(['name' => 'pdf', 'path' => 'huge.md'])))
        ->toBe('File [huge.md] is too large to read inline.');
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

test('it lets the earliest source win a collision between keyed closure results', function (): void {
    $tool = new LoadSkill([
        fn () => collect([new Skill('pdf', 'From the first closure.', 'First.')])->keyBy('name'),
        fn () => collect([new Skill('pdf', 'From the second closure.', 'Second.')])->keyBy('name'),
    ]);

    expect($tool->description())->toContain('- pdf: From the first closure.');
});

test('it keeps a numeric skill name a string in the schema', function (): void {
    $tool = new LoadSkill([new Skill('123', 'Numeric.', 'Body.')]);

    expect($tool->schema(new JsonSchemaTypeFactory)['name']->toArray()['enum'])->toBe(['123'])
        ->and($tool->handle(new Request(['name' => '123'])))->toContain('<skill_content name="123">');
});

test('it reports an unknown skill and an empty source', function (): void {
    expect((new LoadSkill([$this->skills]))->handle(new Request(['name' => 'missing'])))
        ->toBe('Skill [missing] does not exist.')
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

test('it parses frontmatter as YAML', function (): void {
    skill('comment', "name: comment # trailing comment\ndescription: Strips comments.");
    skill('multiline', "name: multiline\ndescription: \"Spans\n  two lines.\"");
    skill('escaped', "name: escaped\ndescription: 'The team''s invoices.'");
    skill('folded', "name: folded\ndescription: > # a comment\n  Folded text.");

    expect((new LoadSkill([$this->skills]))->description())
        ->toContain('- comment: Strips comments.')
        ->toContain('- multiline: Spans two lines.')
        ->toContain("- escaped: The team's invoices.")
        ->toContain('- folded: Folded text.');
});

test('it throws on frontmatter that is not valid YAML', function (): void {
    $path = skill('pdf', "name: pdf\ndescription: Use when: handling PDFs.");

    expect(fn () => (new LoadSkill([$this->skills]))->description())
        ->toThrow(ParseException::class, realpath($path).'/SKILL.md');
});

test('it lists no resources for a skill pointed at a missing directory', function (): void {
    $tool = new LoadSkill([new Skill('ghost', 'Gone.', 'Body.', path: $this->skills.'/missing')]);

    expect($tool->handle(new Request(['name' => 'ghost'])))
        ->toContain('Body.')
        ->not->toContain('<skill_resources>');
});

test('it squishes every skill description into one catalog line', function (): void {
    skill('pdf', "description: >\n  Extract PDF text.\n\n  Use when handling PDFs.\nname: pdf");

    $tool = new LoadSkill([$this->skills, new Skill('audit', "Review invoices.\n  Flag duplicates.", 'Body.')]);

    expect($tool->description())
        ->toContain("- audit: Review invoices. Flag duplicates.\n")
        ->toEndWith('- pdf: Extract PDF text. Use when handling PDFs.');
});

test('an agent without skills does not receive the load skill tool', function (): void {
    $agent = new class implements HasSkills
    {
        public function skills(): iterable
        {
            return [];
        }
    };

    expect(LoadSkill::mergeInto([], $agent))->toBe([]);
});
