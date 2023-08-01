<?php

namespace codename\ComposerRequirejsConfig;

use Composer\Composer;
use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;

/**
 * [Plugin description]
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * Composer Instance
     * @var Composer
     */
    protected Composer $composer;

    /**
     * IOInterface
     * @var IOInterface
     */
    protected IOInterface $io;

    /**
     * [getSubscribedEvents description]
     * @return array[] [type] [description]
     */
    public static function getSubscribedEvents(): array
    {
        return [
          ScriptEvents::POST_INSTALL_CMD => ['generateRequireJsConfig', 0],
          ScriptEvents::POST_UPDATE_CMD => ['generateRequireJsConfig', 0],
        ];
    }

    /**
     * [activate description]
     * @param Composer $composer [description]
     * @param IOInterface $io [description]
     * @return void [type]                [description]
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * @param Event $event
     * @return void
     */
    public function generateRequireJsConfig(Event $event): void
    {
        // debug.
        $this->io->write("Running composer-requirejs-config...");

        // provides access to the current Composer instance
        $composer = $event->getComposer();

        $config = [];

        // get config, which package types to be used for this step
        if ($composer->getPackage()) {
            // get data from the 'extra' field
            $extra = $composer->getPackage()->getExtra();

            if (!empty($extra['composer-requirejs-config'])) {
                $config = (array)$extra['composer-requirejs-config'];
            }
        }

        foreach ($config as $configElement) {
            $generateFile = null;
            $generateCssFile = null;
            $basePath = null;
            $baseUrl = null;
            $alias = [];

            if (!empty($configElement['generate-file'])) {
                $generateFile = $configElement['generate-file'];
            } else {
                continue;
            }
            if (!empty($configElement['generate-css-file'])) {
                $generateCssFile = $configElement['generate-css-file'];
            } else {
                continue;
            }

            if (!empty($configElement['base-path'])) {
                $basePath = $configElement['base-path'];
            } else {
                continue;
            }

            // base-url may be null
            if (!empty($configElement['base-url'])) {
                $baseUrl = $configElement['base-url'];
            }

            if (!empty($configElement['types'])) {
                $types = (array)$configElement['types'];
            } else {
                continue;
            }

            // defined package aliases
            if (!empty($configElement['alias'])) {
                $alias = $configElement['alias'];
            }

            $data = [
              'paths' => [],
            ];
            $cssData = [];

            if ($baseUrl !== null) {
                $data['baseUrl'] = $baseUrl;
            }

            // do the work
            foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages() as $package) {
                if (in_array($package->getType(), $types)) {
                    $json = $this->getPackageJson($package);

                    $name = $alias[$json['name']] ?? $json['name'];

                    // get installPath
                    $installPath = $this->composer->getInstallationManager()->getInstallPath($package);

                    // we have a package
                    if ($json !== null) {
                        $main = null;
                        $css = null;
                        $shim = null;

                        $main = $json['main'] ?? null;

                        if ($main) {
                            $this->io->write("Package {$package->getName()}: \"main\" is: $main.");
                        }

                        //
                        // jspm js
                        //
                        if (!empty($json['jspm']) && !empty($json['jspm']['main'])) {
                            // default directory base for jspm shim is dist (?)
                            $jspmBase = 'dist/';

                            $main = $jspmBase . $json['jspm']['main'] ?? null;
                            $jspmShim = $json['jspm']['shim'] ?? null;

                            if ($jspmShim) {
                                // modify shim
                                foreach ($jspmShim as $shimElement => $shimConfig) {
                                    if ($shimElement == $json['jspm']['main']) {
                                        // we got the right one
                                        $shim = $shimConfig;
                                        // jspm supports deps as a string (single dep) - requirejs doesn't!
                                        if (isset($shim['deps']) && !is_array($shim['deps'])) {
                                            $shim['deps'] = [$shim['deps']];
                                        }
                                    }
                                }
                            }
                        }

                        if (!empty($json['unpkg'])) {
                            $main = $json['unpkg'];
                        }

                        if (!empty($json['jsdelivr'])) {
                            $main = $json['jsdelivr'];
                        }

                        // this is defined by our own components
                        // use assets: -> path to assets.json
                        if (!empty($json['assets'])) {
                            // Write some info to CLI
                            $this->io->write("Package {$package->getName()}: \"assets\" is: \"{$json['assets']}\".");

                            $assetsJsonPathInfo = pathinfo($json['assets']);
                            $fullPath = $installPath . $json['assets'];

                            if (file_exists($fullPath)) {
                                // parse json!
                                $assetsBasePath = $assetsJsonPathInfo['dirname'];
                                $assetsConfig = json_decode(file_get_contents($fullPath), true);

                                // Write some info to CLI
                                $this->io->write("Package {$package->getName()}: \"assets\" is: \"{$json['assets']}\".");
                                // print_r($assetsConfig);

                                $depCollection = [];
                                $cssCollection = [];

                                if (!empty($assetsConfig['assets']['js'])) {
                                    // echo("parse js assets");
                                    $this->io->write("Package {$package->getName()}: Searching for main entrypoint with name \"$main\".");

                                    foreach ($assetsConfig['assets']['js'] as $asset) {
                                        // Write some info to CLI
                                        $this->io->write("Package {$package->getName()}: Handling asset {$asset['name']}.");

                                        // either we have a main defined in package.json OR we fall back to "main"
                                        if ($asset['name'] == ($main ?? 'main')) {
                                            $this->io->write("Package {$package->getName()}: Found main entrypoint \"{$asset['name']}\".");
                                            foreach ($asset['files'] as $file) {
                                                $tFile = $assetsBasePath . '/' . $file;
                                                $this->io->write("Package {$package->getName()}: \"{$asset['name']}\" file: \"$tFile\"");
                                                $depCollection[] = $tFile;
                                            }

                                            // prepend assets base path...
                                            $main = $asset['name'];
                                        }
                                    }
                                }

                                if (!empty($assetsConfig['assets']['css'])) {
                                    $this->io->write("Package {$package->getName()}: Handling css assets...");
                                    foreach ($assetsConfig['assets']['css'] as $asset) {
                                        $this->io->write("Package {$package->getName()}: CSS Asset \"{$asset['name']}\".");
                                        if ($asset['name'] == 'main') {
                                            foreach ($asset['files'] as $file) {
                                                $tFile = $assetsBasePath . '/' . $file;
                                                $cssCollection[] = $tFile;
                                            }
                                        }
                                    }
                                }

                                // overwrite dependency/package entry point collection
                                if (count($depCollection) > 0) {
                                    $main = $depCollection;
                                }
                                if (count($cssCollection) > 0) {
                                    $css = $cssCollection;
                                }
                            } else {
                                $this->io->write("Skipping {$package->getName()}: \"assets\" key does not specify a valid file: \"$fullPath\".");
                            }
                        }

                        if ($main == null) {
                            $this->io->write("Skipping {$package->getName()}: no \"main\" / entrypoint found");
                            continue;
                        }


                        // make main an array, either way
                        if (!is_array($main)) {
                            $main = [$main];
                        }

                        $pathValue = [];
                        $cssPathValue = [];

                        $iterate = [
                          'js' => $main,
                          'css' => $css, // skip css for now
                        ];

                        foreach ($iterate as $type => $values) {
                            if (($values ?? false) && count($values) > 0) {
                                // collect the major requireJS config
                                foreach ($values as $entrypoint) {
                                    $pathInfo = pathinfo($entrypoint);

                                    // the real main path (without ext (?) )
                                    $requireName = $pathInfo['dirname'] . '/' . $pathInfo['filename'];

                                    if (str_starts_with($installPath, $basePath)) {
                                        // Strip down to relative path (basePath)
                                        $relPath = str_replace($basePath, '', $installPath);
                                        $virtualPath = self::normalizePath($relPath . $requireName);

                                        $this->io->write("Package {$package->getName()}: relPath: $relPath, requireName: $requireName");
                                        $this->io->write("Package {$package->getName()}: creating path reference to \"$virtualPath\" (basePath: $basePath, installPath: $installPath");

                                        if ($type === 'js') {
                                            $pathValue[] = $virtualPath;
                                        } elseif ($type === 'css') {
                                            $cssPathValue[] = $virtualPath;
                                        }
                                    }
                                }
                            }
                        }

                        $data['paths'][$name] = count($pathValue) === 1 ? $pathValue[0] : $pathValue;
                        $cssData[$name] = count($cssPathValue) === 1 ? $cssPathValue[0] : $cssPathValue;

                        // shim on need:
                        if ($shim) {
                            $data['shim'][$name] = $shim;
                        }
                    }
                }
            }

            // write the config!
            $this->writeRequireConfigJs($generateFile, $data);
            $this->writeRequireCssConfigJs($generateCssFile, $cssData);
        }
    }

    /**
     * [getPackageJson description]
     * @param PackageInterface $package
     * @param string $filename
     * @return mixed [type] [description]
     */
    protected function getPackageJson(PackageInterface $package, string $filename = 'package.json'): mixed
    {
        // retrieves the NPM Package.json in install dir, if available
        $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
        $packageJsonFile = $installPath . $filename;
        if (file_exists($packageJsonFile)) {
            $packageJson = file_get_contents($packageJsonFile);
            return json_decode($packageJson, true);
        }
        return null;
    }

    /**
     * normalize a path
     */
    protected static function normalizePath($path): string
    {
        $parts = [];// Array to build a new path from the good parts
        $path = str_replace('\\', '/', $path);// Replace backslashes with forwards-lashes
        $path = preg_replace('/\/+/', '/', $path);// Combine multiple slashes into a single slash
        $segments = explode('/', $path);// Collect path segments
        // Initialize testing variable
        foreach ($segments as $segment) {
            if ($segment != '.') {
                $test = array_pop($parts);
                if (is_null($test)) {
                    $parts[] = $segment;
                } elseif ($segment == '..') {
                    if ($test == '..') {
                        $parts[] = $test;
                    }

                    if ($test == '..' || $test == '') {
                        $parts[] = $segment;
                    }
                } else {
                    $parts[] = $test;
                    $parts[] = $segment;
                }
            }
        }
        return implode('/', $parts);
    }

    /**
     * write the config
     * @param string $file
     * @param array $config
     */
    protected function writeRequireConfigJs(string $file, array $config): void
    {
        $content = "require.config(" . json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . ");";
        $this->io->write("Writing requireJS config file (" . $file . "):\n" . $content);
        file_put_contents($file, $content);
    }

    /**
     * write the config
     * @param string $file
     * @param array $config
     */
    protected function writeRequireCssConfigJs(string $file, array $config): void
    {
        $content = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->io->write("Writing requireJS css config file (" . $file . "):\n" . $content);
        file_put_contents($file, $content);
    }

    /**
     * Remove any hooks from Composer
     *
     * This will be called when a plugin is deactivated before being
     * uninstalled, but also before it gets upgraded to a new version
     * so the old one can be deactivated and the new one activated.
     *
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    /**
     * Prepare the plugin to be uninstalled
     *
     * This will be called after deactivate.
     *
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }
}
