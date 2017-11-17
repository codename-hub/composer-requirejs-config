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

    $io->write("helo");

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
    $this->io->write("generateRequireJsConfig");

    // provides access to the current Composer instance
    $composer = $event->getComposer();
    // run any post install tasks here

    // debug.
    $this->io->write("Got composer.");

    //.. do stuff!
    $packageTypes = array();
    $generateFiles = array();

    // get config, which package types to be used for this step
    if ( $composer->getPackage() ) {
			// get data from the 'extra' field
			$extra = $composer->getPackage()->getExtra();
			if ( !empty( $extra['requirejs-generate-types'] ) ) {
				$packageTypes = (array) $extra['requirejs-generate-types'];
			}
      if ( !empty( $extra['requirejs-generate-files'] ) ) {
				$generateFiles = (array) $extra['requirejs-generate-files'];
			}
		}

    $data = array();

    if(count($packageTypes) > 0 && count($generateFiles) > 0) {
      $this->io->write(var_export($packageTypes, true));
      $this->io->write(var_export($generateFiles, true));

      foreach($this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages() as $package) {

        if(in_array($package->getType(), $packageTypes)) {
          $this->io->write("package: " . $package->getName());

          $targets = array();

          foreach($generateFiles as $file => $typeConfig) {
            if(is_array($typeConfig)) {
              if(in_array($package->getType(), $typeConfig)) {
                $targets[] = $file;
              }
            } else {
              if($typeConfig == $package->getType()) {
                $targets[] = $file;
              }
            }
          }

          $this->io->write("package: " . $package->getName() . " targets: " . var_export($targets, true));

          foreach($targets as $t) {
            $json = $this->getPackageJson($package);

            // we have a package
            if($json !== null) {
              // handle the "main" key
              if(isset($json['main'])) {
                $pathInfo = pathinfo($json['main']);
                $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
                // TODO: strip out . ? convert to real path?
                // path > name => path to main js without '.js' (filename)
                $data[$t]['path'][$json['name']] = $installPath . $pathInfo['dirname'] . '/' . $pathInfo['filename'];
              } else {
                // no main key!
              }
            }

            // $data[$t]['packages'][] = $package;
          }
        }
      }

      $this->io->write(var_export($data,true));

    }
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
      echo("main file: " . $decoded['main'] . chr(10));


    } else {
      echo("no json");
    }

    return null;

  }


}
