<?php

namespace Yab\CrudMaker\Console;

use Config;
use Exception;
use Illuminate\Console\AppNamespaceDetectorTrait;
use Illuminate\Console\Command;
use Yab\CrudMaker\Generators\CrudGenerator;
use Yab\CrudMaker\Generators\DatabaseGenerator;
use Yab\CrudMaker\Services\ValidatorService;

class CrudMaker extends Command
{
    use AppNamespaceDetectorTrait;

    /**
     * Column Types.
     *
     * @var array
     */
    public $columnTypes = [
        'bigIncrements',
        'increments',
        'bigInteger',
        'binary',
        'boolean',
        'char',
        'date',
        'dateTime',
        'decimal',
        'double',
        'enum',
        'float',
        'integer',
        'ipAddress',
        'json',
        'jsonb',
        'longText',
        'macAddress',
        'mediumInteger',
        'mediumText',
        'morphs',
        'smallInteger',
        'string',
        'string',
        'text',
        'time',
        'tinyInteger',
        'timestamp',
        'uuid',
    ];

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'crudmaker:new {table}
        {--api : Creates an API Controller and Routes}
        {--apiOnly : Creates only the API Controller and Routes}
        {--ui= : Select one of bootstrap|semantic for the UI}
        {--serviceOnly : Does not generate a Controller or Routes}
        {--withFacade : Creates a facade that can be bound in your app to access the CRUD service}
        {--migration : Generates a migration file}
        {--schema= : Basic schema support ie: id,increments,name:string,parent_id:integer}
        {--relationships= : Define the relationship ie: hasOne|App\Comment|comment,hasOne|App\Rating|rating or relation|class|column (without the _id)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a magical CRUD for a table with options for: Migration, API, UI, Schema and even Relationships';

    /**
     * Generate a CRUD stack.
     *
     * @return mixed
     */
    public function handle()
    {
        $validator = new ValidatorService();
        $section = '';
        $splitTable = [];

        $appPath = app()->path();
        $basePath = app()->basePath();
        $appNamespace = $this->getAppNamespace();
        $framework = ucfirst('Laravel');

        if (stristr(get_class(app()), 'Lumen')) {
            $framework = ucfirst('lumen');
        }

        $table = ucfirst(str_singular($this->argument('table')));

        $validator->validateSchema($this);
        $validator->validateOptions($this);

        $config = [
            'framework'                  => $framework,
            'bootstrap'                  => false,
            'semantic'                   => false,
            'template_source'            => '',
            '_sectionPrefix_'            => '',
            '_sectionTablePrefix_'       => '',
            '_sectionRoutePrefix_'       => '',
            '_sectionNamespace_'         => '',
            '_path_facade_'              => $appPath.'/Facades',
            '_path_service_'             => $appPath.'/Services',
            '_path_repository_'          => $appPath.'/Repositories/_table_',
            '_path_model_'               => $appPath.'/Repositories/_table_',
            '_path_controller_'          => $appPath.'/Http/Controllers/',
            '_path_api_controller_'      => $appPath.'/Http/Controllers/Api',
            '_path_views_'               => $basePath.'/resources/views',
            '_path_tests_'               => $basePath.'/tests',
            '_path_request_'             => $appPath.'/Http/Requests/',
            '_path_routes_'              => $appPath.'/Http/routes.php',
            '_path_api_routes_'          => $appPath.'/Http/api-routes.php',
            '_path_migrations_'          => $basePath.'/database/migrations',
            'routes_prefix'              => '',
            'routes_suffix'              => '',
            '_app_namespace_'            => 'App\\',
            '_namespace_services_'       => $appNamespace.'Services',
            '_namespace_facade_'         => $appNamespace.'Facades',
            '_namespace_repository_'     => $appNamespace.'Repositories\_table_',
            '_namespace_model_'          => $appNamespace.'Repositories\_table_',
            '_namespace_controller_'     => $appNamespace.'Http\Controllers',
            '_namespace_api_controller_' => $appNamespace.'Http\Controllers\Api',
            '_namespace_request_'        => $appNamespace.'Http\Requests',
            '_table_name_'               => str_plural(strtolower($table)),
            '_lower_case_'               => strtolower($table),
            '_lower_casePlural_'         => str_plural(strtolower($table)),
            '_camel_case_'               => ucfirst(camel_case($table)),
            '_camel_casePlural_'         => str_plural(camel_case($table)),
            '_ucCamel_casePlural_'       => ucfirst(str_plural(camel_case($table))),
        ];

        if ($this->option('ui')) {
            $config[$this->option('ui')] = true;
        }

        $config['schema'] = $this->option('schema');
        $config['relationships'] = $this->option('relationships');
        $config['template_source'] = $this->getTemplates($framework, $basePath);

        if (stristr($table, '_')) {
            $splitTable = explode('_', $table);
            $table = $splitTable[1];
            $section = $splitTable[0];
            $config = $this->configASectionedCRUD($config, $section, $table, $splitTable);
        } else {
            $config = array_merge($config, app('config')->get('crudmaker.single', []));
            $config = $this->setConfig($config, $section, $table);
        }

        $this->createCRUD($config, $section, $table, $splitTable);

        $this->info("\nYou may wish to add this as your testing database:\n");
        $this->comment("'testing' => [ 'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '' ],");
        $this->info("\n".'You now have a working CRUD for '.$table."\n");
    }

    /**
     * Create a CRUD.
     *
     * @param array  $config
     * @param string $section
     * @param string $table
     * @param array  $splitTable
     *
     * @return void
     */
    public function createCRUD($config, $section, $table, $splitTable)
    {
        $bar = $this->output->createProgressBar(7);

        $crudGenerator = new CrudGenerator();
        $dbGenerator = new DatabaseGenerator();

        try {
            $this->generateCore($crudGenerator, $config, $bar);
            $this->generateAppBased($crudGenerator, $config, $bar);

            $crudGenerator->createTests(
                $config,
                $this->option('serviceOnly'),
                $this->option('apiOnly'),
                $this->option('api')
            );
            $bar->advance();

            $crudGenerator->createFactory($config);
            $bar->advance();

            $this->generateAPI($crudGenerator, $config, $bar);
            $bar->advance();

            $this->generateDB($dbGenerator, $config, $bar, $section, $table, $splitTable);
            $bar->finish();

            $this->crudReport($table);
        } catch (Exception $e) {
            throw new Exception('Unable to generate your CRUD: '.$e->getMessage(), 1);
        }
    }

    /**
     * Set the config of the CRUD.
     *
     * @param array  $config
     * @param string $section
     * @param string $table
     * @param array  $splitTable
     *
     * @return array
     */
    public function configASectionedCRUD($config, $section, $table, $splitTable)
    {
        $sectionalConfig = [
            '_sectionPrefix_'            => strtolower($section).'.',
            '_sectionTablePrefix_'       => strtolower($section).'_',
            '_sectionRoutePrefix_'       => strtolower($section).'/',
            '_sectionNamespace_'         => ucfirst($section).'\\',
            '_path_facade_'              => $appPath.'Facades',
            '_path_service_'             => $appPath.'Services',
            '_path_repository_'          => $appPath.'Repositories/'.ucfirst($section).'/'.ucfirst($table),
            '_path_model_'               => $appPath.'Repositories/'.ucfirst($section).'/'.ucfirst($table),
            '_path_controller_'          => $appPath.'Http/Controllers/'.ucfirst($section).'/',
            '_path_api_controller_'      => $appPath.'Http/Controllers/Api/'.ucfirst($section).'/',
            '_path_views_'               => $basePath.'/resources/views/'.strtolower($section),
            '_path_tests_'               => $basePath.'/tests',
            '_path_request_'             => $appPath.'Http/Requests/'.ucfirst($section),
            '_path_routes_'              => $appPath.'Http/routes.php',
            '_path_api_routes_'          => $appPath.'Http/api-routes.php',
            '_path_migrations_'          => $basePath.'/database/migrations',
            'routes_prefix'              => "\n\nRoute::group(['namespace' => '".ucfirst($section)."', 'prefix' => '".strtolower($section)."', 'middleware' => ['web']], function () { \n",
            'routes_suffix'              => "\n});",
            '_app_namespace_'            => $appNamespace,
            '_namespace_services_'       => $appNamespace.'Services\\'.ucfirst($section),
            '_namespace_facade_'         => $appNamespace.'Facades',
            '_namespace_repository_'     => $appNamespace.'Repositories\\'.ucfirst($section).'\\'.ucfirst($table),
            '_namespace_model_'          => $appNamespace.'Repositories\\'.ucfirst($section).'\\'.ucfirst($table),
            '_namespace_controller_'     => $appNamespace.'Http\Controllers\\'.ucfirst($section),
            '_namespace_api_controller_' => $appNamespace.'Http\Controllers\Api\\'.ucfirst($section),
            '_namespace_request_'        => $appNamespace.'Http\Requests\\'.ucfirst($section),
            '_lower_case_'               => strtolower($splitTable[1]),
            '_lower_casePlural_'         => str_plural(strtolower($splitTable[1])),
            '_camel_case_'               => ucfirst(camel_case($splitTable[1])),
            '_camel_casePlural_'         => str_plural(camel_case($splitTable[1])),
            '_ucCamel_casePlural_'       => ucfirst(str_plural(camel_case($splitTable[1]))),
            '_table_name_'               => str_plural(strtolower(implode('_', $splitTable))),
        ];

        $config = array_merge($config, $sectionalConfig);
        $config = array_merge($config, app('config')->get('crudmaker.sectioned', []));
        $config = $this->setConfig($config, $section, $table);

        $pathsToMake = [
            '_path_repository_',
            '_path_model_',
            '_path_controller_',
            '_path_api_controller_',
            '_path_views_',
            '_path_request_',
        ];

        foreach ($config as $key => $value) {
            if (in_array($key, $pathsToMake) && !file_exists($value)) {
                mkdir($value, 0777, true);
            }
        }

        return $config;
    }

    /**
     * Get the templates directory
     *
     * @param  string $framework
     * @param  string $basePath
     *
     * @return string
     */
    public function getTemplates($framework, $basePath)
    {
        $templates = __DIR__.'/../Templates/'.$framework;

        if ($framework === 'Laravel') {
            $templateDirectory = $basePath.'/resources/crudmaker/crud';
            if (is_dir($templateDirectory)) {
                $templates = app('config')->get('crudmaker.template_source', $templateDirectory);
            }
        }

        return $templates;
    }

    /**
     * Set the config.
     *
     * @param array  $config
     * @param string $section
     * @param string $table
     *
     * @return array
     */
    public function setConfig($config, $section, $table)
    {
        if (!empty($section)) {
            foreach ($config as $key => $value) {
                $config[$key] = str_replace('_table_', ucfirst($table), str_replace('_section_', ucfirst($section), str_replace('_sectionLowerCase_', strtolower($section), $value)));
            }
        } else {
            foreach ($config as $key => $value) {
                $config[$key] = str_replace('_table_', ucfirst($table), $value);
            }
        }

        return $config;
    }

    /**
     * Generate core elements.
     *
     * @param \Yab\CrudMaker\Generators\CrudGenerator        $crudGenerator
     * @param array                                         $config
     * @param \Symfony\Component\Console\Helper\ProgressBar $bar
     *
     * @return void
     */
    private function generateCore($crudGenerator, $config, $bar)
    {
        $crudGenerator->createRepository($config);
        $crudGenerator->createService($config);

        if ($config['framework'] === 'laravel') {
            $crudGenerator->createRequest($config);
        }

        $bar->advance();
    }

    /**
     * Generate app based elements.
     *
     * @param \Yab\CrudMaker\Generators\CrudGenerator        $crudGenerator
     * @param array                                         $config
     * @param \Symfony\Component\Console\Helper\ProgressBar $bar
     *
     * @return void
     */
    private function generateAppBased($crudGenerator, $config, $bar)
    {
        if (!$this->option('serviceOnly') && !$this->option('apiOnly')) {
            $crudGenerator->createController($config);
            $crudGenerator->createViews($config);
            $crudGenerator->createRoutes($config);

            if ($this->option('withFacade')) {
                $crudGenerator->createFacade($config);
            }
        }
        $bar->advance();
    }

    /**
     * Generate db elements.
     *
     * @param \Yab\CrudMaker\Generators\DatabaseGenerator    $dbGenerator
     * @param \Symfony\Component\Console\Helper\ProgressBar $bar
     * @param string                                        $section
     * @param string                                        $table
     * @param array                                         $splitTable
     *
     * @return void
     */
    private function generateDB($dbGenerator, $config, $bar, $section, $table, $splitTable)
    {
        if ($this->option('migration')) {
            $dbGenerator->createMigration($config, $section, $table, $splitTable, $this);
            if ($this->option('schema')) {
                $dbGenerator->createSchema($config, $section, $table, $splitTable, $this->option('schema'));
            }
        }
        $bar->advance();
    }

    /**
     * Generate api elements.
     *
     * @param \Yab\CrudMaker\Generators\CrudGenerator        $crudGenerator
     * @param array                                         $config
     * @param \Symfony\Component\Console\Helper\ProgressBar $bar
     *
     * @return void
     */
    private function generateAPI($crudGenerator, $config, $bar)
    {
        if ($this->option('api') || $this->option('apiOnly')) {
            $crudGenerator->createApi($config);
        }
        $bar->advance();
    }

    /**
     * Generate a CRUD report.
     *
     * @param string $table
     *
     * @return void
     */
    private function crudReport($table)
    {
        $this->line("\n");
        $this->line('Built repository...');
        $this->line('Built request...');
        $this->line('Built service...');

        if (!$this->option('serviceOnly') && !$this->option('apiOnly')) {
            $this->line('Built controller...');
            $this->line('Built views...');
            $this->line('Built routes...');
        }

        if ($this->option('withFacade')) {
            $this->line('Built facade...');
        }

        $this->line('Built tests...');
        $this->line('Added '.$table.' to database/factories/ModelFactory...');

        if ($this->option('api') || $this->option('apiOnly')) {
            $this->line('Built api...');
            $this->comment("\nAdd the following to your app/Providers/RouteServiceProvider.php: \n");
            $this->info("require app_path('Http/api-routes.php'); \n");
        }

        if ($this->option('migration')) {
            $this->line('Built migration...');
            if ($this->option('schema')) {
                $this->line('Built schema...');
            }
        } else {
            $this->info("\nYou will want to create a migration in order to get the $table tests to work correctly.\n");
        }
    }
}