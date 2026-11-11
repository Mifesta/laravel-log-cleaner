<?php

namespace Mifesta\LogCleaner;

use Illuminate\Support\ServiceProvider;
use Mifesta\LogCleaner\Command\LogClearCommand;

class LogCleanerServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register()
    {
        $this->app->singleton(Cleaner::class, function () {
            return new Cleaner();
        });

        $this->app->alias(Cleaner::class, 'mifesta.log-cleaner');
    }

    /**
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands(array(
                LogClearCommand::class,
            ));
        }
    }
}
