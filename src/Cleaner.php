<?php

namespace Mifesta\LogCleaner;

use Exception;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class Cleaner
{
    /**
     * @var string|null
     */
    private $logDir;

    /**
     * @var int
     */
    private $logRotate = 0;

    /**
     * @param string|null $dirname
     * @param int|string|null $rotate
     */
    public function __construct($dirname = null, $rotate = null)
    {
        $this->dir($dirname)->rotate($rotate);
    }

    /**
     * @param string|null $dirname
     * @return $this
     */
    public function dir($dirname = null)
    {
        if ($dirname === null) {
            if (!function_exists('storage_path')) {
                throw new InvalidArgumentException('A log path is required when storage_path() is unavailable.');
            }

            $this->logDir = storage_path('logs');

            return $this;
        }

        if (!is_string($dirname) || $dirname === '') {
            throw new InvalidArgumentException('Log path must be a non-empty string.');
        }

        if (File::isDirectory($dirname)) {
            $this->logDir = $dirname;

            return $this;
        }

        if (File::isFile($dirname)) {
            $this->logDir = dirname($dirname);

            return $this;
        }

        throw new InvalidArgumentException('Log path does not exist: '.$dirname);
    }

    /**
     * @param int|string|null $rotate
     * @return $this
     */
    public function rotate($rotate = null)
    {
        if ($rotate === null) {
            $this->logRotate = 0;

            return $this;
        }

        if ($rotate === '' || !is_numeric($rotate) || (float) $rotate < 0) {
            throw new InvalidArgumentException('Log rotate must be a non-negative number of days.');
        }

        $this->logRotate = (int) $rotate;

        return $this;
    }

    /**
     * @return bool
     */
    public function clear()
    {
        if (!is_string($this->logDir) || $this->logDir === '') {
            $this->dir(null);
        }

        if (!File::isDirectory($this->logDir)) {
            throw new InvalidArgumentException('Log directory does not exist: '.$this->logDir);
        }

        $before = $this->cutoffDate();

        try {
            $files = File::files($this->logDir);
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            return false;
        }

        $hasErrors = false;

        foreach ($files as $file) {
            $filename = $this->filenameOf($file);
            $pathname = $this->pathnameOf($file);

            if (!preg_match('/\.log$/i', $filename)) {
                continue;
            }

            if (preg_match('/-([0-9]{4}-[0-9]{2}-[0-9]{2})\.log$/i', $filename, $matches)) {
                if ($before === null || strcmp($matches[1], $before) < 0) {
                    if (!$this->deleteFile($pathname)) {
                        $hasErrors = true;
                    }
                }

                continue;
            }

            if ($before === null) {
                if (!$this->deleteFile($pathname)) {
                    $hasErrors = true;
                }

                continue;
            }

            if (!$this->trimFile($pathname, $before)) {
                $hasErrors = true;
            }
        }

        return !$hasErrors;
    }

    /**
     * @return string|null
     */
    private function cutoffDate()
    {
        if ($this->logRotate <= 0) {
            return null;
        }

        $timestamp = strtotime('-'.$this->logRotate.' days');

        if ($timestamp === false) {
            throw new InvalidArgumentException('Log rotate is out of range.');
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * @param mixed $file
     * @return string
     */
    private function filenameOf($file)
    {
        if (is_object($file) && method_exists($file, 'getFilename')) {
            return $file->getFilename();
        }

        return basename((string) $file);
    }

    /**
     * @param mixed $file
     * @return string
     */
    private function pathnameOf($file)
    {
        if (is_object($file) && method_exists($file, 'getPathname')) {
            return $file->getPathname();
        }

        return (string) $file;
    }

    /**
     * @param string $pathname
     * @return bool
     */
    private function deleteFile($pathname)
    {
        return (bool) File::delete($pathname);
    }

    /**
     * @param string $pathname
     * @param string $before
     * @return bool
     */
    private function trimFile($pathname, $before)
    {
        $handle = @fopen($pathname, 'c+');

        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $content = stream_get_contents($handle);

            if ($content === false) {
                return false;
            }

            $updated = $this->trimContent($content, $before, $pathname);

            if ($updated === false) {
                flock($handle, LOCK_UN);
                fclose($handle);
                $handle = null;

                return $this->deleteFile($pathname);
            }

            if ($updated === $content) {
                return true;
            }

            if (!$this->replaceContents($handle, $updated)) {
                $this->replaceContents($handle, $content);

                return false;
            }

            return true;
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /**
     * Keep log records dated on or after $before. Return false when the file
     * should be deleted.
     *
     * @param string $content
     * @param string $before
     * @param string $pathname
     * @return string|false
     */
    private function trimContent($content, $before, $pathname)
    {
        $cut = $this->cutOffset(
            $content,
            $before,
            '/^\[([0-9]{4}-[0-9]{2}-[0-9]{2}) [0-9]{2}:[0-9]{2}:[0-9]{2}\]/m'
        );

        if ($cut === null) {
            $cut = $this->cutOffset(
                $content,
                $before,
                '/^\{.*?"datetime"\s*:\s*"([0-9]{4}-[0-9]{2}-[0-9]{2})/m'
            );
        }

        if ($cut === null) {
            $modifiedAt = @filemtime($pathname);

            if ($modifiedAt !== false && $modifiedAt < strtotime($before.' 00:00:00')) {
                return false;
            }

            return $content;
        }

        if ($cut === false) {
            return false;
        }

        if ($cut === 0) {
            return $content;
        }

        return substr($content, $cut);
    }

    /**
     * @param string $content
     * @param string $before
     * @param string $pattern
     * @return int|false|null
     */
    private function cutOffset($content, $before, $pattern)
    {
        if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $count = count($matches[0]);

        for ($index = 0; $index < $count; $index++) {
            if (strcmp($matches[1][$index][0], $before) >= 0) {
                return (int) $matches[0][$index][1];
            }
        }

        return false;
    }

    /**
     * @param resource $handle
     * @param string $data
     * @return bool
     */
    private function replaceContents($handle, $data)
    {
        if (fseek($handle, 0) !== 0 || !ftruncate($handle, 0) || fseek($handle, 0) !== 0) {
            return false;
        }

        $length = strlen($data);

        if ($length === 0) {
            return fflush($handle);
        }

        $written = fwrite($handle, $data);

        if ($written !== $length) {
            return false;
        }

        return fflush($handle);
    }
}
