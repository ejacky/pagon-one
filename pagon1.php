<?php

namespace Pagon {

use Pagon\Exception\Pass;
use Pagon\Exception\Stop;
/*
 * (The MIT License)
 *
 * Copyright (c) 2013 hfcorriez@gmail.com
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is furnished
 * to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */


class Fiber implements \ArrayAccess
{
        protected $injectors;

        public function __construct(array $injectors = array())
    {
        $this->injectors = $injectors;
    }

        public function __set($key, $value)
    {
        $this->injectors[$key] = $value;
    }

        public function &__get($key)
    {
        if (!isset($this->injectors[$key])) throw new \InvalidArgumentException(sprintf('Can not get non-exists injector "%s::%s"', get_called_class(), $key));

        if ($this->injectors[$key] instanceof \Closure) {
            $tmp = $this->injectors[$key]();
        } else {
            $tmp = & $this->injectors[$key];
        }
        return $tmp;
    }

        public function __isset($key)
    {
        return isset($this->injectors[$key]);
    }

        public function __unset($key)
    {
        unset($this->injectors[$key]);
    }

        public function protect($key, \Closure $closure = null)
    {
        if (!$closure) {
            $closure = $key;
            $key = false;
        }

        $func = function () use ($closure) {
            return $closure;
        };

        return $key ? ($this->injectors[$key] = $func) : $func;
    }

        public function share($key, \Closure $closure = null)
    {
        if (!$closure) {
            $closure = $key;
            $key = false;
        }
        $that = $this;
        $func = function () use ($closure, $that) {
            static $obj;

            if ($obj === null) {
                $obj = $closure($that);
            }

            return $obj;
        };

        return $key ? ($this->injectors[$key] = $func) : $func;
    }

        public function extend($key, \Closure $closure)
    {
        if (!isset($this->injectors[$key])) {
            throw new \InvalidArgumentException(sprintf('Injector "%s::%s" is not defined.', get_called_class(), $key));
        }

        $factory = $this->injectors[$key];

        if (!($factory instanceof \Closure)) {
            throw new \InvalidArgumentException(sprintf('Injector "%s::%s" does not contain an object definition.', get_called_class(), $key));
        }

        $that = $this;
        return $this->injectors[$key] = function () use ($closure, $factory, $that) {
            return $closure(call_user_func_array($factory, func_get_args()), $that);
        };
    }

        public function __call($method, $args)
    {
        if (($closure = $this->$method) instanceof \Closure) {
            return call_user_func_array($closure, $args);
        }

        throw new \BadMethodCallException(sprintf('Call to undefined protect injector "%s::%s()', get_called_class(), $method));
    }

        public function raw($key = null, $value = null)
    {
        if ($key === null) {
            return $this->injectors;
        } elseif ($value !== null) {
            $this->injectors[$key] = $value;
        }

        return isset($this->injectors[$key]) ? $this->injectors[$key] : false;
    }

        public function keys()
    {
        return array_keys($this->injectors);
    }

        public function append(array $injectors)
    {
        $this->injectors = $injectors + $this->injectors;
    }

        public function offsetExists($offset)
    {
        return $this->__isset($offset);
    }

        public function &offsetGet($offset)
    {
        if (!isset($this->injectors[$offset])) throw new \InvalidArgumentException(sprintf('Can not get non-exists injector "%s::%s"', get_called_class(), $offset));

        return $this->__get($offset);
    }

        public function offsetSet($offset, $value)
    {
        return $this->__set($offset, $value);
    }

        public function offsetUnset($offset)
    {
        return $this->__unset($offset);
    }
}

if (function_exists('FNMATCH')) {
    define('FNMATCH', true);
} else {
    define('FNMATCH', false);
}

class EventEmitter extends Fiber
{
        protected $listeners = array();

        public function emit($event, $args = null)
    {
        $event = strtolower($event);

        if ($args !== null) {
            // Check arguments, set inline args more than 1
            $args = array_slice(func_get_args(), 1);
        } else {
            $args = array();
        }

        $all_listeners = array();

        foreach ($this->listeners as $name => $listeners) {
            if (strpos($name, '*') === false || !self::match($name, $event)) {
                continue;
            }

            foreach ($this->listeners[$name] as &$listener) {
                $all_listeners[$name][] = & $listener;
            }
        }

        if (!empty($this->listeners[$event])) {
            foreach ($this->listeners[$event] as &$listener) {
                $all_listeners[$event][] = & $listener;
            }
        }

        // Loop listeners for callback
        foreach ($all_listeners as $name => $listeners) {
            $this_args = $args;
            if (strpos($name, '*') !== false) {
                array_unshift($this_args, $event);
            }
            foreach ($listeners as &$listener) {
                if ($listener instanceof \Closure) {
                    // Closure Listener
                    call_user_func_array($listener, $this_args);
                } elseif (is_array($listener) && $listener[0] instanceof \Closure) {
                    if ($listener[1]['times'] > 0) {
                        // Closure Listener
                        call_user_func_array($listener[0], $this_args);
                        $listener[1]['times']--;
                    }
                }
            }
        }
    }

        public function on($event, \Closure $listener)
    {
        if (is_array($event)) {
            foreach ($event as $e) {
                $this->listeners[strtolower($e)][] = $listener;
            }
        } else {
            $this->listeners[strtolower($event)][] = $listener;
        }
    }

        public function once($event, \Closure $listener)
    {
        if (is_array($event)) {
            foreach ($event as $e) {
                $this->listeners[strtolower($e)][] = array($listener, array('times' => 1));
            }
        } else {
            $this->listeners[strtolower($event)][] = array($listener, array('times' => 1));
        }
    }

        public function many($event, $times = 1, \Closure $listener)
    {
        if (is_array($event)) {
            foreach ($event as $e) {
                $this->listeners[strtolower($e)][] = array($listener, array('times' => $times));
            }
        } else {
            $this->listeners[strtolower($event)][] = array($listener, array('times' => $times));
        }
    }

        public function off($event, \Closure $listener)
    {
        if (is_array($event)) {
            foreach ($event as $e) {
                $this->off($e, $listener);
            }
        } else {
            $event = strtolower($event);
            if (!empty($this->listeners[$event])) {
                // Find Listener index
                if (($key = array_search($listener, $this->listeners[$event])) !== false) {
                    // Remove it
                    unset($this->listeners[$event][$key]);
                }
            }
        }
    }

        public function listeners($event)
    {
        if (!empty($this->listeners[$event])) {
            return $this->listeners[$event];
        }
        return array();
    }

        public function addListener($event, \Closure $listener)
    {
        $this->on($event, $listener);
    }

        public function removeListener($event, \Closure $listener)
    {
        $this->off($event, $listener);
    }

        public function removeAllListeners($event = null)
    {
        if ($event === null) {
            $this->listeners = array();
        } else if (($event = strtolower($event)) && !empty($this->listeners[$event])) {
            $this->listeners[$event] = array();
        }
    }

        protected static function match($pattern, $string)
    {
        if (FNMATCH) {
            return fnmatch($pattern, $string);
        } else {
            return preg_match("#^" . strtr(preg_quote($pattern, '#'), array('\*' => '.*', '\?' => '.')) . "$#i", $string);
        }
    }
}

abstract class Middleware extends EventEmitter
{
    const _CLASS_ = __CLASS__;

        protected $input;

        protected $output;

        protected $app;

        protected $options = array();

        protected $next;

        public function __construct(array $options = array())
    {
        $this->options = $options + $this->options;
    }

        public static function build($route, $options = array())
    {
        if (is_string($route) && is_subclass_of($route, __CLASS__, true)) {
            // Only Class name
            return new $route($options);
        } elseif (is_object($route)) {
            return $route;
        }
        return false;
    }

        abstract function call();

        public function __invoke($input, $output, $next)
    {
        $this->input = $input;
        $this->output = $output;
        $this->app = $input->app;
        $this->next = $next;
        $this->call();
    }

        public function next()
    {
        call_user_func($this->next);
    }
}


abstract class Route extends Middleware
{
        protected function before()
    {
        // Implements if you need
    }

        protected function after()
    {
        // Implements if you need
    }

        public function call()
    {
        $this->before();
        $this->run($this->input, $this->output);
        $this->after();
    }

        public function next()
    {
        call_user_func($this->next);
    }
}




class Router extends Middleware
{
    const _CLASS_ = __CLASS__;

        public $app;

        protected $automatic;

        public function set($path, $route, $more = null)
    {
        if ($more) {
            $_args = func_get_args();
            $path = array_shift($_args);
            $route = $_args;
        }
        $this->app->routes[$path] = $route;
        return $this;
    }

        public function get($path)
    {
        return $this->app->routes[$path];
    }

        public function name($name, $path = null)
    {
        if ($path === null) {
            $path = array_keys($this->app->routes);
            $path = end($path);
        }

        $this->app->names[$name] = $path;
        return $this;
    }

        public function path($name)
    {
        return isset($this->app->names[$name]) ? $this->app->names[$name] : false;
    }

        public function dispatch()
    {
        // Check path
        if ($this->options['path'] === null) return false;

        // Get routes
        $routes = (array)$this->app->routes;

        // Loop routes for parse and dispatch
        foreach ($routes as $p => $route) {
            // Try to parse the params
            if (($param = self::match($this->options['path'], $p)) !== false) {
                try {
                    $param && $this->app->param($param);

                    return $this->run($route);

                    // If multiple controller
                } catch (Pass $e) {
                    // When catch Next, continue next route
                }
            }
        }

        // Try to check automatic route parser
        if ($this->automatic instanceof \Closure) {
            $route = call_user_func($this->automatic, $this->options['path']);

            if ($route && class_exists($route)) {
                try {
                    return $this->run($route);
                } catch (Pass $e) {
                    // When catch Next, continue next route
                }
            }
        }

        return false;
    }

        public function run($route)
    {
        return $this->pass($route, function ($r) {
            return Route::build($r);
        });
    }

        public function pass($routes, \Closure $build)
    {
        if (!$routes) return false;

        $routes = (array)$routes;
        $param = null;

        $pass = function ($route) use ($build, &$param) {
            $runner = $route instanceof \Closure ? $route : $build($route);
            if (is_callable($runner)) {
                call_user_func_array($runner, $param);
                return true;
            } else {
                throw new \InvalidArgumentException("Route '$route' is not exists");
            }
        };

        $param = array(
            $this->app->input,
            $this->app->output,
            function () use (&$routes, $pass) {
                if (!$route = next($routes)) {
                    throw new Pass;
                }

                $pass($route);
            }
        );

        return $pass(current($routes));
    }

        public function automatic(\Closure $closure)
    {
        $this->automatic = $closure;
        return $this;
    }

        public function handle($route, $args = array())
    {
        if (isset($this->app->routes[$route])) {
            $args && $this->app->param($args);
            return $this->run($this->app->routes[$route]);
        }
        return false;
    }

        public function call()
    {
        try {
            if (!$this->dispatch()) {
                $this->app->handleError('404');
            }
        } catch (Stop $e) {
        }
    }

        protected static function match($path, $route)
    {
        $param = false;

        // Regex or Param check
        if (!strpos($route, ':') && strpos($route, '^') === false) {
            if ($path === $route || $path === $route . '/') {
                $param = array();
            }
        } else {
            // Try match
            if (preg_match(self::pathToRegex($route), $path, $matches)) {
                array_shift($matches);
                $param = $matches;
            }
        }

        // When complete the return
        return $param;
    }

        protected static function pathToRegex($path)
    {
        if ($path[1] !== '^') {
            $path = str_replace(array('/'), array('\\/'), $path);
            if ($path{0} == '^') {
                // As regex
                $path = '/' . $path . '/';
            } elseif (strpos($path, ':')) {
                // Need replace
                $path = '/^' . preg_replace('/\(:([a-zA-Z0-9]+)\)/', '(?<$1>[^\/]+?)', $path) . '\/?$/';
            } else {
                // Full match
                $path = '/^' . $path . '\/?$/';
            }

            // * support
            if (strpos($path, '*')) {
                $path = str_replace('*', '([^\/]+?)', $path);
            }
        }
        return $path;
    }
}


class Config extends Fiber
{
        const LOAD_AUTODETECT = 0;

        public static $dir;

        protected static $imports = array(
        'mimes' => array('pagon/config/mimes.php', 0),
    );

        public function __construct(array $input)
    {
        parent::__construct($input);
    }

        public static function import($name, $file, $type = self::LOAD_AUTODETECT)
    {
        static::$imports[$name] = array($file, $type);
    }

        public static function export($name)
    {
        if (!isset(static::$imports[$name])) {
            throw new \InvalidArgumentException("Load config error with non-exists name \"$name\"");
        }

        // Check if config already exists?
        if (static::$imports[$name] instanceof Config) {
            return static::$imports[$name];
        }

        // Try to load
        list($path, $type) = static::$imports[$name];

        // Check file in path
        if (!$file = App::self()->path($path)) {
            throw new \InvalidArgumentException("Can not find file path \"$path\"");
        }

        return static::$imports[$name] = static::load($file, $type);
    }

        public static function load($file, $type = self::LOAD_AUTODETECT)
    {
        if (!is_file($file)) {
            throw new \InvalidArgumentException("Config load error with non-exists file \"$file\"");
        }

        if ($type === self::LOAD_AUTODETECT) {
            $type = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        }

        if ($type !== 'php') {
            return new self(Parser::load($file, $type));
        } else {
            return new self(include($file));
        }
    }

        public function dump($type)
    {
        return Parser::dump($this->injectors, $type);
    }
}


class View
{
    const _CLASS_ = __CLASS__;

        protected $path;

        protected $data = array();

        protected $options = array(
        'dir'    => '',
        'engine' => null,
    );

        public function __construct($path, $data = array(), $options = array())
    {
        // Set dir for the view
        $this->options = $options + $this->options;

        // Set path
        $this->path = ltrim($path, '/');

        // If file exists?
        if (!is_file($options['dir'] . '/' . $this->path)) {
            // Try to load file from absolute path
            if ($path{0} == '/' && is_file($path)) {
                $this->path = $path;
                $this->options['dir'] = '';
            } else {
                throw new \Exception('Template file is not exist: ' . $this->path);
            }
        }

        // Set data
        $this->data = $data;
    }

        public function setEngine($engine)
    {
        $this->options['engine'] = $engine;
        return $this;
    }

        public function setDir($dir)
    {
        $this->options['dir'] = $dir;
        return $this;
    }

        public function set(array $array = array())
    {
        $this->data = $array + $this->data;
        return $this;
    }

        public function render()
    {
        $engine = $this->options['engine'];

        if (!$engine) {
            if ($this->data) {
                extract((array)$this->data);
            }
            ob_start();
            include($this->options['dir'] . ($this->path{0} == '/' ? '' : '/') . $this->path);
            return ob_get_clean();
        }

        return $engine->render($this->path, $this->data, $this->options['dir']);
    }

        public function __set($key, $value)
    {
        $this->data[$key] = $value;
    }

        public function __get($key)
    {
        return isset($this->data[$key]) ? $this->data[$key] : null;
    }

        public function __isset($key)
    {
        return isset($this->data[$key]);
    }

        public function __unset($key)
    {
        unset($this->data[$key]);
    }

        public function __toString()
    {
        return $this->render();
    }
}


const VERSION = '0.5';

class App extends EventEmitter
{
        public $input;

        public $output;

        public $router;

        protected $injectors = array(
        'mode'       => 'develop',
        'debug'      => false,
        'views'      => false,
        'error'      => true,
        'routes'     => array(),
        'names'      => array(),
        'buffer'     => true,
        'timezone'   => 'UTC',
        'charset'    => 'UTF-8',
        'autoload'   => null,
        'alias'      => array(),
        'namespaces' => array(),
        'engines'    => array(
            'jade' => 'Jade'
        ),
        'errors'     => array(
            '404'       => array(404, 'Location not found'),
            'exception' => array(500, 'Error occurred'),
            'crash'     => array(500, 'Application crash')
        ),
        'stacks'     => array(),
        'mounts'     => array(),
        'bundles'    => array(),
        'locals'     => array(),
    );

        private $_cli = false;

        private $_run = false;

        protected static $self;

        protected static $loads = array();

        public static function self()
    {
        if (!self::$self) {
            throw new \RuntimeException("There is no App exists");
        }

        return self::$self;
    }

        public function __construct($config = array())
    {
        $app = & $this;

        // Is cli
        $this->_cli = PHP_SAPI == 'cli';

        // Register shutdown
        register_shutdown_function(array($this, '__shutdown'));

        // Register autoload
        spl_autoload_register(array($this, '__autoload'));

        // Set io depends on SAPI
        if (!$this->_cli) {
            $this->input = new Http\Input($this);
            $this->output = new Http\Output($this);
        } else {
            $this->input = new Cli\Input($this);
            $this->output = new Cli\Output($this);
        }

        // Init Route
        $this->router = new Router(array('path' => $this->input->path()));
        $this->router->app = $this;

        // Force use UTF-8 encoding
        iconv_set_encoding("internal_encoding", "UTF-8");
        mb_internal_encoding('UTF-8');

        // Set config
        $this->injectors =
            (!is_array($config) ? Parser::load((string)$config) : $config)
            + ($this->_cli ? array('buffer' => false) : array())
            + $this->injectors;

        // Register some initialize
        $this->on('run', function () use ($app) {
            // configure timezone
            if ($app->timezone) date_default_timezone_set($app->timezone);

            // configure debug
            if ($app->debug) $app->add(new Middleware\PrettyException());

            // Share the cryptor for the app
            $app->share('cryptor', function ($app) {
                if (empty($app->crypt)) {
                    throw new \RuntimeException('Encrypt cookie need configure config["crypt"]');
                }
                return new Utility\Cryptor($app->crypt);
            });
        });

        // Set default locals
        $this->injectors['locals']['config'] = & $this->injectors;

        // Set mode
        $this->injectors['mode'] = ($_mode = getenv('PAGON_ENV')) ? $_mode : $this->injectors['mode'];

        // Set pagon root directory
        $this->injectors['mounts']['pagon'] = dirname(dirname(__DIR__));

        // Save current app
        self::$self = $this;
    }

        public function isCli()
    {
        return $this->_cli;
    }

        public function isRunning()
    {
        return $this->_run;
    }

        public function set($key, $value)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->set($k, $v);
            }
        } elseif (strpos($key, '.') !== false) {
            $config = & $this->injectors;
            $namespaces = explode('.', $key);
            foreach ($namespaces as $namespace) {
                if (!isset($config[$namespace])) $config[$namespace] = array();
                $config = & $config[$namespace];
            }
            $config = $value;
        } else {
            $this->injectors[$key] = $value;
        }
    }

        public function enable($key)
    {
        $this->set($key, true);
    }

        public function disable($key)
    {
        $this->set($key, false);
    }

        public function enabled($key)
    {
        return $this->get($key) === true;
    }

        public function disabled($key)
    {
        return $this->get($key) === false;
    }

        public function mode($mode = null)
    {
        if ($mode) {
            $this->injectors['mode'] = $mode instanceof \Closure ? $mode() : (string)$mode;
        }
        return $this->injectors['mode'];
    }

        public function configure($mode, \Closure $closure = null)
    {
        if ($closure === null) {
            $closure = $mode instanceof \Closure ? $mode : null;
            $mode = null;
        } elseif ($mode == 'all') {
            // All mode
            $mode = null;
        }

        // Not exists closure?
        if (!$closure) return;

        // Allow set mode get method when mode is closure
        if (!$mode) {
            $closure($this->injectors['mode']);
        } elseif ($mode == $this->injectors['mode']) {
            $closure();
        }
    }

        public function add($path, $middleware = null, $options = array())
    {
        if ($path instanceof Middleware || is_string($path) && $path{0} != '/') {
            // If not path
            $options = (array)$middleware;
            $middleware = $path;
            $path = '';
        }

        if (is_string($middleware)) {
            // If middleware is class name
            if ($middleware{0} !== '\\') {
                // Support short class name
                $middleware = __NAMESPACE__ . '\Middleware\\' . $middleware;
            }

            // Check if base on Middleware class
            if (!is_subclass_of($middleware, Middleware::_CLASS_)) {
                throw new \RuntimeException("Bad middleware can not be called");
            }
        }

        // Add to the end
        $this->injectors['stacks'][] = array($path, $middleware, $options);
    }

        public function bundle($name, $options = array())
    {
        if (!is_array($options)) {
            $path = $name;
            $name = $options;
            $options = array('path' => $path);
        }
        $this->injectors['bundles'][$name] = $options;
    }

        public function get($path, $route = null, $more = null)
    {
        // Get config for use
        if ($route === null) {
            if ($path === null) return $this->injectors;

            $tmp = null;
            if (strpos($path, '.') !== false) {
                $ks = explode('.', $path);
                $tmp = $this->injectors;
                foreach ($ks as $k) {
                    if (!isset($tmp[$k])) return null;

                    $tmp = & $tmp[$k];
                }
            } else {
                if (isset($this->injectors[$path])) {
                    $tmp = & $this->injectors[$path];
                }
            }

            return $tmp;
        }

        if ($this->_cli || !$this->input->isGet()) return $this->router;

        if ($more !== null) {
            return call_user_func_array(array($this->router, 'set'), func_get_args());
        } else {
            return $this->router->set($path, $route);
        }
    }

        public function post($path, $route, $more = null)
    {
        if ($this->_cli || !$this->input->isPost()) return $this->router;

        if ($more !== null) {
            return call_user_func_array(array($this->router, 'set'), func_get_args());
        } else {
            return $this->router->set($path, $route);
        }
    }

        public function put($path, $route, $more = null)
    {
        if ($this->_cli || !$this->input->isPut()) return $this->router;

        if ($more !== null) {
            return call_user_func_array(array($this->router, 'set'), func_get_args());
        } else {
            return $this->router->set($path, $route);
        }
    }

        public function delete($path, $route, $more = null)
    {
        if ($this->_cli || !$this->input->isDelete()) return $this->router;

        if ($more !== null) {
            return call_user_func_array(array($this->router, 'set'), func_get_args());
        } else {
            return $this->router->set($path, $route);
        }
    }

        public function all($path, $route = null, $more = null)
    {
        if ($this->_cli) return $this->router;

        if ($more !== null) {
            return call_user_func_array(array($this->router, 'set'), func_get_args());
        } else {
            return $this->router->set($path, $route);
        }
    }

        public function autoRoute($closure)
    {
        if ($closure instanceof \Closure) {
            return $this->router->automatic($closure);
        } elseif ($closure === true || is_string($closure)) {
            $_cli = $this->_cli;
            // Set route use default automatic
            return $this->router->automatic(function ($path) use ($closure, $_cli) {
                if (!$_cli && $path !== '/' || $_cli && $path !== '') {
                    $splits = array_map(function ($split) {
                        return ucfirst(strtolower($split));
                    }, $_cli ? explode(':', $path) : explode('/', ltrim($path, '/')));
                } else {
                    // If path is root or is not found
                    $splits = array('Index');
                }

                return ($closure === true ? '' : $closure . '\\') . join('\\', $splits);
            });
        }
    }

        public function cli($path, $route = null, $more = null)
    {
        if (!$this->_cli) return $this->router;

        if ($more !== null) {
            return call_user_func_array(array($this->router, 'set'), func_get_args());
        } else {
            return $this->router->set($path, $route);
        }
    }

        public function mount($path, $dir = null)
    {
        if (!$dir) {
            $dir = $path;
            $path = '';
        }

        $this->injectors['mounts'][$path] = $dir;
    }

        public function load($file)
    {
        if (!$file = $this->path($file)) {
            throw new \InvalidArgumentException('Can load non-exists file "' . $file . '"');
        }

        if (isset(self::$loads[$file])) {
            return self::$loads[$file];
        }

        return self::$loads[$file] = include($file);
    }

        public function path($file)
    {
        foreach ($this->injectors['mounts'] as $path => $dir) {
            if ($path === '' || strpos($file, $path) === 0) {
                if (!$path = stream_resolve_include_path($dir . '/' . ($path ? substr($file, strlen($path)) : $file))) continue;
                return $path;
            }
        }

        if ($path = stream_resolve_include_path($file)) {
            return $path;
        }

        return false;
    }

        public function loaded($file)
    {
        if (!$file = $this->path($file)) {
            throw new \InvalidArgumentException('Can not check non-exists file "' . $file . '"');
        }

        if (isset(self::$loads[$file])) {
            return true;
        }

        return false;
    }

        public function engine($name, $engine = null)
    {
        if ($engine) {
            // Set engine
            $this->injectors['engines'][$name] = $engine;
        }
        return isset($this->injectors['engines'][$name]) ? $this->injectors['engines'][$name] : null;
    }

        public function render($path, array $data = null, array $options = array())
    {
        echo $this->compile($path, $data, $options);
    }

        public function compile($path, array $data = null, array $options = array())
    {
        if (!isset($options['engine'])) {
            // Get ext
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $options['engine'] = false;

            // If ext then check engine with ext
            if ($ext && isset($this->injectors['engines'][$ext])) {
                // If engine exists
                if (is_string($this->injectors['engines'][$ext])) {
                    if (class_exists($this->injectors['engines'][$ext])) {
                        $class = $this->injectors['engines'][$ext];
                    } else if (class_exists(__NAMESPACE__ . '\\Engine\\' . $this->injectors['engines'][$ext])) {
                        $class = __NAMESPACE__ . '\\Engine\\' . $this->injectors['engines'][$ext];
                    } else {
                        throw new \RuntimeException("Unavailable view engine '{$this->injectors['engines'][$ext]}'");
                    }
                    // Create new engine
                    $this->injectors['engines'][$ext] = $options['engine'] = new $class();
                } else {
                    // Get engine from exists engines
                    $options['engine'] = $this->injectors['engines'][$ext];
                }
            }
        }

        // Set default data
        if ($data === null) $data = array();

        // Default set app
        $data['_'] = $this;

        // Create view
        $view = new View($path, $data + $this->injectors['locals'], $options + array(
                'dir' => $this->injectors['views']
            ));

        // Return view
        return $view;
    }

        public function run()
    {
        // Check if run
        if ($this->_run) {
            throw new \RuntimeException("Application already running");
        }

        // Emit run
        $this->emit('run');

        // Set run
        $this->_run = true;

        $_error = false;
        if ($this->injectors['error']) {
            // If config error, register error handle and set flag
            $_error = true;
            $this->registerErrorHandler();
        }

        try {
            // Emit "bundle" event
            $this->emit('bundle');
                        foreach ($this->injectors['bundles'] as $id => $options) {
                // Set id
                $id = isset($options['id']) ? $options['id'] : $id;

                // Set bootstrap file
                $bootstrap = isset($options['bootstrap']) ? $options['bootstrap'] : 'bootstrap.php';

                // Set dir to load
                $dir = isset($options['dir']) ? $options['dir'] : 'bundles/' . $id;

                // Path check, if not match start of path, skip
                if (isset($options['path']) && strpos($this->input->path(), $options['path']) !== 0) {
                    continue;
                }

                // Check the file path
                if (!$file = $this->path($dir . '/' . $bootstrap)) {
                    throw new \InvalidArgumentException('Bundle "' . $id . '" can not bootstrap');
                }

                // Check if bootstrap file loaded
                if (isset(self::$loads[$file])) {
                    throw new \RuntimeException('Bundle "' . $id . '" can not bootstrap twice');
                }

                // Emit "bundle.[id]" event
                $this->emit('bundle.' . $id);

                // Set variable for bootstrap file
                $app = $this;
                extract($options);
                require $file;

                // Save to loads
                self::$loads[$file] = true;
            }

            // Start buffer
            if ($this->injectors['buffer']) ob_start();
            $this->injectors['stacks'][] = $this->router;

            // Emit "middleware" event
            $this->emit('middleware');

            // Loop stacks to match
            foreach ($this->injectors['stacks'] as $index => &$middleware) {
                if (!is_array($middleware)) {
                    $middleware = array('', $middleware);
                }
                // Try to match the path
                if ($middleware[0] && strpos($this->input->path(), $middleware[0]) === false) {
                    unset($this->injectors['stacks'][$index]);
                }
            }

            try {
                $this->router->pass($this->injectors['stacks'], function ($m) {
                    return Middleware::build($m[1], isset($m[2]) ? $m[2] : array());
                });
            } catch (Exception\Pass $e) {
            }

            // Write direct output to the head of buffer
            if ($this->injectors['buffer']) $this->output->write(ob_get_clean());
        } catch (Exception\Stop $e) {
        } catch (\Exception $e) {
            if ($this->injectors['debug']) {
                throw $e;
            } else {
                try {
                    $this->handleError('exception', $e);
                } catch (Exception\Stop $e) {
                }
            }
            $this->emit('error');
        }

        $this->_run = false;

        // Send start
        $this->emit('flush');

        // Send headers
        if (!$this->_cli) {
            $this->output->sendHeader();
        }

        // Send
        echo $this->output->body();

        // Send end
        $this->emit('end');

        if ($_error) $this->restoreErrorHandler();
    }

        public function handleError($type, $route = null)
    {
        if (!isset($this->injectors['errors'][$type])) {
            throw new \InvalidArgumentException('Unknown error type "' . $type . '" to call');
        }

        if ($route && !$route instanceof \Exception) {
            $this->router->set('_' . $type, $route);
        } else {
            ob_get_level() && ob_clean();
            ob_start();
            if (!$this->router->handle('_' . $type, array($route))) {
                echo $this->injectors['errors'][$type][1];
            }
            $this->halt($this->injectors['errors'][$type][0], ob_get_clean());
        }
    }

        public function halt($status, $body = '')
    {
        $this->output->status($status)->body($body);
        throw new Exception\Stop;
    }

        public function stop()
    {
        throw new Exception\Stop();
    }

        public function pass()
    {
        ob_get_level() && ob_clean();
        throw new Exception\Pass();
    }

        public function param($param = null)
    {
        if ($param === null) {
            return $this->input->params;
        } else {
            if (is_array($param)) {
                $this->input->params = $param;
                return true;
            } else {
                return isset($this->input->params[$param]) ? $this->input->params[$param] : null;
            }
        }
    }

        public function assisting()
    {
        $this->load(dirname(__DIR__) . '/assistant.php');
    }

        public function registerErrorHandler()
    {
        set_error_handler(array($this, '__error'));
    }

        public function restoreErrorHandler()
    {
        restore_error_handler();
    }

        protected function __autoload($class)
    {
        if ($class{0} == '\\') $class = ltrim($class, '\\');

        // Alias check
        if (!empty($this->injectors['alias'][$class])) {
            class_alias($this->injectors['alias'][$class], $class);
            $class = $this->injectors['alias'][$class];
        }

        // If with Pagon path, force require
        if (substr($class, 0, strlen(__NAMESPACE__) + 1) == __NAMESPACE__ . '\\') {
            if ($file = stream_resolve_include_path(__DIR__ . '/' . str_replace('\\', '/', substr($class, strlen(__NAMESPACE__) + 1)) . '.php')) {
                require $file;
                return true;
            }
        } else {
            // Set the 99 high order for default autoload
            $available_path = array();

            // Autoload
            if ($this->injectors['autoload']) {
                $available_path[99] = $this->injectors['autoload'];
            }

            // Check other namespaces
            if ($this->injectors['namespaces']) {
                // Loop namespaces as autoload
                foreach ($this->injectors['namespaces'] as $_prefix => $_path) {
                    // Check if match prefix
                    if (($_pos = strpos($class, $_prefix)) === 0) {
                        // Set ordered path
                        $available_path[strlen($_prefix)] = $_path;
                    }
                }
                // Sort by order
                ksort($available_path);
            }

            // No available path, no continue
            if ($available_path) {
                // Set default file name
                $file_name = '';
                // PSR-0 check
                if ($last_pos = strrpos($class, '\\')) {
                    $namespace = substr($class, 0, $last_pos);
                    $class = substr($class, $last_pos + 1);
                    $file_name = str_replace('\\', '/', $namespace) . '/';
                }
                // Get last file name
                $file_name .= str_replace('_', '/', $class) . '.php';
                // Loop available path for check
                foreach ($available_path as $_path) {
                    // Check file if exists
                    if ($file = stream_resolve_include_path($_path . '/' . $file_name)) {
                        require $file;
                        return true;
                    }
                }
            }

            $try_class = __NAMESPACE__ . '\\' . $class;
            if (class_exists($try_class)) {
                class_alias($try_class, $class);
            }
        }

        return false;
    }

        public function __error($type, $message, $file, $line)
    {
        if (error_reporting() & $type) throw new \ErrorException($message, $type, 0, $file, $line);
    }

        public function __shutdown()
    {
        $this->emit('exit');
        if (!$this->_run) return;

        if (($error = error_get_last())
            && in_array($error['type'], array(E_PARSE, E_ERROR, E_USER_ERROR, E_COMPILE_ERROR, E_CORE_ERROR))
        ) {
            if (!$this->injectors['debug']) {
                try {
                    $this->handleError('crash');
                } catch (Exception\Stop $e) {
                    // Send headers
                    if (!$this->_cli) {
                        $this->output->sendHeader();
                    }

                    // Send
                    echo $this->output->body();
                }
            }
            $this->emit('crash', $error);
        }
    }
}

}

namespace Pagon\Http {

use Pagon\App;
use Pagon\Config;
use Pagon\EventEmitter;
use Pagon\Exception\Stop;
use Pagon\View;
use Pagon\Exception\Pass;




class Input extends EventEmitter
{
        public $app;

        public function __construct(App $app)
    {
        $this->app = $app;

        parent::__construct(array('params' => array(), 'query' => &$_GET, 'data' => &$_POST) + $_SERVER);
    }

        public function protocol()
    {
        return $this->injectors['SERVER_PROTOCOL'];
    }

        public function uri()
    {
        return $this->injectors['REQUEST_URI'];
    }

        public function root()
    {
        return rtrim($this->injectors['DOCUMENT_ROOT'], '/') . rtrim($this->scriptName(), '/');
    }

        public function scriptName()
    {
        if (!isset($this->injectors['script_name'])) {
            $_script_name = $this->injectors['SCRIPT_NAME'];
            if (strpos($this->injectors['REQUEST_URI'], $_script_name) !== 0) {
                $_script_name = str_replace('\\', '/', dirname($_script_name));
            }
            $this->injectors['script_name'] = rtrim($_script_name, '/');
        }
        return $this->injectors['script_name'];
    }

        public function path()
    {
        if (!isset($this->injectors['path_info'])) {
            $_path_info = substr_replace($this->injectors['REQUEST_URI'], '', 0, strlen($this->scriptName()));
            if (strpos($_path_info, '?') !== false) {
                // Query string is not removed automatically
                $_path_info = substr_replace($_path_info, '', strpos($_path_info, '?'));
            }
            $this->injectors['path_info'] = (!$_path_info || $_path_info{0} != '/' ? '/' : '') . $_path_info;
        }
        return $this->injectors['path_info'];
    }

        public function url()
    {
        if (!isset($this->injectors['url'])) {
            $_url = $this->scheme() . '://' . $this->host();
            if (($this->scheme() === 'https' && $this->port() !== 443) || ($this->scheme() === 'http' && $this->port() !== 80)) {
                $_url .= sprintf(':%s', $this->port());
            }
            $this->injectors['url'] = $_url . $this->uri();
        }

        return $this->injectors['url'];
    }

        public function site()
    {
        return $this->scheme() . '://' . $this->domain();
    }

        public function method()
    {
        return $this->injectors['REQUEST_METHOD'];
    }

        public function is($method)
    {
        return $this->method() == strtoupper($method);
    }

        public function isGet()
    {
        return $this->method() === 'GET';
    }

        public function isPost()
    {
        return $this->method() === 'POST';
    }

        public function isPut()
    {
        return $this->method() === 'PUT';
    }

        public function isDelete()
    {
        return $this->method() === 'DELETE';
    }

        public function isAjax()
    {
        return !$this->header('x-requested-with') && 'XMLHttpRequest' == $this->header('x-requested-with');
    }

        public function isXhr()
    {
        return $this->isAjax();
    }

        public function isSecure()
    {
        return $this->scheme() === 'https';
    }

        public function isUpload()
    {
        return !empty($_FILES);
    }

        public function accept($type = null)
    {
        if (!isset($this->injectors['accept'])) {
            $this->injectors['accept'] = self::buildAcceptMap($this->raw('HTTP_ACCEPT'));
        }

        // if no parameter was passed, just return parsed data
        if (!$type) return $this->injectors['accept'];
        // Support get best match
        if ($type === true) {
            reset($this->injectors['accept']);
            return key($this->injectors['accept']);
        }

        // If type is 'txt', 'xml' and so on, use smarty stracy
        if (is_string($type) && !strpos($type, '/')) {
            $type = Config::export('mimes')->{$type};
            if (!$type) return null;
        }

        // Force to array
        $type = (array)$type;

        // let’s check our supported types:
        foreach ($this->injectors['accept'] as $mime => $q) {
            if ($q && in_array($mime, $type)) return $mime;
        }

        // All match
        if (isset($this->injectors['accept']['*/*'])) return $type[0];
        return null;
    }

        public function acceptEncoding($type = null)
    {
        if (!isset($this->injectors['accept_encoding'])) {
            $this->injectors['accept_encoding'] = self::buildAcceptMap($this->raw('HTTP_ACCEPT_LANGUAGE'));
        }

        // if no parameter was passed, just return parsed data
        if (!$type) return $this->injectors['accept_encoding'];
        // Support get best match
        if ($type === true) {
            reset($this->injectors['accept_encoding']);
            return key($this->injectors['accept_encoding']);
        }

        // Force to array
        $type = (array)$type;

        // let’s check our supported types:
        foreach ($this->injectors['accept_encoding'] as $lang => $q) {
            if ($q && in_array($lang, $type)) return $lang;
        }
        return null;
    }

        public function acceptLanguage($type = null)
    {
        if (!isset($this->injectors['accept_language'])) {
            $this->injectors['accept_language'] = self::buildAcceptMap($this->raw('HTTP_ACCEPT_LANGUAGE'));
        }

        // if no parameter was passed, just return parsed data
        if (!$type) return $this->injectors['accept_language'];
        // Support get best match
        if ($type === true) {
            reset($this->injectors['accept_language']);
            return key($this->injectors['accept_language']);
        }

        // Force to array
        $type = (array)$type;

        // let’s check our supported types:
        foreach ($this->injectors['accept_language'] as $lang => $q) {
            if ($q && in_array($lang, $type)) return $lang;
        }
        return null;
    }

        public function ip()
    {
        if ($ips = $this->proxy()) {
            return $ips[0];
        }
        return $this->injectors['REMOTE_ADDR'];
    }

        public function proxy()
    {
        if ($ips = $this->raw('HTTP_X_FORWARDED_FOR')) {
            return strpos($ips, ', ') ? explode(', ', $ips) : array($ips);
        }

        return array();
    }

        public function subDomains()
    {
        $parts = explode('.', $this->host());
        return array_reverse(array_slice($parts, 0, -2));
    }

        public function refer()
    {
        return $this->raw('HTTP_REFERER');
    }

        public function host()
    {
        if ($host = $this->raw('HTTP_HOST')) {
            if (strpos($host, ':') !== false) {
                $hostParts = explode(':', $host);

                return $hostParts[0];
            }

            return $host;
        }
        return $this->injectors['SERVER_NAME'];
    }

        public function hostPort()
    {
        return $this->host() . ':' . $this->port();
    }

        public function domain()
    {
        return $this->host();
    }

        public function scheme()
    {
        return !$this->raw('HTTPS') || $this->raw('HTTPS') === 'off' ? 'http' : 'https';
    }

        public function port()
    {
        return (int)$this->raw('SERVER_PORT');
    }

        public function userAgent()
    {
        return $this->raw('HTTP_USER_AGENT');
    }

        public function contentType()
    {
        return $this->raw('CONTENT_TYPE');
    }

        public function mediaType()
    {
        $contentType = $this->contentType();
        if ($contentType) {
            $contentTypeParts = preg_split('/\s*[;,]\s*/', $contentType);

            return strtolower($contentTypeParts[0]);
        }
        return null;
    }

        public function contentCharset()
    {
        $mediaTypeParams = $this->mediaType();
        if (isset($mediaTypeParams['charset'])) {
            return $mediaTypeParams['charset'];
        }
        return null;
    }

        public function contentLength()
    {
        if ($len = $this->raw('CONTENT_LENGTH')) {
            return (int)$len;
        }
        return 0;
    }

        public function query($key, $default = null)
    {
        return isset($this->injectors['query'][$key]) ? $this->injectors['query'][$key] : $default;
    }

        public function data($key, $default = null)
    {
        return isset($this->injectors['data'][$key]) ? $this->injectors['data'][$key] : $default;

    }

        public function param($key, $default = null)
    {
        return isset($this->injectors['params'][$key]) ? $this->injectors['params'][$key] : $default;
    }

        public function header($name = null)
    {
        if (!isset($this->injectors['headers'])) {
            $_header = array();
            foreach ($this->injectors as $key => $value) {
                $_name = false;
                if ('HTTP_' === substr($key, 0, 5)) {
                    $_name = substr($key, 5);
                } elseif ('X_' == substr($key, 0, 2)) {
                    $_name = substr($key, 2);
                } elseif (in_array($key, array('CONTENT_LENGTH',
                    'CONTENT_MD5',
                    'CONTENT_TYPE'))
                ) {
                    $_name = $key;
                }
                if (!$_name) continue;

                // Set header
                $_header[strtolower(str_replace('_', '-', $_name))] = trim($value);
            }

            if (isset($_SERVER['PHP_AUTH_USER'])) {
                $pass = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
                $_header['authorization'] = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $pass);
            }
            $this->injectors['headers'] = $_header;
            unset($_header);
        }

        if ($name === null) return $this->injectors['headers'];

        $name = strtolower(str_replace('_', '-', $name));
        return isset($this->injectors['headers'][$name]) ? $this->injectors['headers'][$name] : null;
    }

        public function cookie($key = null, $default = null)
    {
        if (!isset($this->injectors['cookies'])) {
            $this->injectors['cookies'] = $_COOKIE;
            $_option = $this->app->cookie;
            foreach ($this->injectors['cookies'] as &$value) {
                if (!$value) continue;

                // Check crypt
                if (strpos($value, 'c:') === 0) {
                    $value = $this->app->cryptor->decrypt(substr($value, 2));
                }

                // Parse signed cookie
                if ($value && strpos($value, 's:') === 0 && $_option['secret']) {
                    $_pos = strrpos($value, '.');
                    $_data = substr($value, 2, $_pos - 2);
                    if (substr($value, $_pos + 1) === hash_hmac('sha1', $_data, $_option['secret'])) {
                        $value = $_data;
                    } else {
                        $value = false;
                    }
                }

                // Parse json cookie
                if ($value && strpos($value, 'j:') === 0) {
                    $value = json_decode(substr($value, 2), true);
                }
            }
        }
        if ($key === null) return $this->injectors['cookies'];
        return isset($this->injectors['cookies'][$key]) ? $this->injectors['cookies'][$key] : $default;
    }

        public function session($key = null, $value = null)
    {
        if ($value !== null) {
            return $_SESSION[$key] = $value;
        } elseif ($key !== null) {
            return isset($_SESSION[$key]) ? $_SESSION[$key] : null;
        }

        return $_SESSION;
    }

        public function body()
    {
        if (!isset($this->injectors['body'])) {
            $this->injectors['body'] = @(string)file_get_contents('php://input');
        }
        return $this->injectors['body'];
    }

        public function pass()
    {
        ob_get_level() && ob_clean();
        throw new Pass();
    }

        protected static function buildAcceptMap($string)
    {
        $_accept = array();

        // Accept header is case insensitive, and whitespace isn’t important
        $accept = strtolower(str_replace(' ', '', $string));
        // divide it into parts in the place of a ","
        $accept = explode(',', $accept);
        foreach ($accept as $a) {
            // the default quality is 1.
            $q = 1;
            // check if there is a different quality
            if (strpos($a, ';q=')) {
                // divide "mime/type;q=X" into two parts: "mime/type" i "X"
                list($a, $q) = explode(';q=', $a);
            }
            // mime-type $a is accepted with the quality $q
            // WARNING: $q == 0 means, that mime-type isn’t supported!
            $_accept[$a] = $q;
        }
        arsort($_accept);
        return $_accept;
    }
}



class Output extends EventEmitter
{
    public static $messages = array(
        // Informational 1xx
        100 => 'Continue',
        101 => 'Switching Protocols',

        // Success 2xx
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',

        // Redirection 3xx
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found', // 1.1
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        // 306 is deprecated but reserved
        307 => 'Temporary Redirect',

        // Client Error 4xx
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed',

        // Server Error 5xx
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        509 => 'Bandwidth Limit Exceeded'
    );

        public $locals = array();

        public $app;

        public function __construct(App $app)
    {
        $this->app = $app;

        parent::__construct(array(
            'status'       => 200,
            'body'         => '',
            'content_type' => 'text/html',
            'length'       => false,
            'charset'      => $this->app->charset,
            'headers'      => array(),
            'cookies'      => array(),
        ));

        $this->locals = & $this->app->locals;
    }

        public function body($content = null)
    {
        if ($content !== null) {
            $this->injectors['body'] = $content;
            $this->injectors['length'] = strlen($this->injectors['body']);
            return $this;
        }

        return $this->injectors['body'];
    }

        public function write($data)
    {
        if (!$data) return $this->injectors['body'];

        $this->injectors['body'] .= $data;
        $this->injectors['length'] = strlen($this->injectors['body']);

        return $this;
    }

        public function end($data = '')
    {
        $this->write($data);
        throw new Stop();
    }

        public function status($status = null)
    {
        if ($status === null) {
            return $this->injectors['status'];
        } elseif (isset(self::$messages[$status])) {
            $this->injectors['status'] = (int)$status;
            return $this;
        } else {
            throw new \Exception('Unknown status :value', array(':value' => $status));
        }
    }

        public function header($name = null, $value = null, $replace = true)
    {
        if ($name === null) {
            return $this->injectors['headers'];
        } elseif (is_array($name)) {
            // Batch set headers
            foreach ($name as $k => $v) {
                // Force replace
                $this->header($k, $v, $replace);
            }
        } else {
            if ($value === null) {
                return $this->injectors['headers'][$name][1];
            } else {
                if (!$replace && !empty($this->injectors['headers'][$name])) {
                    if (is_array($this->injectors['headers'][$name])) {
                        $this->injectors['headers'][$name][] = $value;
                    } else {
                        $this->injectors['headers'][$name] = array($this->injectors['headers'][$name], $value);
                    }
                } else {
                    $this->injectors['headers'][$name] = $value;
                }
                return $this;
            }
        }
    }

        public function charset($charset = null)
    {
        if ($charset) {
            $this->injectors['charset'] = $charset;
            return $this;
        }
        return $this->injectors['charset'];
    }

        public function lastModified($time = null)
    {
        if ($time !== null) {
            if (is_integer($time)) {
                $this->header('Last-Modified', date(DATE_RFC1123, $time));
                if ($time === strtotime($this->app->input->header('If-Modified-Since'))) {
                    $this->app->halt(304);
                }
            }

            return $this;
        }

        return $this->header('Last-Modified');
    }

        public function etag($value = null)
    {
        if ($value !== null) {
            //Set etag value
            $value = 'W/"' . $value . '"';
            $this->header('Etag', $value);

            //Check conditional GET
            if ($etag = $this->app->input->header('If-None-Match')) {
                $etags = preg_split('@\s*,\s*@', $etag);
                if (in_array($value, $etags) || in_array('*', $etags)) {
                    $this->app->halt(304);
                }
            }

            return $this;
        }

        return $this->header('Etag');
    }

        public function expires($time = null)
    {
        if ($time !== null) {
            if (!is_numeric($time) && is_string($time)) {
                $time = strtotime($time);
            } elseif (is_numeric($time) && strlen($time) != 10) {
                $time = time() + (int)$time;
            }

            $this->header('Expires', gmdate(DATE_RFC1123, $time));
            return $this;
        }

        return $this->header('Expires');
    }

        public function contentType($mime_type = null)
    {
        if ($mime_type) {
            if (!strpos($mime_type, '/')) {
                if (!$type = Config::export('mimes')->{$mime_type}[0]) {
                    throw new \InvalidArgumentException("Unknown mime type '{$mime_type}'");
                }
                $mime_type = $type;
            }
            $this->injectors['content_type'] = $mime_type;

            return $this;
        }

        return $this->injectors['content_type'];
    }

        public function cookie($key = null, $value = null, $option = array())
    {
        if ($value !== null) {
            if ($value !== false) {
                $this->injectors['cookies'][$key] = array($value, $option);
            } else {
                unset($this->injectors['cookies'][$key]);
            }
            return $this;
        }

        if ($key === null) return $this->injectors['cookies'];
        return isset($this->injectors['cookies'][$key]) ? $this->injectors['cookies'][$key] : null;
    }

        public function message($status = null)
    {
        !$status && $status = $this->injectors['status'];
        if (isset(self::$messages[$status])) {
            return self::$messages[$status];
        }
        return null;
    }

        public function sendHeader()
    {
        // Check header
        if (headers_sent() === false) {
            $this->emit('header');

            // Send header
            header(sprintf('HTTP/%s %s %s', $this->app->input->protocol(), $this->injectors['status'], $this->message()));

            // Set content type if not exists
            if (!isset($this->injectors['headers']['Content-Type'])) {
                $this->injectors['headers']['Content-Type'] = $this->injectors['content_type'] . '; charset=' . $this->injectors['charset'];
            }

            if (is_numeric($this->injectors['length'])) {
                // Set content length
                $this->injectors['headers']['Content-Length'] = $this->injectors['length'];
            }

            // Loop header to send
            if ($this->injectors['headers']) {
                foreach ($this->injectors['headers'] as $name => $value) {
                    // Multiple line header support
                    if (is_array($value)) {
                        foreach ($value as $v) {
                            header("$name: $v", false);
                        }
                    } else {
                        header("$name: $value");
                    }
                }
            }

            // Set cookie
            if ($this->injectors['cookies']) {
                $_default = $this->app->cookie;
                if (!$_default) {
                    $_default = array(
                        'path'     => '/',
                        'domain'   => null,
                        'secure'   => false,
                        'httponly' => false,
                        'timeout'  => 0,
                        'sign'     => false,
                        'secret'   => '',
                        'encrypt'  => false,
                    );
                }
                // Loop for set
                foreach ($this->injectors['cookies'] as $key => $value) {
                    $_option = (array)$value[1] + $_default;
                    $value = $value[0];
                    // Json object cookie
                    if (is_array($value)) {
                        $value = 'j:' . json_encode($value);
                    }

                    // Sign cookie
                    if ($_option['sign'] && $_default['secret']) {
                        $value = 's:' . $value . '.' . hash_hmac('sha1', $value, $_default['secret']);
                    }

                    // Encrypt
                    if ($_option['encrypt']) {
                        $value = 'c:' . $this->app->cryptor->encrypt($value);
                    }

                    // Set cookie
                    setcookie($key, $value, time() + $_option['timeout'], $_option['path'], $_option['domain'], $_option['secure'], $_option['httponly']);
                }
            }
        }
        return $this;
    }

        public function render($template, array $data = null, array $options = array())
    {
        $this->app->render($template, $data, $options);
        return $this;
    }

        public function compile($template, array $data = null, array $options = array())
    {
        return $this->app->compile($template, $data, $options);
    }

        public function json($data)
    {
        $this->contentType('application/json');
        $this->body(json_encode($data));
        return $this;
    }

        public function jsonp($data, $callback = 'callback')
    {
        $this->contentType('application/javascript');
        $this->body($callback . '(' . json_encode($data) . ');');
        return $this;
    }

        public function xml($data, $root = 'root', $item = 'item')
    {
        $this->contentType('application/xml');
        $this->body(\Pagon\Xml::fromArray($data, $root, $item));
        return $this;
    }

        public function redirect($url, $status = 302)
    {
        $this->injectors['status'] = $status;
        $this->injectors['headers']['location'] = $url == 'back' ? $this->app->input->refer() : $url;
        return $this;
    }

        public function stop()
    {
        throw new Stop();
    }

        public function isCachable()
    {
        return $this->injectors['status'] >= 200 && $this->injectors['status'] < 300 || $this->injectors['status'] == 304;
    }

        public function isEmpty()
    {
        return in_array($this->injectors['status'], array(201, 204, 304));
    }

        public function isOk()
    {
        return $this->injectors['status'] === 200;
    }

        public function isSuccessful()
    {
        return $this->injectors['status'] >= 200 && $this->injectors['status'] < 300;
    }

        public function isRedirect()
    {
        return in_array($this->injectors['status'], array(301, 302, 303, 307));
    }

        public function isForbidden()
    {
        return $this->injectors['status'] === 403;
    }

        public function isNotFound()
    {
        return $this->injectors['status'] === 404;
    }

        public function isClientError()
    {
        return $this->injectors['status'] >= 400 && $this->injectors['status'] < 500;
    }

        public function isServerError()
    {
        return $this->injectors['status'] >= 500 && $this->injectors['status'] < 600;
    }

        public function __toString()
    {
        return $this->injectors['body'];
    }
}
}

namespace Pagon\Cli {

use Pagon\App;
use Pagon\EventEmitter;
use Pagon\Exception\Stop;
use Pagon\Exception\Pass;
use Pagon\Config;



class Input extends EventEmitter
{
        public $app;

        public function __construct(App $app)
    {
        $this->app = $app;

        parent::__construct(array('params' => array()) + $_SERVER);
    }

        public function path()
    {
        if (!isset($this->injectors['path_info'])) {
            $this->injectors['path_info'] = isset($GLOBALS['argv'][1]) && $GLOBALS['argv'][1]{0} != '-' ? $GLOBALS['argv'][1] : '';
        }

        return $this->injectors['path_info'];
    }

        public function root()
    {
        return getcwd();
    }

        public function body()
    {
        if (!isset($this->injectors['body'])) {
            $this->injectors['body'] = @(string)file_get_contents('php://input');
        }
        return $this->injectors['body'];
    }

        public function param($key, $default = null)
    {
        return isset($this->injectors['params'][$key]) ? $this->injectors['params'][$key] : $default;
    }

        public function pass()
    {
        ob_get_level() && ob_clean();
        throw new Pass();
    }
}


class Output extends EventEmitter
{
        public $locals = array();

        public $app;

        public function __construct(App $app)
    {
        $this->app = $app;

        parent::__construct(array(
            'status' => 0,
            'body'   => '',
        ));

        $this->locals = & $this->app->locals;
    }

        public function status($status = null)
    {
        if (is_numeric($status)) {
            $this->injectors['status'] = $status;
            return $this;
        }
        return $this->injectors['status'];
    }

        public function body($content = null)
    {
        if ($content !== null) {
            $this->injectors['body'] = $content;
            return $this;
        }

        return $this->injectors['body'];
    }

        public function write($data)
    {
        if (!$data) return $this->injectors['body'];

        $this->injectors['body'] .= $data;

        return $this;
    }

        public function end($data = '')
    {
        $this->write($data);
        throw new Stop();
    }

        public function isOk()
    {
        return $this->injectors['status'] === 0;
    }

        public function __toString()
    {
        return $this->injectors['body'];
    }
}
}

namespace Pagon\Engine {

use Everzet\Jade\Jade as Jader;
use Everzet\Jade\Dumper\PHPDumper;
use Everzet\Jade\Parser;
use Everzet\Jade\Lexer\Lexer;



class Jade
{
        protected $options = array(
        'compile_dir' => '/tmp'
    );

    protected $engine;

        public function __construct(array $options = array())
    {
        $this->options = $options + $this->options;

        $dumper = new PHPDumper();
        $parser = new Parser(new Lexer());

        $this->engine = new Jader($parser, $dumper, $this->options['compile_dir']);
    }

        public function render($path, $data, $dir)
    {
        $file = $this->engine->cache($dir . $path);

        ob_start();
        if ($data) {
            extract((array)$data);
        }
        include($file);

        return ob_get_clean();
    }
}

}

namespace Pagon\Exception {


class Pass extends \Exception
{
}


class Stop extends \Exception
{
}

}

namespace  {

use Pagon\App;
use Pagon\Cache;
use Pagon\Paginator;
use Pagon\Url;


function app()
{
    return App::self();
}

function get($key, $default = null)
{
    return App::self()->input->query($key, $default);
}

function post($key, $default = null)
{
    return App::self()->input->data($key, $default);
}

function url($path, array $query = null, $full = false)
{
    return Url::to($path, $query, $full);
}

function assert_url($path, array $query = null, $full = false)
{
    return Url::asset($path, $query, $full);
}

function current_url(array $query = null, $full = false)
{
    return Url::current($query, $full);
}

function page($pattern = "/page/(:num)", $total = 0, $size = 10, $displays = 10)
{
    return Paginator::create($pattern, $total, $size, $displays);
}

function config($key, $value = null)
{
    if ($value === null) {
        return App::self()->get($key);
    }
    App::self()->set($key, $value);
}

function cache($name, $key = null, $value = null)
{
    if ($key === null) {
        return Cache::dispense($name);
    } else if ($value !== null) {
        return Cache::dispense($name)->set($key, $value);
    } else {
        return Cache::dispense($name)->get($key);
    }
}

function path($file)
{
    return App::self()->path($file);
}

function load($file)
{
    return App::self()->load($file);
}

function mount($path, $dir = null)
{
    App::self()->mount($path, $dir);
}

function local($key, $value = null)
{
    $app = App::self();
    if ($value === null) {
        return isset($app->locals[$key]) ? $app->locals[$key] : null;
    }

    $app->locals[$key] = $value;
}

function render($path, array $data = null, array $options = array())
{
    App::self()->render($path, $data, $options);
}


function array_get($arr, $index)
{
    return isset($arr[$index]) ? $arr[$index] : null;
}

function array_pluck($array, $key)
{
    return array_map(function ($v) use ($key) {
        return is_object($v) ? $v->$key : $v[$key];

    }, $array);
}

function array_only($array, $keys)
{
    return array_intersect_key($array, array_flip((array)$keys));
}

function array_except($array, $keys)
{
    return array_diff_key($array, array_flip((array)$keys));
}

function magic_quotes()
{
    return function_exists('get_magic_quotes_gpc') and get_magic_quotes_gpc();
}

function start_with($haystack, $needle)
{
    return strpos($haystack, $needle) === 0;
}

function end_with($haystack, $needle)
{
    return $needle == substr($haystack, strlen($haystack) - strlen($needle));
}

function str_contains($haystack, $needle)
{
    foreach ((array)$needle as $n) {
        if (strpos($haystack, $n) !== false) return true;
    }

    return false;
}

function human_size($size)
{
    $units = array('Bytes', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB');
    return @round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . ' ' . $units[$i];
}
}

