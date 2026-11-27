<?php

namespace Laravel\Ai\Tools;

/**
 * Wraps content the application doesn't control so the model can tell it apart from its instructions.
 */
class UntrustedContent
{
    /**
     * Wrap the given content in untrusted content delimiters.
     *
     * Any closing delimiter inside the content is escaped, so the content can't end the wrapper early and have
     * the text after it read as if it came from outside the wrapper.
     */
    public static function wrap(string $content, string $source): string
    {
        $content = preg_replace('/<\/untrusted_content/i', '&lt;/untrusted_content', $content) ?? $content;

        return sprintf(
            "<untrusted_content source=\"%s\">\n%s\n</untrusted_content>",
            htmlspecialchars($source, ENT_QUOTES),
            $content,
        );
    }
}
