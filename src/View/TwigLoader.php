<?php

namespace Themosis\View;

use InvalidArgumentException;
use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Resolve Twig names through the same finder used by the view factory.
 */
class TwigLoader implements LoaderInterface
{
    public const MAIN_NAMESPACE = '__main__';

    /**
     * Resolved paths per template name, valid for one finder revision.
     *
     * @var array<string, string>
     */
    private array $resolved = [];

    /**
     * Lookup error messages per template name, valid for one finder revision.
     *
     * @var array<string, string>
     */
    private array $errors = [];

    private int $revision = -1;

    public function __construct(private FileViewFinder $finder)
    {
    }

    public function getSourceContext(string $name): Source
    {
        $path = $this->findTemplate($name);

        return new Source(file_get_contents($path), $name, $path);
    }

    public function getCacheKey(string $name): string
    {
        // A changed finder selection must produce a different compiled class,
        // even when Twig has already loaded the previous template in memory.
        return $this->findTemplate($name);
    }

    public function isFresh(string $name, int $time): bool
    {
        return filemtime($this->findTemplate($name)) < $time;
    }

    public function exists(string $name): bool
    {
        try {
            $this->findTemplate($name);

            return true;
        } catch (LoaderError $exception) {
            return false;
        }
    }

    /**
     * Keep path registration compatible with the former filesystem loader.
     */
    public function addPath(string $path, string $namespace = self::MAIN_NAMESPACE): void
    {
        if (! is_dir($path)) {
            throw new LoaderError(sprintf('The "%s" directory does not exist.', $path));
        }

        if ($namespace === self::MAIN_NAMESPACE) {
            $this->finder->addLocation($path);
        } else {
            $this->finder->addNamespace($namespace, $path);
        }
    }

    public function getPaths(string $namespace = self::MAIN_NAMESPACE): array
    {
        return $namespace === self::MAIN_NAMESPACE
            ? $this->finder->getPaths()
            : ($this->finder->getHints()[$namespace] ?? []);
    }

    private function findTemplate(string $name): string
    {
        // Twig asks for the same names several times per render (cache key,
        // freshness, source): resolve each once until the finder changes.
        if ($this->revision !== $this->finder->getRevision()) {
            $this->resolved = $this->errors = [];
            $this->revision = $this->finder->getRevision();
        }

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        if (isset($this->errors[$name])) {
            throw new LoaderError($this->errors[$name]);
        }

        try {
            return $this->resolved[$name] = $this->resolveTemplate($name);
        } catch (LoaderError $error) {
            $this->errors[$name] = $error->getRawMessage();

            throw $error;
        }
    }

    private function resolveTemplate(string $name): string
    {
        if (str_contains($name, "\0")) {
            throw new LoaderError('A template name cannot contain NUL bytes.');
        }

        // The engine receives the physical path selected by the view factory,
        // including Factory::file() views without a cached logical name.
        if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $name)) {
            if (is_file($name)) {
                return realpath($name) ?: $name;
            }

            throw new LoaderError(sprintf('Unable to find template "%s".', $name));
        }

        $view = str_replace('\\', '/', $name);
        if (in_array('..', explode('/', $view), true)) {
            throw new LoaderError(sprintf('Invalid template name "%s".', $name));
        }
        $namespace = self::MAIN_NAMESPACE;
        $relative = $view;
        if (str_starts_with($view, '@')) {
            $parts = explode('/', substr($view, 1), 2);
            if (count($parts) !== 2) {
                throw new LoaderError(sprintf('Malformed namespaced template name "%s".', $name));
            }
            [$namespace, $relative] = $parts;
            $view = $parts[0].'::'.$parts[1];
        }
        if (str_ends_with($view, '.twig')) {
            $view = substr($view, 0, -5);
        }
        $view = str_replace('/', '.', $view);

        // Non-view files (e.g. "svg/icon.svg") resolve by relative path, as with
        // Twig's filesystem loader, in finder path order. Try them before the
        // finder, which would otherwise probe every view extension first.
        $basename = basename($relative);
        $direct = str_contains($basename, '.') && ! str_ends_with($basename, '.twig');

        if ($direct && ! is_null($file = $this->findRelative($namespace, $relative))) {
            return $file;
        }

        try {
            $path = $this->finder->find($view);
        } catch (InvalidArgumentException $exception) {
            if (! $direct && ! is_null($file = $this->findRelative($namespace, $relative))) {
                return $file;
            }

            throw new LoaderError($exception->getMessage(), -1, null, $exception);
        }

        if (! is_file($path)) {
            throw new LoaderError(sprintf('Unable to find template "%s".', $name));
        }

        return realpath($path) ?: $path;
    }

    private function findRelative(string $namespace, string $relative): ?string
    {
        foreach ($this->getPaths($namespace) as $directory) {
            if (is_file($file = rtrim($directory, '/').'/'.ltrim($relative, '/'))) {
                return realpath($file) ?: $file;
            }
        }

        return null;
    }
}
