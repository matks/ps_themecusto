<?php
/**
* 2007-2018 PrestaShop
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
* @author PrestaShop SA <contact@prestashop.com>
* @copyright 2007-2018 PrestaShop SA
* @license http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
* International Registered Trademark & Property of PrestaShop SA
**/

use PrestaShop\PrestaShop\Core\Addon\Theme\ThemeManagerBuilder;

class AdminPsThemeCustoAdvancedController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();

        $this->theme_manager = (new ThemeManagerBuilder($this->context, Db::getInstance()))->build();
        $this->theme_repository = (new ThemeManagerBuilder($this->context, Db::getInstance()))->buildRepository();
    }

    /**
     * Initialize the content by adding Boostrap and loading the TPL
     *
     * @param none
     * @return none
     */
    public function initContent()
    {
        parent::initContent();
        $this->context->smarty->assign(array(
            'bootstrap'         =>  1,
            'configure_type'    => 'advanced'
        ));
        $this->module->setMedia();
        $this->setTemplate( $this->module->template_dir.'page.tpl');
    }

     /**
     * Clone a theme and modify the config to set the parent theme
     *
     * @param none
     * @return bool
     */
    public function ajaxProcessDownloadChildTheme()
    {
        global $kernel;

        if (!$this->module->hasEditRight()) {
            return $this->l("You do not have permission to edit this.");
        }

        $exporter = $kernel->getContainer()->get('prestashop.core.addon.theme.exporter');
        $path = $exporter->export($this->context->shop->theme);
        $aPath = array_reverse(explode("/", $path));
        $sThemeZipPath = "/themes/".$aPath[0];

        die($sThemeZipPath);
    }

    /**
     * AJAX getting a file attachment and will upload the file, install it, check if there's modules in it ...
     *
     * @param none
     * @return string
     */
    public function ajaxProcessUploadChildTheme()
    {
        $aChildThemeReturned = Tools::fileAttachment('file');
        self::processUploadFileChild( $aChildThemeReturned, _PS_ALL_THEMES_DIR_.$aChildThemeReturned['rename']);
        $sFolderPath = self::postProcessInstall(_PS_ALL_THEMES_DIR_.$aChildThemeReturned['rename']);

        if ($sFolderPath === false) {
            die('no good format ZIP');
        } else {
            $bChildThemeHasModules = self::checkChildThemeHasModules($sFolderPath);
            if( $bChildThemeHasModules ) {
                $test = self::deleteChildTheme($sFolderPath);
                die('Child theme deleted !!!');
            } else {
                die('Child theme installed !!!');
            }
        }
    }

    public function processUploadFileChild($aChildThemeReturned, $dest)
    {
        if (!$this->module->hasEditRight()) {
            return $this->l("You do not have permission to edit this.");
        }

        switch ($aChildThemeReturned['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $this->errors[] = $this->trans('The uploaded file is too large.', array(), 'Admin.Design.Notification');
                return false;
            default:
                $this->errors[] = $this->trans('Unknown error.', array(), 'Admin.Notifications.Error');
                return false;
        }

        $tmp_name = $aChildThemeReturned['tmp_name'];
        $mimeType = false;
        $goodMimeType = false;

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME);
            $mimeType = @finfo_file($finfo, $tmp_name);
            @finfo_close($finfo);
        } elseif (function_exists('mime_content_type')) {
            $mimeType = @mime_content_type($tmp_name);
        } elseif (function_exists('exec')) {
            $mimeType = trim(@exec('file -b --mime-type '.escapeshellarg($tmp_name)));
            if (!$mimeType) {
                $mimeType = trim(@exec('file --mime '.escapeshellarg($tmp_name)));
            }
            if (!$mimeType) {
                $mimeType = trim(@exec('file -bi '.escapeshellarg($tmp_name)));
            }
        }

        if (!empty($mimeType)) {
            preg_match('#application/zip#', $mimeType, $matches);
            if (!empty($matches)) {
                $goodMimeType = true;
            }
        }

        if (false === $goodMimeType) {
            $this->errors[] = $this->trans('Invalid file format.', array(), 'Admin.Design.Notification');
            return false;
        }

        $name = $aChildThemeReturned['name'];
        if (!Validate::isFileName($name)) {
            $dest = _PS_ALL_THEMES_DIR_.sha1_file($tmp_name).'.zip';
        }

        if (!move_uploaded_file(
            $aChildThemeReturned['tmp_name'],
            $dest
        )) {
            $this->errors[] = $this->trans('Failed to move uploaded file.', array(), 'Admin.Design.Notification');
            return false;
        }

        return $dest;
    }

    /**
     * We install the child theme and we return the folder child theme's name
     *
     * @param string
     * @return string
     */
    public function postProcessInstall($dest)
    {
        if (!$this->module->hasEditRight()) {
            return $this->l("You do not have permission to edit this.");
        }

        try {
            $this->theme_manager->install($dest);
            @unlink($dest);
            $aFolderScan = @scandir(_PS_ALL_THEMES_DIR_);

            foreach ($aFolderScan as $key => $sObject) {
                $sDirThemeFolder = _PS_ALL_THEMES_DIR_.$sObject;
                if (is_dir($sDirThemeFolder) && !in_array($sObject,array('.','..'))) {
                    $aFolder[filemtime($sDirThemeFolder)] = $sDirThemeFolder;
                }
            }

            krsort($aFolder);
            $aChildthemeFolder = array_values($aFolder);
            return $aChildthemeFolder[0];
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * the child theme must not having modules. If it does, we will delete it later.
     *
     * @param string
     * @return bool
     */
    public function checkChildThemeHasModules($sFolderPath)
    {
        $aScanedRoot = @scandir($sFolderPath);

        if (in_array("modules", $aScanedRoot)) {
            $aScanModules = @scandir($sFolderPath."/modules");
            unset($aScanModules[array_search(".", $aScanModules)]);
            unset($aScanModules[array_search("..", $aScanModules)]);
            if (($key = array_search("index.php", $aScanModules)) !== false) {
                unset($aScanModules[$key]);
            }
            $iHasModules = count($aScanModules);
            if ($iHasModules > 0) {
                return true;
            } else {
                return false;
            }
        }
        return false;
    }

    /**
     * the child theme has modules. We can't keep it.
     *
     * @param string
     * @return bool
     */
    public function deleteChildTheme($sFolderPath)
    {
        $it = new RecursiveDirectoryIterator($sFolderPath, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        return @rmdir($sFolderPath);
    }

}