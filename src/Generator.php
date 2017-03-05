<?php
/**
 * Laravel IDE Helper Generator
 *
 * @author    Barry vd. Heuvel <barryvdh@gmail.com>
 * @copyright 2014 Barry vd. Heuvel / Fruitcake Studio (http://www.fruitcakestudio.nl)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      https://github.com/barryvdh/laravel-ide-helper
 */

namespace CarterZenk\EloquentIdeHelper;

class Generator
{
    /**
     * @var array
     */
    protected $aliases = [
        'Eloquent' => 'Illuminate\Database\Eloquent\Model'
    ];

    /**
     * @var array
     */
    protected $extra = [
        'Eloquent' => [
            'Illuminate\Database\Eloquent\Builder',
            'Illuminate\Database\Query\Builder'
        ]
    ];

    /**
     * @var array
     */
    protected $magic = [];

    /**
     * @var array
     */
    protected $interfaces = [];

    /**
     * @param array $extra
     * @param array $magic
     * @param array $interfaces
     */
    public function __construct(array $extra = [], array $magic = [], array $interfaces = [])
    {
        $this->extra = array_merge($this->extra, $extra);
        $this->magic = array_merge($this->magic, $magic);
        $this->interfaces = array_merge($this->interfaces, $interfaces);

        // Make all interface classes absolute
        foreach ($this->interfaces as &$interface) {
            $interface = '\\' . ltrim($interface, '\\');
        }
    }

    /**
     * Generate the helper file contents;
     *
     * @param  string  $format  The format to generate the helper in (php/json)
     * @return string
     */
    public function generate($format = 'php')
    {
        // Check if the generator for this format exists
        $method = 'generate'.ucfirst($format).'Helper';
        if (method_exists($this, $method)) {
            return $this->$method();
        }

        return $this->generatePhpHelper();
    }

    /**
     * Generate the helper file contents in php;
     *
     * @return string
     */
    public function generatePhpHelper()
    {
        $docs = "<?php
/**
 * A helper file for illuminate/database, to provide autocomplete information to your IDE
 * Generated on ".date('Y-m-d H:i:s').".
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */
namespace  {
    exit(\"This file should not be included, only analyzed by your IDE\");\n\n";

        foreach ($this->getNamespaces() as $namespace => $aliases) {
            foreach ($aliases as $alias) {
                /** @var Alias $alias */
                $docs .= "\t{$alias->getClasstype()} {$alias->getShortName()} extends {$alias->getExtends()} {";

                if ($namespace === '\Illuminate\Database\Eloquent') {
                    foreach ($alias->getMethods() as $method) {
                        /** @var Method $method */

                        // Write method doc block.
                        $docComment = $method->getDocComment();
                        $docs .= "\n{$docComment}\n";

                        // Write method declaration.
                        $docs .= "\t\tpublic static function {$method->getName()}({$method->getParamsWithDefault()})\n";
                        $docs .= "\t\t{\n";

                        if ($method->getDeclaringClass() !== $method->getRoot()) {
                            $docs .= "\t\t\t//Method inherited from {$method->getDeclaringClass()}\n";
                        }

                        $return = $method->shouldReturn() ? 'return ' : '';
                        $docs .= "\t\t\t{$return}{$method->getRoot()}::{$method->getName()}({$method->getParams()});\n";
                        $docs .= "\t\t}\n";
                    }
                }

                $docs .= "\n\t}\n\n";
            }
        }

        $docs .= "}\n";

        return $docs;
    }

    /**
     * Generate the helper file contents in json;
     *
     * @return string
     */
    public function generateJsonHelper()
    {
        $classes = array();
        foreach ($this->getNamespaces() as $aliases) {
            foreach ($aliases as $alias) {
                /** @var Alias $alias */
                $functions = array();
                foreach ($alias->getMethods() as $method) {
                    /** @var Method $method */
                    $functions[$method->getName()] = '('. $method->getParamsWithDefault().')';
                }
                $classes[$alias->getAlias()] = array(
                    'functions' => $functions,
                );
            }
        }

        $flags = JSON_FORCE_OBJECT;
        if (defined('JSON_PRETTY_PRINT')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode(array(
            'php' => array(
                'classes' => $classes,
            ),
        ), $flags);
    }

    /**
     * Find all namespaces that are valid for us to render
     *
     * @return array
     */
    protected function getNamespaces()
    {
        $namespaces = array();

        // Get all aliases
        foreach ($this->getAliases() as $name => $facade) {
            $magicMethods = array_key_exists($name, $this->magic) ? $this->magic[$name] : array();
            $alias = new Alias($name, $facade, $magicMethods, $this->interfaces);
            if ($alias->isValid()) {
                //Add extra methods, from other classes (magic static calls)
                if (array_key_exists($name, $this->extra)) {
                    $alias->addClass($this->extra[$name]);
                }

                $namespace = $alias->getExtendsNamespace() ?: $alias->getNamespace();
                if (!isset($namespaces[$namespace])) {
                    $namespaces[$namespace] = array();
                }
                $namespaces[$namespace][] = $alias;
            }
        }

        return $namespaces;
    }

    /**
     * Find all aliases that are valid for us to render.
     *
     * @return array
     */
    protected function getAliases()
    {
        // Only return the ones that actually exist
        return array_filter($this->aliases, function ($alias) {
            return class_exists($alias);
        });
    }
}