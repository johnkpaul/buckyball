<?php

/**
* Registry of modules, their manifests and dependencies
*/
class BModuleRegistry extends BClass
{
    /**
    * Relevant environment variables cache
    *
    * @var array
    */
    protected $_env = array();

    /**
    * Module information collected from manifests
    *
    * @var array
    */
    protected $_modules = array();

    /**
    * Manifest files cache
    *
    * @var array
    */
    protected $_manifestCache = array();

    /**
    * Current module name, not BNULL when:
    * - In module bootstrap
    * - In observer
    * - In view
    *
    * @var string
    */
    protected static $_currentModuleName = BNULL;

    /**
    * Current module stack trace
    *
    * @var array
    */
    protected static $_currentModuleStack = array();

    public function __construct()
    {
        BPubSub::i()->on('BFrontController::dispatch.before', array($this, 'onBeforeDispatch'));
    }

    /**
    * Shortcut to help with IDE autocompletion
    *
    * @return BModuleRegistry
    */
    public static function i($new=false, array $args=array())
    {
        return BClassRegistry::i()->instance(__CLASS__, $args, !$new);
    }

    /**
    * Register or return module object
    *
    * @param string $modName
    * @param array|callback $params if not supplied, return module by name
    * @return BModule
    */
    public function module($modName, $params=BNULL)
    {
        if (BNULL===$params) {
            return isset($this->_modules[$modName]) ? $this->_modules[$modName] : null;
        }

        if (is_callable($params)) {
            $params = array('bootstrap'=>array('callback'=>$params));
        } else {
            $params = (array)$params;
        }
        $params['name'] = $modName;
        if (!empty($this->_modules[$modName])) {
            $mod = $this->_modules[$modName];
            if (empty($params['update'])) {
                $rootDir = $mod->root_dir;
                $file = $mod->bootstrap['file'];
                throw new BException(BApp::t('Module is already registered: %s (%s)', array($modName, $rootDir.'/'.$file)));
            } else {
                BDebug::debug('MODULE UPDATE: '.$modName);
                foreach ($params as $k=>$v) {
                    if (is_array($mod->$k)) {
                        $mod->$k = array_merge_recursive($mod->$k, $v);
                    } else {
                        $mod->$k = $v;
                        //TODO: make more flexible without sacrificing performance
                        switch ($k) {
                        case 'url_prefix':
                            $mod->base_href = BApp::baseUrl().($v ? '/'.$v : '');
                            break;
                        }
                    }
                }
                return $this;
            }
        }
        if (($params = $this->_prepareModuleParams($params))) {
            $this->_modules[$modName] = BModule::i(true, $params);
        }
        return $this;
    }

    protected function _getManifestData(&$params)
    {
        if (empty($this->_env)) {
            $this->_env['doc_root'] = BRequest::i()->docRoot();
            $this->_env['http_host'] = BRequest::i()->httpHost();
            $basePath = BConfig::i()->get('web/base_path');
            $this->_env['base_href'] = '//'.$this->_env['http_host'].($basePath ? $basePath : $this->_env['doc_root']);
        }
        if (empty($params['manifest_file'])) {
            $bt = debug_backtrace();
            foreach ($bt as $i=>$t) {
                if ($t['method'] = 'module') {
                    $t1 = $bt[$i+2];
                    break;
                }
            }
            if (!empty($t1)) {
                $params['manifest_file'] = $t1['file'];
            }
        }
        $file = $params['manifest_file'];

        if (empty($this->_manifestCache[$file])) {
            $dir = dirname(realpath($file));
            $this->_manifestCache[$file] = array(
                'root_dir' => $dir,
                'base_src' => '//'.$this->_env['http_host'].str_replace($this->_env['doc_root'], '', $dir),
            );
        }
        return $this->_manifestCache[$file];
    }

    protected function _prepareModuleParams($params)
    {
        $modName = $params['name'];
        if (empty($params['bootstrap']['callback'])) {
            BDebug::warning('Missing bootstrap information, skipping module: %s', $modName);
            return false;
        }

        $m = $this->_getManifestData($params);
        if (empty($params['bootstrap']['file'])) {
            $params['bootstrap']['file'] = null;
        }
        if (empty($params['root_dir'])) {
            $params['root_dir'] = $m['root_dir'];
        }
        if (empty($params['url_prefix'])) {
            $params['url_prefix'] = '';
        }
        //TODO: optimize path calculations
        if (!BUtil::isPathAbsolute($params['root_dir'])) {
//echo "{$m['root_dir']}, {$params['root_dir']}\n";
            $params['root_dir'] = BUtil::normalizePath($m['root_dir'].'/'.$params['root_dir']);
        }
        if (empty($params['view_root_dir'])) {
            $params['view_root_dir'] = $params['root_dir'];
        }

        if (empty($params['base_src'])) {
            $params['base_src'] = rtrim($m['base_src'].str_replace($m['root_dir'], '', $params['root_dir']), '/');
            $params['base_src'] = BUtil::normalizePath($params['base_src']);
        }
        if (empty($params['base_href'])) {
            $params['base_href'] = $this->_env['base_href'];
            if (!empty($params['url_prefix'])) {
                $params['base_href'] .= '/'.$params['url_prefix'];
            }
        }

        $modConfig = BConfig::i()->get('modules/'.$modName);
        if (!isset($params['run_level'])) {
            $params['run_level'] = isset($modConfig['run_level']) ? $modConfig['run_level'] : BModule::ONDEMAND;
        }
        if (!isset($params['run_status'])) {
            $params['run_status'] = BModule::IDLE;
        }

#echo "<hr>"; print_r($params);
        return $params;
    }

    /**
    * Scan for module manifests in a folder
    *
    * Scan can be performed multiple times on different locations, order doesn't matter for dependencies
    * Wildcards are accepted.
    *
    * @see BApp::i()->load() for examples
    *
    * @param string $source
    */
    public function scan($source)
    {
        // if $source does not end with .json, assume it is a folder
        if (substr($source, -5)!=='.json' && substr($source, -4)!=='.php') {
            $source .= '/manifest.*';
        }
        $manifests = glob($source, GLOB_BRACE);
        BDebug::debug('MODULE.SCAN '.$source.': '.print_r($manifests, 1));
        if (!$manifests) {
            return $this;
        }
        foreach ($manifests as $file) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            switch ($ext) {
                case 'php':
                    $manifest = include($file);
                    break;
                case 'json':
                    $json = file_get_contents($file);
                    $manifest = BUtil::fromJson($json);
                    break;
                default:
                    BDebug::error(BApp::t("Unknown manifest file format: %s", $file));
            }
            if (empty($manifest['modules'])) {
                BDebug::error(BApp::t("Could not read manifest file: %s", $file));
            }
            foreach ($manifest['modules'] as $modName=>$params) {
                $params['manifest_file'] = $file;
                $this->module($modName, $params);
            }
        }
        return $this;
    }

    /**
    * Check module dependencies
    *
    * @return BModuleRegistry
    */
    public function checkDepends()
    {
        // validate required modules
        foreach ((array)BConfig::i()->get('modules') as $modName=>$modConfig) {
            if (!empty($modConfig['run_level'])) {
                if ($modConfig['run_level']===BModule::REQUIRED && empty($this->_modules[$modName])) {
                    BDebug::error('Module is required but not found: '.$modName);
                }
                $this->_modules[$modName]->run_level = $modConfig['run_level'];
            }
        }
        // scan for dependencies
        foreach ($this->_modules as $modName=>$mod) {
            // normalize dependencies format
            foreach ($mod->depends as &$dep) {
                if (is_string($dep)) {
                    $dep = array('name'=>$dep);
                }
            }
            unset($dep);
            // is currently iterated module required?
            if ($mod->run_level===BModule::REQUIRED) {
                $mod->run_status = BModule::PENDING;
            }
            $depsMet = true;
            // iterate over module dependencies
            if (!empty($mod->depends)) {
                foreach ($mod->depends as &$dep) {
                    $depMod = !empty($this->_modules[$dep['name']]) ? $this->_modules[$dep['name']] : false;
                    // is the module missing
                    if (!$depMod) {
                        $dep['error'] = array('type'=>'missing');
                        $depsMet = false;
                        continue;
                    // is the module disabled
                    } elseif ($depMod->run_level===BModule::DISABLED) {
                        $dep['error'] = array('type'=>'disabled');
                        $depsMet = false;
                        continue;
                    // is the module version not valid
                    } elseif (!empty($dep['version'])) {
                        $depVer = $dep['version'];
                        if (!empty($depVer['from']) && version_compare($depMod->version, $depVer['from'], '<')
                            || !empty($depVer['to']) && version_compare($depMod->version, $depVer['to'], '>')
                            || !empty($depVer['exclude']) && in_array($depMod->version, (array)$depVer['exclude'])
                        ) {
                            $dep['error'] = array('type'=>'version');
                            $depsMet = false;
                            continue;
                        }
                    }
                    // for ordering by dependency
                    $mod->parents[] = $dep['name'];
                    $depMod->children[] = $modName;
                    if ($mod->run_status===BModule::PENDING) {
                        $depMod->run_status = BModule::PENDING;
                    }
                    // add dependency information to bootstrap config
                    //if (!empty($reqModules)) {
                    //    BConfig::i()->add(array('bootstrap'=>array('depends'=>array($dep['name']))));
                    //}

                }
            }
            if ($depsMet && $mod->run_level===BModule::REQUESTED) {
                $mod->run_status = BModule::PENDING;
            }
            unset($dep);
        }
        // propagate dependencies into subdependent modules
        foreach ($this->_modules as $modName=>$mod) {
            foreach ($mod->depends as &$dep) {
                if (!empty($dep['error'])) {
                    if (empty($dep['error']['propagated'])) {
                        $this->propagateDepends($modName, $dep);
                    }
                } else {
                    if ($mod->run_status===BModule::PENDING) {
                        $this->_modules[$dep['name']]->run_status = BModule::PENDING;
                    }
                }
            }
            unset($dep);
        }
        return $this;
    }

    /**
    * Propagate dependencies into submodules recursively
    *
    * @param string $modName
    * @param BModule $dep
    * @return BModuleRegistry
    */
    public function propagateDepends($modName, &$dep)
    {
        if (empty($this->_modules[$modName])) {
            return $this;
        }
        $mod = $this->_modules[$modName];
        $mod->run_status = BModule::ERROR;
        $mod->error = 'depends';
        $mod->action = !empty($dep['action']) ? $dep['action'] : 'error';
        $dep['error']['propagated'] = true;
        if (!empty($mod->depends)) {
            foreach ($mod->depends as &$subDep) {
                if (empty($subDep['error'])) {
                    $subDep['error'] = array('type'=>'parent');
                    $this->propagateDepends($dep['name'], $subDep);
                }
            }
            unset($subDep);
        }
        return $this;
    }

    /**
    * Perform topological sorting for module dependencies
    *
    * @return BModuleRegistry
    */
    public function sortDepends()
    {
        $modules = $this->_modules;
        // get modules without dependencies
        $rootModules = array();
        foreach ($modules as $modName=>$mod) {
            if (empty($mod->parents)) {
                $rootModules[] = $mod;
            }
        }
#echo "<pre>"; print_r($this->_modules); echo "</pre>";
#echo "<pre>"; print_r($rootModules); echo "</pre>";
        // begin algorithm
        $sorted = array();
        while ($modules) {
            // check for circular reference
            if (!$rootModules) return false;
            // remove this node from root modules and add it to the output
            $n = array_pop($rootModules);
            $sorted[$n->name] = $n;
            // for each of its children: queue the new node, finally remove the original
            for ($i = count($n->children)-1; $i>=0; $i--) {
                // get child module
                $childModule = $modules[$n->children[$i]];
                // remove child modules from parent
                unset($n->children[$i]);
                // remove parent from child module
                unset($childModule->parents[array_search($n->name, $childModule->parents)]);
                // check if this child has other parents. if not, add it to the root modules list
                if (!$childModule->parents) array_push($rootModules, $childModule);
            }
            // remove processed module from list
            unset($modules[$n->name]);
        }
        $this->_modules = $sorted;

        return $this;
    }

    /**
    * Run modules bootstrap callbacks
    *
    * @return BModuleRegistry
    */
    public function bootstrap()
    {
        $this->checkDepends();
        $this->sortDepends();

        foreach ($this->_modules as $mod) {
            if ($mod->run_status!==BModule::PENDING) {
                continue;
            }
            $this->pushModule($mod->name);
            if (!empty($mod->bootstrap['file'])) {
                $includeFile = BUtil::normalizePath($mod->root_dir.'/'.$mod->bootstrap['file']);
                BDebug::debug('MODULE.BOOTSTRAP '.$includeFile);
                require ($includeFile);
            }
            $start = BDebug::debug(BApp::t('Start bootstrap for %s', array($mod->name)));
            call_user_func($mod->bootstrap['callback']);
            #$mod->run_status = BModule::LOADED;
            BDebug::profile($start);
            BDebug::debug(BApp::t('End bootstrap for %s', array($mod->name)));
            $this->popModule();
        }
        return $this;
    }

    /**
    * Set or return current module context
    *
    * If $name is specified, set current module, otherwise retrieve one
    *
    * Used in context of bootstrap, event observer, view
    *
    * @param string|empty $name
    * @return BModule|BModuleRegistry
    */
    public function currentModule($name=BNULL)
    {
        if (BNULL===$name) {
#echo '<hr><pre>'; debug_print_backtrace(); echo static::$_currentModuleName.' * '; print_r($this->module(static::$_currentModuleName)); #echo '</pre>';
            $name = static::currentModuleName();
            return $name ? $this->module($name) : false;
        }
        static::$_currentModuleName = $name;
        return $this;
    }

    public function pushModule($name)
    {
        array_push(self::$_currentModuleStack, $name);
        return $this;
    }

    public function popModule()
    {
        array_pop(self::$_currentModuleStack);
        return $this;
    }

    static public function currentModuleName()
    {
        if (!empty(self::$_currentModuleStack)) {
            return self::$_currentModuleStack[sizeof(self::$_currentModuleStack)-1];
        }
        return static::$_currentModuleName;
    }

    public function onBeforeDispatch()
    {
        $front = BFrontController::i();
        foreach ($this->_modules as $module) {
            if (($prefix = $module->url_prefix)) {
                $front->redirect('GET /'.$prefix, $prefix.'/');
            }
        }
    }

    public function debug()
    {
        return $this->_modules;
    }
}

/**
* Module object to store module manifest and other properties
*/
class BModule extends BClass
{
    public $name;
    public $run_level;
    public $run_status;
    public $bootstrap;
    public $version;
    public $db_connection_name;
    public $root_dir;
    public $view_root_dir;
    public $base_src;
    public $base_href;
    public $depends = array();
    public $parents = array();
    public $children = array();
    public $update;

    const
        // run_level
        DISABLED  = 'DISABLED',
        ONDEMAND  = 'ONDEMAND',
        REQUESTED = 'REQUESTED',
        REQUIRED  = 'REQUIRED',

        // run_status
        IDLE    = 'IDLE',
        PENDING = 'PENDING',
        LOADED  = 'LOADED',
        ERROR   = 'ERROR'
    ;

    /**
    * Shortcut to help with IDE autocompletion
    *
    * @return BModule
    */
    public static function i($new=false, array $args=array())
    {
        return BClassRegistry::i()->instance(__CLASS__, $args, !$new);
    }

    /**
    * Assign arguments as module parameters
    *
    * @param array $args
    * @return BModule
    */
    public function __construct(array $args)
    {
        foreach ($args as $k=>$v) {
            $this->$k = $v;
        }
    }

    /**
    * Register module specific autoload callback
    *
    * @param mixed $rootDir
    * @param mixed $callback
    */
    public function autoload($rootDir='', $callback=null)
    {
        if (!$rootDir) {
            $rootDir = $this->root_dir;
        } elseif (!BUtil::isPathAbsolute($rootDir)) {
            $rootDir = $this->root_dir.'/'.$rootDir;
        }
        BClassAutoload::i(true, array(
            'module_name' => $this->name,
            'root_dir' => rtrim($rootDir, '/'),
            'filename_cb' => $callback,
        ));
        return $this;
    }

    /**
    * Module specific base URL
    *
    * @return string
    */
    public function baseSrc()
    {
        return $this->base_src;
    }

    public function baseHref()
    {
        return $this->base_href;
    }

    public function runLevel($level=BNULL, $updateConfig=false)
    {
        if (BNULL===$level) {
            return $this->run_level;
        }
        $this->run_level = $level;
        if ($updateConfig) {
            BConfig::i()->add(array('modules'=>array($this->name=>array('run_level'=>$level))));
        }
        return $this;
    }

    public function runStatus($status=BNULL)
    {
        if (BNULL===$status) {
            return $this->run_status;
        }
        $this->run_status = $status;
        return $this;
    }

    public function _($string, $args=array())
    {
        $tr = dgettext($this->name, $string);
        if ($args) {
            $tr = BUtil::sprintfn($tr, $args);
        }
        return $tr;
    }

    public function set($key, $value)
    {
        if (is_array($key)) {
            foreach ($key as $k=>$v) {
                $this->$k = $v;
            }
            return $this;
        }
        $this->$key = $value;
        return $this;
    }
}


class BDbModule extends BModel
{
    protected static $_table = 'buckyball_module';

    public static function init()
    {
        $table = BDb::t(static::$_table);
        BDb::connect();
        if (BDebug::is('debug,development') && !BDb::ddlTableExists($table)) {
            BDb::run("
CREATE TABLE {$table} (
id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
module_name VARCHAR(100) NOT NULL,
schema_version VARCHAR(20),
data_version varchar(20),
last_upgrade DATETIME,
last_status varchar(20),
UNIQUE (module_name)
) ENGINE=INNODB;
            ");
        }
        BDbModuleConfig::init();
    }
}

class BDbModuleConfig extends BModel
{
    protected static $_table = 'buckyball_module_config';

    public static function init()
    {
        $table = BDb::t(static::$_table);
        $modTable = BDb::t('buckyball_module');
        if (!BDb::ddlTableExists($table)) {
            BDb::run("
CREATE TABLE {$table} (
id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
module_id INT UNSIGNED NOT NULL,
`key` VARCHAR(100),
`value` TEXT,
UNIQUE (module_id, `key`),
CONSTRAINT `FK_{$modTable}` FOREIGN KEY (`module_id`) REFERENCES `{$modTable}` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=INNODB;
            ");
        }
    }
}
