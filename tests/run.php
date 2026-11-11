<?php

use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Mifesta\LogCleaner\Cleaner;
use Mifesta\LogCleaner\Command\LogClearCommand;
use Mifesta\LogCleaner\Facades\Cleaner as CleanerFacade;
use Mifesta\LogCleaner\LogCleanerServiceProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('UTC');

set_error_handler(function ($severity, $message, $file, $line) {
    $report = E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE | E_USER_ERROR | E_RECOVERABLE_ERROR;

    if (!($severity & $report) || !(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

$vendor = getenv('VENDOR');

if (!$vendor) {
    $vendor = dirname(__DIR__).DIRECTORY_SEPARATOR.'vendor';
}

require $vendor.'/autoload.php';

$failures = 0;
$STORAGE_ROOT = sys_get_temp_dir().DIRECTORY_SEPARATOR.'llc-storage-'.getmypid();

if (!function_exists('storage_path')) {
    function storage_path($path = '')
    {
        global $STORAGE_ROOT;

        if ($path === '' || $path === null) {
            return $STORAGE_ROOT;
        }

        return $STORAGE_ROOT.DIRECTORY_SEPARATOR.$path;
    }
}

$app = new class extends Container {
    public function runningUnitTests()
    {
        return true;
    }

    public function runningInConsole()
    {
        return true;
    }
};

$app->singleton('files', function () {
    return new Filesystem();
});
Facade::setFacadeApplication($app);

function check($name, $condition, $detail = '')
{
    global $failures;

    if ($condition) {
        echo "PASS $name\n";

        return;
    }

    $failures++;
    echo "FAIL $name".($detail !== '' ? " :: $detail" : '')."\n";
}

function reset_storage()
{
    global $STORAGE_ROOT;

    if (is_dir($STORAGE_ROOT)) {
        remove_tree($STORAGE_ROOT);
    }

    mkdir($STORAGE_ROOT.DIRECTORY_SEPARATOR.'logs', 0777, true);
}

function remove_tree($dir)
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir.DIRECTORY_SEPARATOR.$item;

        if (is_dir($path)) {
            remove_tree($path);
        } else {
            @chmod($path, 0666);
            @unlink($path);
        }
    }

    @chmod($dir, 0777);
    @rmdir($dir);
}

function make_dir()
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'llc-case-'.getmypid().'-'.mt_rand(1000, 999999);
    mkdir($dir, 0777, true);

    return $dir;
}

function write_file($path, $contents)
{
    $parent = dirname($path);

    if (!is_dir($parent)) {
        mkdir($parent, 0777, true);
    }

    file_put_contents($path, $contents);
}

function entry($day, $message)
{
    return '['.$day.' 12:00:00] local.ERROR: '.$message."\n";
}

function day_shift($days)
{
    return date('Y-m-d', strtotime($days.' days'));
}

function reset_facade()
{
    global $app;

    foreach (array('mifesta.log-cleaner', 'Mifesta\\LogCleaner\\Cleaner') as $key) {
        if ($app->bound($key)) {
            unset($app[$key]);
        }
    }

    $reset = Closure::bind(function () {
        self::$fallback = null;
    }, null, 'Mifesta\\LogCleaner\\Facades\\Cleaner');
    $reset();
}

function use_strings_for_files($enabled)
{
    global $app;

    unset($app['files']);

    $app->singleton('files', function () use ($enabled) {
        $inner = new Filesystem();

        if (!$enabled) {
            return $inner;
        }

        return new StringFileList($inner);
    });

    Facade::clearResolvedInstance('files');
}

class CommandRecorder
{
    public $stub;

    public function resolveCommands($commands)
    {
        $this->stub->commands = $commands;
    }
}

class StringFileList
{
    private $inner;

    public function __construct($inner)
    {
        $this->inner = $inner;
    }

    public function __call($method, $arguments)
    {
        $result = call_user_func_array(array($this->inner, $method), $arguments);

        if ($method !== 'files') {
            return $result;
        }

        $paths = array();

        foreach ($result as $file) {
            $paths[] = is_object($file) && method_exists($file, 'getPathname')
                ? $file->getPathname()
                : (string) $file;
        }

        return $paths;
    }
}

function run_command($parameters)
{
    global $app;

    $command = new LogClearCommand();
    $command->setLaravel($app);
    $output = new BufferedOutput();
    $exit = $command->run(new ArrayInput($parameters), $output);

    return array($exit, $output->fetch());
}

echo 'PHP '.PHP_VERSION."\n";
echo 'illuminate/filesystem '.\Composer\InstalledVersions::getPrettyVersion('illuminate/filesystem')."\n";
echo 'illuminate/console '.\Composer\InstalledVersions::getPrettyVersion('illuminate/console')."\n";

$rotate = 14;
$before = day_shift(-$rotate);
$older = day_shift(-($rotate + 1));
$today = day_shift(0);
echo "cutoff before=$before older=$older today=$today\n";

reset_storage();
reset_facade();
use_strings_for_files(false);

$dir = make_dir();
write_file($dir."/laravel-$older.log", "old daily\n");
write_file($dir."/laravel-$before.log", "boundary daily\n");
write_file($dir."/laravel-$today.log", "today daily\n");
write_file($dir."/custom-$older.log", "custom old\n");
write_file($dir.'/horizon.log', entry($older, 'horizon old').entry($today, 'horizon new'));
write_file($dir.'/notes.txt', "notes\n");
write_file($dir."/laravel-$older.log.bak", "bak\n");
write_file($dir."/subdir/laravel-$older.log", "nested\n");
write_file($dir."/.hidden-$older.log", "hidden\n");
$single = entry($older, "старая\n#0 /var/www/app.php(10): boom()")
    .entry($before, 'граница')
    .entry($today, 'новая');
write_file($dir.'/laravel.log', $single);

$cleaner = new Cleaner($dir, $rotate);
check('rotate 14 returns true', $cleaner->clear() === true);
check('old daily deleted', !file_exists($dir."/laravel-$older.log"));
check('boundary daily kept', file_exists($dir."/laravel-$before.log"));
check('today daily kept', file_exists($dir."/laravel-$today.log"));
check('old custom daily deleted', !file_exists($dir."/custom-$older.log"));
check('notes kept', file_exists($dir.'/notes.txt'));
check('bak kept', file_exists($dir."/laravel-$older.log.bak"));
check('nested kept', file_exists($dir."/subdir/laravel-$older.log"));
check('dotfile kept', file_exists($dir."/.hidden-$older.log"));
$expectedSingle = entry($before, 'граница').entry($today, 'новая');
$actualSingle = file_get_contents($dir.'/laravel.log');
check('laravel.log trimmed on a header boundary', $actualSingle === $expectedSingle, json_encode($actualSingle));
$horizon = file_get_contents($dir.'/horizon.log');
check('horizon.log trimmed', $horizon === entry($today, 'horizon new'), json_encode($horizon));
remove_tree($dir);

$dir = make_dir();
$embedded = entry($older, 'see ['.$today.' 09:00:00] inside').entry($today, 'real');
write_file($dir.'/laravel.log', $embedded);
(new Cleaner($dir, $rotate))->clear();
$after = file_get_contents($dir.'/laravel.log');
check('embedded timestamp is not a cut point', $after === entry($today, 'real'), json_encode($after));
remove_tree($dir);

$dir = make_dir();
$jsonOld = '{"message":"old","datetime":"'.$older.'T12:00:00.000000+00:00"}'."\n";
$jsonNew = '{"message":"new","datetime":"'.$today.'T12:00:00.000000+00:00"}'."\n";
write_file($dir.'/browser.log', $jsonOld.$jsonNew);
(new Cleaner($dir, $rotate))->clear();
check('json log trimmed by datetime', file_get_contents($dir.'/browser.log') === $jsonNew, json_encode(file_get_contents($dir.'/browser.log')));
remove_tree($dir);

$dir = make_dir();
write_file($dir.'/plain.log', "no dates\n");
touch($dir.'/plain.log', strtotime('-40 days'));
write_file($dir.'/fresh-plain.log', "no dates\n");
(new Cleaner($dir, $rotate))->clear();
check('undated old file deleted by mtime', !file_exists($dir.'/plain.log'));
check('undated fresh file kept', file_exists($dir.'/fresh-plain.log'));
remove_tree($dir);

$dir = make_dir();
write_file($dir.'/laravel.log', entry($older, 'only old'));
check('all-old file clear returns true', (new Cleaner($dir, $rotate))->clear() === true);
check('all-old file deleted', !file_exists($dir.'/laravel.log'));
remove_tree($dir);

$dir = make_dir();
$fresh = entry($today, 'только новая');
write_file($dir.'/laravel.log', $fresh);
(new Cleaner($dir, $rotate))->clear();
check('fresh file unchanged', file_get_contents($dir.'/laravel.log') === $fresh);
remove_tree($dir);

$dir = make_dir();
write_file($dir."/laravel-$today.log", "today\n");
write_file($dir.'/horizon.log', "horizon\n");
write_file($dir.'/notes.txt', "notes\n");
check('rotate 0 returns true', (new Cleaner($dir, 0))->clear() === true);
check('rotate 0 deletes today daily', !file_exists($dir."/laravel-$today.log"));
check('rotate 0 deletes horizon.log', !file_exists($dir.'/horizon.log'));
check('rotate 0 keeps notes', file_exists($dir.'/notes.txt'));
remove_tree($dir);

$dir = make_dir();
write_file($dir."/laravel-$today.log", "today\n");
$thrown = null;

try {
    new Cleaner($dir, -1);
} catch (InvalidArgumentException $exception) {
    $thrown = $exception->getMessage();
}

check('negative rotate throws', $thrown !== null, var_export($thrown, true));
check('negative rotate deletes nothing', file_exists($dir."/laravel-$today.log"));
remove_tree($dir);

$dir = make_dir();
write_file($dir."/laravel-$older.log", "old\n");
write_file($dir."/laravel-$today.log", "today\n");
$instance = new Cleaner($dir, $rotate);
$thrown = null;

try {
    $instance->rotate('fourteen');
} catch (InvalidArgumentException $exception) {
    $thrown = $exception->getMessage();
}

check('bad rotate throws', $thrown !== null);
$instance->clear();
check('bad rotate keeps the previous retention', file_exists($dir."/laravel-$today.log") && !file_exists($dir."/laravel-$older.log"));
remove_tree($dir);

reset_storage();
$decoy = storage_path('logs')."/laravel-$older.log";
write_file($decoy, "default\n");
$thrown = null;

try {
    (new Cleaner('/no/such/llc/path', $rotate))->clear();
} catch (InvalidArgumentException $exception) {
    $thrown = $exception->getMessage();
}

check('missing path throws', $thrown !== null && strpos($thrown, 'does not exist') !== false, var_export($thrown, true));
check('missing path keeps the default log', file_exists($decoy));
@unlink($decoy);

$dir = make_dir();
write_file($dir."/laravel-$older.log", "old\n");
write_file($dir.'/laravel.log', entry($today, 'live'));
(new Cleaner($dir.'/laravel.log', $rotate))->clear();
check('file path cleans its directory', !file_exists($dir."/laravel-$older.log"));
check('file path keeps fresh laravel.log', is_file($dir.'/laravel.log'));
remove_tree($dir);

reset_facade();
$dir = make_dir();
write_file($dir."/laravel-$today.log", "today\n");
write_file($dir."/laravel-$older.log", "old\n");
CleanerFacade::rotate($rotate);
CleanerFacade::dir($dir);
CleanerFacade::clear();
check('separate facade calls keep rotate', file_exists($dir."/laravel-$today.log") && !file_exists($dir."/laravel-$older.log"));
remove_tree($dir);

use_strings_for_files(true);
$dir = make_dir();
write_file($dir."/laravel-$older.log", "old\n");
write_file($dir."/laravel-$today.log", "today\n");
write_file($dir.'/laravel.log', entry($older, 'old').entry($today, 'new'));
check('string file list returns true', (new Cleaner($dir, $rotate))->clear() === true);
check('string file list deletes old daily', !file_exists($dir."/laravel-$older.log"));
check('string file list keeps today daily', file_exists($dir."/laravel-$today.log"));
check('string file list trims laravel.log', file_get_contents($dir.'/laravel.log') === entry($today, 'new'));
remove_tree($dir);
use_strings_for_files(false);

$provider = new LogCleanerServiceProvider($app);
$provider->register();
check('provider binds the cleaner', $app->bound('mifesta.log-cleaner') && $app->bound(Cleaner::class));
Artisan::forgetBootstrappers();
$provider->boot();
$bootstrappers = new ReflectionProperty('Illuminate\\Console\\Application', 'bootstrappers');

if (PHP_VERSION_ID < 80100) {
    $bootstrappers->setAccessible(true);
}

$registered = array();

$callbacks = PHP_VERSION_ID >= 80000 ? $bootstrappers->getValue() : $bootstrappers->getValue(null);

foreach ($callbacks as $bootstrapper) {
    $stub = new stdClass();
    $stub->commands = array();
    $recorder = new CommandRecorder();
    $recorder->stub = $stub;
    $bootstrapper($recorder);
    $registered = array_merge($registered, $stub->commands);
}
check('provider registers log:clear', in_array(LogClearCommand::class, $registered, true), json_encode($registered));
$package = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true);
check(
    'package discovery lists the provider',
    isset($package['extra']['laravel']['providers'])
        && in_array('Mifesta\\LogCleaner\\LogCleanerServiceProvider', $package['extra']['laravel']['providers'], true)
);

reset_storage();
reset_facade();
$provider->register();
$dir = storage_path('logs');
write_file($dir."/laravel-$older.log", "old\n");
write_file($dir."/laravel-$today.log", "today\n");
write_file($dir.'/laravel.log', entry($older, 'old line').entry($today, 'new line'));
write_file($dir.'/horizon.log', "horizon\n");
write_file($dir.'/notes.txt', "notes\n");

try {
    list($exit, $display) = run_command(array('--path' => $dir, '--rotate' => (string) $rotate));
} catch (Exception $exception) {
    $exit = null;
    $display = $exception->getMessage();
}

check('command rotate exits 0', $exit === 0, var_export($exit, true).' '.json_encode($display));
check('command rotate reports success', is_string($display) && strpos($display, 'Log files were cleared successfully.') !== false, json_encode($display));
check('command rotate deletes the old daily file', !file_exists($dir."/laravel-$older.log"));
check('command rotate keeps today', file_exists($dir."/laravel-$today.log"));
check('command rotate trims laravel.log', file_get_contents($dir.'/laravel.log') === entry($today, 'new line'));
check('command rotate keeps horizon without dates', file_exists($dir.'/horizon.log'));

write_file($dir."/laravel-$today.log", "today\n");
write_file($dir.'/laravel.log', entry($today, 'live'));

try {
    list($exit, $display) = run_command(array('--path' => $dir, '--rotate' => '0'));
} catch (Exception $exception) {
    $exit = null;
    $display = $exception->getMessage();
}

check('command rotate 0 exits 0', $exit === 0, var_export($exit, true).' '.json_encode($display));
check('command rotate 0 deletes logs', !file_exists($dir."/laravel-$today.log") && !file_exists($dir.'/laravel.log'));
check('command rotate 0 keeps notes', file_exists($dir.'/notes.txt'));

write_file($dir.'/laravel.log', "keep\n");

try {
    list($exit, $display) = run_command(array('--path' => '/no/such/llc/path', '--rotate' => '14'));
} catch (Exception $exception) {
    $exit = null;
    $display = $exception->getMessage();
}

check('command bad path exits 1', $exit === 1, var_export($exit, true).' '.json_encode($display));
check('command bad path explains the error', is_string($display) && strpos($display, 'does not exist') !== false, json_encode($display));
check('command bad path keeps the existing log', file_get_contents($dir.'/laravel.log') === "keep\n");

$missingRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'llc-missing-'.getmypid();
$STORAGE_ROOT = $missingRoot;
reset_facade();
$app->singleton(Cleaner::class, function () {
    return new Cleaner();
});
$app->alias(Cleaner::class, 'mifesta.log-cleaner');

try {
    list($exit, $display) = run_command(array());
} catch (Exception $exception) {
    $exit = null;
    $display = $exception->getMessage();
}

check('command missing directory exits 1', $exit === 1, var_export($exit, true).' '.json_encode($display));
check('command missing directory is not a success', !is_string($display) || strpos($display, 'successfully') === false, json_encode($display));

$definition = (new LogClearCommand())->getDefinition();
check('shortcut P', $definition->getOption('path')->getShortcut() === 'P');
check('shortcut R', $definition->getOption('rotate')->getShortcut() === 'R');

remove_tree(sys_get_temp_dir().DIRECTORY_SEPARATOR.'llc-storage-'.getmypid());
remove_tree($missingRoot);

echo "FAILURES $failures\n";
exit($failures > 0 ? 1 : 0);
