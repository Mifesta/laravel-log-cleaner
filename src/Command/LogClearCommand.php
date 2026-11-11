<?php

namespace Mifesta\LogCleaner\Command;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Mifesta\LogCleaner\Facades\Cleaner;

class LogClearCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'log:clear
                            {--P|path= : Directory with logs, or a log file inside that directory}
                            {--R|rotate= : Delete entries older than this many days. Omit to delete every log file}';

    /**
     * @var string
     */
    protected $description = 'Clear log files';

    /**
     * @return int
     */
    public function handle()
    {
        try {
            $cleared = Cleaner::dir($this->option('path'))
                ->rotate($this->option('rotate'))
                ->clear();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        if ($cleared) {
            $this->info('Log files were cleared successfully.');

            return 0;
        }

        $this->error('Log files were cleared with errors.');

        return 1;
    }
}
