<?php

namespace ZeeshanElahi\AiGuard\Tests;

use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ZeeshanElahi\AiGuard\AiGuardServiceProvider;

abstract class TestCase extends Orchestra
{
	protected function getPackageProviders($app): array
	{
		return [
			AiServiceProvider::class,
			AiGuardServiceProvider::class,
		];
	}

	protected function defineEnvironment($app): void
	{
		$app['config']->set('database.default', 'testing');
		$app['config']->set('database.connections.testing', [
			'driver' => 'sqlite',
			'database' => ':memory:',
			'prefix' => '',
		]);
		$app['config']->set('ai.default', 'ollama');
	}
}
