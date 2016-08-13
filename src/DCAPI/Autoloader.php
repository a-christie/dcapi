<?php
/**
 * This file is part of DCAPI
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DCAPI;

    // DCAPI-wide constants
    define('DCAPI\FEED_ITEMS', '^feed');                    // name of "all items" file (stored in blobCache)
    define('DCAPI\FEED_TERMS', 'feedTerms');                // field name for feed terms
    define('DCAPI\REMOVE_MARKER', "\e");                    // alone it indicates to remove the field - signalled by {{remove}}
    define('DCAPI\RETAIN_MARKER', "\e\e");                  // if in the field it indicates to retain the field value in the position given (maximum once) - signalled by {{retain}}
    define('DCAPI\HIDDEN', '^hidden');                      // used as a special marker for hidden fields
    define('DCAPI\HIDDENBLOB', '^hiddenBlob');              // used as a special marker for hidden blobs
    define('DCAPI\REPEAT', '^repeat');                      // used as a special marker for repeating fields
    define('DCAPI\CONFIGFILE', '^blob');                    // filename of special DCAPI blob config file
    define('DCAPI\INDEXFILE', '^index');                    // filename of special DCAPI index config file
    define('DCAPI\TOUCHFILE', '^touch');                    // filename of special "touch" file
    define('DCAPI\LOGFILE', '^log');                        // filename of special "log" file
    define('DCAPI\QUEUEEMPTYFILE', '^queueEmpty');          // filename of special "queueEmpty" file
    define('DCAPI\HOMEROUTE', '^home');                     // special internal route for home page
    define('DCAPI\SEARCHROUTE', '^search');                 // special internal route for search page

    // lexical constants (DCAPI\Handlebars)
    define('DCAPI\F_STRING', 1);
    define('DCAPI\F_COMMENT', 2);
    define('DCAPI\F_TRIMBEFORE', 3);
    define('DCAPI\F_TRIMAFTER', 4);
    define('DCAPI\F_BLOCK', 5);
    define('DCAPI\F_MUSTACHE', 6);
    define('DCAPI\F_PARTIAL', 7);
    define('DCAPI\F_MUSTACHERAW', 8);

class Autoloader
{

    private $_baseDir;

    protected function __construct($baseDir = null)
    {
        if ($baseDir === null) {
            $this->_baseDir = realpath(__DIR__ . '/..');
        } else {
            $this->_baseDir = rtrim($baseDir, '/');
        }
    }

    public static function register($baseDir = null)        // Register a new instance as an SPL autoloader.
    {
        $loader = new self($baseDir);
        spl_autoload_register(array($loader, 'autoload'));
        return $loader;
    }

    public function autoload($class)                        // Autoload DCAPI classes.
    {

        if ($class[0] !== '\\') {
            $class = '\\' . $class;
        }

/*        if (strpos($class, 'DCAPI') !== 1) {
            return;
        }
*/
        $file = sprintf(
            '%s%s.php',
            $this->_baseDir,
            str_replace('\\', '/', $class)
        );

        if (is_file($file)) {
            include $file;
        }
    }

}
