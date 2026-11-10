<?php

namespace Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\TestCase as ApplicationTestCase;

class EnvironmentGuardTest extends TestCase
{
    #[TestWith(['APP_ENV', 'local'])]
    #[TestWith(['DB_CONNECTION', 'pgsql'])]
    #[TestWith(['DB_DATABASE', 'store'])]
    public function test_unsafe_test_environment_is_rejected_before_application_boot(string $name, string $value): void
    {
        $original = getenv($name);
        $probe = new class('testProbe') extends ApplicationTestCase
        {
            public function test_probe(): void {}
        };
        try {
            putenv($name.'='.$value);
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('isolated SQLite');
            $probe->createApplication();
        } finally {
            putenv($original === false ? $name : $name.'='.$original);
        }
    }
}
