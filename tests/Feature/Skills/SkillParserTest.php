<?php

namespace Tests\Feature\Skills;

use Laravel\Ai\Skills\SkillParser;
use Tests\TestCase;

class SkillParserTest extends TestCase
{
    public function test_parses_valid_skill_md()
    {
        $markdown = <<<'MD'
---
name: my-skill
description: A useful skill
---
# Instructions
Do something.
MD;

        $skill = SkillParser::parse($markdown, 'remote', '/base');

        $this->assertNotNull($skill);
        $this->assertSame('my-skill', $skill->name);
        $this->assertSame('A useful skill', $skill->description);
        $this->assertSame('remote', $skill->source);
        $this->assertSame('/base', $skill->basePath);
        $this->assertSame('# Instructions'.PHP_EOL.'Do something.', $skill->instructions);
    }

    public function test_returns_null_for_missing_name()
    {
        $markdown = <<<'MD'
---
description: Missing name
---
Body
MD;

        $this->assertNull(SkillParser::parse($markdown));
    }

    public function test_returns_null_for_missing_description()
    {
        $markdown = <<<'MD'
---
name: Missing description
---
Body
MD;

        $this->assertNull(SkillParser::parse($markdown));
    }

    public function test_returns_null_for_invalid_yaml()
    {
        $markdown = <<<'MD'
---
name: invalid: [yaml
---
Body
MD;

        $this->assertNull(SkillParser::parse($markdown));
    }

    public function test_handles_minimal_frontmatter()
    {
        $markdown = <<<'MD'
---
name: simple
description: simple desc
---
body
MD;

        $skill = SkillParser::parse($markdown);

        $this->assertNotNull($skill);
        $this->assertSame('simple', $skill->name);
        $this->assertSame('simple desc', $skill->description);
        $this->assertSame('local', $skill->source);
        $this->assertNull($skill->basePath);
    }

    public function test_preserves_markdown_instructions()
    {
        $markdown = <<<'MD'
---
name: md
description: md desc
---
# Header
- list item
MD;

        $skill = SkillParser::parse($markdown);

        $this->assertSame('# Header'.PHP_EOL.'- list item', $skill->instructions);
    }

    public function test_returns_null_for_content_without_frontmatter()
    {
        $markdown = <<<'MD'
# Just Markdown
No frontmatter here at all.
MD;

        $this->assertNull(SkillParser::parse($markdown));
    }
}
