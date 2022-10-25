<?php declare(strict_types = 1);

/**
 * BaseConfigurator.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:Bootstrap!
 * @subpackage     Boot
 * @since          0.1.0
 *
 * @date           25.10.22
 */

namespace FastyBird\Bootstrap\Boot;

use ArrayAccess;
use Closure;
use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Countable;
use DateTimeImmutable;
use FastyBird\Bootstrap\Exceptions\InvalidState;
use IteratorAggregate;
use Latte\Bridges\Tracy\BlueScreenPanel as LatteBlueScreenPanel;
use Nette\DI\Compiler;
use Nette\DI\Config\Adapter;
use Nette\DI\Config\Loader;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\DI\Extensions\ExtensionsExtension;
use Nette\DI\Helpers as DIHelpers;
use Nette\PhpGenerator\Literal;
use Nette\Schema\Helpers as ConfigHelpers;
use ReflectionClass;
use stdClass;
use Tracy\Bridges\Nette\Bridge;
use Tracy\Debugger;
use Traversable;
use function array_keys;
use function assert;
use function boolval;
use function class_exists;
use function filemtime;
use function is_array;
use function is_file;
use function is_subclass_of;
use function method_exists;
use function mkdir;
use function strval;
use function unlink;
use const DATE_ATOM;
use const PHP_RELEASE_VERSION;
use const PHP_SAPI;
use const PHP_VERSION_ID;

/**
 * @internal
 */
abstract class BaseConfigurator
{

	/**
	 * @var Array<int|string, Closure>
	 * @phpstan-var Array<int|string, Closure(Compiler $compiler): void>
	 */
	public array $onCompile = [];

	/** @var Array<int|string, class-string> */
	public array $autowireExcludedClasses = [ArrayAccess::class, Countable::class, IteratorAggregate::class, stdClass::class, Traversable::class];

	/** @var Array<string, mixed> */
	protected array $staticParameters;

	/** @var Array<string, mixed> */
	protected array $dynamicParameters = [];

	/** @var Array<string, object> */
	protected array $services = [];

	/** @var Array<string, Adapter> */
	protected array $configAdapters = [];

	private bool $forceReloadContainer = false;

	public function __construct(protected string $rootDir)
	{
		$this->staticParameters = $this->getDefaultParameters();
	}

	/**
	 * @return Array<string, mixed>
	 */
	protected function getDefaultParameters(): array
	{
		/** @infection-ignore-all */
		return [
			'rootDir' => $this->rootDir,
			'appDir' => $this->rootDir . '/src',
			'buildDir' => $this->rootDir . '/var/build',
			'dataDir' => $this->rootDir . '/data',
			'logsDir' => $this->rootDir . '/var/log',
			'tempDir' => $this->rootDir . '/var/cache',
			'vendorDir' => $this->rootDir . '/vendor',
			'wwwDir' => $this->rootDir . '/public',
			'debugMode' => false,
			'consoleMode' => PHP_SAPI === 'cli',
		];
	}

	public function isConsoleMode(): bool
	{
		return boolval($this->staticParameters['consoleMode']);
	}

	public function isDebugMode(): bool
	{
		return boolval($this->staticParameters['debugMode']);
	}

	public function setDebugMode(bool $debugMode): void
	{
		$this->staticParameters['debugMode'] = $debugMode;
	}

	public function enableDebugger(): void
	{
		if (!InstalledVersions::isInstalled('tracy/tracy')) {
			throw new InvalidState('Missing required tracy/tracy package');
		}

		@mkdir(strval($this->staticParameters['logsDir']), 0_777, true);
		Debugger::$strictMode = true;
		Debugger::enable(
			$this->isDebugMode() ? Debugger::DEVELOPMENT : Debugger::PRODUCTION,
			strval($this->staticParameters['logsDir']),
		);
		/** @infection-ignore-all */
		Bridge::initialize();
		/** @infection-ignore-all */
		if (class_exists(LatteBlueScreenPanel::class)) {
			LatteBlueScreenPanel::initialize();
		}
	}

	/**
	 * @param Array<string, mixed> $parameters
	 */
	public function addStaticParameters(array $parameters): self
	{
		$merged = ConfigHelpers::merge($parameters, $this->staticParameters);
		assert(is_array($merged));

		$this->staticParameters = $merged;

		return $this;
	}

	/**
	 * @param Array<string, mixed> $parameters
	 */
	public function addDynamicParameters(array $parameters): self
	{
		$this->dynamicParameters = $parameters + $this->dynamicParameters;

		return $this;
	}

	/**
	 * @param Array<string, object> $services
	 */
	public function addServices(array $services): self
	{
		$this->services = $services + $this->services;

		return $this;
	}

	public function setForceReloadContainer(bool $force = true): self
	{
		$this->forceReloadContainer = $force;

		return $this;
	}

	/**
	 * @param Array<int|string, string> $configFiles
	 */
	private function generateContainer(Compiler $compiler, array $configFiles): void
	{
		$loader = new Loader();
		$loader->setParameters($this->staticParameters);

		foreach ($this->configAdapters as $extension => $adapter) {
			$loader->addAdapter($extension, $adapter);
		}

		foreach ($configFiles as $configFile) {
			$compiler->loadConfig($configFile, $loader);
		}

		$now = new DateTimeImmutable();

		$parameters = DIHelpers::escape($this->staticParameters) +
			[
				'container' => [
					'compiledAtTimestamp' => (int) $now->format('U'),
					'compiledAt' => $now->format(DATE_ATOM),
					'className' => new Literal('static::class'),
				],
			];
		$compiler->addConfig(['parameters' => $parameters]);
		$compiler->setDynamicParameterNames(array_keys($this->dynamicParameters));

		$builder = $compiler->getContainerBuilder();
		$builder->addExcludedClasses($this->autowireExcludedClasses);

		$compiler->addExtension('extensions', new ExtensionsExtension());

		$this->onCompile($compiler);
	}

	private function onCompile(Compiler $compiler): void
	{
		foreach ($this->onCompile as $cb) {
			$cb($compiler);
		}
	}

	/**
	 * @return Array<int|string, string>
	 */
	abstract protected function loadConfigFiles(): array;

	/**
	 * @return class-string<Container>
	 */
	public function loadContainer(): string
	{
		/** @infection-ignore-all */
		$buildDir = $this->staticParameters['buildDir'] . '/fb.di.configurator';

		/** @infection-ignore-all */
		$loader = new ContainerLoader(
			$buildDir,
			boolval($this->staticParameters['debugMode']),
		);

		$configFiles = $this->loadConfigFiles();
		$containerKey = $this->getContainerKey($configFiles);

		$this->reloadContainerOnDemand($loader, $containerKey, $buildDir);

		$containerClass = $loader->load(
			fn (Compiler $compiler) => $this->generateContainer($compiler, $configFiles),
			$containerKey,
		);
		assert(is_subclass_of($containerClass, Container::class));

		return $containerClass;
	}

	public function createContainer(): Container
	{
		$containerClass = $this->loadContainer();
		$container = new $containerClass($this->dynamicParameters);

		foreach ($this->services as $name => $service) {
			$container->addService($name, $service);
		}

		assert(method_exists($container, 'initialize'));
		$container->initialize();

		return $container;
	}

	/**
	 * @param Array<int|string, string> $configFiles
	 * @return Array<int|string, mixed>
	 */
	private function getContainerKey(array $configFiles): array
	{
		/** @infection-ignore-all */
		return [
			$this->staticParameters,
			array_keys($this->dynamicParameters),
			$configFiles,
			PHP_VERSION_ID - PHP_RELEASE_VERSION,
			class_exists(ClassLoader::class)
				? filemtime(
					strval((new ReflectionClass(ClassLoader::class))->getFileName()),
				)
				: null,
		];
	}

	/**
	 * @param Array<int|string, mixed> $containerKey
	 */
	private function reloadContainerOnDemand(ContainerLoader $loader, array $containerKey, string $buildDir): void
	{
		$this->forceReloadContainer
		&& !class_exists($containerClass = $loader->getClassName($containerKey), false)
		&& is_file($file = "$buildDir/$containerClass.php")
		&& unlink($file);
	}

}
