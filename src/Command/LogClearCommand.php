<?php

namespace Mifesta\LogCleaner\Command;

use Illuminate\Console\Command;
use Mifesta\LogCleaner\Facades\Cleaner;

/**
 * Class LogClearCommand
 *
 * @package Mifesta\LogCleaner\Command
 */
class LogClearCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'log:clear
                            {--P|path= : Path to log files}
                            {--R|rotate= : Log rotate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear logs';

    /**
     * Execute the console command.
     *
     * @return string
     */
    public function handle()
    {
        if (Cleaner::dir($this->option('path'))->rotate($this->option('rotate'))->clear()) {
            $this->info('Log files were cleared successfully.');

            return 0;
        } else {
            $this->error('Log files were cleared with errors.');

            return 1;
        }
    }
}
