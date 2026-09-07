<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function postEmptyJson(string $uri): TestResponse
    {
        return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]), '{}');
    }

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if ($app->configurationIsCached()
            || ! $app->environment('testing')
            || $app['config']->get('database.default') !== 'pgsql'
            || $app['db']->connection()->getDatabaseName() !== 'food_rescue_api_test') {
            throw new RuntimeException('Os testes exigem PostgreSQL no banco food_rescue_api_test e configuração sem cache. Execute php artisan config:clear.');
        }

        return $app;
    }
}
