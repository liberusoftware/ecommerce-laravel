<?php

namespace App\Modules\Support;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\ModuleManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ExternalModuleLoader
{
    protected ModuleManager $moduleManager;

    protected array $loadedPaths = [];

    public function __construct(ModuleManager $moduleManager)
    {
        $this->moduleManager = $moduleManager;
    }

    public function loadFromPath(string $path, string $namespace = 'Modules'): void
    {
        if (! File::exists($path) || ! File::isDirectory($path)) {
            Log::debug("External module path does not exist: {$path}");

            return;
        }

        if (in_array($path, $this->loadedPaths)) {
            return;
        }

        $this->loadedPaths[] = $path;

        foreach (File::directories($path) as $directory) {
            $this->loadModuleFromDirectory($directory, $namespace);
        }
    }

    /**
     * Scan Composer vendor packages for modules by reading installed.json PSR-4 metadata.
     * Packages exposing a *Module class implementing ModuleInterface are auto-registered.
     */
    public function loadFromVendor(?string $vendorPath = null): void
    {
        $vendorPath ??= base_path('vendor');
        $installedJson = $vendorPath.'/composer/installed.json';

        if (! File::exists($installedJson)) {
            return;
        }

        $installed = json_decode(File::get($installedJson), true) ?? [];
        $packages = $installed['packages'] ?? $installed;

        foreach ($packages as $package) {
            $autoload = $package['autoload']['psr-4'] ?? [];

            foreach ($autoload as $namespace => $srcPath) {
                $absolutePath = $vendorPath.'/'.$package['name'].'/'.$srcPath;

                if (! File::isDirectory($absolutePath)) {
                    continue;
                }

                $namespace = rtrim($namespace, '\\');
                $this->discoverModuleClass($absolutePath, $namespace);
            }
        }
    }

    /**
     * Look for a *Module.php class in the given path that implements ModuleInterface.
     */
    protected function discoverModuleClass(string $srcPath, string $namespace): void
    {
        foreach (File::files($srcPath) as $file) {
            if (! str_ends_with($file->getFilename(), 'Module.php')) {
                continue;
            }

            $className = $namespace.'\\'.pathinfo($file->getFilename(), PATHINFO_FILENAME);

            if (! class_exists($className)) {
                try {
                    require_once $file->getPathname();
                } catch (\Throwable $e) {
                    Log::warning("Failed requiring vendor module file {$file->getPathname()}: ".$e->getMessage());

                    continue;
                }
            }

            if (! class_exists($className)) {
                continue;
            }

            try {
                $module = new $className;
                if ($module instanceof ModuleInterface && ! $this->moduleManager->has($module->getName())) {
                    $this->moduleManager->register($module);
                    Log::debug("Loaded vendor module: {$module->getName()} from {$className}");
                }
            } catch (\Throwable $e) {
                Log::warning("Failed loading vendor module from '{$className}': ".$e->getMessage());
            }
        }
    }

    protected function loadModuleFromDirectory(string $directory, string $baseNamespace): void
    {
        $moduleName = basename($directory);
        $moduleClass = "{$baseNamespace}\\{$moduleName}\\{$moduleName}Module";

        if (! class_exists($moduleClass)) {
            $moduleFile = $directory.'/src/'.$moduleName.'Module.php';
            if (File::exists($moduleFile)) {
                try {
                    require_once $moduleFile;
                } catch (\Throwable $e) {
                    Log::warning("Failed requiring external module file {$moduleFile}: ".$e->getMessage());

                    return;
                }
            }
        }

        if (! class_exists($moduleClass)) {
            return;
        }

        try {
            $module = new $moduleClass;

            if ($module instanceof ModuleInterface) {
                $this->moduleManager->register($module);
                Log::debug("Loaded external module: {$moduleName}");
            }
        } catch (\Throwable $e) {
            Log::warning("Failed loading external module '{$moduleName}': ".$e->getMessage());
        }
    }
}
