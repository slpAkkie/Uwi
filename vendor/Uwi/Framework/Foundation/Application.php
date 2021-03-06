<?php

namespace Uwi\Foundation;

use Closure;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use Uwi\Contracts\Http\Response\ResponseContract;
use Uwi\Contracts\Http\Routing\RouterContract;
use Uwi\Contracts\SingletonContract;
use Uwi\Exceptions\NotFoundException;
use Uwi\Filesystem\Filesystem;
use Uwi\Filesystem\Path;
use Uwi\Foundation\Http\Request\Request;
use Uwi\Foundation\Providers\ServiceProvider;

class Application extends Container
{
    /**
     * App instance
     *
     * @var Application
     */
    public static Application $instance;

    /**
     * Request object
     *
     * @var Request
     */
    public readonly Request $request;

    /**
     * Application configuration
     *
     * @var array
     */
    private array $config = [];

    /**
     * Initialize the App
     */
    public function __construct()
    {
        (self::$instance = $this)
            ->loadHelpers()
            ->loadConfiguration()

            ->registerServiceProviders();
    }

    /**
     * Load helpers
     *
     * @return static
     */
    private function loadHelpers(): static
    {
        foreach (Filesystem::getFiles(Path::glue(UWI_FRAMEWORK_PATH, 'Foundation', 'Helpers')) as $helpersFile) {
            include_once($helpersFile);
        }

        return $this;
    }

    /**
     * Load configurations
     *
     * @return static
     */
    private function loadConfiguration(): static
    {
        $configFiles = FileSystem::getFiles(CONFIG_PATH);
        foreach ($configFiles as $configFile) {
            $configKey = FileSystem::getFileNameWithoutExtention($configFile);
            $this->config[$configKey] = include_once($configFile);
        }

        return $this;
    }

    /**
     * Register service providers specified in configuration
     *
     * @return static
     */
    private function registerServiceProviders(): static
    {
        /** @var ServiceProvider[] */
        $serviceProviders = [];
        foreach ($this->getConfig('app.providers', []) as $serviceProvider) {
            $serviceProviders[] = $this->registerServiceProvider($serviceProvider);
        }

        foreach ($serviceProviders as $serviceProvider) {
            $serviceProvider->boot();
            $serviceProvider->booted = true;
        }

        return $this;
    }

    /**
     * Register the service provider
     *
     * @param string $serviceProviderClass
     * @return ServiceProvider
     */
    public function registerServiceProvider(string $serviceProviderClass): ServiceProvider
    {
        /** @var ServiceProvider */
        $serviceProvider = $this->instantiate($serviceProviderClass);

        foreach ($serviceProvider->bindings as $abstract => $concrete) {
            $this->bind($abstract, $concrete);
        }

        $serviceProvider->register();
        $serviceProvider->registered = true;

        return $serviceProvider;
    }

    /**
     * Get loaded config by specified key
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     * 
     * @throws NotFoundException
     */
    public function getConfig(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $targetKey = join('.', array_slice($keys, -1, 1));
        $prevKeys = join('.', array_slice($keys, 0, -1));

        if (!strlen($prevKeys)) {
            if (!key_exists($targetKey, $this->config)) {
                throw new NotFoundException('Config key [' . $key . '] not found');
            }

            return $this->config[$targetKey];
        } else {
            $prevConfig = $this->getConfig($prevKeys);
            if ($prevConfig === null || !key_exists($targetKey, $prevConfig)) {
                return $default;
            }

            return $prevConfig[$targetKey];
        }
    }

    /**
     * Run the Application
     *
     * @return void
     */
    public function run(): void
    {
        // Initialize a Request object
        $this->request = $this->singleton(Request::class);

        // Identify Route and run the controller
        $currentRoute = $this->singleton(RouterContract::class)->getCurrentRoute();

        // Run the controller method with parameters injecting
        $response = tap([
            $currentRoute->controllerClass,
            $currentRoute->controllerMethod
        ]);

        $this->sendResponse($response);
    }

    /**
     * Send response to the client
     *
     * @param ResponseContract $response
     * @return void
     */
    public function sendResponse(ResponseContract $response): void
    {
        $response->send();

        $this->destroy();
    }

    /**
     * Destory Application before end
     *
     * @return void
     */
    public function destroy(): void
    {
        //
    }

    /**
     * Resolve parameters
     *
     * @param ReflectionParameter[] $parameters
     * @return array
     */
    private function resolveParameters(array $parameters): array
    {
        $methodArgs = [];

        foreach ($parameters as $reflectedParameter) {
            $paramType = $reflectedParameter->getType()->getName();

            if (is_subclass_of($paramType, SingletonContract::class)) {
                $methodArgs[] = $this->singleton($paramType);
            } else if (\class_exists($paramType)) {
                $methodArgs[] = $this->instantiate($paramType);
            } else {
                // TODO: It's not a solve a problem
                $methodArgs[] = null;
            }
        }

        return $methodArgs;
    }

    /**
     * Collect and instantiate controller method parameters
     *
     * @param string $controller
     * @param string $method
     * @return array
     */
    public function resolveMethodParameter(string $controller, string $method): array
    {
        $reflectedControllerMethod = new ReflectionMethod($controller, $method);

        return $this->resolveParameters($reflectedControllerMethod->getParameters());
    }

    /**
     * Collect and instantiate Closure method parameters
     *
     * @param Closure $closure
     * @return array
     */
    public function resolveClosureParameter(Closure $closure): array
    {
        $reflectedClosure = new ReflectionFunction($closure);

        return $this->resolveParameters($reflectedClosure->getParameters());
    }
}
