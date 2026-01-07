<?php

namespace Laravel\Ai\Concerns;

use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

trait Storable
{
    /**
     * The cached copy of the image's random name.
     */
    protected ?string $randomStorageName = null;

    /**
     * Store the image on a filesystem disk.
     */
    public function store(string $path = '', ?string $disk = null, array $options = []): string|bool
    {
        return $this->storeAs($path, $this->randomStorageName(), $disk, $options);
    }

    /**
     * Store the image on a filesystem disk with public visibility.
     */
    public function storePublicly(string $path = '', ?string $disk = null, array $options = []): string|bool
    {
        $options['visibility'] = 'public';

        return $this->storeAs($path, $this->randomStorageName(), $disk, $options);
    }

    /**
     * Store the image on a filesystem disk with public visibility.
     */
    public function storePubliclyAs(string $path, ?string $name = null, ?string $disk = null, array $options = []): string|bool
    {
        if (is_null($name)) {
            [$path, $name] = ['', $path];
        }

        $options['visibility'] = 'public';

        return $this->storeAs($path, $name, $disk, $options);
    }

    /**
     * Store the image on a filesystem disk.
     */
    public function storeAs(string $path, ?string $name = null, ?string $disk = null, array $options = []): string|bool
    {
        if (is_null($name)) {
            [$path, $name] = ['', $path];
        }

        $result = Container::getInstance()->make(FilesystemFactory::class)->disk($disk)->put(
            $path = trim($path.'/'.$name, '/'), $this->content(), $options
        );

        return $result ? $path : false;
    }
}
