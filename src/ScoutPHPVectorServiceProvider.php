<?php

declare(strict_types=1);

namespace DanieleBarbaro\ScoutPHPVector;

use DanieleBarbaro\ScoutPHPVector\Contracts\EmbeddingGenerator;
use DanieleBarbaro\ScoutPHPVector\Embeddings\LaravelAiEmbeddingGenerator;
use DanieleBarbaro\ScoutPHPVector\Indexing\IndexManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;

final class ScoutPHPVectorServiceProvider extends ServiceProvider
{
    public const string DRIVER = 'phpvector';

    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'scout-phpvector');

        $this->app->singleton(IndexManager::class, static function (Container $app): IndexManager {
            // The config is read lazily on every access so runtime overrides
            // (Octane state resets, tests, tenant switches) take effect without
            // rebuilding the manager.
            return new IndexManager(static function () use ($app): array {
                /** @var array<string, mixed> $config */
                $config = $app->make('config')->get('scout-phpvector', []);

                return $config;
            });
        });

        $this->app->bind(EmbeddingGenerator::class, LaravelAiEmbeddingGenerator::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath() => $this->app->configPath('scout-phpvector.php'),
            ], 'scout-phpvector-config');
        }

        $this->app->make(EngineManager::class)->extend(
            self::DRIVER,
            fn (Container $app): PHPVectorEngine => new PHPVectorEngine(
                indexes: $app->make(IndexManager::class),
                embeddings: $app->make(EmbeddingGenerator::class),
                softDelete: (bool) $app->make('config')->get('scout.soft_delete', false),
            ),
        );
    }

    private function configPath(): string
    {
        return __DIR__.'/../config/scout-phpvector.php';
    }
}
