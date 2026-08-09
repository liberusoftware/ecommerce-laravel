<?php

namespace App\Modules;

use App\Models\Module;
use App\Modules\Contracts\ModuleInterface;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ModuleManager
{
    protected Collection $modules;

    protected array $enabledModules = [];

    public function __construct()
    {
        $this->modules = collect();
        $this->loadModules();
    }

    public function all(): Collection
    {
        return $this->modules;
    }

    public function enabled(): Collection
    {
        return $this->modules->filter(fn ($module) => $module->isEnabled());
    }

    public function disabled(): Collection
    {
        return $this->modules->filter(fn ($module) => ! $module->isEnabled());
    }

    public function get(string $name): ?ModuleInterface
    {
        return $this->modules->first(fn ($module) => $module->getName() === $name);
    }

    public function has(string $name): bool
    {
        return $this->modules->contains(fn ($module) => $module->getName() === $name);
    }

    public function enable(string $name): bool
    {
        $module = $this->get($name);

        if (! $module) {
            return false;
        }

        if (! $this->checkDependencies($module)) {
            throw new Exception("Module {$name} has unmet dependencies.");
        }

        $module->enable();

        try {
            $mdl = Module::firstOrNew(['name' => $module->getName()]);
            $mdl->enabled = true;
            $mdl->version = $module->getVersion();
            $mdl->description = $module->getDescription();
            $mdl->dependencies = $module->getDependencies();
            $mdl->config = $module->getConfig();
            $mdl->save();
        } catch (\Throwable $e) {
            Log::warning("Failed to persist enabled state for module '{$name}': ".$e->getMessage());
        }

        return true;
    }

    public function disable(string $name): bool
    {
        $module = $this->get($name);

        if (! $module) {
            return false;
        }

        if ($this->hasDependents($name)) {
            throw new Exception("Cannot disable module {$name} as other modules depend on it.");
        }

        $module->disable();

        try {
            $mdl = Module::firstOrNew(['name' => $module->getName()]);
            $mdl->enabled = false;
            $mdl->save();
        } catch (\Throwable $e) {
            Log::warning("Failed to persist disabled state for module '{$name}': ".$e->getMessage());
        }

        return true;
    }

    public function install(string $name): bool
    {
        $module = $this->get($name);

        if (! $module) {
            return false;
        }

        if (! $this->checkDependencies($module)) {
            throw new Exception("Module {$name} has unmet dependencies.");
        }

        $module->install();

        return true;
    }

    public function uninstall(string $name): bool
    {
        $module = $this->get($name);

        if (! $module) {
            return false;
        }

        if ($this->hasDependents($name)) {
            throw new Exception("Cannot uninstall module {$name} as other modules depend on it.");
        }

        $module->uninstall();

        return true;
    }

    public function register(ModuleInterface $module): void
    {
        $this->modules->put($module->getName(), $module);
    }

    protected function loadModules(): void
    {
        try {
            $cacheEnabled = is_array(config('modules.cache'))
                ? config('modules.cache.enabled', true)
                : config('modules.cache', true);

            if ($cacheEnabled && ! config('modules.development', false)) {
                $cacheKey = is_array(config('modules.cache'))
                    ? config('modules.cache.key', 'app.modules')
                    : config('modules.cache_key', 'app.modules');

                $cachedModules = Cache::get($cacheKey);

                if ($cachedModules) {
                    $this->modules = collect($cachedModules);

                    return;
                }
            }
        } catch (\Throwable $e) {
            // Cache may not be available during early bootstrap
        }

        $modulesPath = app_path('Modules');
        if (File::exists($modulesPath)) {
            foreach (File::directories($modulesPath) as $modulePath) {
                $this->loadModule(basename($modulePath), $modulePath);
            }
        }

        foreach (config('modules.external_paths', []) as $path => $namespace) {
            if (File::exists($path)) {
                foreach (File::directories($path) as $modulePath) {
                    $this->loadModuleFromExternalPath(basename($modulePath), $modulePath, $namespace);
                }
            }
        }

        $modularPath = base_path(config('modular.modules_directory', 'app-modules'));
        if (File::exists($modularPath)) {
            foreach (File::directories($modularPath) as $modulePath) {
                $this->loadModularModule(basename($modulePath), $modulePath);
            }
        }

        try {
            $cacheEnabled = is_array(config('modules.cache'))
                ? config('modules.cache.enabled', true)
                : config('modules.cache', true);

            if ($cacheEnabled && ! config('modules.development', false)) {
                $cacheKey = is_array(config('modules.cache'))
                    ? config('modules.cache.key', 'app.modules')
                    : config('modules.cache_key', 'app.modules');
                $cacheTtl = is_array(config('modules.cache'))
                    ? config('modules.cache.ttl', 3600)
                    : config('modules.cache_ttl', 3600);

                Cache::put($cacheKey, $this->modules->all(), $cacheTtl);
            }
        } catch (\Throwable $e) {
            // Cache may not be available during early bootstrap
        }
    }

    protected function loadModuleFromExternalPath(string $moduleName, string $modulePath, string $namespace): void
    {
        $moduleClass = "{$namespace}\\{$moduleName}\\{$moduleName}Module";

        if (! class_exists($moduleClass)) {
            $mainFile = $modulePath."/{$moduleName}Module.php";
            if (File::exists($mainFile)) {
                try {
                    require_once $mainFile;
                } catch (\Throwable $e) {
                    Log::warning('Failed requiring external module file: '.$e->getMessage());
                }
            }
        }

        if (! class_exists($moduleClass)) {
            return;
        }

        try {
            $module = new $moduleClass;
            if ($module instanceof ModuleInterface) {
                $this->register($module);
            }
        } catch (\Throwable $e) {
            Log::warning("Failed loading external module '{$moduleName}': ".$e->getMessage());
        }
    }

    protected function loadModule(string $moduleName, string $modulePath): void
    {
        $moduleClass = "App\\Modules\\{$moduleName}\\{$moduleName}Module";

        if (! class_exists($moduleClass)) {
            $mainFile = $modulePath."/{$moduleName}Module.php";
            if (File::exists($mainFile)) {
                try {
                    require_once $mainFile;
                } catch (\Throwable $e) {
                    Log::warning("Failed requiring main file for module {$moduleName}: ".$e->getMessage());
                }
            }
        }

        if (! class_exists($moduleClass)) {
            return;
        }

        try {
            $module = new $moduleClass;
        } catch (\Throwable $e) {
            Log::warning("Failed instantiating module class {$moduleClass}: ".$e->getMessage());

            return;
        }

        if ($module instanceof ModuleInterface) {
            $this->register($module);

            try {
                Module::updateOrCreate(
                    ['name' => $module->getName()],
                    [
                        'version' => $module->getVersion(),
                        'description' => $module->getDescription(),
                        'dependencies' => $module->getDependencies(),
                        'config' => $module->getConfig(),
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning("Failed to persist module '{$moduleName}' metadata: ".$e->getMessage());
            }
        }
    }

    protected function loadModularModule(string $moduleName, string $modulePath): void
    {
        $namespace = config('modular.modules_namespace', 'Modules');
        $moduleClass = "{$namespace}\\{$moduleName}\\{$moduleName}Module";

        if (! class_exists($moduleClass)) {
            return;
        }

        try {
            $module = new class($moduleClass) implements ModuleInterface
            {
                private string $moduleClass;

                private bool $enabled = false;

                private $moduleInstance;

                public function __construct(string $moduleClass)
                {
                    $this->moduleClass = $moduleClass;
                    if (class_exists($moduleClass)) {
                        $this->moduleInstance = new $moduleClass;
                    }

                    try {
                        if ($this->moduleInstance && method_exists($this->moduleInstance, 'getName')) {
                            $dbModule = Module::where('name', $this->getName())->first();
                            $this->enabled = $dbModule ? $dbModule->enabled : false;
                        }
                    } catch (\Throwable $e) {
                        // Ignore DB errors during module loading
                    }
                }

                public function getName(): string
                {
                    if ($this->moduleInstance && method_exists($this->moduleInstance, 'getName')) {
                        return $this->moduleInstance::getName();
                    }

                    return basename(str_replace('\\', '/', $this->moduleClass));
                }

                public function getVersion(): string
                {
                    if ($this->moduleInstance && method_exists($this->moduleInstance, 'getVersion')) {
                        return $this->moduleInstance::getVersion();
                    }

                    return '1.0.0';
                }

                public function getDescription(): string
                {
                    if ($this->moduleInstance && method_exists($this->moduleInstance, 'getDescription')) {
                        return $this->moduleInstance::getDescription();
                    }

                    return '';
                }

                public function getDependencies(): array
                {
                    return [];
                }

                public function isEnabled(): bool
                {
                    return $this->enabled;
                }

                public function enable(): void
                {
                    $this->enabled = true;
                }

                public function disable(): void
                {
                    $this->enabled = false;
                }

                public function install(): void {}

                public function uninstall(): void {}

                public function getConfig(): array
                {
                    return config(strtolower($this->getName()), []);
                }
            };

            $this->register($module);

            try {
                Module::updateOrCreate(
                    ['name' => $module->getName()],
                    [
                        'version' => $module->getVersion(),
                        'description' => $module->getDescription(),
                        'dependencies' => $module->getDependencies(),
                        'config' => $module->getConfig(),
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning("Failed to persist modular module '{$moduleName}' metadata: ".$e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::warning("Failed loading modular module '{$moduleName}': ".$e->getMessage());
        }
    }

    protected function checkDependencies(ModuleInterface $module): bool
    {
        foreach ($module->getDependencies() as $dependency) {
            $dep = $this->get($dependency);
            if (! $dep || ! $dep->isEnabled()) {
                return false;
            }
        }

        return true;
    }

    protected function hasDependents(string $moduleName): bool
    {
        return $this->enabled()->contains(
            fn ($module) => in_array($moduleName, $module->getDependencies())
        );
    }

    public function getModuleInfo(string $name): array
    {
        $module = $this->get($name);

        if (! $module) {
            return [];
        }

        return [
            'name' => $module->getName(),
            'version' => $module->getVersion(),
            'description' => $module->getDescription(),
            'dependencies' => $module->getDependencies(),
            'enabled' => $module->isEnabled(),
            'config' => $module->getConfig(),
        ];
    }

    public function getAllModulesInfo(): array
    {
        return $this->modules->map(fn ($module) => $this->getModuleInfo($module->getName()))->toArray();
    }

    public function clearCache(): void
    {
        $cacheKey = is_array(config('modules.cache'))
            ? config('modules.cache.key', 'app.modules')
            : config('modules.cache_key', 'app.modules');

        Cache::forget($cacheKey);
    }

    public function checkHealth(string $name): array
    {
        $module = $this->get($name);

        if (! $module) {
            return ['healthy' => false, 'errors' => ['Module not found']];
        }

        $errors = [];
        $warnings = [];

        foreach ($module->getDependencies() as $dependency) {
            $dep = $this->get($dependency);
            if (! $dep) {
                $errors[] = "Dependency {$dependency} not found";
            } elseif (! $dep->isEnabled()) {
                $warnings[] = "Dependency {$dependency} is disabled";
            }
        }

        if ($module->isEnabled() && ! $this->checkDependencies($module)) {
            $errors[] = 'Module is enabled but has unmet dependencies';
        }

        return [
            'healthy' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
