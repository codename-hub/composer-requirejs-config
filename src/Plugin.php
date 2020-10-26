<?php
namespace codename\ComposerRequirejsConfig;
use \Composer\Composer;
use \Composer\IO\IOInterface;
use \Composer\Plugin\PluginInterface;
use \Composer\Script\ScriptEvents;
use \Composer\EventDispatcher\Event;

/**
 * [Plugin description]
 */
class Plugin implements PluginInterface, \Composer\EventDispatcher\EventSubscriberInterface {

  /**
   * Composer Instance
   * @var Composer
   */
  protected $composer;

  /**
   * IOInterface
   * @var IOInterface
   */
  protected $io;

  /**
   * [activate description]
   * @param  Composer    $composer [description]
   * @param  IOInterface $io       [description]
   * @return [type]                [description]
   */
	public function activate( Composer $composer, IOInterface $io ) {
    $this->composer = $composer;
    $this->io = $io;
	}

  /**
   * [getSubscribedEvents description]
   * @return [type] [description]
   */
  public static function getSubscribedEvents()
  {
      return array(
          ScriptEvents::POST_INSTALL_CMD => array('generateRequireJsConfig', 0),
          ScriptEvents::POST_UPDATE_CMD => array('generateRequireJsConfig', 0)
      );
  }

  public function generateRequireJsConfig(Event $event) {

    // debug.
    $this->io->write("Running composer-requirejs-config...");

    // provides access to the current Composer instance
    $composer = $event->getComposer();

    //.. do stuff!
    $packageTypes = array();
    $generateFiles = array();

    $config = array();

    // get config, which package types to be used for this step
    if ( $composer->getPackage() ) {
			// get data from the 'extra' field
			$extra = $composer->getPackage()->getExtra();

      if ( !empty( $extra['composer-requirejs-config'] ) ) {
				$config = (array) $extra['composer-requirejs-config'];
			}
		}

    foreach($config as $configElement) {

      $generateFile = null;
      $basePath = null;
      $baseUrl = null;
      $types = array();
      $alias = array();

  		if ( !empty( $configElement['generate-file'] ) ) {
  			$generateFile = $configElement['generate-file'];
  		} else {
        continue;
      }

      if ( !empty( $configElement['base-path'] ) ) {
  			$basePath = $configElement['base-path'];
  		} else {
        continue;
      }

      // base-url may be null
      if ( !empty( $configElement['base-url'] ) ) {
  			$baseUrl = $configElement['base-url'];
  		}

      if ( !empty( $configElement['types'] ) ) {
  			$types = (array) $configElement['types'];
  		} else {
        continue;
      }

      // defined package aliases
      if ( !empty( $configElement['alias'] ) ) {
  			$alias = $configElement['alias'];
  		}

      $data = array(
        'paths' => array()
      );

      if($baseUrl !== null) {
        $data['baseUrl'] = $baseUrl;
      }

      // do the work
      foreach($this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages() as $package) {

        if(in_array($package->getType(), $types)) {
          $json = $this->getPackageJson($package);

          $name = $alias[$json['name']] ?? $json['name'];

          // get installPath
          $installPath = $this->composer->getInstallationManager()->getInstallPath($package);

          // we have a package
          if($json !== null) {

            $main = null;
            $css = null;
            $shim = null;

            $main = $json['main'] ?? null;

            if($main) {
              $this->io->write("Package {$package->getName()}: \"main\" is: {$main}.");
            }

            // bower fallback?
            // if(!$main) {
            //   $bowerJson = $this->getPackageJson($package, 'bower.json');
            //   $main = $bowerJson['main'];
            // }

            //
            // browserify??
            //
            // if(!empty($json['browser']) && !empty($json['browser']['main'])) {
            //   $main = $json['browser']['main'];
            // }

            //
            // jam js
            //
            // if(!empty($json['jam']) && !empty($json['jam']['main'])) {
            //   $main = $json['jam']['main'];
            // }

            //
            // jspm js
            //
            if(!empty($json['jspm']) && !empty($json['jspm']['main'])) {

              // default directory base for jspm shim is dist (?)
              $jspmBase = 'dist/';

              $main = $jspmBase . $json['jspm']['main'] ?? null;
              $jspmShim = $json['jspm']['shim'] ?? null;

              if($jspmShim) {
                // modify shim
                foreach($jspmShim as $shimElement => $shimConfig) {
                  if($shimElement == $json['jspm']['main']) {
                    // we got the right one
                    $shim = $shimConfig;
                    // jspm supports deps as a string (single dep) - requirejs doesn't!
                    if(isset($shim['deps']) && !is_array($shim['deps'])) {
                      $shim['deps'] = array($shim['deps']);
                    }
                  }
                }

              }
            }

            if(!empty($json['unpkg'])) {
              $main = $json['unpkg'];
            }

            if(!empty($json['jsdelivr'])) {
              $main = $json['jsdelivr'];
            }

            // this is defined by our own components
            // use assets: -> path to assets.json
            if(!empty($json['assets'])) {

              // Write some info to CLI
              $this->io->write("Package {$package->getName()}: \"assets\" is: \"{$json['assets']}\".");

              $assetsJsonPathInfo = pathinfo($json['assets']);
              $fullPath = $installPath . $json['assets'];

              if(file_exists($fullPath)) {
                // parse json!
                $assetsBasePath = $assetsJsonPathInfo['dirname'];
                $assetsConfig = json_decode(file_get_contents($fullPath), true);

                // Write some info to CLI
                $this->io->write("Package {$package->getName()}: \"assets\" is: \"{$json['assets']}\".");
                // print_r($assetsConfig);

                $depCollection = array();
                $cssCollection = array();

                if(!empty($assetsConfig['assets']['js'])) {

                  // echo("parse js assets");
                  $this->io->write("Package {$package->getName()}: Searching for main entrypoint with name \"{$main}\".");

                  foreach($assetsConfig['assets']['js'] as $asset) {
                    // echo("asset:");
                    // print_r($asset);

                    // Write some info to CLI
                    $this->io->write("Package {$package->getName()}: Handling asset {$asset['name']}.");

                    // either we have a main defined in package.json OR we fallback to "main"
                    if($asset['name'] == ($main ?? 'main')) {

                      $this->io->write("Package {$package->getName()}: Found main entrypoint \"{$asset['name']}\".");
                      foreach($asset['files'] as $file) {

                        // prepend assets base path...
                        // if(substr($file, 0, 1) === '/') {
                        //   // absolute path
                        //   $this->io->write("Package {$package->getName()}: \"{$asset['name']}\" file: \"{$file}\"");
                        //   $depCollection[] = $file;
                        // } else {
                          // relative path to assets base path
                          $tFile = $assetsBasePath . '/' . $file;
                          $this->io->write("Package {$package->getName()}: \"{$asset['name']}\" file: \"{$tFile}\"");
                          $depCollection[] = $tFile;
                        // }
                      }

                      // prepend assets base path...
                      // $depCollection[] = $assetsBasePath . '/' . $asset['files'][0];
                      $main = $asset['name'];
                    }
                  }
                }

                // if(!empty($assetsConfig['assets']['css'])) {
                //
                //   $this->io->write("Package {$package->getName()}: Handling css assets...");
                //   // echo("parse js assets");
                //   foreach($assetsConfig['assets']['css'] as $asset) {
                //
                //     $this->io->write("Package {$package->getName()}: CSS Asset \"{$asset['name']}\".");
                //     // echo("asset:");
                //     print_r($asset);
                //     if($asset['name'] == 'main') {
                //       foreach($asset['files'] as $file) {
                //         $tFile = $assetsBasePath . '/' . $file;
                //         $cssCollection[] = $tFile;
                //       }
                //       // $depCollection[] = $asset['files'][0];
                //     }
                //   }
                // }

                  /*
                if(!empty($assetConfig['assets']['js']['main'])) {
                  $depCollection[] = $assetConfig['assets']['js']['main'];
                }
                if(!empty($assetConfig['assets']['css']['main'])) {
                  $depCollection[] = $assetConfig['assets']['css']['main'];
                }
                */
                // overwrite dependency/package entry point collection
                if(count($depCollection) > 0) {
                  $main = $depCollection;
                }
                if(count($cssCollection) > 0) {
                  $css = $cssCollection;
                }

              } else {
                $this->io->write("Skipping {$package->getName()}: \"assets\" key does not specify a valid file: \"{$fullPath}\".");
              }
            }

            if($main == null) {
              $this->io->write("Skipping {$package->getName()}: no \"main\" / entrypoint found");
              continue;
            }


            // make main an array, either way
            if(!is_array($main)) {
              $main = array($main);
            }

            $deps = array();

            $pathValue = [];

            $iterate = [
              'js' => $main,
              // 'css' => $css // skip css for now
            ];

            foreach($iterate as $type => $values) {

              if($values ?? false && count($values) > 0) {
                // collect the major requireJS config
                foreach($values as $entrypoint) {
                  $pathInfo = pathinfo($entrypoint);

                  // the real main path (without ext (?) )
                  $requireName = $pathInfo['dirname'] . '/' . $pathInfo['filename'];

                  if(strpos($installPath, $basePath) === 0) {
                    // Strip down to relative path (basePath)
                    $relPath = str_replace($basePath, '', $installPath);
                    $virtualPath = self::normalizePath($relPath.$requireName);

                    $this->io->write("Package {$package->getName()}: relPath: {$relPath}, requireName: {$requireName}");
                    $this->io->write("Package {$package->getName()}: creating path reference to \"{$virtualPath}\" (basePath: {$basePath}, installPath: $installPath");

                    if($type === 'js') {
                      $pathValue[] = $virtualPath;
                    } elseif($type === 'css') {
                      $pathValue[] = 'css!'.$virtualPath;
                    }
                  }
                }
              }
            }

            $data['paths'][$name] = count($pathValue) === 1 ? $pathValue[0] : $pathValue;

            // shim on need:
            if($shim) {
              $data['shim'][$name] = $shim;
            }

          }
        }
      }

      // write the config!
      $this->writeRequireConfigJs($generateFile, $data);
    }
  }

  /**
   * write the config
   * @param string $file
   * @param array $config
   */
  protected function writeRequireConfigJs(string $file, array $config) {
    // $requireCss = [
    //   'map' => [
    //     '*' => [
    //       'css' => 'require-css/css'
    //     ]
    //   ]
    // ];
    //
    // $config = array_merge($config, $requireCss);

    $content = "require.config(". json_encode($config, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . ");";
    $this->io->write("Writing requireJS config file (" .$file. "):\n" . $content);
    file_put_contents($file, $content);
  }

  /**
   * [getPackageJson description]
   * @return [type] [description]
   */
  protected function getPackageJson(\Composer\Package\PackageInterface $package, string $filename = 'package.json') {
    // retrieves the NPM Package.json in install dir, if available
    $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
    $packageJsonFile = $installPath . $filename;
    if(file_exists($packageJsonFile)) {
      $packageJson = file_get_contents($packageJsonFile);
      $decoded = json_decode($packageJson, true);
      return $decoded;
    } else {
      // No JSON
    }
    return null;
  }

  /**
   * normalize a path
   */
  protected static function normalizePath($path)
  {
    $parts = array();// Array to build a new path from the good parts
    $path = str_replace('\\', '/', $path);// Replace backslashes with forwardslashes
    $path = preg_replace('/\/+/', '/', $path);// Combine multiple slashes into a single slash
    $segments = explode('/', $path);// Collect path segments
    $test = '';// Initialize testing variable
    foreach($segments as $segment)
    {
        if($segment != '.')
        {
            $test = array_pop($parts);
            if(is_null($test))
                $parts[] = $segment;
            else if($segment == '..')
            {
                if($test == '..')
                    $parts[] = $test;

                if($test == '..' || $test == '')
                    $parts[] = $segment;
            }
            else
            {
                $parts[] = $test;
                $parts[] = $segment;
            }
        }
    }
    return implode('/', $parts);
  }

  /**
   * Remove any hooks from Composer
   *
   * This will be called when a plugin is deactivated before being
   * uninstalled, but also before it gets upgraded to a new version
   * so the old one can be deactivated and the new one activated.
   *
   * @param Composer    $composer
   * @param IOInterface $io
   */
  public function deactivate(Composer $composer, IOInterface $io) {

  }

  /**
   * Prepare the plugin to be uninstalled
   *
   * This will be called after deactivate.
   *
   * @param Composer    $composer
   * @param IOInterface $io
   */
  public function uninstall(Composer $composer, IOInterface $io) {
    
  }


}
