<?php

namespace Wangzd\OpenSearch;

use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;

class OpenSearchServiceProvider extends ServiceProvider
{
    /**
     * register
     */
    public function register(){}

    public function boot()
    {
        resolve(EngineManager::class)->extend('opensearch', function ($app) {
            return new OpenSearchEngine($app['config']);
        });
    }
}
