<?php

declare(strict_types=1);

namespace Modules\Stox\Providers;

use App\Models\Order;
use Illuminate\Support\ServiceProvider;
use Modules\Stox\Observers\OrderStatusObserver;

class StoxServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'Stox';

    protected string $moduleNameLower = 'stox';

    public function boot(): void
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/Migrations'));
        Order::observe(OrderStatusObserver::class);
    }

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }

    protected function registerConfig(): void
    {
        $this->publishes([
            module_path($this->moduleName, 'Config/stox.php') => config_path('stox.php'),
        ], 'config');

        $this->mergeConfigFrom(
            module_path($this->moduleName, 'Config/stox.php'),
            $this->moduleNameLower
        );
    }

    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/' . $this->moduleNameLower);
        $sourcePath = module_path($this->moduleName, 'Resources/views');

        $this->publishes([
            $sourcePath => $viewPath,
        ], ['views', $this->moduleNameLower . '-module-views']);

        $this->loadViewsFrom(
            array_merge($this->getPublishableViewPaths(), [$sourcePath]),
            $this->moduleNameLower
        );
    }

    public function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/' . $this->moduleNameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);
        } else {
            $this->loadTranslationsFrom(
                module_path($this->moduleName, 'Resources/lang'),
                $this->moduleNameLower
            );
        }
    }

    public function provides(): array
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];

        foreach (config('view.paths') as $path) {
            $moduleViewPath = $path . '/modules/' . $this->moduleNameLower;
            if (is_dir($moduleViewPath)) {
                $paths[] = $moduleViewPath;
            }
        }

        return $paths;
    }
}

