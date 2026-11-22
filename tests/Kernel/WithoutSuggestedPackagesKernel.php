<?php

namespace Base\Video\Tests\Kernel;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Yaml\Yaml;

/**
 * The host application (the one whose suite this runs in) as it would be without the packages this bundle only
 * suggests: their classes are hidden from the autoloader (hide(), in a process of its own), their bundles left out
 * of config/bundles.php, the configuration files and route files of those bundles left out with them - as an
 * application that never installed them. What is proven is the bundle's own wiring: its container compiles, its
 * routes load, its services are made, with its core requirements alone.
 *
 * @internal
 */
final class WithoutSuggestedPackagesKernel extends Kernel
{
    use MicroKernelTrait;

    /** @var list<string> the namespaces hidden (hide()) */
    private static array $hidden = [];

    /** @return list<string> the configuration and route files left out, their bundle or extension not registered */
    public static array $leftOutFiles = [];

    /**
     * Hides the classes of these namespaces from every autoloader of the process: class_exists() answers false,
     * as where the package was never installed. Called once, before the kernel boots, in a separate process.
     *
     * @param list<string> $namespaces "Omnistay\\Local\\", "Typesense\\"...
     */
    public static function hide(array $namespaces): void
    {
        self::$hidden = $namespaces;
        $loaders = spl_autoload_functions();
        foreach ($loaders as $loader) {
            spl_autoload_unregister($loader);
        }
        spl_autoload_register(static function (string $class) use ($loaders): void {
            if (self::hidden($class)) {
                return;
            }
            foreach ($loaders as $loader) {
                $loader($class);
                if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false) || enum_exists($class, false)) {
                    return;
                }
            }
        });
    }

    public static function hidden(string $class): bool
    {
        foreach (self::$hidden as $namespace) {
            if (str_starts_with(ltrim($class, '\\'), $namespace)) {
                return true;
            }
        }

        return false;
    }

    public function registerBundles(): iterable
    {
        $contents = require $this->getProjectDir().'/config/bundles.php';
        foreach ($contents as $class => $envs) {
            if (self::hidden($class) || !class_exists($class)) {
                continue;
            }
            if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                yield new $class();
            }
        }
    }

    public function getProjectDir(): string
    {
        return \dirname((new \ReflectionClass('App\\Kernel'))->getFileName(), 2);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/'.$this->environment.'_without_suggested_'.substr(md5(implode(',', self::$hidden)), 0, 8);
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir().'/var/log';
    }

    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $configDir = $this->getConfigDir();
        $aliases = array_keys($builder->getExtensions());
        self::$leftOutFiles = [];

        foreach ([$configDir.'/packages', $configDir.'/packages/'.$this->environment] as $dir) {
            foreach (glob($dir.'/*.{php,yaml}', \GLOB_BRACE) ?: [] as $file) {
                if (str_ends_with($file, '.yaml') && !self::readable($file, $aliases)) {
                    self::$leftOutFiles[] = basename($file);
                    continue;
                }
                $container->import($file);
            }
        }

        if (is_file($configDir.'/services.yaml')) {
            $container->import($configDir.'/services.yaml');
            $container->import($configDir.'/{services}_'.$this->environment.'.yaml');
        } elseif (is_file($path = $configDir.'/services.php')) {
            (require $path)($container->withPath($path), $this);
        }

        // A route type a left-out bundle's loader gives: none, as in an application without it.
        $container->services()->set('test.without_suggested.route_types', LeftOutRouteTypes::class)
            ->args([['api_platform', 'webauthn', 'typesense']])
            ->tag('routing.loader');
    }

    /** The application's routes, but for the files importing a bundle left out. */
    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $configDir = $this->getConfigDir();
        $bundles = array_keys($this->getBundles());

        foreach ([$configDir.'/routes/'.$this->environment, $configDir.'/routes'] as $dir) {
            foreach (glob($dir.'/*.{php,yaml}', \GLOB_BRACE) ?: [] as $file) {
                preg_match_all('~@([A-Za-z0-9]+Bundle)\b~', (string) file_get_contents($file), $named);
                if (array_diff($named[1], $bundles)) {
                    self::$leftOutFiles[] = 'routes/'.basename($file);
                    continue;
                }
                $routes->import($file);
            }
        }

        if (is_file($configDir.'/routes.yaml')) {
            $routes->import($configDir.'/routes.yaml');
        } elseif (is_file($path = $configDir.'/routes.php')) {
            (require $path)($routes->withPath($path), $this);
        }
    }

    /** Whether every extension a YAML file configures (at its root or under when@env) is registered. */
    private static function readable(string $file, array $aliases): bool
    {
        $keys = [];
        foreach ((array) Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS | Yaml::PARSE_CONSTANT) as $key => $value) {
            if (str_starts_with((string) $key, 'when@')) {
                $keys = array_merge($keys, array_keys((array) $value));
            } else {
                $keys[] = $key;
            }
        }

        foreach ($keys as $key) {
            if (!\in_array($key, ['services', 'parameters', 'imports'], true) && !\in_array($key, $aliases, true)) {
                return false;
            }
        }

        return true;
    }
}
