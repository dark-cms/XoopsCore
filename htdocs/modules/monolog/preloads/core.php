<?php
/*
 You may not change or alter any portion of this comment or credits
 of supporting developers from this source code or any supporting source code
 which is considered copyrighted (c) material of the original comment or credit authors.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
*/

use Psr\Log\LogLevel;

/**
 * MonologLogger core preloads
 *
 * @category  MonologLogger
 * @package   MonologLogger
 * @author    Richard Griffith <richard@geekwright.com>
 * @copyright 2013 The XOOPS Project http://sourceforge.net/projects/xoops/
 * @license   GNU GPL 2 or later (http://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
 * @version   Release: 1.0
 * @link      http://xoops.org
 * @since     1.0
 */
class MonologCorePreload extends XoopsPreloadItem
{
    private static $registry = array();

    private static $query_start_time = 0;
    private static $query_stop_time = 0;

    private static $configs = null;

    /**
     * getConfigs
     * 
     * @return array of config options
     */
    private static function getConfigs()
    {
        $cache_key = 'module_monolog_configs';
        if (array_key_exists('monolog_default_configs', self::$configs)
            && self::$configs['monolog_default_configs'] == true) {
            self::$configs = null;
        }
        if (is_null(self::$configs)) {
            if (!$monolog_configs = Xoops_Cache::read($cache_key)) {
                $helper = \Xoops::getInstance()->getModuleHelper('monolog');
                self::$configs['monolog_enable'] = (bool) $helper->getConfig('monolog_enable');
                self::$configs['include_blocks'] = (bool) $helper->getConfig('include_blocks');
                self::$configs['include_deprecated'] = (bool) $helper->getConfig('include_deprecated');
                self::$configs['include_extra'] = (bool) $helper->getConfig('include_extra');
                self::$configs['include_queries'] = (bool) $helper->getConfig('include_queries');
                self::$configs['include_timers'] = (bool) $helper->getConfig('include_timers');
                self::$configs['logging_threshold'] = $helper->getConfig('logging_threshold');
                self::$configs['log_file_path'] = $helper->getConfig('log_file_path');

                $monolog_configs=serialize(self::$configs);
                Xoops_Cache::write($cache_key, $monolog_configs);
            } else {
                self::$configs = unserialize($monolog_configs);
            }
        }
        if (!array_key_exists('phpfire_enable', self::$configs)) {
            $xoops = \Xoops::getInstance();
            $user_groups = $xoops->isUser() ? $xoops->user->getGroups() : array(XOOPS_GROUP_ANONYMOUS);
            $moduleperm_handler = $xoops->getHandlerGroupperm();
            $helper = $xoops->getModuleHelper('monolog');
            $mid = $helper->getModule()->getVar('mid');
            self::$configs['phpfire_enable'] = false;

            if ($moduleperm_handler->checkRight('use_monolog', 0, $user_groups, $mid)) {
                // only other settings are in user config
                $uchelper = $xoops->getModuleHelper('userconfigs');
                if ($xoops->isUser() && $uchelper) {
                    $config_handler = $uchelper->getHandlerConfig();
                    $user_configs =
                        $config_handler->getConfigsByUser($xoops->user->getVar('uid'), $mid);
                    if (array_key_exists('phpfire_enable', $user_configs)) {
                        self::$configs['phpfire_enable'] = $user_configs['phpfire_enable'];
                    }
                }
            } else {
                // user has no permissions, turn everything off
                self::$configs['phpfire_enable'] = false;
            }
        }

        return self::$configs;
    }

    /**
     * eventCoreException
     * 
     * @param Exception $e an exception 
     * 
     * @return void
     */
    public static function eventCoreException($e)
    {
        MonologLogger::getInstance()->addException($e);
    }

    /**
     * eventCoreIncludeCommonStart
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreIncludeCommonStart($args)
    {
        XoopsLoad::addMap(array('monologlogger' => dirname(dirname(__FILE__)) . '/class/monologlogger.php'));

        $cache_key = 'module_monolog_configs';
        self::$configs=array();
        self::$configs['monolog_enable'] = false;
        self::$configs['monolog_default_configs']=true;
        if ($monolog_configs = Xoops_Cache::read('module_logger_plugin')) {
            self::$configs = unserialize($monolog_configs);
        }
        $logger = MonologLogger::getInstance();

        if (self::$configs['monolog_enable']) {
            $logger->enable(); //until we get a db connection debug is enabled
        } else {
            self::$configs['include_blocks'] = false;
            self::$configs['include_deprecated'] = false;
            self::$configs['include_extra'] = false;
            self::$configs['include_queries'] = false;
            self::$configs['include_timers'] = false;
        }
        $logger->setConfigs(self::$configs);
        $logger->startTime();
        $logger->startTime('XOOPS Boot');
    }

    /**
     * core.database.noconn
     *
     * @param array $args arguments
     * 
     * @return void
     */
    public static function eventCoreDatabaseNoconn($args)
    {
        if (class_exists('MonologLogger')) {
            /* @var $db XoopsConnection */
            $db = $args[0];
            MonologLogger::getInstance()->log(LogLevel::ALERT, $db->error(), array('errno' => $db->errno()));
        }
    }

    /**
     * eventCoreDatabaseNodb
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreDatabaseNodb($args)
    {
        if (class_exists('MonologLogger')) {
            /* @var $db XoopsConnection */
            $db = $args[0];
            MonologLogger::getInstance()->log(LogLevel::ALERT, $db->error(), array('errno' => $db->errno()));
        }
    }

    /**
     * eventCoreDatabaseQueryStart
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreDatabaseQueryStart($args)
    {
        self::$query_start_time = microtime(true);
    }

    /**
     * eventCoreDatabaseQueryEnd
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreDatabaseQueryEnd($args)
    {
        self::$query_stop_time = microtime(true);
    }

    /**
     * eventCoreDatabaseQuerySuccess
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreDatabaseQuerySuccess($args)
    {
        $sql = $args[0];
        $context = array(
            'channel'=>'Queries',
            'error'=>null,
            'errno'=>null,
            'query_time'=> self::$query_stop_time - self::$query_start_time
        );

        MonologLogger::getInstance()->log(LogLevel::INFO, $sql, $context);
    }

    /**
     * eventCoreDatabaseQueryFailure
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreDatabaseQueryFailure($args)
    {
        /* @var $db XoopsConnection */
        $sql = $args[0];
        $db = $args[1];
        if (method_exists($db, 'error')) {
            $error = $db->error();
            $errno = $db->errno();
        } else {
            $error = $db->errorInfo();
            $errno = $db->errorCode();
        }
        $context = array(
            'channel'=>'Queries',
            'error'=>$error,
            'errno'=>$errno,
            'query_time'=> self::$query_stop_time - self::$query_start_time
        );

        MonologLogger::getInstance()->log(LogLevel::ERROR, $sql, $context);
    }

    /**
     * eventCoreIncludeCommonConfigsSuccess
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreIncludeCommonConfigsSuccess($args)
    {
        $xoops = Xoops::getInstance();
        $xoops->loadLocale();
        $xoops->loadLanguage('main', 'monolog');
    }

    /**
     * eventCoreIncludeCommonAuthSuccess
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreIncludeCommonAuthSuccess($args)
    {
        $xoops = Xoops::getInstance();
        $logger = MonologLogger::getInstance();
        $configs = self::getConfigs();
        $logger->setConfigs($configs);
        if ($configs['monolog_enable']) {
            $logger->enable();
        } else {
            $logger->disable();
        }
    }

    /**
     * eventCoreIncludeCommonEnd
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreIncludeCommonEnd($args)
    {
        XoopsLoad::addMap(array('monologlogger' => dirname(dirname(__FILE__)) . '/class/monologlogger.php'));

        $logger = MonologLogger::getInstance();
        $logger->stopTime('XOOPS Boot');
        $logger->startTime('Module init');
    }

    /**
     * eventCoreTemplateConstructStart
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreTemplateConstructStart($args)
    {
        /*
        $tpl = $args[0];

        $configs = self::getConfigs();
        if ($configs['debugbar_enable']) {
            $tpl->debugging_ctrl = 'URL';
        }
        if ($configs['debug_smarty_enable']) {
                $tpl->debugging = true;
        }
        */
    }

    /**
     * eventCoreThemeRenderStart
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreThemeRenderStart($args)
    {
        MonologLogger::getInstance()->startTime('Page rendering');
    }

    /**
     * eventCoreThemeRenderEnd
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreThemeRenderEnd($args)
    {
        MonologLogger::getInstance()->stopTime('Page rendering');
    }

    /**
     * eventCoreThemeCheckcacheSuccess
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreThemeCheckcacheSuccess($args)
    {
        $template = $args[0];
        $theme = $args[1];
        MonologLogger::getInstance()->addExtra(
            $template,
            sprintf('Cached (regenerates every %d seconds)', $theme->contentCacheLifetime)
        );
    }

    /**
     * eventCoreThemeblocksBuildblockStart
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreThemeblocksBuildblockStart($args)
    {
        /* @var $block XoopsBlock */
        $block = $args[0];
        $isCached= $args[1];
        $context = array('channel'=>'Blocks', 'cached'=>$isCached, 'cachetime'=>$block->getVar('bcachetime'));
        MonologLogger::getInstance()->log(LogLevel::INFO, $block->getVar('name'), $context);

    }

    /**
     * eventCoreDeprecated
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreDeprecated($args)
    {
        $message = $args[0];
        MonologLogger::getInstance()->log(LogLevel::WARNING, $message, array('channel'=>'Deprecated'));
    }

    /**
     * eventCoreDisableerrorreporting
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreDisableerrorreporting($args)
    {
        MonologLogger::getInstance()->disable();
    }

    /**
     * eventCoreHeaderStart
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreHeaderStart($args)
    {
        $logger = MonologLogger::getInstance();
        $logger->stopTime('Module init');
        $logger->startTime('XOOPS output init');
    }

    /**
     * eventCoreHeaderEnd
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreHeaderEnd($args)
    {
        $logger = MonologLogger::getInstance();
        $logger->stopTime('XOOPS output init');
        $logger->startTime('Module display');
    }

    /**
     * eventCoreFooterStart
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreFooterStart($args)
    {
        $logger = MonologLogger::getInstance();
        $logger->stopTime('Module display');
        $logger->addExtra(
            _MD_MONOLOG_MEMORY,
            sprintf(
                _MD_MONOLOG_MEM_USAGE,
                (float) memory_get_usage(true)/1000000,
                (float) memory_get_peak_usage(true)/1000000
            )
        );
        $logger->addExtra(
            _MD_MONOLOG_INCLUDED_FILES,
            sprintf(_MD_MONOLOG_FILES, count(get_included_files()))
        );
        $logger->stopTime();
    }

    /**
     * eventCoreFooterEnd
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreFooterEnd($args)
    {
        //$logger = MonologLogger::getInstance();

        //$logger->stopTime();
    }

    /**
     * eventCoreSecurityValidatetokenEnd
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreSecurityValidatetokenEnd($args)
    {
        $logger = MonologLogger::getInstance();
        $logs = $args[0];
        foreach ($logs as $log) {
            $context = array('channel'=>'Extra', 'name'=>$log[0]);
            $logger->log(LogLevel::INFO, $log[1], $context);
        }
    }

    /**
     * eventCoreModuleAddlog
     * 
     * @param mixed $args arguments supplied to triggerEvent
     * 
     * @return void
     */
    public static function eventCoreModuleAddlog($args)
    {
        $context = array('channel'=>'Extra', 'name'=>$args[0]);
        MonologLogger::getInstance()->log(LogLevel::DEBUG, $args[1], $context);

    }
}
