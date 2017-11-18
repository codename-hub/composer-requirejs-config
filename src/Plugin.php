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

	public function activate( Composer $composer, IOInterface $io ) {
    $this->composer = $composer;
    $this->io = $io;

    // $io->write("helo");

		// $installer = new Generator( $io, $composer );
		// $composer->getInstallationManager()->addInstaller( $installer );
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
    // run any post install tasks here

    // debug.
    // $this->io->write("Got composer.");

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

      $this->io->write("Config Element");

      $generateFile = null;
      $basePath = null;
      $baseUrl = null;
      $types = array();

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

          // we have a package
          if($json !== null) {
            // handle the "main" key
            if(isset($json['main'])) {

              $pathInfo = pathinfo($json['main']);
              $installPath = $this->composer->getInstallationManager()->getInstallPath($package);

              // the real main path (without ext (?) )
              $main = $pathInfo['dirname'] . '/' . $pathInfo['filename'];

              $this->io->write("Name: " . $json['name']);
              $this->io->write("InstallPath: " . $installPath);
              $this->io->write("Main: " . $main);

              if(strpos($installPath, $basePath) === 0) {
                // Strip down to relative path (basePath)
                $relPath = str_replace($basePath, '', $installPath);
                $this->io->write("RelPath: " .$relPath);

                $virtualPath = self::normalizePath($relPath.$main);

                $this->io->write("Value: " .$virtualPath );

                $data['paths'][$json['name']] = $virtualPath;
              }

              // TODO: strip out . ? convert to real path?
              // path > name => path to main js without '.js' (filename)
              //$data['paths'][$json['name']] = $installPath . $pathInfo['dirname'] . '/' . $pathInfo['filename'];

              $this->io->write("");

            } else {
              // no main key!
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

    $this->io->write("Would write (" .$file. "):\n" . $content);

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
      // echo("main file: " . $decoded['main'] . chr(10));
    } else {
      // echo("no json");
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
