<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \LogicException('Tests require APP_ENV=testing and an isolated SQLite :memory: database.');
        }
        foreach (['APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'DB_URL', 'BCRYPT_ROUNDS', 'SESSION_DRIVER', 'CACHE_STORE', 'MAIL_MAILER', 'QUEUE_CONNECTION'] as $name) {
            $_SERVER[$name] = $_ENV[$name] = getenv($name);
        }
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'sqlite' || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new \LogicException('Refusing to run database-refresh tests against a non-isolated database.');
        }

        return $app;
    }
}
