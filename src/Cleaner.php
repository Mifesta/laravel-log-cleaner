<?php

namespace Mifesta\LogCleaner;

use Illuminate\Support\Facades\File;
use Mifesta\LogCleaner\Facades\Cleaner as Facade;

/**
 * Class Cleaner
 *
 * @package Mifesta\LogCleaner
 */
class Cleaner extends Facade
{
    /**
     * Path to logs
     *
     * @var string
     */
    private $log_dir;

    /**
     * log rotate
     *
     * @var int
     */
    private $log_rotate = 0;

    /**
     * Create a new cleaner instance.
     *
     * @param string|null $dirname
     * @param int|null $rotate
     */
    public function __construct($dirname = null, $rotate = null)
    {
        $this->dir($dirname)->rotate($rotate);
    }

    /**
     * Set path to logs
     *
     * @param string|null $dirname
     * @return \Mifesta\LogCleaner\Cleaner
     */
    public function dir($dirname = null)
    {
        if (is_null($dirname)) {
            $this->log_dir = storage_path('logs');
        } elseif (! is_string($dirname)) {
            trigger_error('The query result does not have the required methods and properties', E_USER_WARNING);
        } elseif (File::isDirectory($dirname)) {
            $this->log_dir = $dirname;
        } elseif (File::isFile($dirname)) {
            $this->log_dir = dirname($dirname);
        } else {
            trigger_error('The query result does not have the required methods and properties', E_USER_WARNING);
        }

        return $this;
    }

    /**
     * Set log rotate
     *
     * @param int|null $rotate
     * @return \Mifesta\LogCleaner\Cleaner
     */
    public function rotate($rotate = null)
    {
        if (is_null($rotate)) {
            $this->log_rotate = 0;
        } elseif (is_numeric($rotate)) {
            $this->log_rotate = (int) $rotate;
        } else {
            trigger_error('The query result does not have the required methods and properties', E_USER_WARNING);
        }

        return $this;
    }

    /**
     * Clear logs
     *
     * @return bool
     */
    public function clear()
    {
        $has_errors = false;
        if (! is_string($this->log_dir)) {
            $this->dir();
        }
        if (FIle::exists($this->log_dir)) {
            $files = File::files($this->log_dir, false);
            if ($this->log_rotate > 0) {
                $before = date('Y-m-d', strtotime('-'.$this->log_rotate.' days'));
            } else {
                $before = false;
            }
            foreach ($files as $file) {
                if (preg_match('/-([0-9]{4}-[0-9]{2}-[0-9]{2})\.log$/is', $file->getFilename(), $matches)) {
                    if (empty($before) || ($matches[1] < $before)) {
                        if (! File::delete($file->getPathname())) {
                            $has_errors = true;
                        }
                    }
                } elseif ($file->getFilename() === 'laravel.log') {
                    if (empty($before)) {
                        if (! File::delete($file->getPathname())) {
                            $has_errors = true;
                        }
                    } else {
                        $content = File::get($file->getPathname());
                        if (preg_match_all('/\[([0-9]{4}-[0-9]{2}-[0-9]{2}) [0-9]{2}:[0-9]{2}:[0-9]{2}]/is', $content, $matches, PREG_SET_ORDER)) {
                            $date = '';
                            foreach ($matches as $match) {
                                if ($match[1] >= $before) {
                                    $date = $match[0];
                                    break;
                                }
                            }
                            unset($match);
                            if (empty($date)) {
                                if (! File::delete($file->getPathname())) {
                                    $has_errors = true;
                                }
                            } elseif ($start = mb_strpos($content, $date)) {
                                if (! File::put($file->getPathname(), mb_substr($content, $start))) {
                                    $has_errors = true;
                                }
                            }
                            unset($date);
                        }
                        unset($content);
                    }
                }
            }
        }

        return empty($has_errors);
    }
}
