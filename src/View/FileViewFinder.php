<?php

namespace Themosis\View;

use Illuminate\View\FileViewFinder as IlluminateFileViewFinder;

class FileViewFinder extends IlluminateFileViewFinder
{
    /**
     * Ordered location paths.
     *
     * @var array
     */
    private $orderedPaths = [];

    /**
     * Merged ordered and default paths, rebuilt after location changes.
     *
     * @var array|null
     */
    private $mergedPaths;

    /**
     * Incremented whenever locations, namespaces or found views change,
     * so dependent caches (the Twig loader) know when to refresh.
     *
     * @var int
     */
    private $revision = 0;

    /**
     * Return the current lookup revision.
     *
     * @return int
     */
    public function getRevision(): int
    {
        return $this->revision;
    }

    /**
     * Invalidate merged paths and dependent caches.
     */
    private function changed(): void
    {
        $this->mergedPaths = null;
        $this->revision++;
    }

    public function addLocation($location)
    {
        parent::addLocation($location);
        $this->changed();
    }

    public function prependLocation($location)
    {
        parent::prependLocation($location);
        $this->changed();
    }

    public function setPaths($paths)
    {
        $result = parent::setPaths($paths);
        $this->changed();

        return $result;
    }

    public function addNamespace($namespace, $hints)
    {
        parent::addNamespace($namespace, $hints);
        $this->changed();
    }

    public function prependNamespace($namespace, $hints)
    {
        parent::prependNamespace($namespace, $hints);
        $this->changed();
    }

    public function replaceNamespace($namespace, $hints)
    {
        parent::replaceNamespace($namespace, $hints);
        $this->changed();
    }

    public function addExtension($extension)
    {
        parent::addExtension($extension);
        $this->changed();
    }

    public function flush()
    {
        parent::flush();
        $this->changed();
    }

    /**
     * Return located views.
     *
     * @return array
     */
    public function getViews()
    {
        return $this->views;
    }

    /**
     * Add ordered view location.
     *
     * @param string $location
     * @param int    $priority
     */
    public function addOrderedLocation(string $location, int $priority = 20)
    {
        $this->orderedPaths[] = new FileViewLocation($location, $priority);
        $this->changed();
    }

    /**
     * Get the active view paths.
     *
     * @return array
     */
    public function getPaths()
    {
        return $this->mergedPaths ??= $this->parseOrderedPaths();
    }

    /**
     * Get the fully qualified location of the view.
     *
     * @param string $name
     *
     * @return string
     */
    public function find($name)
    {
        if (isset($this->views[$name])) {
            return $this->views[$name];
        }

        if ($this->hasHintInformation($name = trim($name))) {
            return $this->views[$name] = $this->findNamespacedView($name);
        }

        return $this->views[$name] = $this->findInPaths($name, $this->getPaths());
    }

    /**
     * Merge ordered path locations with default paths.
     *
     * @return array
     */
    private function parseOrderedPaths()
    {
        if (empty($this->orderedPaths)) {
            return $this->paths;
        }

        /**
         * @var FileViewLocation[]
         */
        $orderedPaths = $this->orderedPaths;

        usort($orderedPaths, function ($a, $b) {
            return $a->getPriority() <=> $b->getPriority();
        });

        $paths = [];
        foreach ($orderedPaths as $orderedPath) {
            $paths[] = $this->resolvePath($orderedPath->getPath());
        }

        return array_merge($paths, $this->paths);
    }
}
