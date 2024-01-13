<?php

/**
 * AutoConstructorPLugin class file.
 *
 * @package    Extremis
 * @subpackage Composer
 */

namespace Oblak\Composer\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use ReflectionClass;

class HookableDependencyFinderPlugin implements PluginInterface, EventSubscriberInterface
{
    private const MESSAGE_RUNNING_PLUGIN   = 'Running dependency collector...';
    private const MESSAGE_NO_CLASSES_FOUND = 'No classes found to add to the DI...';

    /**
     * Extra keys for the composer.json file.
     */
    private const KEY_MODULE_FILE  = 'wp-di-module-file';
    private const KEY_PACKAGE_NAME = 'wp-di-package-name';

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var string
     */
    private $cwd;

    /**
     * @var Filesystem
     */
    private $fs;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * Triggers the plugin's main functionality.
     *
     * Makes it possible to run the plugin as a custom command.
     *
     * @param Event $event
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws LogicException
     * @throws ProcessFailedException
     * @throws RuntimeException
     */
    public static function run(Event $event)
    {
        $io       = $event->getIO();
        $composer = $event->getComposer();

        $instance = new static();

        $instance->io       = $io;
        $instance->composer = $composer;

        $instance->init();
        $instance->generateFile();
    }

    /**
     * {@inheritDoc}
     *
     * @throws \RuntimeException
     * @throws LogicException
     * @throws ProcessFailedException
     * @throws RuntimeException
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io       = $io;

        $this->init();
    }

    /**
     * {@inheritDoc}
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * Prepares the plugin so it's main functionality can be run.
     *
     * @throws \RuntimeException
     * @throws LogicException
     * @throws ProcessFailedException
     * @throws RuntimeException
     */
    private function init()
    {
        $this->cwd = getcwd();
        $this->fs  = new Filesystem(new ProcessExecutor($this->io));
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_AUTOLOAD_DUMP => [
                ['generateFile', 0],
            ],

        ];
    }

    /**
     * Entry point for post autoload dump event.
     *
     * @throws \InvalidArgumentException
     * @throws LogicException
     * @throws ProcessFailedException
     * @throws RuntimeException
     */
    public function generateFile()
    {
        $io        = $this->io;
        $isVerbose = $io->isVerbose();
        $exitCode  = 0;

        if ($isVerbose) {
            $io->write(sprintf('<info>%s</info>', self::MESSAGE_RUNNING_PLUGIN));
        }

        $classnames = $this->findHookableClasses(
            include $this->composer->getConfig()->get('vendor-dir') . '/composer/autoload_classmap.php',
            $this->composer->getPackage()->getAutoload()
        );

        if (empty($classnames)) {
            if ($isVerbose) {
                $io->write(sprintf('<info>%s</info>', self::MESSAGE_NO_CLASSES_FOUND));
            }
        }

        return $this->createModuleFile($classnames);
    }

    private function findHookableClasses(array $autoloadable_classes, array $autload_config)
    {
        $namespaces      = array_map(
            fn($path) => $this->cwd . '/' . $path,
            array_merge(
                ...array_values(
                    array_filter(
                        $autload_config,
                        fn($c) => str_starts_with($c, 'psr'),
                        ARRAY_FILTER_USE_KEY
                    )
                )
            )
        );
        $project_classes = array_keys(
            array_filter(
                $autoloadable_classes,
                fn($path, $namespace) => $this->startsWithReduce($namespace, array_keys($namespaces)) &&
                    $this->startsWithReduce($path, array_values($namespaces)),
                ARRAY_FILTER_USE_BOTH
            )
        );
        return array_map(
            fn($class) => $class . '::class',
            array_values(
                array_filter($project_classes, [$this, 'isClassHookable'])
            )
        );
    }

    private function startsWithReduce(string $haystack, array $needles): bool
    {
        return array_reduce(
            $needles,
            fn($carry, $needle) => $carry || str_starts_with($haystack, $needle),
            false
        );
    }

    private function isClassHookable(string $classname)
    {
        $reflector = new ReflectionClass($classname);

        return $reflector->isInstantiable() && !empty($reflector->getAttributes('Oblak\\WP\\Decorators\\Hookable'));
    }

    private function createModuleFile($classnames)
    {
        $baseNamespace = $this->getBaseNamespace();
        $io            = $this->io;

        $output = sprintf(
            <<<'PHP'
<?php
/**
 * %1$s Modules
 *
 * @package    %1$s
 * @subpackage Config
 */

return array(%2$s%3$s%4$s);

PHP,
            $baseNamespace,
            !empty($classnames) ? "\n\t" : '',
            implode(",\n\t", $classnames),
            !empty($classnames) ? "\n" : ''
        );

        $bytes = $this->writeFile($output);

        if (0 === $bytes) {
            $io->write('<error>Dependency file could not be created</error>');
            return 1;
        }

        $io->write(sprintf('<info>Dependency file created successfully with %d classes</info>', count($classnames)));

        return 0;
    }

    private function writeFile(string $output): int
    {
        return file_put_contents($this->getModuleFile(), $output);
    }

    private function getBaseNamespace()
    {
        $baseNamespace = 'DI Package';
        $extra         = $this->composer->getPackage()->getExtra();

        if (isset($extra[self::KEY_PACKAGE_NAME])) {
            $baseNamespace = $extra[self::KEY_PACKAGE_NAME];
        }

        return $baseNamespace;
    }

    private function getModuleFile()
    {
        $modulePath = $this->cwd . '/config/dependencies.php';
        $extra      = $this->composer->getPackage()->getExtra();

        if (isset($extra[self::KEY_MODULE_FILE])) {
            $modulePath = $this->cwd . '/' . $extra[self::KEY_MODULE_FILE];
        }

        return $modulePath;
    }
}
