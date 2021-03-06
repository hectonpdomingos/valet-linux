<?php

namespace Valet;

class Caddy
{
    public $cli;
    public $files;
    public $daemonPath;
    public $linux;

    /**
     * Create a new Caddy instance.
     *
     * @param Linux       $linux
     * @param CommandLine $cli
     * @param Filesystem  $files
     */
    public function __construct(Linux $linux, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
        $this->linux = $linux;
        $this->daemonPath = $this->linux->getConfig('systemd-caddy');
    }

    /**
     * Install the system launch daemon for the Caddy server.
     *
     * @return void
     */
    public function install()
    {
        $this->caddyAllowRootPorts();
        $this->installCaddyFile();
        $this->installCaddyDirectory();
        $this->installCaddyDaemon();
    }

    /**
     * Install the Caddyfile to the ~/.valet directory.
     *
     * This file serves as the main server configuration for Valet.
     *
     * @return void
     */
    public function caddyAllowRootPorts()
    {
        $caddy_bin = $this->files->realpath(__DIR__.'/../../').'/bin/caddy';

        $this->cli->quietly('setcap cap_net_bind_service=+ep '.$caddy_bin);
    }

    /**
     * Install the Caddyfile to the ~/.valet directory.
     *
     * This file serves as the main server configuration for Valet.
     *
     * @return void
     */
    public function installCaddyFile()
    {
        $contents = str_replace(
            'FPM_ADDRESS', $this->linux->getConfig('systemd-caddy-fpm'),
            $this->files->get(__DIR__.'/../stubs/Caddyfile')
        );

        $this->files->putAsUser(
            VALET_HOME_PATH.'/Caddyfile',
            str_replace('VALET_HOME_PATH', VALET_HOME_PATH, $contents)
        );
    }

    /**
     * Install the Caddy configuration directory to the ~/.valet directory.
     *
     * This directory contains all site-specific Caddy definitions.
     *
     * @return void
     */
    public function installCaddyDirectory()
    {
        if (!$this->files->isDir($caddyDirectory = VALET_HOME_PATH.'/Caddy')) {
            $this->files->mkdirAsUser($caddyDirectory);
        }

        $this->files->touchAsUser($caddyDirectory.'/.keep');
    }

    /**
     * Install the Caddy daemon on a system level daemon.
     *
     * @return void
     */
    public function installCaddyDaemon()
    {
        $contents = str_replace(
            'VALET_PATH', $this->files->realpath(__DIR__.'/../../'),
            $this->files->get(__DIR__.'/../stubs/caddy.service')
        );

        $this->files->put(
            $this->daemonPath, str_replace('VALET_HOME_PATH', VALET_HOME_PATH, $contents)
        );

        $this->cli->quietly('systemctl daemon-reload');
        $this->linux->enableService('caddy');
    }

    /**
     * Restart the launch daemon.
     *
     * @return void
     */
    public function restart()
    {
        $this->cli->quietly('systemctl daemon-reload');
        $this->linux->restartService('caddy');
    }

    /**
     * Stop the launch daemon.
     *
     * @return void
     */
    public function stop()
    {
        $this->linux->stopService('caddy');
    }

    /**
     * Remove the launch daemon.
     *
     * @return void
     */
    public function uninstall()
    {
        $this->stop();
        $this->linux->disableService('caddy');

        $this->files->unlink($this->daemonPath);
        // remove .valet files
        $files = glob('/home/'.$_SERVER['SUDO_USER'].'/.valet/*'); // get all file names
        foreach ($files as $file) { // iterate files
            if (is_file($file)) {
                unlink($file);
            } // delete file
        }
        $this->cli->quietly('sed -i \'/conf-file=\/home\/'.$_SERVER['SUDO_USER'].'\/.valet\/dnsmasq.conf/d\' /etc/dnsmasq.conf');
        $this->cli->quietly('systemctl daemon-reload');
    }
}
