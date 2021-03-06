<?php

/**
* Sometimes NULL is a value too.
*/
define('BNULL', '!@BNULL#$');

/**
* Base class that allows easy singleton/instance creation and method overrides (decorator)
*
* This class is used for all BuckyBall framework base classes
*
* @see BClassRegistry for invokation
*/
class BClass
{
    /**
    * Original class to be used as event prefix to remain constant in overridden classes
    *
    * Usage:
    *
    * class Some_Class extends BClass
    * {
    *    static protected $_origClass = __CLASS__;
    * }
    *
    * @var string
    */
    static protected $_origClass;

    /**
    * Retrieve original class name
    *
    * @return string
    */
    public static function origClass()
    {
        return static::$_origClass;
    }

    /**
    * Fallback singleton/instance factory
    *
    * @param bool|object $new if true returns a new instance, otherwise singleton
    *                         if object, returns singleton of the same class
    * @param array $args
    * @return BClass
    */
    public static function i($new=false, array $args=array())
    {
        if (is_object($new)) {
            $class = get_class($new);
            $new = false;
        } else {
            $class = get_called_class();
        }
        return BClassRegistry::i()->instance($class, $args, !$new);
    }
}

/**
* Main BuckyBall Framework class
*
*/
class BApp extends BClass
{
    /**
    * Registry of supported features
    *
    * @var array
    */
    protected static $_compat = array();

    /**
    * Global app vars registry
    *
    * @var array
    */
    protected $_vars = array();

    /**
    * Verify if a feature is currently supported. Features:
    *
    * - PHP5.3
    *
    * @param mixed $feature
    * @return boolean
    */
    public static function compat($feature)
    {
        if (!empty(static::$_compat[$feature])) {
            return static::$_compat[$feature];
        }
        switch ($feature) {
        case 'PHP5.3':
            $compat = version_compare(phpversion(), '5.3.0', '>=');
            break;

        default:
            BDebug::error(BApp::t('Unknown feature: %s', $feature));
        }
        static::$_compat[$feature] = $compat;
        return $compat;
    }

    /**
    * Shortcut to help with IDE autocompletion
    *
    * @todo Run multiple applications within the same script
    *       This requires to decide which registries should be app specific
    *
    * @return BApp
    */
    public static function i($new=false, array $args=array())
    {
        return BClassRegistry::i()->instance(__CLASS__, $args, !$new);
    }

    /**
    * Application contructor
    *
    * Starts debugging session for timing
    *
    * @return BApp
    */
    public function __construct()
    {
        BDebug::i();
    }

    /**
    * Shortcut to add configuration, used mostly from bootstrap index file
    *
    * @param array|string $config If string will load configuration from file
    */
    public function config($config)
    {
        if (is_array($config)) {
            BConfig::i()->add($config);
        } elseif (is_string($config) && is_file($config)) {
            BConfig::i()->addFile($config);
        } else {
            BDebug::error("Invalid configuration argument");
        }
        return $this;
    }

    /**
    * Shortcut to scan folders for module manifest files
    *
    * @param string|array $folders Relative path(s) to manifests. May include wildcards.
    */
    public function load($folders='.')
    {
#echo "<pre>"; print_r(debug_backtrace()); echo "</pre>";
        if (is_string($folders)) {
            $folders = explode(',', $folders);
        }
        $modules = BModuleRegistry::i();
        foreach ($folders as $folder) {
            $modules->scan($folder);
        }
        return $this;
    }

    /**
    * The last method to be ran in bootstrap index file.
    *
    * Performs necessary initializations and dispatches requested action.
    *
    */
    public function run()
    {
        // load session variables
        BSession::i()->open();

        // bootstrap modules
        BModuleRegistry::i()->bootstrap();

        // run module migration scripts if neccessary
        BDb::i()->runMigrationScripts();

        // dispatch requested controller action
        BFrontController::i()->dispatch();

        // If session variables were changed, update session
        BSession::i()->close();

        return $this;
    }

    /**
    * Shortcut for translation
    *
    * @param string $string Text to be translated
    * @param string|array $args Arguments for the text
    * @return string
    */
    public static function t($string, $args=array())
    {
        return Blocale::i()->translate($string, $args);
    }

    /**
    * Shortcut to get a current module or module by name
    *
    * @param string $modName
    * @return BModule
    */
    public static function m($modName=null)
    {
        $reg = BModuleRegistry::i();
        return is_null($modName) ? $reg->currentModule() : $reg->module($modName);
    }

    /**
    * Shortcut to generate URL of module base and custom path
    *
    * @param string $modName
    * @param string $url
    * @param string $method
    * @return string
    */
    public static function url($modName, $url='', $method='baseHref')
    {
        $m = BApp::m($modName);
        if (!$m) {
            BDebug::error('Invalid module: '.$modName);
            return '';
        }
        return $m->$method() . $url;
    }

    /**
    * Shortcut for base URL to use in views and controllers
    *
    * @return string
    */
    public static function baseUrl($full=true)
    {
        static $baseUrl = array();
        if (empty($baseUrl[(int)$full])) {
            /** @var BRequest */
            $r = BRequest::i();
            $baseUrl[(int)$full] = $full ? $r->baseUrl() : $r->webRoot();
        }
        return $baseUrl[(int)$full];
    }

    public function set($key, $val)
    {
        $this->_vars[$key] = $val;
        return $this;
    }

    public function get($key)
    {
        return isset($this->_vars[$key]) ? $this->_vars[$key] : null;
    }
}


/**
* Bucky specialized exception
*/
class BException extends Exception
{
    /**
    * Logs exceptions
    *
    * @param string $message
    * @param int $code
    * @return BException
    */
    public function __construct($message="", $code=0)
    {
        parent::__construct($message, $code);
        //BApp::log($message, array(), array('event'=>'exception', 'code'=>$code, 'file'=>$this->getFile(), 'line'=>$this->getLine()));
    }
}

/**
* Global configuration storage class
*/
class BConfig extends BClass
{
    /**
    * Configuration storage
    *
    * @var array
    */
    protected $_config = array();

    /**
    * Configuration that will be saved on request
    *
    * @var array
    */
    protected $_configToSave = array();

    /**
    * Enable double data storage for saving?
    *
    * @var boolean
    */
    protected $_enableSaving = true;

    /**
    * Shortcut to help with IDE autocompletion
    *
    * @return BConfig
    */
    public static function i($new=false, array $args=array())
    {
        return BClassRegistry::i()->instance(__CLASS__, $args, !$new);
    }

    /**
    * Add configuration fragment to global tree
    *
    * @param array $config
    * @param boolean $toSave whether this config should be saved in file
    * @return BConfig
    */
    public function add(array $config, $toSave=false)
    {
        $this->_config = BUtil::arrayMerge($this->_config, $config);
        if ($this->_enableSaving && $toSave) {
            $this->_configToSave = BUtil::arrayMerge($this->_configToSave, $config);
        }
        return $this;
    }

    /**
    * Add configuration from file, stored as JSON
    *
    * @param string $filename
    */
    public function addFile($filename, $toSave=false)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!BUtil::isPathAbsolute($filename) && ($dir = $this->get('config_dir'))) {
            $filename = $dir.'/'.$filename;
        }
        if (!is_readable($filename)) {
            BDebug::error(BApp::t('Invalid configuration file name: %s', $filename));
        }
        switch ($ext) {
        case 'php':
            $config = include($filename);
            break;

        case 'json':
            $config = BUtil::fromJson(file_get_contents($filename));
            break;
        }
        if (!$config) {
            BDebug::error(BApp::t('Invalid configuration contents: %s', $filename));
        }
        $this->add($config, $toSave);
        return $this;
    }

    /**
    * Set configuration data in $path location
    *
    * @param string $path slash separated path to the config node
    * @param mixed $value scalar or array value
    * @param boolean $merge merge new value to old?
    */
    public function set($path, $value, $merge=false, $toSave=false)
    {
        if (is_string($toSave) && $toSave==='_configToSave') { // limit?
            $root =& $this->$toSave;
        } else {
            $root =& $this->_config;
        }
        foreach (explode('/', $path) as $key) {
            $root =& $root[$key];
        }
        if ($merge) {
            $root = (array)$root;
            $value = (array)$root;
            $root = BUtil::arrayMerge($root, $value);
        } else {
            $root = $value;
        }
        if ($this->_enableSaving && true===$toSave) {
            $this->set($path, $value, $merge, '_configToSave');
        }
        return $this;
    }

    /**
    * Get configuration data using path
    *
    * Ex: BConfig::i()->get('some/deep/config')
    *
    * @param string $path
    */
    public function get($path=null, $toSave=false)
    {
        $root = $toSave ? $this->_configToSave : $this->_config;
        if (is_null($path)) {
            return $root;
        }
        foreach (explode('/', $path) as $key) {
            if (!isset($root[$key])) {
                return null;
            }
            $root = $root[$key];
        }
        return $root;
    }

    public function writeFile($filename, $config=null, $format='php')
    {
        if (is_null($config)) {
            $config = $this->_configToSave;
        }
        switch ($format) {
            case 'php':
                $contents = "<?php return ".var_export($config, 1).';';
/*
                // Additional check for allowed tokens
                $tokens = token_get_all($contents);
                $t1 = array();
                $allowed = array(T_OPEN_TAG=>1, T_RETURN=>1, T_WHITESPACE=>1,
                    T_ARRAY=>1, T_CONSTANT_ENCAPSED_STRING=>1, T_DOUBLE_ARROW=>1,
                    T_DNUMBER=>1, T_LNUMBER=>1, T_STRING=>1, '('=>1, ','=>1, ')'=>1);
                $denied = array();
                foreach ($tokens as $t) {
                    $t1[token_name($t[0])] = 1;
                    $t1[$t[0]] = 1;
                    if (!isset($allowed[$t[0]])) {
                        $denied[] = token_name($t[0]).': '.$t[1]
                            .(!empty($t[2]) ? ' ('.$t[2].')':'');
                    }
                }
                if ($denied) {
                    throw new BException('Invalid tokens in configuration found');
                }
*/
                // a small formatting enhancement
                $contents = preg_replace('#=> \n\s+#', '=> ', $contents);
                break;

            case 'json':
                $contents = BUtil::i()->toJson($config);
                break;
        }

        if (!BUtil::isPathAbsolute($filename)) {
            $filename = BConfig::i()->get('config_dir').'/'.$filename;
        }
        // Write contents
        if (!file_put_contents($filename, $contents, LOCK_EX)) {
            BDebug::error('Error writing configuration file: '.$filename);
        }
    }
}

/**
* Registry of classes, class overrides and method overrides
*/
class BClassRegistry extends BClass
{
    /**
    * Self instance for singleton
    *
    * @var BClassRegistry
    */
    static protected $_instance;

    /**
    * Class overrides
    *
    * @var array
    */
    protected $_classes = array();

    /**
    * Method overrides and augmentations
    *
    * @var array
    */
    protected $_methods = array();

    /**
    * Property setter/getter overrides and augmentations
    *
    * @var array
    */
    protected $_properties = array();

    /**
    * Registry of singletons
    *
    * @var array
    */
    protected $_singletons = array();

    /**
    * Shortcut to help with IDE autocompletion
    *
    * @param bool $new
    * @param array $args
    * @param bool $forceRefresh force the recreation of singleton
    * @return BClassRegistry
    */
    public static function i($new=false, array $args=array(), $forceRefresh=false)
    {
        if (!static::$_instance) {
            static::$_instance = new BClassRegistry;
        }
        if (!$new && !$forceRefresh) {
            return static::$_instance;
        }
        $class = get_called_class();
        return static::$_instance->instance($class, $args, !$new);
    }

    /**
    * Override a class
    *
    * Usage: BClassRegistry::i()->overrideClass('BaseClass', 'MyClass');
    *
    * Remembering the module that overrode the class for debugging
    *
    * @todo figure out how to update events on class override
    *
    * @param string $class Class to be overridden
    * @param string $newClass New class
    * @param bool $replaceSingleton If there's already singleton of overridden class, replace with new one
    * @return BClassRegistry
    */
    public function overrideClass($class, $newClass, $replaceSingleton=false)
    {
        $this->_classes[$class] = array(
            'class_name' => $newClass,
            'module_name' => BModuleRegistry::currentModuleName(),
        );
        if ($replaceSingleton && !empty($this->_singletons[$class]) && get_class($this->_singletons[$class])!==$newClass) {
            $this->_singletons[$class] = $this->instance($newClass);
        }
        return $this;
    }

    /**
    * Dynamically override a class method (decorator pattern)
    *
    * Already existing instances of the class will not be affected.
    *
    * Usage: BClassRegistry::i()->overrideMethod('BaseClass', 'someMethod', array('MyClass', 'someMethod'));
    *
    * Overridden class should be called one of the following ways:
    * - BClassRegistry::i()->instance('BaseClass')
    * - BaseClass:i() -- if it extends BClass or has the shortcut defined
    *
    * Callback method example (original method had 2 arguments):
    *
    * class MyClass {
    *   static public function someMethod($origObject, $arg1, $arg2)
    *   {
    *       // do some custom stuff before call to original method here
    *
    *       $origObject->someMethod($arg1, $arg2);
    *
    *       // do some custom stuff after call to original method here
    *
    *       return $origObject;
    *   }
    * }
    *
    * Remembering the module that overrode the method for debugging
    *
    * @todo decide whether static overrides are needed
    *
    * @param string $class Class to be overridden
    * @param string $method Method to be overridden
    * @param mixed $callback Callback to invoke on method call
    * @param bool $static Whether the static method call should be overridden
    * @return BClassRegistry
    */
    public function overrideMethod($class, $method, $callback, $static=false)
    {
        $this->_methods[$class][$static ? 1 : 0][$method]['override'] = array(
            'module_name' => BModuleRegistry::currentModuleName(),
            'callback' => $callback,
        );
        return $this;
    }

    /**
    * Dynamically augment class method result
    *
    * Allows to change result of a method for every invokation.
    * Syntax similar to overrideMethod()
    *
    * Callback method example (original method had 2 arguments):
    *
    * class MyClass {
    *   static public function someMethod($result, $origObject, $arg1, $arg2)
    *   {
    *       // augment $result of previous object method call
    *       $result['additional_info'] = 'foo';
    *
    *       return $result;
    *   }
    * }
    *
    * A difference between overrideModule and augmentModule is that
    * you can override only with one another method, but augment multiple times.
    *
    * If augmented multiple times, each consequetive callback will receive result
    * changed by previous callback.
    *
    * @param string $class
    * @param string $method
    * @param mixed $callback
    * @param boolean $static
    * @return BClassRegistry
    */
    public function augmentMethod($class, $method, $callback, $static=false)
    {
        $this->_methods[$class][$static ? 1 : 0][$method]['augment'][] = array(
            'module_name' => BModuleRegistry::currentModuleName(),
            'callback' => $callback,
        );
        return $this;
    }

    /**
    * Augment class property setter/getter
    *
    * BClassRegistry::i()->augmentProperty('SomeClass', 'foo', 'set', 'override', 'MyClass::newSetter');
    * BClassRegistry::i()->augmentProperty('SomeClass', 'foo', 'get', 'after', 'MyClass::newGetter');
    *
    * class MyClass {
    *   static public function newSetter($object, $property, $value)
    *   {
    *     $object->$property = myCustomProcess($value);
    *   }
    *
    *   static public function newGetter($object, $property, $prevResult)
    *   {
    *     return $prevResult+5;
    *   }
    * }
    *
    * @param string $class
    * @param string $property
    * @param string $op {set|get}
    * @param string $type {override|before|after} get_before is not implemented
    * @param mixed $callback
    * @return BClassRegistry
    */
    public function augmentProperty($class, $property, $op, $type, $callback)
    {
        if ($op!=='set' && $op!=='get') {
             BDebug::error(BApp::t('Invalid property augmentation operator: %s', $op));
        }
        if ($type!=='override' && $type!=='before' && $type!=='after') {
            BDebug::error(BApp::t('Invalid property augmentation type: %s', $type));
        }
        $entry = array(
            'module_name' => BModuleRegistry::currentModuleName(),
            'callback' => $callback,
        );
        if ($type==='override') {
            $this->_properties[$class][$property][$op.'_'.$type] = $entry;
        } else {
            $this->_properties[$class][$property][$op.'_'.$type][] = $entry;
        }
        return $this;
    }

    /**
    * Call overridden method
    *
    * @param object $origObject
    * @param string $method
    * @param mixed $args
    * @return mixed
    */
    public function callMethod($origObject, $method, array $args=array())
    {
        $class = get_class($origObject);

        if (!empty($this->_methods[$class][0][$method]['override'])) {
            $overridden = true;
            $callback = $this->_methods[$class][0][$method]['override']['callback'];
            array_unshift($args, $origObject);
        } else {
            $overridden = false;
            $callback = array($origObject, $method);
        }

        $result = call_user_func_array($callback, $args);

        if (!empty($this->_methods[$class][0][$method]['augment'])) {
            if (!$overridden) {
                array_unshift($args, $origObject);
            }
            array_unshift($args, $result);
            foreach ($this->_methods[$class][0][$method]['augment'] as $augment) {
                $result = call_user_func_array($augment['callback'], $args);
                $args[0] = $result;
            }
        }

        return $result;
    }

    /**
    * Call static overridden method
    *
    * Static class properties will not be available to callbacks
    *
    * @todo decide if this is needed
    *
    * @param string $class
    * @param string $method
    * @param array $args
    */
    public function callStaticMethod($class, $method, array $args=array())
    {
        $callback = !empty($this->_methods[$class][1][$method])
            ? $this->_methods[$class][1][$method]['override']['callback']
            : array($class, $method);

        $result = call_user_func_array($callback, $args);

        if (!empty($this->_methods[$class][1][$method]['augment'])) {
            array_unshift($args, $result);
            foreach ($this->_methods[$class][1][$method]['augment'] as $augment) {
                $result = call_user_func_array($augment['callback'], $args);
                $args[0] = $result;
            }
        }

        return $result;
    }

    /**
    * Call augmented property setter
    *
    * @param object $origObject
    * @param string $property
    * @param mixed $value
    */
    public function callSetter($origObject, $property, $value)
    {
        $class = get_class($origObject);

        if (!empty($this->_properties[$class][$method]['set_before'])) {
            foreach ($this->_properties[$class][$method]['set_before'] as $entry) {
                call_user_func($entry['callback'], $origObject, $property, $value);
            }
        }

        if (!empty($this->_properties[$class][$method]['set_override'])) {
            $callback = $this->_properties[$class][$method]['set_override']['callback'];
            call_user_func($callback, $origObject, $property, $value);
        } else {
            $origObject->$property = $value;
        }

        if (!empty($this->_properties[$class][$method]['set_after'])) {
            foreach ($this->_properties[$class][$method]['set_after'] as $entry) {
                call_user_func($entry['callback'], $origObject, $property, $value);
            }
        }
    }

    /**
    * Call augmented property getter
    *
    * @param object $origObject
    * @param string $property
    * @return mixed
    */
    public function callGetter($origObject, $property)
    {
        $class = get_class($origObject);

        // get_before does not make much sense, so is not implemented

        if (!empty($this->_properties[$class][$method]['get_override'])) {
            $callback = $this->_properties[$class][$method]['get_override']['callback'];
            $result = call_user_func($callback, $origObject, $property);
        } else {
            $result = $origObject->$property;
        }

        if (!empty($this->_properties[$class][$method]['get_after'])) {
            foreach ($this->_properties[$class][$method]['get_after'] as $entry) {
                $result = call_user_func($entry['callback'], $origObject, $property, $result);
            }
        }

        return $result;
    }

    /**
    * Get actual class name for potentially overridden class
    *
    * @param mixed $class
    * @return mixed
    */
    public function className($class)
    {
        return !empty($this->_classes[$class]) ? $this->_classes[$class]['class_name'] : $class;
    }

    /**
    * Get a new instance or a singleton of a class
    *
    * If at least one method of the class if overridden, returns decorator
    *
    * @param string $class
    * @param mixed $args
    * @param bool $singleton
    * @return object
    */
    public function instance($class, array $args=array(), $singleton=false)
    {
        // if singleton is requested and already exists, return the singleton
        if ($singleton && !empty($this->_singletons[$class])) {
            return $this->_singletons[$class];
        }

        // get original or overridden class instance
        $className = $this->className($class);
        if (!class_exists($className, true)) {
            BDebug::error(BApp::t('Invalid class name: %s', $className));
        }
        $instance = new $className($args);

        // if any methods are overridden or augmented, get decorator
        if (!empty($this->_methods[$class])) {
            $instance = $this->instance('BClassDecorator', array($instance));
        }

        // if singleton is requested, save
        if ($singleton) {
            $this->_singletons[$class] = $instance;
        }

        return $instance;
    }
}

/**
* Decorator class to allow easy method overrides
*
*/
class BClassDecorator
{
    /**
    * Contains the decorated (original) object
    *
    * @var object
    */
    protected $_decoratedComponent;

    /**
    * Decorator constructor, creates an instance of decorated class
    *
    * @param object|string $class
    * @return BClassDecorator
    */
    public function __construct($args)
    {
//echo '1: '; print_r($class);
        $class = array_shift($args);
        $this->_decoratedComponent = is_string($class) ? BClassRegistry::i()->instance($class, $args) : $class;
    }

    /**
    * Method override facility
    *
    * @param string $name
    * @param array $args
    * @return mixed Result of callback
    */
    public function __call($name, array $args)
    {
        return BClassRegistry::i()->callMethod($this->_decoratedComponent, $name, $args);
    }

    /**
    * Static method override facility
    *
    * @param mixed $name
    * @param mixed $args
    * @return mixed Result of callback
    */
    public static function __callStatic($name, array $args)
    {
        return BClassRegistry::i()->callStaticMethod(get_called_class(), $name, $args);
    }

    /**
    * Proxy to set decorated component property or a setter
    *
    * @param string $name
    * @param mixed $value
    */
    public function __set($name, $value)
    {
        //$this->_decoratedComponent->$name = $value;
        BClassRegistry::i()->callSetter($this->_decoratedComponent, $name, $value);
    }

    /**
    * Proxy to get decorated component property or a getter
    *
    * @param string $name
    * @return mixed
    */
    public function __get($name)
    {
        //return $this->_decoratedComponent->$name;
        return BClassRegistry::i()->callGetter($this->_decoratedComponent, $name);
    }

    /**
    * Proxy to unset decorated component property
    *
    * @param string $name
    */
    public function __unset($name)
    {
        unset($this->_decoratedComponent->$name);
    }

    /**
    * Proxy to check whether decorated component property is set
    *
    * @param string $name
    * @return boolean
    */
    public function __isset($name)
    {
        return isset($this->_decoratedComponent->$name);
    }

    /**
    * Proxy to return decorated component as string
    *
    * @return string
    */
    public function __toString()
    {
        return (string)$this->_decoratedComponent;
    }

    /**
    * Proxy method to serialize decorated component
    *
    */
    public function __sleep()
    {
        if (method_exists($this->_decoratedComponent, '__sleep')) {
            return $this->_decoratedComponent->__sleep();
        }
        return array();
    }

    /**
    * Proxy method to perform for decorated component on unserializing
    *
    */
    public function __wakeup()
    {
        if (method_exists($this->_decoratedComponent, '__wakeup')) {
            $this->_decoratedComponent->__wakeup();
        }
    }

    /**
    * Proxy method to invoke decorated component as a method if it is callable
    *
    */
    public function __invoke()
    {
        if (is_callable($this->_decoratedComponent)) {
            return $this->_decoratedComponent(func_get_args());
        }
        return null;
    }
}

class BClassAutoload extends BClass
{
    public $root_dir;
    public $filename_cb;
    public $module_name;

    public function __construct($params)
    {
        foreach ($params as $k=>$v) {
            $this->$k = $v;
        }
        spl_autoload_register(array($this, 'callback'), false);
        BDebug::debug('AUTOLOAD: '.print_r($this,1));
    }

    /**
    * Default autoload callback
    *
    * @param string $class
    */
    public function callback($class)
    {
        if ($this->filename_cb) {
            $file = call_user_func($this->filename_cb, $class);
        } else {
            $file = str_replace('_', '/', $class).'.php';
        }
        if ($file) {
            if ($file[0]!=='/' && $file[1]!==':') {
                $file = $this->root_dir.'/'.$file;
            }
            if (file_exists($file)) {
                include ($file);
            }
        }
    }
}

/**
* Events and observers registry
*/
class BPubSub extends BClass
{
    /**
    * Stores events and observers
    *
    * @todo figure out how to update events on class override
    *
    * @var array
    */
    protected $_events = array();

    /**
    * Shortcut to help with IDE autocompletion
    *
    * @return BPubSub
    */
    public static function i($new=false, array $args=array())
    {
        return BClassRegistry::i()->instance(__CLASS__, $args, !$new);
    }

    /**
    * Declare event with default arguments in bootstrap function
    *
    * This method is optional and currently not used.
    *
    * @param string|array $eventName accepts multiple events in form of non-associative array
    * @param array $args
    * @return BPubSub
    */
    public function event($eventName, $args=array())
    {
        if (is_array($eventName)) {
            foreach ($eventName as $event) {
                $this->event($event[0], !empty($event[1]) ? $event[1] : array());
            }
            return $this;
        }
        $this->_events[$eventName] = array(
            'observers' => array(),
            'args' => $args,
        );
        return $this;
    }

    /**
    * Declare observers in bootstrap function
    *
    * observe|watch|on|sub|subscribe ?
    *
    * @param string|array $eventName accepts multiple observers in form of non-associative array
    * @param mixed $callback
    * @param array $args
    * @return BPubSub
    */
    public function on($eventName, $callback=null, $args=array())
    {
        if (is_array($eventName)) {
            foreach ($eventName as $obs) {
                $this->observe($obs[0], $obs[1], !empty($obs[2]) ? $obs[2] : array());
            }
            return $this;
        }
        $observer = array('callback'=>$callback, 'args'=>$args);
        if (($moduleName = BModuleRegistry::currentModuleName())) {
            $observer['module_name'] = $moduleName;
        }
        $this->_events[$eventName]['observers'][] = $observer;
        BDebug::debug('SUBSCRIBE '.$eventName.': '.var_export($callback, 1), 1);
        return $this;
    }

    /**
    * Alias for on()
    *
    * @param string|array $eventName
    * @param mixed $callback
    * @param array $args
    * @return BPubSub
    */
    public function watch($eventName, $callback=null, $args=array())
    {
        return $this->on($eventName, $callback, $args);
    }

    /**
    * Alias for on()
    *
    * @param string|array $eventName
    * @param mixed $callback
    * @param array $args
    * @return BPubSub
    */
    public function observe($eventName, $callback=null, $args=array())
    {
        return $this->on($eventName, $callback, $args);
    }

    /**
    * Dispatch event observers
    *
    * dispatch|fire|notify|pub|publish ?
    *
    * @param string $eventName
    * @param array $args
    * @return array Collection of results from observers
    */
    public function fire($eventName, $args=array())
    {
        BDebug::debug('FIRE '.$eventName.(empty($this->_events[$eventName])?' (NO SUBSCRIBERS)':''), 1);
        $result = array();
        if (!empty($this->_events[$eventName])) {
            foreach ($this->_events[$eventName]['observers'] as $i=>$observer) {

                if (!empty($this->_events[$eventName]['args'])) {
                    $args = array_merge($this->_events[$eventName]['args'], $args);
                }
                if (!empty($observer['args'])) {
                    $args = array_merge($observer['args'], $args);
                }

                // Set current module to be used in observer callback
                if (!empty($observer['module_name'])) {
                    BModuleRegistry::i()->pushModule($observer['module_name']);
                }

                $cb = $observer['callback'];

                // For cases like BView
                if (is_object($cb) && !$cb instanceof Closure) {
                    if (is_callable(array($cb, 'set'))) {
                        $cb->set($args);
                    }
                    $result[] = (string)$cb;
                    continue;
                }

                // Special singleton syntax
                if (is_string($cb)) {
                    foreach (array('.', '->') as $sep) {
                        $r = explode($sep, $cb);
                        if (sizeof($r)==2) {
                            $cb = array($r[0]::i(), $r[1]);
                            $observer['callback'] = $cb;
                            // remember for next call, don't want to use &$observer
                            $this->_events[$eventName]['observers'][$i]['callback'] = $cb;
                            break;
                        }
                    }
                }

                // Invoke observer
                if (is_callable($cb)) {
                    BDebug::debug('ON '.$eventName/*.' : '.var_export($cb, 1)*/, 1);
                    $result[] = call_user_func($cb, $args);
                } else {
                    BDebug::warning('Invalid callback: '.var_export($cb, 1), 1);
                }

                if (!empty($observer['module_name'])) {
                    BModuleRegistry::i()->popModule();
                }
            }
        }
        return $result;
    }

    /**
    * Alias for fire()
    *
    * @param string|array $eventName
    * @param array $args
    * @return array Collection of results from observers
    */
    public function dispatch($eventName, $args=array())
    {
        return $this->fire($eventName, $args);
    }

    public function debug()
    {
        echo "<pre>"; print_r($this->_events); echo "</pre>";
    }
}

/**
* Facility to handle session state
*/
class BSession extends BClass
{
    /**
    * Session data, specific to the application namespace
    *
    * @var array
    */
    public $data = null;

    /**
    * Current sesison ID
    *
    * @var string
    */
    protected $_sessionId;

    /**
    * Whether PHP session is currently open
    *
    * @var bool
    */
    protected $_phpSessionOpen = false;

    /**
    * Whether any session variable was changed since last session save
    *
    * @var bool
    */
    protected $_dirty = false;

    /**
    * Shortcut to help with IDE autocompletion
    *
    * @return BSession
    */
    public static function i($new=false, array $args=array())
    {
        return BClassRegistry::i()->instance(__CLASS__, $args, !$new);
    }

    /**
    * Open session
    *
    * @param string|null $id Optional session ID
    * @param bool $close Close and unlock PHP session immediately
    */
    public function open($id=null, $autoClose=true)
    {
        if (!is_null($this->data)) {
            return $this;
        }
        $config = BConfig::i()->get('cookie');
        session_set_cookie_params(
            !empty($config['timeout']) ? $config['timeout'] : 3600,
            !empty($config['path']) ? $config['path'] : BRequest::i()->webRoot(),
            !empty($config['domain']) ? $config['domain'] : BRequest::i()->httpHost()
        );
        session_name(!empty($config['name']) ? $config['name'] : 'buckyball');
        if (!empty($id) || ($id = BRequest::i()->get('SID'))) {
            session_id($id);
        }
        if (headers_sent()) {
            BDebug::warning("Headers already sent, can't start session");
        } else {
            session_start();
        }
        $this->_phpSessionOpen = true;
        $this->_sessionId = session_id();

        if (!empty($config['session_check_ip'])) {
            $ip = BRequest::i()->ip();
            if (empty($_SESSION['_ip'])) {
                $_SESSION['_ip'] = $ip;
            } elseif ($_SESSION['_ip']!==$ip) {
                BResponse::i()->status(403, "Remote IP doesn't match session", "Remote IP doesn't match session");
            }
        }

        $namespace = !empty($config['session_namespace']) ? $config['session_namespace'] : 'default';
        $this->data = !empty($_SESSION[$namespace]) ? $_SESSION[$namespace] : array();

        if (!empty($this->data['_locale'])) {
            if (is_array($this->data['_locale'])) {
                foreach ($this->data['_locale'] as $c=>$l) {
                    setlocale($c, $l);
                }
            } elseif (is_string($this->data['_locale'])) {
                setlocale(LC_ALL, $this->data['_locale']);
            }
        }

        if (!empty($this->data['_timezone'])) {
            date_default_timezone_set($this->data['_timezone']);
        }

        if ($autoClose) {
            session_write_close();
            $this->_phpSessionOpen = false;
        }
        return $this;
    }

    /**
    * Set or retrieve dirty session flag
    *
    * @param bool $flag
    * @return bool
    */
    public function dirty($flag=BNULL)
    {
        if (BNULL===$flag) {
            return $this->_dirty;
        }
        BDebug::debug('SESSION.DIRTY '.($flag?'TRUE':'FALSE'), 2);
        $this->_dirty = $flag;
        return $this;
    }

    /**
    * Set or retrieve session variable
    *
    * @param string $key If ommited, return all session data
    * @param mixed $value If ommited, return data by $key
    * @return mixed|BSession
    */
    public function data($key=BNULL, $value=BNULL)
    {
        if (BNULL===$key) {
            return $this->data;
        }
        if (BNULL===$value) {
            return isset($this->data[$key]) ? $this->data[$key] : null;
        }
        if (!isset($this->data[$key]) || $this->data[$key]!==$value) {
            $this->dirty(true);
        }
        $this->data[$key] = $value;
        return $this;
    }

    public function pop($key)
    {
        $data = $this->data($key);
        $this->data($key, null);
        return $data;
    }

    /**
    * Get reference to session data and set dirty flag true
    *
    * @return array
    */
    public function &dataToUpdate()
    {
        $this->dirty(true);
        return $this->data;
    }

    /**
    * Write session variable changes and close PHP session
    *
    * @return BSession
    */
    public function close()
    {
        if (!$this->_dirty) {
            return;
        }
#ob_start(); debug_print_backtrace(); BDebug::debug(nl2br(ob_get_clean()));
        if (!$this->_phpSessionOpen) {
            if (headers_sent()) {
                BDebug::warning("Headers already sent, can't start session");
            } else {
                session_start();
            }
        }
        $namespace = BConfig::i()->get('cookie/session_namespace');
        if (!$namespace) $namespace = 'default';
        $_SESSION[$namespace] = $this->data;
        BDebug::debug(__METHOD__);
        session_write_close();
        $this->_phpSessionOpen = false;
        $this->dirty(false);
        return $this;
    }

    /**
    * Get session ID
    *
    * @return string
    */
    public function sessionId()
    {
        return $this->_sessionId;
    }

    /**
    * Add session message
    *
    * @param string $msg
    * @param string $type
    * @param string $tag
    * @return BSession
    */
    public function addMessage($msg, $type='info', $tag='_')
    {
        $this->dirty(true);
        $this->data['_messages'][$tag][] = array('msg'=>$msg, 'type'=>$type);
        return $this;
    }

    /**
    * Return any buffered messages for a tag and clear them from session
    *
    * @param string $tags comma separated
    * @return array
    */
    public function messages($tags='_')
    {
        if (empty($this->data['_messages'])) {
            return array();
        }
        $tags = explode(',', $tags);
        $msgs = array();
        foreach ($tags as $tag) {
            if (empty($this->data['_messages'][$tag])) continue;
            foreach ($this->data['_messages'][$tag] as $i=>$m) {
                $msgs[] = $m;
                unset($this->data['_messages'][$tag][$i]);
                $this->dirty(true);
            }
        }
        return $msgs;
    }
}
