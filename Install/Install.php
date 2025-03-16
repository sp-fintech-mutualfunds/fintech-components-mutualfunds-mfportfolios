<?php

namespace Apps\Fintech\Components\Mf\Portfolios\Install;

use System\Base\BasePackage;
use System\Base\Providers\ModulesServiceProvider\MenuInstaller;

class Install extends BasePackage
{
    protected $menuInstaller;

    public function init()
    {
        $this->menuInstaller = new MenuInstaller;

        return $this;
    }

    public function install()
    {
        $this->installMenu();

        return true;
    }

    protected function installMenu()
    {
        $this->menuInstaller->installMenu($this);

        return true;
    }

    public function uninstall($remove = false)
    {
        if ($remove) {
            $this->menuInstaller->uninstallMenu($this);
        }

        return true;
    }
}