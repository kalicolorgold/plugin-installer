<?php

namespace Roundcube\Composer;

use Composer\Installer\LibraryInstaller;
use Composer\Package\Version\VersionParser;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\ProcessExecutor;
use React\Promise\PromiseInterface;

/**
 * @category Plugins
 * @package  PluginInstaller
 * @author   Till Klampaeckel <till@php.net>
 * @author   Thomas Bruederli <thomas@roundcube.net>
 * @license  GPL-3.0+
 * @version  GIT: <git_id>
 * @link     http://github.com/roundcube/plugin-installer
 */
class PluginInstaller extends LibraryInstaller
{
    const INSTALLER_TYPE = 'roundcube-plugin';

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        static $vendorDir;
        if ($vendorDir === null) {
            $vendorDir = $this->getVendorDir();
        }

        return sprintf('%s/%s', $vendorDir, $this->getPluginName($package));
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $this->rcubeVersionCheck($package);

        $self = $this;
        $postInstall = function() use ($self, $package) {
            // post-install: activate plugin in Roundcube config
            $config_file = $self->rcubeConfigFile();
            $plugin_name = $self->getPluginName($package);
            $plugin_dir = $self->getVendorDir() . DIRECTORY_SEPARATOR . $plugin_name;
            $extra = $package->getExtra();
            $plugin_name = $self->getPluginName($package);

            if (is_writeable($config_file) && php_sapi_name() == 'cli') {
                $answer = $self->io->askConfirmation("Do you want to activate the plugin $plugin_name? [Y|n] ", true);
                if ($answer === true) {
                    $self->rcubeAlterConfig($plugin_name, true);
                }
            }

            // copy config.inc.php.dist -> config.inc.php
            if (is_file($plugin_dir . DIRECTORY_SEPARATOR . 'config.inc.php.dist') && !is_file($plugin_dir . DIRECTORY_SEPARATOR . 'config.inc.php') && is_writeable($plugin_dir)) {
                $self->io->write("<info>Creating plugin config file</info>");
                copy($plugin_dir . DIRECTORY_SEPARATOR . 'config.inc.php.dist', $plugin_dir . DIRECTORY_SEPARATOR . 'config.inc.php');
            }

            // initialize database schema
            if (!empty($extra['roundcube']['sql-dir'])) {
                if ($sqldir = realpath($plugin_dir . DIRECTORY_SEPARATOR . $extra['roundcube']['sql-dir'])) {
                    $self->io->write("<info>Running database initialization script for $plugin_name</info>");
                    system(getcwd() . "/vendor/bin/rcubeinitdb.sh --package=$plugin_name --dir=$sqldir");
                }
            }

            // run post-install script
            if (!empty($extra['roundcube']['post-install-script'])) {
                $self->rcubeRunScript($extra['roundcube']['post-install-script'], $package);
            }
        };

        $promise = parent::install($repo, $package);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($postInstall);
        }

        // If not, execute the code right away (composer v1, or v2 without async)
        $postInstall();
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $this->rcubeVersionCheck($target);

        $self = $this;

        // backup the original plugin config
        $plugin_name  = $self->getPluginName($initial);
        $plugin_dir   = $self->getVendorDir() . DIRECTORY_SEPARATOR . $plugin_name;
        $config_file  = $plugin_dir . DIRECTORY_SEPARATOR . 'config.inc.php';
        $config_templ = is_readable($config_file) ? (@file_get_contents($config_file) ?: '') : '';

        $postUpdate = function() use ($self, $target, $config_templ) {
            $plugin_name = $self->getPluginName($target);
            $plugin_dir  = $self->getVendorDir() . DIRECTORY_SEPARATOR . $plugin_name;
            $config_file = $plugin_dir . DIRECTORY_SEPARATOR . 'config.inc.php';

            // restore the original plugin config
            if (!empty($config_templ) && is_writeable($plugin_dir)) {
                $self->io->write("<info>Restore plugin config file</info>");
                $success = file_put_contents($config_file, $config_templ);
            }

            $extra = $target->getExtra();

            // trigger updatedb.sh
            if (!empty($extra['roundcube']['sql-dir'])) {
                if ($sqldir = realpath($plugin_dir . DIRECTORY_SEPARATOR . $extra['roundcube']['sql-dir'])) {
                    $self->io->write("<info>Updating database schema for $plugin_name</info>");
                    system(getcwd() . "/bin/updatedb.sh --package=$plugin_name --dir=$sqldir", $res);
                }
            }

            // run post-update script
            if (!empty($extra['roundcube']['post-update-script'])) {
                $self->rcubeRunScript($extra['roundcube']['post-update-script'], $target);
            }
        };

        $promise = parent::update($repo, $initial, $target);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($postUpdate);
        }

        // If not, execute the code right away (composer v1, or v2 without async)
        $postUpdate();
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $self = $this;
        $postUninstall = function() use ($self, $package) {
            // post-uninstall: deactivate plugin
            $plugin_name = $self->getPluginName($package);
            $self->rcubeAlterConfig($plugin_name, false);

            // run post-uninstall script
            $extra = $package->getExtra();
            if (!empty($extra['roundcube']['post-uninstall-script'])) {
                $self->rcubeRunScript($extra['roundcube']['post-uninstall-script'], $package);
            }
        };

        $promise = parent::uninstall($repo, $package);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($postUninstall);
        }

        // If not, execute the code right away (composer v1, or v2 without async)
        $postUninstall();
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return $packageType === self::INSTALLER_TYPE;
    }

    /**
     * Setup vendor directory to one of these two:
     *  ./plugins
     *
     * @return string
     */
    public function getVendorDir()
    {
        $pluginDir  = getcwd();
        $pluginDir .= '/plugins';

        return $pluginDir;
    }

    /**
     * Extract the (valid) plugin name from the package object
     */
    private function getPluginName(PackageInterface $package)
    {
        @list($vendor, $pluginName) = explode('/', $package->getPrettyName());

        return strtr($pluginName, '-', '_');
    }

    /**
     * Check version requirements from the "extra" block of a package
     * against the local Roundcube version
     */
    private function rcubeVersionCheck($package)
    {
        $parser = new VersionParser;

        // read rcube version from iniset
        $rootdir = getcwd();
        $iniset = @file_get_contents($rootdir . '/program/include/iniset.php');
        if (preg_match('/define\(.RCMAIL_VERSION.,\s*.([0-9.]+[a-z-]*)?/', $iniset, $m)) {
            $rcubeVersion = $parser->normalize(str_replace('-git', '.999', $m[1]));
        } else {
            throw new \Exception("Unable to find a Roundcube installation in $rootdir");
        }

        $extra = $package->getExtra();

        if (!empty($extra['roundcube'])) {
            foreach (array('min-version' => '>=', 'max-version' => '<=') as $key => $operator) {
                if (!empty($extra['roundcube'][$key])) {
                    $version = $parser->normalize(str_replace('-git', '.999', $extra['roundcube'][$key]));
                    if (!self::versionCompare($rcubeVersion, $version, $operator)) {
                        throw new \Exception("Version check failed! " . $package->getName() . " requires Roundcube version $operator $version, $rcubeVersion was detected.");
                    }
                }
            }
        }
    }

    /**
     * Add or remove the given plugin to the list of active plugins in the Roundcube config.
     */
    private function rcubeAlterConfig($plugin_name, $add)
    {
        $config_file = $this->rcubeConfigFile();
        @include($config_file);
        $success = false;
        $varname = '$config';

        if (empty($config) && !empty($rcmail_config)) {
            $config  = $rcmail_config;
            $varname = '$rcmail_config';
        }

        if (is_array($config) && is_writeable($config_file)) {
            $config_templ   = @file_get_contents($config_file) ?: '';
            $config_plugins = !empty($config['plugins']) ? ((array) $config['plugins']) : array();
            $active_plugins = $config_plugins;

            if ($add && !in_array($plugin_name, $active_plugins)) {
                $active_plugins[] = $plugin_name;
            } elseif (!$add && ($i = array_search($plugin_name, $active_plugins)) !== false) {
                unset($active_plugins[$i]);
            }

            if ($active_plugins != $config_plugins) {
                $count      = 0;
                $var_export = "array(\n\t'" . join("',\n\t'", $active_plugins) . "',\n);";
                $new_config = preg_replace(
                    "/(\\$varname\['plugins'\])\s+=\s+(.+);/Uims",
                    "\\1 = " . $var_export,
                    $config_templ, -1, $count);

                // 'plugins' option does not exist yet, add it...
                if (!$count) {
                    $var_txt    = "\n{$varname}['plugins'] = $var_export;\n";
                    $new_config = str_replace('?>', $var_txt . '?>', $config_templ, $count);

                    if (!$count) {
                        $new_config = $config_templ . $var_txt;
                    }
                }

                $success = file_put_contents($config_file, $new_config);
            }
        }

        if ($success && php_sapi_name() == 'cli') {
            $this->io->write("<info>Updated local config at $config_file</info>");
        }

        return $success;
    }

    /**
     * Helper method to get an absolute path to the local Roundcube config file
     */
    private function rcubeConfigFile()
    {
        return realpath(getcwd() . '/config/config.inc.php');
    }

    /**
     * Run the given script file
     */
    private function rcubeRunScript($script, PackageInterface $package)
    {
        $plugin_name = $this->getPluginName($package);
        $plugin_dir = $this->getVendorDir() . DIRECTORY_SEPARATOR . $plugin_name;

        // check for executable shell script
        if (($scriptfile = realpath($plugin_dir . DIRECTORY_SEPARATOR . $script)) && is_executable($scriptfile)) {
            $script = $scriptfile;
        }

        // run PHP script in Roundcube context
        if ($scriptfile && preg_match('/\.php$/', $scriptfile)) {
            $incdir = realpath(getcwd() . '/program/include');
            include_once($incdir . '/iniset.php');
            include($scriptfile);
        }
        // attempt to execute the given string as shell commands
        else {
            $process = new ProcessExecutor($this->io);
            $exitCode = $process->execute($script, $output, $plugin_dir);
            if ($exitCode !== 0) {
                throw new \RuntimeException('Error executing script: '. $process->getErrorOutput(), $exitCode);
            }
        }
    }

    /**
     * version_compare() wrapper, originally from composer/semver
     */
    private static function versionCompare($a, $b, $operator, $compareBranches = false)
    {
        $aIsBranch = 'dev-' === substr($a, 0, 4);
        $bIsBranch = 'dev-' === substr($b, 0, 4);

        if ($aIsBranch && $bIsBranch) {
            return $operator === '==' && $a === $b;
        }

        // when branches are not comparable, we make sure dev branches never match anything
        if (!$compareBranches && ($aIsBranch || $bIsBranch)) {
            return false;
        }

        return version_compare($a, $b, $operator);
    }
}
