<?php

namespace Laravel\Ai\Concerns;

use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Arr;

trait Storable
{
    /**
     * The cached copy of the image's random name.
     */
    protected ?string $randomStorageName = null;

    /**
     * Store the image on a filesystem disk.
     */
    public function store(string $path = '', array|string $options = []): string|bool
    {
        return $this->storeAs($path, $this->randomStorageName(), $this->parseStorageOptions($options));
    }

    /**
     * Store the image on a filesystem disk with public visibility.
     */
    public function storePublicly(string $path = '', array|string $options = []): string|bool
    {
        $options = $this->parseStorageOptions($options);

        $options['visibility'] = 'public';

        return $this->storeAs($path, $this->randomStorageName(), $options);
    }

    /**
     * Store the image on a filesystem disk with public visibility.
     */
    public function storePubliclyAs(string $path, array|string|null $name = null, array|string $options = []): string|bool
    {
        if (is_null($name) || is_array($name)) {
            [$path, $name, $options] = ['', $path, $name ?? []];
        }

        $options = $this->parseStorageOptions($options);

        $options['visibility'] = 'public';

        return $this->storeAs($path, $name, $options);
    }

    /**
     * Store the image on a filesystem disk.
     */
    public function storeAs(string $path, array|string|null $name = null, array|string $options = []): string|bool
    {
        if (is_null($name) || is_array($name)) {
            [$path, $name, $options] = ['', $path, $name ?? []];
        }

        $options = $this->parseStorageOptions($options);

        $disk = Arr::pull($options, 'disk');

        $result = Container::getInstance()->make(FilesystemFactory::class)->disk($disk)->put(
            $path = trim($path.'/'.((string) $name), '/'), $this->content(), $options
        );

        return $result ? $path : false;
    }

    /**
     * Parse and format the given storage options.
     */
    protected function parseStorageOptions(array|string $options): array
    {
        if (is_string($options)) {
            $options = ['disk' => $options];
        }

        return $options;
    }
}
