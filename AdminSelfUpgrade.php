<?php
/*
* 2007-2016 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Open Software License (OSL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/osl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*	@author PrestaShop SA <contact@prestashop.com>
*	@copyright	2007-2016 PrestaShop SA
*	@version	Release: $Revision: 11834 $
*	@license		http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
*	International Registered Trademark & Property of PrestaShop SA
*/

use PrestaShop\Module\AutoUpgrade\AjaxResponse;
use PrestaShop\Module\AutoUpgrade\BackupFinder;
use PrestaShop\Module\AutoUpgrade\UpgradePage;
use PrestaShop\Module\AutoUpgrade\Upgrader;
use PrestaShop\Module\AutoUpgrade\UpgradeSelfCheck;
use PrestaShop\Module\AutoUpgrade\Tools14;
use PrestaShop\Module\AutoUpgrade\UpgradeException;
use PrestaShop\Module\AutoUpgrade\Parameters\UpgradeConfigurationStorage;
use PrestaShop\Module\AutoUpgrade\Parameters\UpgradeFileNames;
use PrestaShop\Module\AutoUpgrade\UpgradeContainer;

require __DIR__.'/vendor/autoload.php';

class AdminSelfUpgrade extends AdminController
{
    public $multishop_context;
    public $multishop_context_group = false;
    public $_html = '';
    // used for translations
    public static $l_cache;
    // retrocompatibility
    public $noTabLink = array();
    public $id = -1;

    public $ajax = false;

    public $standalone = true;

    /**
     * Initialized in initPath()
     */
    public $autoupgradePath = null;
    public $downloadPath = null;
    public $backupPath = null;
    public $latestPath = null;
    public $tmpPath = null;

    /**
     * autoupgradeDir
     *
     * @var string directory relative to admin dir
     */
    public $autoupgradeDir = 'autoupgrade';
    public $latestRootDir = '';
    public $prodRootDir = '';
    public $adminDir = '';

    public $keepImages = null;
    public $updateDefaultTheme = null;
    public $changeToDefaultTheme = null;
    public $keepMails = null;
    public $manualMode = null;
    public $deactivateCustomModule = null;

    public static $classes14 = array('Cache', 'CacheFS', 'CarrierModule', 'Db', 'FrontController', 'Helper','ImportModule',
    'MCached', 'Module', 'ModuleGraph', 'ModuleGraphEngine', 'ModuleGrid', 'ModuleGridEngine',
    'MySQL', 'Order', 'OrderDetail', 'OrderDiscount', 'OrderHistory', 'OrderMessage', 'OrderReturn',
    'OrderReturnState', 'OrderSlip', 'OrderState', 'PDF', 'RangePrice', 'RangeWeight', 'StockMvt',
    'StockMvtReason', 'SubDomain', 'Shop', 'Tax', 'TaxRule', 'TaxRulesGroup', 'WebserviceKey', 'WebserviceRequest', '');

    public static $loopBackupFiles = 400;
    public static $maxBackupFileSize = 15728640; // 15 Mo
    public static $loopBackupDbTime = 6;
    public static $max_written_allowed = 4194304; // 4096 ko
    public static $loopUpgradeFiles = 600;
    public static $loopRestoreFiles = 400;
    public static $loopRestoreQueryTime = 6;
    public static $loopUpgradeModulesTime = 6;
    public static $loopRemoveSamples = 400;

    /* usage :  key = the step you want to ski
     * value = the next step you want instead
     *	example : public static $skipAction = array();
     *	initial order upgrade:
     *		download, unzip, removeSamples, backupFiles, backupDb, upgradeFiles, upgradeDb, upgradeModules, cleanDatabase, upgradeComplete
     * initial order rollback: rollback, restoreFiles, restoreDb, rollbackComplete
     */
    public static $skipAction = array();

    public $_fieldsUpgradeOptions = array();
    public $_fieldsBackupOptions = array();

    /**
     * @var UpgradeContainer
     */
    private $upgradeContainer;

    /**
     * replace tools encrypt
     *
     * @param mixed $string
     * @return void
     */
    public function encrypt($string)
    {
        return md5(_COOKIE_KEY_.$string);
    }

    public function checkToken()
    {
        // simple checkToken in ajax-mode, to be free of Cookie class (and no Tools::encrypt() too )
        if ($this->ajax && isset($_COOKIE['id_employee'])) {
            return ($_COOKIE['autoupgrade'] == $this->encrypt($_COOKIE['id_employee']));
        } else {
            return parent::checkToken();
        }
    }

    /**
     * create cookies id_employee, id_tab and autoupgrade (token)
     */
    public function createCustomToken()
    {
        // ajax-mode for autoupgrade, we can't use the classic authentication
        // so, we'll create a cookie in admin dir, based on cookie key
        global $cookie;
        $id_employee = $cookie->id_employee;
        if ($cookie->id_lang) {
            $iso_code = $_COOKIE['iso_code'] = Language::getIsoById((int)$cookie->id_lang);
        } else {
            $iso_code = 'en';
        }
        $admin_dir = trim(str_replace($this->prodRootDir, '', $this->adminDir), DIRECTORY_SEPARATOR);
        $cookiePath = __PS_BASE_URI__.$admin_dir;
        setcookie('id_employee', $id_employee, 0, $cookiePath);
        setcookie('id_tab', $this->id, 0, $cookiePath);
        setcookie('iso_code', $iso_code, 0, $cookiePath);
        setcookie('autoupgrade', $this->encrypt($id_employee), 0, $cookiePath);
        return false;
    }

    public function viewAccess($disable = false)
    {
        if ($this->ajax) {
            return true;
        } else {
            // simple access : we'll allow only 46admin
            global $cookie;
            if ($cookie->profile == 1) {
                return true;
            }
        }
        return false;
    }

    public function __construct()
    {
        parent::__construct();
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('magic_quotes_runtime', '0');
        @ini_set('magic_quotes_sybase', '0');

        $this->init();

        $this->db = Db::getInstance();
        $this->bootstrap = true;

        // Performance settings, if your server has a low memory size, lower these values
        $perf_array = array(
            'loopBackupFiles' => array(400, 800, 1600),
            'maxBackupFileSize' => array(15728640, 31457280, 62914560),
            'loopBackupDbTime' => array(6, 12, 25),
            'max_written_allowed' => array(4194304, 8388608, 16777216),
            'loopUpgradeFiles' => array(600, 1200, 2400),
            'loopRestoreFiles' => array(400, 800, 1600),
            'loopRestoreQueryTime' => array(6, 12, 25),
            'loopUpgradeModulesTime' => array(6, 12, 25),
            'loopRemoveSamples' => array(400, 800, 1600)
        );
        switch ($this->upgradeContainer->getUpgradeConfiguration()->get('PS_AUTOUP_PERFORMANCE')) {
            case 3:
                foreach ($perf_array as $property => $values) {
                    self::$$property = $values[2];
                }
                break;
            case 2:
                foreach ($perf_array as $property => $values) {
                    self::$$property = $values[1];
                }
                break;
            case 1:
            default:
                foreach ($perf_array as $property => $values) {
                    self::$$property = $values[0];
                }
        }

        self::$currentIndex = $_SERVER['SCRIPT_NAME'].(($controller = Tools14::getValue('controller')) ? '?controller='.$controller: '');

        if (defined('_PS_ADMIN_DIR_')) {
            $file_tab = @filemtime($this->autoupgradePath.DIRECTORY_SEPARATOR.'ajax-upgradetab.php');
            $file =  @filemtime(_PS_ROOT_DIR_.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.$this->autoupgradeDir.DIRECTORY_SEPARATOR.'ajax-upgradetab.php');

            if ($file_tab < $file) {
                @copy(_PS_ROOT_DIR_.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.$this->autoupgradeDir.DIRECTORY_SEPARATOR.'ajax-upgradetab.php',
                    $this->autoupgradePath.DIRECTORY_SEPARATOR.'ajax-upgradetab.php');
            }
        }

        if (!$this->ajax) {
            Context::getContext()->smarty->assign('display_header_javascript', true);
        }
    }

    /**
     * function to set configuration fields display
     *
     * @return void
     */
    private function _setFields()
    {
        $this->_fieldsBackupOptions = array(
            'PS_AUTOUP_BACKUP' => array(
                'title' => $this->trans('Back up my files and database', array(), 'Modules.Autoupgrade.Admin'), 'cast' => 'intval', 'validation' => 'isBool', 'defaultValue' => '1',
                'type' => 'bool', 'desc' => $this->trans('Automatically back up your database and files in order to restore your shop if needed. This is experimental: you should still perform your own manual backup for safety.', array(), 'Modules.Autoupgrade.Admin'),
            ),
            'PS_AUTOUP_KEEP_IMAGES' => array(
                'title' => $this->trans('Back up my images', array(), 'Modules.Autoupgrade.Admin'), 'cast' => 'intval', 'validation' => 'isBool', 'defaultValue' => '1',
                'type' => 'bool', 'desc' => $this->trans('To save time, you can decide not to back your images up. In any case, always make sure you did back them up manually.', array(), 'Modules.Autoupgrade.Admin'),
            ),
        );
        $this->_fieldsUpgradeOptions = array(
            'PS_AUTOUP_PERFORMANCE' => array(
                'title' => $this->trans('Server performance', array(), 'Modules.Autoupgrade.Admin'), 'cast' => 'intval', 'validation' => 'isInt', 'defaultValue' => '1',
                'type' => 'select', 'desc' => $this->trans('Unless you are using a dedicated server, select "Low".', array(), 'Modules.Autoupgrade.Admin').'<br />'.
                $this->trans('A high value can cause the upgrade to fail if your server is not powerful enough to process the upgrade tasks in a short amount of time.', array(), 'Modules.Autoupgrade.Admin'),
                'choices' => array(1 => $this->trans('Low (recommended)', array(), 'Modules.Autoupgrade.Admin'), 2 => $this->trans('Medium', array(), 'Modules.Autoupgrade.Admin'), 3 => $this->trans('High', array(), 'Modules.Autoupgrade.Admin'))
            ),
            'PS_AUTOUP_CUSTOM_MOD_DESACT' => array(
                'title' => $this->trans('Disable non-native modules', array(), 'Modules.Autoupgrade.Admin'), 'cast' => 'intval', 'validation' => 'isBool',
                'type' => 'bool', 'desc' => $this->trans('As non-native modules can experience some compatibility issues, we recommend to disable them by default.', array(), 'Modules.Autoupgrade.Admin').'<br />'.
                $this->trans('Keeping them enabled might prevent you from loading the "Modules" page properly after the upgrade.', array(), 'Modules.Autoupgrade.Admin'),
            ),
            'PS_AUTOUP_UPDATE_DEFAULT_THEME' => array(
                'title' => $this->trans('Upgrade the default theme', array(), 'Modules.Autoupgrade.Admin'), 'cast' => 'intval', 'validation' => 'isBool', 'defaultValue' => '1',
                'type' => 'bool', 'desc' => $this->trans('If you customized the default PrestaShop theme in its folder (folder name "classic" in 1.7), enabling this option will lose your modifications.', array(), 'Modules.Autoupgrade.Admin').'<br />'
                .$this->trans('If you are using your own theme, enabling this option will simply update the default theme files, and your own theme will be safe.', array(), 'Modules.Autoupgrade.Admin'),
            ),
            'PS_AUTOUP_CHANGE_DEFAULT_THEME' => array(
                'title' => $this->trans('Switch to the default theme', array(), 'Modules.Autoupgrade.Admin'), 'cast' => 'intval', 'validation' => 'isBool', 'defaultValue' => '0',
                'type' => 'bool', 'desc' => $this->trans('This will change your theme: your shop will then use the default theme of the version of PrestaShop you are upgrading to.', array(), 'Modules.Autoupgrade.Admin'),
            ),
            'PS_AUTOUP_KEEP_MAILS' => array(
                'title' => $this->trans('Keep the customized email templates', array(), 'Modules.Autoupgrade.Admin'), 'cast' => 'intval', 'validation' => 'isBool',
                'type' => 'bool', 'desc' => $this->trans('This will not upgrade the default PrestaShop e-mails.', array(), 'Modules.Autoupgrade.Admin').'<br />'
                .$this->trans('If you customized the default PrestaShop e-mail templates, enabling this option will keep your modifications.', array(), 'Modules.Autoupgrade.Admin'),
            ),
        );
    }

    /**
     * init to build informations we need
     *
     * @return void
     */
    public function init()
    {
        if (!$this->ajax) {
            parent::init();
        }

        // For later use, let's set up prodRootDir and adminDir
        // This way it will be easier to upgrade a different path if needed
        $this->prodRootDir = _PS_ROOT_DIR_;
        $this->adminDir = realpath(_PS_ADMIN_DIR_);
        $this->upgradeContainer = new UpgradeContainer($this->prodRootDir, $this->adminDir);
        if (!defined('__PS_BASE_URI__')) {
            // _PS_DIRECTORY_ replaces __PS_BASE_URI__ in 1.5
            if (defined('_PS_DIRECTORY_')) {
                define('__PS_BASE_URI__', _PS_DIRECTORY_);
            } else {
                define('__PS_BASE_URI__', realpath(dirname($_SERVER['SCRIPT_NAME'])).'/../../');
            }
        }
        // from $_POST or $_GET
        $this->action = empty($_REQUEST['action'])?null:$_REQUEST['action'];
        $this->initPath();
        $this->upgradeContainer->getState()->importFromArray(
            empty($_REQUEST['params']) ? array() : $_REQUEST['params']
        );

        // If you have defined this somewhere, you know what you do
        /* load options from configuration if we're not in ajax mode */
        if (!$this->ajax) {
            $this->createCustomToken();

            $this->upgradeContainer->getState()->initDefault(
                $this->upgradeContainer->getUpgrader(),
                $this->upgradeContainer->getProperty(UpgradeContainer::PS_ROOT_PATH),
                $this->upgradeContainer->getProperty(UpgradeContainer::PS_VERSION));
            // removing temporary files
            $this->upgradeContainer->getFileConfigurationStorage()->cleanAll();
        }


        $this->keepImages = $this->upgradeContainer->getUpgradeConfiguration()->get('PS_AUTOUP_KEEP_IMAGES');
        $this->updateDefaultTheme = $this->upgradeContainer->getUpgradeConfiguration()->get('PS_AUTOUP_UPDATE_DEFAULT_THEME');
        $this->changeToDefaultTheme = $this->upgradeContainer->getUpgradeConfiguration()->get('PS_AUTOUP_CHANGE_DEFAULT_THEME');
        $this->keepMails = $this->upgradeContainer->getUpgradeConfiguration()->get('PS_AUTOUP_KEEP_MAILS');
        $this->manualMode = $this->upgradeContainer->getUpgradeConfiguration()->isManualModeEnabled();
        $this->deactivateCustomModule = $this->upgradeContainer->getUpgradeConfiguration()->get('PS_AUTOUP_CUSTOM_MOD_DESACT');
    }

    /**
     * create some required directories if they does not exists
     */
    public function initPath()
    {
        // set autoupgradePath, to be used in backupFiles and backupDb config values
        $this->autoupgradePath = $this->adminDir.DIRECTORY_SEPARATOR.$this->autoupgradeDir;
        $this->backupPath = $this->autoupgradePath.DIRECTORY_SEPARATOR.'backup';
        $this->downloadPath = $this->autoupgradePath.DIRECTORY_SEPARATOR.'download';
        $this->latestPath = $this->autoupgradePath.DIRECTORY_SEPARATOR.'latest';
        $this->tmpPath = $this->autoupgradePath.DIRECTORY_SEPARATOR.'tmp';
        $this->latestRootDir = $this->latestPath.DIRECTORY_SEPARATOR;

        // Check directory is not missing
        foreach (array('autoupgradePath', 'backupPath', 'downloadPath', 'latestPath', 'tmpPath', 'latestRootDir') as $pathName) {
            $path = $this->{$pathName};
            if (!file_exists($path) && !mkdir($path)) {
                $this->_errors[] = $this->trans('Unable to create directory %s', array($path), 'Modules.Autoupgrade.Admin');
            }
            if (!is_writable($path)) {
                $this->_errors[] = $this->trans('Unable to write in the directory "%s"', array($path), 'Modules.Autoupgrade.Admin');
            }
        }

        if (!file_exists($this->backupPath.DIRECTORY_SEPARATOR.'index.php')) {
            if (!copy(_PS_ROOT_DIR_.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'index.php', $this->backupPath.DIRECTORY_SEPARATOR.'index.php')) {
                $this->_errors[] = $this->trans('Unable to create file %s', array($this->backupPath.DIRECTORY_SEPARATOR.'index.php'), 'Modules.Autoupgrade.Admin');
            }
        }

        $tmp = "order deny,allow\ndeny from all";
        if (!file_exists($this->backupPath.DIRECTORY_SEPARATOR.'.htaccess')) {
            if (!file_put_contents($this->backupPath.DIRECTORY_SEPARATOR.'.htaccess', $tmp)) {
                $this->_errors[] = $this->trans('Unable to create file %s', array($this->backupPath.DIRECTORY_SEPARATOR.'.htaccess'), 'Modules.Autoupgrade.Admin');
            }
        }
    }

    public function postProcess()
    {
        $this->_setFields();

        if (Tools14::isSubmit('putUnderMaintenance')) {
            foreach (Shop::getCompleteListOfShopsID() as $id_shop) {
                Configuration::updateValue('PS_SHOP_ENABLE', 0, false, null, (int)$id_shop);
            }
            Configuration::updateGlobalValue('PS_SHOP_ENABLE', 0);
        }

//        if (Tools14::isSubmit('customSubmitAutoUpgrade')) {
//            $config_keys = array_keys(array_merge($this->_fieldsUpgradeOptions, $this->_fieldsBackupOptions));
//            $config = array();
//            foreach ($config_keys as $key) {
//                if (isset($_POST[$key])) {
//                    $config[$key] = $_POST[$key];
//                }
//            }
//            $res = $this->writeConfig($config);
//            if ($res) {
//                Tools14::redirectAdmin(self::$currentIndex.'&conf=6&token='.Tools14::getValue('token'));
//            }
//        }

        if (Tools14::isSubmit('deletebackup')) {
            $res = false;
            $name = Tools14::getValue('name');
            $filelist = scandir($this->backupPath);
            foreach ($filelist as $filename) {
                // the following will match file or dir related to the selected backup
                if (!empty($filename) && $filename[0] != '.' && $filename != 'index.php' && $filename != '.htaccess'
                    && preg_match('#^(auto-backupfiles_|)'.preg_quote($name).'(\.zip|)$#', $filename, $matches)) {
                    if (is_file($this->backupPath.DIRECTORY_SEPARATOR.$filename)) {
                        $res &= unlink($this->backupPath.DIRECTORY_SEPARATOR.$filename);
                    } elseif (!empty($name) && is_dir($this->backupPath.DIRECTORY_SEPARATOR.$name.DIRECTORY_SEPARATOR)) {
                        $res = self::deleteDirectory($this->backupPath.DIRECTORY_SEPARATOR.$name.DIRECTORY_SEPARATOR);
                    }
                }
            }
            if ($res) {
                Tools14::redirectAdmin(self::$currentIndex.'&conf=1&token='.Tools14::getValue('token'));
            } else {
                $this->_errors[] = $this->trans('Error when trying to delete backups %s', array($name), 'Modules.Autoupgrade.Admin');
            }
        }
        parent::postProcess();
    }

    /**
     * update module configuration (saved in file UpgradeFiles::configFilename) with $new_config
     *
     * @param array $new_config
     * @return boolean true if success
     */
//    public function writeConfig($config)
//    {
//        if (!$this->upgradeContainer->getFileConfigurationStorage()->exists(UpgradeFileNames::configFilename) && !empty($config['channel'])) {
//            $this->upgradeContainer->getUpgrader()->channel = $config['channel'];
//            $this->upgradeContainer->getUpgrader()->checkPSVersion();
//
//            $this->upgradeContainer->getState()->setInstallVersion($this->upgradeContainer->getUpgrader()->version_num);
//        }
//
//        $this->upgradeContainer->getUpgradeConfiguration()->merge($config);
//        $this->upgradeContainer->getLogger()->info($this->trans('Configuration successfully updated.', array(), 'Modules.Autoupgrade.Admin').' <strong>'.$this->trans('This page will now be reloaded and the module will check if a new version is available.', array(), 'Modules.Autoupgrade.Admin').'</strong>');
//        return (new UpgradeConfigurationStorage($this->autoupgradePath.DIRECTORY_SEPARATOR))->save($this->upgradeContainer->getUpgradeConfiguration(), UpgradeFileNames::configFilename);
//    }

    /**
     * Delete directory and subdirectories
     *
     * @param string $dirname Directory name
     */
    public static function deleteDirectory($dirname, $delete_self = true)
    {
        return Tools14::deleteDirectory($dirname, $delete_self);
    }

    public function display()
    {
        /* Make sure the user has configured the upgrade options, or set default values */
        $configuration_keys = array(
            'PS_AUTOUP_UPDATE_DEFAULT_THEME' => 1,
            'PS_AUTOUP_CHANGE_DEFAULT_THEME' => 0,
            'PS_AUTOUP_KEEP_MAILS' => 0,
            'PS_AUTOUP_CUSTOM_MOD_DESACT' => 1,
            'PS_AUTOUP_PERFORMANCE' => 1,
        );

        foreach ($configuration_keys as $k => $default_value) {
            if (Configuration::get($k) == '') {
                Configuration::updateValue($k, $default_value);
            }
        }

        // update backup name
        $backupFinder = new BackupFinder($this->backupPath);
        $availableBackups = $backupFinder->getAvailableBackups();
        if (!$this->upgradeContainer->getUpgradeConfiguration()->get('PS_AUTOUP_BACKUP')
            && !empty($availableBackups)
            && !in_array($this->upgradeContainer->getState()->getBackupName(), $availableBackups)
        ) {
            $this->upgradeContainer->getState()->setBackupName(end($availableBackups));
        }

        $upgrader = $this->upgradeContainer->getUpgrader();
        $upgradeSelfCheck = new UpgradeSelfCheck(
            $upgrader,
            $this->prodRootDir,
            $this->adminDir,
            $this->autoupgradePath
        );
        $response = new AjaxResponse($this->upgradeContainer->getTranslator(), $this->upgradeContainer->getState(), $this->upgradeContainer->getLogger());
        $this->_html = (new UpgradePage(
            $this->upgradeContainer->getUpgradeConfiguration(),
            $this->upgradeContainer->getTwig(),
            $this->upgradeContainer->getTranslator(),
            $upgradeSelfCheck,
            $upgrader,
            $backupFinder,
            $this->autoupgradePath,
            $this->prodRootDir,
            $this->adminDir,
            self::$currentIndex,
            $this->token,
            $this->upgradeContainer->getState()->getInstallVersion(),
            $this->manualMode,
            $this->upgradeContainer->getState()->getBackupName(),
            $this->downloadPath
        ))->display(
            $response->setUpgradeConfiguration($this->upgradeContainer->getUpgradeConfiguration())
                ->getJsonResponse()
        );

        $this->ajax = true;
        $this->content = $this->_html;
        return parent::display();
    }

    /**
     * Adapter for trans calls, existing only on PS 1.7.
     * Making them available for PS 1.6 as well
     *
     * @param string $id
     * @param array $parameters
     * @param string $domain
     * @param string $locale
     */
    public function trans($id, array $parameters = array(), $domain = null, $locale = null)
    {
        return (new \PrestaShop\Module\AutoUpgrade\UpgradeTools\Translator(get_class()))->trans($id, $parameters, $domain, $locale);
    }
}
