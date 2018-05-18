<?php

/**
 * @file
 * Provides a way to patch Composer packages after installation.
 */

namespace cweagans\Composer\Plugin;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Installer\PackageEvents;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Installer\PackageEvent;
use Composer\Util\ProcessExecutor;
use Composer\Util\RemoteFilesystem;
use Symfony\Component\Process\Process;
use cweagans\Composer\Util;
use cweagans\Composer\PatchEvent;
use cweagans\Composer\PatchEvents;
use cweagans\Composer\ConfigurablePlugin;
use cweagans\Composer\Patch;

class Patches implements PluginInterface, EventSubscriberInterface
{

    use ConfigurablePlugin;

    /**
     * @var Composer $composer
     */
    protected $composer;

    /**
     * @var IOInterface $io
     */
    protected $io;

    /**
     * @var EventDispatcher $eventDispatcher
     */
    protected $eventDispatcher;

    /**
     * @var ProcessExecutor $executor
     */
    protected $executor;

    /**
     * @var array $patches
     */
    protected $patches;

    /**
     * Apply plugin modifications to composer
     *
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->eventDispatcher = $composer->getEventDispatcher();
        $this->executor = new ProcessExecutor($this->io);
        $this->patches = array();
        $this->installedPatches = array();

        $this->configuration = [
            'exit-on-patch-failure' => [
                'type' => 'bool',
                'default' => true,
            ],
            'disable-patching' => [
                'type' => 'bool',
                'default' => false,
            ],
            'disable-patching-from-dependencies' => [
                'type' => 'bool',
                'default' => false,
            ],
            'disable-patch-reports' => [
                'type' => 'bool',
                'default' => false,
            ],
            'patch-levels' => [
                'type' => 'list',
                'default' => ['-p1', '-p0', '-p2', '-p4']
            ],
            'patches-file' => [
                'type' => 'string',
                'default' => '',
            ]
        ];
        $this->configure($this->composer->getPackage()->getExtra(), 'composer-patches');
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::PRE_INSTALL_CMD => array('checkPatches'),
            ScriptEvents::PRE_UPDATE_CMD => array('checkPatches'),
            PackageEvents::PRE_PACKAGE_INSTALL => array('gatherPatches'),
            PackageEvents::PRE_PACKAGE_UPDATE => array('gatherPatches'),
            // The following is a higher weight for compatibility with
            // https://github.com/AydinHassan/magento-core-composer-installer and
            // more generally for compatibility with any Composer plugin which
            // deploys downloaded packages to other locations. In the case that
            // you want those plugins to deploy patched files, those plugins have
            // to run *after* this plugin.
            // @see: https://github.com/cweagans/composer-patches/pull/153
            PackageEvents::POST_PACKAGE_INSTALL => array('postInstall', 10),
            PackageEvents::POST_PACKAGE_UPDATE => array('postInstall', 10),
        );
    }

    /**
     * Before running composer install,
     * @param Event $event
     */
    public function checkPatches(Event $event)
    {
        if (!$this->isPatchingEnabled()) {
            return;
        }

        try {
            $repositoryManager = $this->composer->getRepositoryManager();
            $localRepository = $repositoryManager->getLocalRepository();
            $installationManager = $this->composer->getInstallationManager();
            $packages = $localRepository->getPackages();

            $tmp_patches = $this->grabPatches();
            foreach ($packages as $package) {
                $extra = $package->getExtra();
                if (isset($extra['patches'])) {
                    $this->installedPatches[$package->getName()] = $extra['patches'];
                }
                $patches = isset($extra['patches']) ? $extra['patches'] : array();
                $tmp_patches = Util::arrayMergeRecursiveDistinct($tmp_patches, $patches);
            }

            if ($tmp_patches == false) {
                $this->io->write('<info>No patches supplied.</info>');
                return;
            }

            // Remove packages for which the patch set has changed.
            foreach ($packages as $package) {
                if (!($package instanceof AliasPackage)) {
                    $package_name = $package->getName();
                    $extra = $package->getExtra();
                    $has_patches = isset($tmp_patches[$package_name]);
                    $has_applied_patches = isset($extra['patches_applied']);
                    if (($has_patches && !$has_applied_patches)
                        || (!$has_patches && $has_applied_patches)
                        || ($has_patches && $has_applied_patches &&
                            $tmp_patches[$package_name] !== $extra['patches_applied'])) {
                        $uninstallOperation = new UninstallOperation(
                            $package,
                            'Removing package so it can be re-installed and re-patched.'
                        );
                        $this->io->write('<info>Removing package ' .
                            $package_name .
                            ' so that it can be re-installed and re-patched.</info>');
                        $installationManager->uninstall($localRepository, $uninstallOperation);
                    }
                }
            }
        } catch (\LogicException $e) {
            // If the Locker isn't available, then we don't need to do this.
            // It's the first time packages have been installed.
            return;
        }
    }

    /**
     * Gather patches from dependencies and store them for later use.
     *
     * @param PackageEvent $event
     */
    public function gatherPatches(PackageEvent $event)
    {
        // If we've already done this, then don't do it again.
        if (isset($this->patches['_patchesGathered'])) {
            $this->io->write('<info>Patches already gathered. Skipping</info>', true, IOInterface::VERBOSE);
            return;
        } // If patching has been disabled, bail out here.
        elseif (!$this->isPatchingEnabled()) {
            $this->io->write('<info>Patching is disabled. Skipping.</info>', true, IOInterface::VERBOSE);
            return;
        }

        $this->patches = $this->grabPatches();
        if (empty($this->patches)) {
            $this->io->write('<info>No patches supplied.</info>');
        }

        $extra = $this->composer->getPackage()->getExtra();
        $patches_ignore = isset($extra['patches-ignore']) ? $extra['patches-ignore'] : array();

        // Now add all the patches from dependencies that will be installed.
        if (!$this->getConfig('disable-patching-from-dependencies')) {
            $operations = $event->getOperations();
            $this->io->write('<info>Gathering patches for dependencies. This might take a minute.</info>');
            foreach ($operations as $operation) {
                if ($operation->getJobType() == 'install' || $operation->getJobType() == 'update') {
                    $package = $this->getPackageFromOperation($operation);
                    $extra = $package->getExtra();
                    if (isset($extra['patches'])) {
                        if (isset($patches_ignore[$package->getName()])) {
                            foreach ($patches_ignore[$package->getName()] as $package_name => $patches) {
                                if (isset($extra['patches'][$package_name])) {
                                    $extra['patches'][$package_name] = array_diff(
                                        $extra['patches'][$package_name],
                                        $patches
                                    );
                                }
                            }
                        }
                        $this->patches = Util::arrayMergeRecursiveDistinct($this->patches, $extra['patches']);
                    }
                    // Unset installed patches for this package
                    if (isset($this->installedPatches[$package->getName()])) {
                        unset($this->installedPatches[$package->getName()]);
                    }
                }
            }
        }

        // Merge installed patches from dependencies that did not receive an update.
        foreach ($this->installedPatches as $patches) {
//            $this->patches = Util::arrayMergeRecursiveDistinct($this->patches, $patches);
        }

        // If we're in verbose mode, list the projects we're going to patch.
        if ($this->io->isVerbose()) {
            foreach ($this->patches as $package => $patches) {
                $number = count($patches);
                $this->io->write('<info>Found ' . $number . ' patches for ' . $package . '.</info>');
            }
        }

        // Make sure we don't gather patches again. Extra keys in $this->patches
        // won't hurt anything, so we'll just stash it there.
        $this->patches['_patchesGathered'] = true;
    }

    /**
     * Get the patches from root composer or external file
     * @return Patches
     * @throws \Exception
     */
    public function grabPatches()
    {
        // First, try to get the patches from the root composer.json.
        $patches = [];
        $extra = $this->composer->getPackage()->getExtra();
        if (isset($extra['patches'])) {
            $this->io->write('<info>Gathering patches for root package.</info>');
            $patches = $extra['patches'];
        } // If it's not specified there, look for a patches-file definition.
        elseif ($this->getConfig('patches-file') != '') {
            $this->io->write('<info>Gathering patches from patch file.</info>');
            $patches = file_get_contents($this->getConfig('patches-file'));
            $patches = json_decode($patches, true);
            $error = json_last_error();
            if ($error != 0) {
                switch ($error) {
                    case JSON_ERROR_DEPTH:
                        $msg = ' - Maximum stack depth exceeded';
                        break;
                    case JSON_ERROR_STATE_MISMATCH:
                        $msg = ' - Underflow or the modes mismatch';
                        break;
                    case JSON_ERROR_CTRL_CHAR:
                        $msg = ' - Unexpected control character found';
                        break;
                    case JSON_ERROR_SYNTAX:
                        $msg = ' - Syntax error, malformed JSON';
                        break;
                    case JSON_ERROR_UTF8:
                        $msg = ' - Malformed UTF-8 characters, possibly incorrectly encoded';
                        break;
                    default:
                        $msg = ' - Unknown error';
                        break;
                }
                throw new \Exception('There was an error in the supplied patches file:' . $msg);
            }
            if (isset($patches['patches'])) {
                $patches = $patches['patches'];
            } elseif (!$patches) {
                throw new \Exception('There was an error in the supplied patch file');
            }
        } else {
            return array();
        }

        // Now that we have a populated $patches list, populate patch objects for everything.
        foreach ($patches as $package => $patch_defs) {
            if (isset($patch_defs[0]) && is_array($patch_defs[0])) {
                $this->io->write("<info>Using expanded definition format for package {$package}</info>");

                foreach ($patch_defs as $index => $def) {
                    $patch = new Patch();
                    $patch->package = $package;
                    $patch->url = $def["url"];
                    $patch->description = $def["description"];

                    $patches[$package][$index] = $patch;
                }
            } else {
                $this->io->write("<info>Using compact definition format for package {$package}</info>");

                $temporary_patch_list = [];

                foreach ($patch_defs as $description => $url) {
                    $patch = new Patch();
                    $patch->package = $package;
                    $patch->url = $url;
                    $patch->description = $description;

                    $temporary_patch_list[] = $patch;
                }

                $patches[$package] = $temporary_patch_list;
            }
        }

        return $patches;
    }

    /**
     * @param PackageEvent $event
     * @throws \Exception
     */
    public function postInstall(PackageEvent $event)
    {
        // Get the package object for the current operation.
        $operation = $event->getOperation();
        /** @var PackageInterface $package */
        $package = $this->getPackageFromOperation($operation);
        $package_name = $package->getName();

        if (!isset($this->patches[$package_name])) {
            if ($this->io->isVerbose()) {
                $this->io->write('<info>No patches found for ' . $package_name . '.</info>');
            }
            return;
        }
        $this->io->write('  - Applying patches for <info>' . $package_name . '</info>');

        // Get the install path from the package object.
        $manager = $event->getComposer()->getInstallationManager();
        $install_path = $manager->getInstaller($package->getType())->getInstallPath($package);

        // Set up a downloader.
        $downloader = new RemoteFilesystem($this->io, $this->composer->getConfig());

        // Track applied patches in the package info in installed.json
        $localRepository = $this->composer->getRepositoryManager()->getLocalRepository();
        $localPackage = $localRepository->findPackage($package_name, $package->getVersion());
        $extra = $localPackage->getExtra();
        $extra['patches_applied'] = array();

        foreach ($this->patches[$package_name] as $index => $patch) {
            /** @var Patch $patch */
            $this->io->write('    <info>' . $patch->url . '</info> (<comment>' . $patch->description . '</comment>)');
            try {
                $this->eventDispatcher->dispatch(
                    null,
                    new PatchEvent(PatchEvents::PRE_PATCH_APPLY, $package, $patch->url, $patch->description)
                );
                $this->getAndApplyPatch($downloader, $install_path, $patch->url);
                $this->eventDispatcher->dispatch(
                    null,
                    new PatchEvent(PatchEvents::POST_PATCH_APPLY, $package, $patch->url, $patch->description)
                );
                $extra['patches_applied'][$patch->description] = $patch->url;
            } catch (\Exception $e) {
                $this->io->write(
                    '   <error>Could not apply patch! Skipping. The error was: ' .
                    $e->getMessage() .
                    '</error>'
                );
                if ($this->getConfig('exit-on-patch-failure')) {
                    throw new \Exception("Cannot apply patch $patch->description ($patch->url)!");
                }
            }
        }
//        $localPackage->setExtra($extra);

        $this->io->write('');
        $this->writePatchReport($this->patches[$package_name], $install_path);
    }

    /**
     * Get a Package object from an OperationInterface object.
     *
     * @param OperationInterface $operation
     * @return PackageInterface
     * @throws \Exception
     */
    protected function getPackageFromOperation(OperationInterface $operation)
    {
        if ($operation instanceof InstallOperation) {
            $package = $operation->getPackage();
        } elseif ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        } else {
            throw new \Exception('Unknown operation: ' . get_class($operation));
        }

        return $package;
    }

    /**
     * Apply a patch on code in the specified directory.
     *
     * @param RemoteFilesystem $downloader
     * @param $install_path
     * @param $patch_url
     * @throws \Exception
     */
    protected function getAndApplyPatch(RemoteFilesystem $downloader, $install_path, $patch_url)
    {

        // Local patch file.
        if (file_exists($patch_url)) {
            $filename = realpath($patch_url);
        } else {
            // Generate random (but not cryptographically so) filename.
            $filename = uniqid(sys_get_temp_dir() . '/') . ".patch";

            // Download file from remote filesystem to this location.
            $hostname = parse_url($patch_url, PHP_URL_HOST);
            $downloader->copy($hostname, $patch_url, $filename, false);
        }

        // Modified from drush6:make.project.inc
        $patched = false;
        // The order here is intentional. p1 is most likely to apply with git apply.
        // p0 is next likely. p2 is extremely unlikely, but for some special cases,
        // it might be useful. p4 is useful for Magento 2 patches
        $patch_levels = $this->getConfig('patch-levels');
        foreach ($patch_levels as $patch_level) {
            if ($this->io->isVerbose()) {
                $comment = 'Testing ability to patch with git apply.';
                $comment .= ' This command may produce errors that can be safely ignored.';
                $this->io->write('<comment>' . $comment . '</comment>');
            }
            $checked = $this->executeCommand(
                'git -C %s apply --check -v %s %s',
                $install_path,
                $patch_level,
                $filename
            );
            $output = $this->executor->getErrorOutput();
            if (substr($output, 0, 7) == 'Skipped') {
                // Git will indicate success but silently skip patches in some scenarios.
                //
                // @see https://github.com/cweagans/composer-patches/pull/165
                $checked = false;
            }
            if ($checked) {
                // Apply the first successful style.
                $patched = $this->executeCommand(
                    'git -C %s apply %s %s',
                    $install_path,
                    $patch_level,
                    $filename
                );
                break;
            }
        }

        // In some rare cases, git will fail to apply a patch, fallback to using
        // the 'patch' command.
        if (!$patched) {
            foreach ($patch_levels as $patch_level) {
                // --no-backup-if-mismatch here is a hack that fixes some
                // differences between how patch works on windows and unix.
                if ($patched = $this->executeCommand(
                    "patch %s --no-backup-if-mismatch -d %s < %s",
                    $patch_level,
                    $install_path,
                    $filename
                )
                ) {
                    break;
                }
            }
        }

        // Clean up the temporary patch file.
        if (isset($hostname)) {
            unlink($filename);
        }
        // If the patch *still* isn't applied, then give up and throw an Exception.
        // Otherwise, let the user know it worked.
        if (!$patched) {
            throw new \Exception("Cannot apply patch $patch_url");
        }
    }

    /**
     * Checks if the root package enables patching.
     *
     * @return bool
     *   Whether patching is enabled. Defaults to true.
     */
    protected function isPatchingEnabled()
    {
        $enabled = true;

        $has_no_patches = empty($extra['patches']);
        $has_no_patches_file = ($this->getConfig('patches-file') == '');
        $patching_disabled = $this->getConfig('disable-patching');

        if ($patching_disabled || !($has_no_patches && $has_no_patches_file)) {
            $enabled = false;
        }

        return $enabled;
    }

    /**
     * Writes a patch report to the target directory.
     *
     * @param array $patches
     * @param string $directory
     */
    protected function writePatchReport($patches, $directory)
    {
        if ($this->getConfig('disable-patch-reports')) {
            return;
        }

        $output = "This file was automatically generated by Composer Patches";
        $output .= " (https://github.com/cweagans/composer-patches)\n";
        $output .= "Patches applied to this directory:\n\n";
        foreach ($patches as $index => $patch) {
            $output .= $patch->description . "\n";
            $output .= 'Source: ' . $patch->url . "\n\n\n";
        }
        file_put_contents($directory . "/PATCHES.txt", $output);
    }

    /**
     * Executes a shell command with escaping.
     *
     * @param string $cmd
     * @return bool
     */
    protected function executeCommand($cmd)
    {
        // Shell-escape all arguments except the command.
        $args = func_get_args();
        foreach ($args as $index => $arg) {
            if ($index !== 0) {
                $args[$index] = escapeshellarg($arg);
            }
        }

        // And replace the arguments.
        $command = call_user_func_array('sprintf', $args);
        $output = '';
        if ($this->io->isVerbose()) {
            $this->io->write('<comment>' . $command . '</comment>');
            $io = $this->io;
            $output = function ($type, $data) use ($io) {
                if ($type == Process::ERR) {
                    $io->write('<error>' . $data . '</error>');
                } else {
                    $io->write('<comment>' . $data . '</comment>');
                }
            };
        }
        return ($this->executor->execute($command, $output) == 0);
    }
}