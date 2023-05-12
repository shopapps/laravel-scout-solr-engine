<?php

declare(strict_types=1);

namespace Scout\Solr;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use Scout\Solr\Engines\SolrEngine;
use Solarium\Core\Client\Adapter\Curl;
use Symfony\Component\EventDispatcher\EventDispatcher;


class ScoutSolrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scout-solr.php', 'scout-solr');

        $this->app->singleton(ClientInterface::class, static function (Application $app) {
            $adapter = new \Solarium\Core\Client\Adapter\Curl();
            if (config('scout-solr.endpoint.localhost.timeout')) {
                $adapter->setTimeout(config('scout-solr.endpoints.default.timeout'));
            }
            $client = new Client(
                $adapter,
                new EventDispatcher(),
                null,
            );

            $client->getEndpoint('localhost')->setOptions($app['config']->get('scout-solr.endpoints.default'));
            return $client;
        });

        $this->app->singleton(Client::class, function () {
            $adapter = new \Solarium\Core\Client\Adapter\Curl();
            if (config('solr.endpoint.localhost.timeout')) {
                $adapter->setTimeout(config('scout-solr.endpoints.default.timeout'));
            }
            $config = config('scout-solr.endpoints.default');
            return new Client(
                $adapter,
                new EventDispatcher(),
                $config
            );
        });
    }

    /**
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        $this->app->make(EngineManager::class)->extend('solr', function () {
            return $this->app->make(SolrEngine::class);
        });

        $this->publishes([
            __DIR__ . '/../config/scout-solr.php' => config_path('scout-solr.php'),
        ]);
    }
}
