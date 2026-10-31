<?php

namespace Laravel\Ai\Tools\Filesystem;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

abstract class FilesystemTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct(protected Filesystem|string|null $disk = null) {}

    /**
     * Determine whether the given path points to a file, not a directory.
     */
    protected function fileExists(Filesystem $disk, string $path): bool
    {
        if (method_exists($disk, 'fileExists')) {
            return $disk->fileExists($path);
        }

        try {
            $disk->size($path);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Resolve the filesystem disk the tool operates on.
     */
    protected function disk(): Filesystem
    {
        return $this->disk instanceof Filesystem
            ? $this->disk
            : Storage::disk($this->disk);
    }

    /**
     * Determine whether the tool needs approval for the given request.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        return false;
    }
}
