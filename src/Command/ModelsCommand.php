<?php
/**
 * Laravel IDE Helper Generator
 *
 * @author    Barry vd. Heuvel <barryvdh@gmail.com>
 * @copyright 2014 Barry vd. Heuvel / Fruitcake Studio (http://www.fruitcakestudio.nl)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      https://github.com/barryvdh/laravel-ide-helper
 */

namespace CarterZenk\EloquentIdeHelper\Command;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\ClassLoader\ClassMapGenerator;
use Barryvdh\Reflection\DocBlock;
use Barryvdh\Reflection\DocBlock\Context;
use Barryvdh\Reflection\DocBlock\Tag;
use Barryvdh\Reflection\DocBlock\Serializer as DocBlockSerializer;

/**
 * A command to generate autocomplete information for your IDE
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */
class ModelsCommand extends Command
{
    protected $properties = [];
    protected $methods = [];
    protected $write = false;
    protected $ignore;
    protected $dirs;
    protected $filename;
    protected $reset;
    protected $settings;
    protected $verbosity;

    /**
     * @param string|null $name
     * @param array $settings
     */
    public function __construct(string $name = null, array $settings = [])
    {
        $this->settings = $settings;
        $this->dirs = $this->fromSettings('modelDirectories', []);
        $this->filename = $this->fromSettings('outputFile', '_ide_helper_models.php');
        $this->ignore = $this->fromSettings('ignore', []);

        parent::__construct($name);
    }

    /**
     * @param $offset
     * @param $default
     * @return mixed
     */
    protected function fromSettings($offset, $default)
    {
        if (isset($this->settings[$offset])) {
            return $this->settings[$offset];
        } else {
            return $default;
        }
    }

    /**
     * @inheritdoc
     */
    public function configure()
    {
        $this->setName('ide-helper:models');
        $this->setDescription('Model IDE Helper');
        $this->setHelp('Generates auto-completion for models.');

        $this->addArgument('model', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Which models to include', []);
        $this->addOption('filename', 'F', InputOption::VALUE_OPTIONAL, 'The path to the helper file', $this->filename);
        $this->addOption('dir', 'D', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The model dirs', []);
        $this->addOption('ignore', 'I', InputOption::VALUE_OPTIONAL, 'Which models to ignore', "");
        $this->addOption('write', 'W', InputOption::VALUE_NONE, 'Write to Model file');
        $this->addOption('nowrite', 'N', InputOption::VALUE_NONE, 'Don\'t write to Model file');
        $this->addOption('reset', 'R', InputOption::VALUE_NONE, 'Remove the original phpdocs instead of appending');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->verbosity = $output->getVerbosity();
        $this->dirs = array_merge($this->dirs, $input->getOption('dir'));
        $this->write = $input->getOption('write');
        $this->reset = $input->getOption('reset');

        $ignore = array_merge($this->ignore, explode(",", $input->getOption('ignore')));
        $filename = $input->getOption('filename');
        $model = $input->getArgument('model');

        $io = new SymfonyStyle($input, $output);

        //If filename is default and Write is not specified, ask what to do
        if (!$this->write && $filename === $this->filename && !$input->getOption('nowrite')) {
            $overwriteModels = $io->confirm(
                "Do you want to overwrite the existing model files? Choose no to write to $this->filename instead.",
                false
            );

            if ($overwriteModels) {
                $this->write = true;
            }
        }

        $content = $this->generateDocs($io, $model, $ignore);

        if (!$this->write) {
            if (file_put_contents($filename, $content, 0) != false) {
                $io->success("Model information was written to $filename");
            } else {
                $io->error("Failed to write model information to $filename");
                return 1;
            }
        }

        return null;
    }

    /**
     * @param StyleInterface $io
     * @param $loadModels
     * @param array $ignore
     * @return string|OutputInterface
     */
    protected function generateDocs(StyleInterface $io, $loadModels, $ignore)
    {
        $docs = "<?php
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */
\n\n";

        $hasDoctrine = interface_exists('Doctrine\DBAL\Driver');

        if (empty($loadModels)) {
            $models = $this->loadModels();
        } else {
            $models = array();
            foreach ($loadModels as $model) {
                $models = array_merge($models, explode(',', $model));
            }
        }

        foreach ($models as $name) {
            if (in_array($name, $ignore)) {
                if ($this->verbosity >= OutputInterface::VERBOSITY_VERBOSE) {
                    $io->text("Ignoring model '$name'");
                }
                continue;
            }
            $this->properties = array();
            $this->methods = array();
            if (class_exists($name)) {
                try {
                    // handle abstract classes, interfaces, ...
                    $reflectionClass = new \ReflectionClass($name);

                    if (!$reflectionClass->isSubclassOf('Illuminate\Database\Eloquent\Model')) {
                        continue;
                    }

                    if ($this->verbosity >= OutputInterface::VERBOSITY_VERBOSE) {
                        $io->text("Loading model '$name'");
                    }

                    if (!$reflectionClass->isInstantiable()) {
                        // ignore abstract class or interface
                        continue;
                    }

                    /** @var Model $model */
                    $model = $reflectionClass->newInstanceWithoutConstructor();

                    if ($hasDoctrine) {
                        $this->getPropertiesFromTable($model);
                    }

                    if (method_exists($model, 'getCasts')) {
                        $this->castPropertiesType($model);
                    }

                    $this->getPropertiesFromMethods($model);
                    $docs .= $this->createPhpDocs($io, $name);
                    $ignore[] = $name;
                } catch (\Exception $e) {
                    $io->error([
                        "Exception: " . $e->getMessage(),
                        "Could not analyze class $name"
                    ]);
                }
            }
        }

        if (!$hasDoctrine) {
            $io->warning([
                'Warning: `"doctrine/dbal": "~2.3"` is required to load database information.',
                'Please require that in your composer.json and run `composer update`.'
            ]);
        }

        return $docs;
    }

    /**
     * @return array
     */
    protected function loadModels()
    {
        $models = array();
        foreach ($this->dirs as $dir) {
            if (file_exists($dir)) {
                foreach (ClassMapGenerator::createMap($dir) as $model => $path) {
                    $models[] = $model;
                }
            }
        }
        return $models;
    }

    /**
     * cast the properties's type from $casts.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function castPropertiesType($model)
    {
        $casts = $model->getCasts();
        foreach ($casts as $name => $type) {
            switch ($type) {
                case 'boolean':
                case 'bool':
                    $realType = 'boolean';
                    break;
                case 'string':
                    $realType = 'string';
                    break;
                case 'array':
                case 'json':
                    $realType = 'array';
                    break;
                case 'object':
                    $realType = 'object';
                    break;
                case 'int':
                case 'integer':
                case 'timestamp':
                    $realType = 'integer';
                    break;
                case 'real':
                case 'double':
                case 'float':
                    $realType = 'float';
                    break;
                case 'date':
                case 'datetime':
                    $realType = '\Carbon\Carbon';
                    break;
                case 'collection':
                    $realType = '\Illuminate\Support\Collection';
                    break;
                default:
                    $realType = 'mixed';
                    break;
            }

            if (!isset($this->properties[$name])) {
                continue;
            } else {
                $this->properties[$name]['type'] = $this->getTypeOverride($realType);
            }
        }
    }

    /**
     * Returns the overide type for the give type.
     *
     * @param string $type
     * @return string
     */
    protected function getTypeOverride($type)
    {
        $typeOverrides = $this->fromSettings('typeOverrides', []);

        return isset($typeOverrides[$type]) ? $typeOverrides[$type] : $type;
    }

    /**
     * Load the properties from the database table.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromTable(Model $model)
    {
        $table = $model->getConnection()->getTablePrefix() . $model->getTable();
        $schema = $model->getConnection()->getDoctrineSchemaManager();
        $databasePlatform = $schema->getDatabasePlatform();
        $databasePlatform->registerDoctrineTypeMapping('enum', 'string');

        $platformName = $databasePlatform->getName();
        $customDbTypes = $this->fromSettings("customDbTypes", []);

        $customTypes = isset($customDbTypes[$platformName]) ? $customDbTypes[$platformName] : [];

        foreach ($customTypes as $yourTypeName => $doctrineTypeName) {
            $databasePlatform->registerDoctrineTypeMapping($yourTypeName, $doctrineTypeName);
        }

        $database = null;
        if (strpos($table, '.')) {
            list($database, $table) = explode('.', $table);
        }

        $columns = $schema->listTableColumns($table, $database);

        if ($columns) {
            foreach ($columns as $column) {
                $name = $column->getName();
                if (in_array($name, $model->getDates())) {
                    $type = '\Carbon\Carbon';
                } else {
                    $type = $column->getType()->getName();
                    switch ($type) {
                        case 'string':
                        case 'text':
                        case 'date':
                        case 'time':
                        case 'guid':
                        case 'datetimetz':
                        case 'datetime':
                            $type = 'string';
                            break;
                        case 'integer':
                        case 'bigint':
                        case 'smallint':
                            $type = 'integer';
                            break;
                        case 'decimal':
                        case 'float':
                            $type = 'float';
                            break;
                        case 'boolean':
                            $type = 'boolean';
                            break;
                        default:
                            $type = 'mixed';
                            break;
                    }
                }

                $comment = $column->getComment();
                $this->setProperty($name, $type, true, true, $comment);
                $this->setMethod(
                    Str::camel("where_" . $name),
                    '\Illuminate\Database\Query\Builder|\\' . get_class($model),
                    array('$value')
                );
            }
        }
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromMethods($model)
    {
        $methods = get_class_methods($model);
        if ($methods) {
            foreach ($methods as $method) {
                if (Str::startsWith($method, 'get') && Str::endsWith(
                        $method,
                        'Attribute'
                    ) && $method !== 'getAttribute'
                ) {
                    //Magic get<name>Attribute
                    $name = Str::snake(substr($method, 3, -9));
                    if (!empty($name)) {
                        $reflection = new \ReflectionMethod($model, $method);
                        $type = $this->getReturnTypeFromDocBlock($reflection);
                        $this->setProperty($name, $type, true, null);
                    }
                } elseif (Str::startsWith($method, 'set') && Str::endsWith(
                        $method,
                        'Attribute'
                    ) && $method !== 'setAttribute'
                ) {
                    //Magic set<name>Attribute
                    $name = Str::snake(substr($method, 3, -9));
                    if (!empty($name)) {
                        $this->setProperty($name, null, null, true);
                    }
                } elseif (Str::startsWith($method, 'scope') && $method !== 'scopeQuery') {
                    //Magic set<name>Attribute
                    $name = Str::camel(substr($method, 5));
                    if (!empty($name)) {
                        $reflection = new \ReflectionMethod($model, $method);
                        $args = $this->getParameters($reflection);
                        //Remove the first ($query) argument
                        array_shift($args);
                        $this->setMethod($name, '\Illuminate\Database\Query\Builder|\\' . $reflection->class, $args);
                    }
                } elseif (!method_exists('Illuminate\Database\Eloquent\Model', $method)
                    && !Str::startsWith($method, 'get')
                ) {
                    //Use reflection to inspect the code, based on Illuminate/Support/SerializableClosure.php
                    $reflection = new \ReflectionMethod($model, $method);

                    $file = new \SplFileObject($reflection->getFileName());
                    $file->seek($reflection->getStartLine() - 1);

                    $code = '';
                    while ($file->key() < $reflection->getEndLine()) {
                        $code .= $file->current();
                        $file->next();
                    }
                    $code = trim(preg_replace('/\s\s+/', '', $code));
                    $begin = strpos($code, 'function(');
                    $code = substr($code, $begin, strrpos($code, '}') - $begin + 1);

                    foreach (array(
                                 'hasMany',
                                 'hasManyThrough',
                                 'belongsToMany',
                                 'hasOne',
                                 'belongsTo',
                                 'morphOne',
                                 'morphTo',
                                 'morphMany',
                                 'morphToMany'
                             ) as $relation) {
                        $search = '$this->' . $relation . '(';
                        if ($pos = stripos($code, $search)) {
                            //Resolve the relation's model to a Relation object.
                            $relationObj = $model->$method();

                            if ($relationObj instanceof Relation) {
                                $relatedModel = '\\' . get_class($relationObj->getRelated());

                                $relations = ['hasManyThrough', 'belongsToMany', 'hasMany', 'morphMany', 'morphToMany'];
                                if (in_array($relation, $relations)) {
                                    //Collection or array of models (because Collection is Arrayable)
                                    $this->setProperty(
                                        $method,
                                        $this->getCollectionClass($relatedModel) . '|' . $relatedModel . '[]',
                                        true,
                                        null
                                    );
                                } elseif ($relation === "morphTo") {
                                    // Model isn't specified because relation is polymorphic
                                    $this->setProperty(
                                        $method,
                                        '\Illuminate\Database\Eloquent\Model|\Eloquent',
                                        true,
                                        null
                                    );
                                } else {
                                    //Single model is returned
                                    $this->setProperty($method, $relatedModel, true, null);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param string $name
     * @param string|null $type
     * @param bool|null $read
     * @param bool|null $write
     * @param string|null $comment
     */
    protected function setProperty($name, $type = null, $read = null, $write = null, $comment = '')
    {
        if (!isset($this->properties[$name])) {
            $this->properties[$name] = array();
            $this->properties[$name]['type'] = 'mixed';
            $this->properties[$name]['read'] = false;
            $this->properties[$name]['write'] = false;
            $this->properties[$name]['comment'] = (string) $comment;
        }
        if ($type !== null) {
            $this->properties[$name]['type'] = $this->getTypeOverride($type);
        }
        if ($read !== null) {
            $this->properties[$name]['read'] = $read;
        }
        if ($write !== null) {
            $this->properties[$name]['write'] = $write;
        }
    }

    /**
     * @param $name
     * @param string $type
     * @param array $arguments
     */
    protected function setMethod($name, $type = '', $arguments = array())
    {
        $methods = array_change_key_case($this->methods, CASE_LOWER);

        if (!isset($methods[strtolower($name)])) {
            $this->methods[$name] = array();
            $this->methods[$name]['type'] = $type;
            $this->methods[$name]['arguments'] = $arguments;
        }
    }

    /**
     * @param StyleInterface $io
     * @param string $class
     * @return string
     * @throws \Exception
     */
    protected function createPhpDocs(StyleInterface $io, $class)
    {
        $reflection = new \ReflectionClass($class);
        $namespace = $reflection->getNamespaceName();
        $classname = $reflection->getShortName();
        $originalDoc = $reflection->getDocComment();

        $phpdoc = $this->getDocBlock($reflection, $namespace);

        // Override computed doc blocks if an existing doc block is present in the model.
        $propertyOverrides = array();
        $methodOverrides = array();
        foreach ($phpdoc->getTags() as $tag) {
            $name = $tag->getName();
            if ($name == "property" || $name == "property-read" || $name == "property-write") {
                $propertyOverrides[] = $tag->getVariableName();
            } elseif ($name == "method") {
                $methodOverrides[] = $tag->getMethodName();
            }
        }

        // Append computed property tags to doc block.
        $this->appendPropertyTags($phpdoc, $propertyOverrides);

        // Append computed method tags to doc block.
        $this->appendMethodTags($phpdoc, $methodOverrides);

        if ($this->write && !$phpdoc->getTagsByName('mixin')) {
            $phpdoc->appendTag(Tag::createInstance("@mixin \\Eloquent", $phpdoc));
        }

        $serializer = new DocBlockSerializer();
        $serializer->getDocComment($phpdoc);
        $docComment = $serializer->getDocComment($phpdoc);

        if ($this->write) {
            $filename = $reflection->getFileName();
            if (is_file($filename)) {
                $contents = file_get_contents($filename);
            } else {
                throw new \Exception("File does not exist at path {$filename}");
            }
            if ($originalDoc) {
                $contents = str_replace($originalDoc, $docComment, $contents);
            } else {
                $needle = "class {$classname}";
                $replace = "{$docComment}\nclass {$classname}";
                $pos = strpos($contents, $needle);
                if ($pos !== false) {
                    $contents = substr_replace($contents, $replace, $pos, strlen($needle));
                }
            }
            if (file_put_contents($filename, $contents, 0)) {
                $io->text("Written new phpDocBlock to $filename");
            }
        }

        $output = "namespace {$namespace}{\n{$docComment}\n\tclass {$classname} extends \Eloquent {}\n}\n\n";
        return $output;
    }

    protected function appendPropertyTags(DocBlock $phpDoc, array $overrides)
    {
        foreach ($this->properties as $name => $property) {
            $name = "\$$name";
            if (in_array($name, $overrides)) {
                continue;
            }
            if ($property['read'] && $property['write']) {
                $attr = 'property';
            } elseif ($property['write']) {
                $attr = 'property-write';
            } else {
                $attr = 'property-read';
            }

            if ($this->hasCamelCaseModelProperties()) {
                $name = Str::camel($name);
            }

            $tagLine = trim("@{$attr} {$property['type']} {$name} {$property['comment']}");
            $tag = Tag::createInstance($tagLine, $phpDoc);
            $phpDoc->appendTag($tag);
        }
    }

    protected function appendMethodTags(DocBlock $phpDoc, array $overrides)
    {
        foreach ($this->methods as $name => $method) {
            if (in_array($name, $overrides)) {
                continue;
            }
            $arguments = implode(', ', $method['arguments']);
            $tag = Tag::createInstance("@method static {$method['type']} {$name}({$arguments})", $phpDoc);
            $phpDoc->appendTag($tag);
        }
    }

    /**
     * @param \ReflectionClass $reflection
     * @param string $namespace
     * @return DocBlock
     */
    protected function getDocBlock(\ReflectionClass $reflection, $namespace)
    {
        if ($this->reset) {
            $phpDoc = new DocBlock('', new Context($namespace));
        } else {
            $phpDoc = new DocBlock($reflection, new Context($namespace));
        }

        if (!$phpDoc->getText()) {
            $phpDoc->setText($reflection->getName());
        }

        return $phpDoc;
    }

    /**
     * Get the parameters and format them correctly
     *
     * @param \ReflectionMethod $method
     * @return array
     */
    public function getParameters(\ReflectionMethod $method)
    {
        //Loop through the default values for paremeters, and make the correct output string
        $params = array();
        $paramsWithDefault = array();
        /** @var \ReflectionParameter $param */
        foreach ($method->getParameters() as $param) {
            $paramClass = $param->getClass();
            $paramStr = (!is_null($paramClass) ? '\\' . $paramClass->getName() . ' ' : '') . '$' . $param->getName();
            $params[] = $paramStr;
            if ($param->isOptional() && $param->isDefaultValueAvailable()) {
                $default = $param->getDefaultValue();
                if (is_bool($default)) {
                    $default = $default ? 'true' : 'false';
                } elseif (is_array($default)) {
                    $default = 'array()';
                } elseif (is_null($default)) {
                    $default = 'null';
                } elseif (is_int($default)) {
                    //$default = $default;
                } else {
                    $default = "'" . trim($default) . "'";
                }
                $paramStr .= " = $default";
            }
            $paramsWithDefault[] = $paramStr;
        }
        return $paramsWithDefault;
    }

    /**
     * Determine a model classes' collection type.
     *
     * @see http://laravel.com/docs/eloquent-collections#custom-collections
     * @param string $className
     * @return string
     */
    private function getCollectionClass($className)
    {
        // Return something in the very very unlikely scenario the model doesn't
        // have a newCollection() method.
        if (!method_exists($className, 'newCollection')) {
            return '\Illuminate\Database\Eloquent\Collection';
        }

        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $className;
        return '\\' . get_class($model->newCollection());
    }

    /**
     * @return bool
     */
    protected function hasCamelCaseModelProperties()
    {
        return $this->fromSettings('modelCamelCaseProperties', false);
    }

    /**
     * Get method return type based on it DocBlock comment
     *
     * @param \ReflectionMethod $reflection
     *
     * @return null|string
     */
    protected function getReturnTypeFromDocBlock(\ReflectionMethod $reflection)
    {
        $type = null;
        $phpdoc = new DocBlock($reflection);

        if ($phpdoc->hasTag('return')) {
            $type = $phpdoc->getTagsByName('return')[0]->getContent();
        }

        return $type;
    }
}
