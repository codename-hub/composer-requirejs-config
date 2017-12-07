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

          // we have a package
          if($json !== null) {

            $main = null;
            $shim = null;

            $main = $json['main'] ?? null;

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
            
            if($main == null) {
              $this->io->write("Skipping {$package->getName()}: no \"main\" / entrypoint found");
              continue;
            }

            $pathInfo = pathinfo($main);

            $installPath = $this->composer->getInstallationManager()->getInstallPath($package);

            // the real main path (without ext (?) )
            $requireName = $pathInfo['dirname'] . '/' . $pathInfo['filename'];

            if(strpos($installPath, $basePath) === 0) {
              // Strip down to relative path (basePath)
              $relPath = str_replace($basePath, '', $installPath);
              $virtualPath = self::normalizePath($relPath.$requireName);
              $data['paths'][$name] = $virtualPath;

              // shim on need:
              if($shim) {
                $data['shim'][$name] = $shim;
              }
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
    $content = "require.config(". json_encode($config, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . ");";
    $this->io->write("Writing requireJS config file (" .$file. "):\n" . $content);
    file_put_contents($file, $content);
  }

  /**
   * [getPackageJson description]
   * @return [type] [description]
   */
  protected function getPackageJson(\Composer\Package\PackageInterface $package) {
    // retrieves the NPM Package.json in install dir, if available
    $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
    $packageJsonFile = $installPath . 'package.json';
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


}
