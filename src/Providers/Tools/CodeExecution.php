<?php

namespace Laravel\Ai\Providers\Tools;

use Laravel\Ai\Contracts\Files\HasProviderId;

class CodeExecution extends ProviderTool
{
    /** @var array<int, string> */
    public array $files;

    /**
     * @param  array<int, string|HasProviderId>  $files
     */
    public function __construct(array $files = [])
    {
        $this->files = array_values(array_map(
            fn (string|HasProviderId $file): string => $file instanceof HasProviderId ? $file->id() : $file,
            $files,
        ));
    }
}
