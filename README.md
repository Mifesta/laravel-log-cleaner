# Laravel Log Cleaner

[![Latest Stable Version](https://poser.pugx.org/mifesta/laravel-log-cleaner/v/stable)](https://packagist.org/packages/mifesta/laravel-log-cleaner)
[![Total Downloads](https://poser.pugx.org/mifesta/laravel-log-cleaner/downloads)](https://packagist.org/packages/mifesta/laravel-log-cleaner)
[![License](https://poser.pugx.org/mifesta/laravel-log-cleaner/license)](https://packagist.org/packages/mifesta/laravel-log-cleaner)

Artisan command and facade that delete old Laravel log files.

The package release matches the major Laravel version. `composer require` selects the newest release that fits the Laravel version already installed in the project.

| Laravel | Package constraint | PHP |
| --- | --- | --- |
| 5.5 – 5.8 | `^5.5` | `>=7.0` |
| 6 | `^6.0` | `^7.2` |
| 7 | `^7.0` | `^7.2.5` |
| 8 | `^8.0` | `^7.3\|^8.0` |
| 9 | `^9.0` | `^8.0` |
| 10 | `^10.0` | `^8.1` |
| 11 | `^11.0` | `^8.2` |
| 12 | `^12.0` | `^8.2` |
| 13 | `^13.0` | `^8.3` |

Release 1.0.1 remains available for existing `1.*` constraints. It does not include these fixes.

## Installation

```shell
composer require mifesta/laravel-log-cleaner
```

Laravel 5.5 and newer discover the service provider and the `log:clear` command. No edit to `app/Console/Kernel.php` is required.

## Usage

Delete every `*.log` file in `storage/logs`:

```shell
php artisan log:clear
```

Keep entries from the last 14 days. A file dated exactly 14 days ago is kept; older files are deleted.

```shell
php artisan log:clear --rotate=14
```

Clean another directory, or pass a log file to clean the directory that contains it:

```shell
php artisan log:clear --path=/path/to/logs --rotate=14
php artisan log:clear -P /path/to/logs/laravel.log -R 14
```

The same operations are available through `Mifesta\LogCleaner\Facades\Cleaner`. Calls share one instance.

```php
use Mifesta\LogCleaner\Facades\Cleaner;

Cleaner::rotate(14);

if (Cleaner::dir(storage_path('logs'))->clear()) {
    // Entries older than 14 days were removed.
}
```

Inside `storage/logs` the cleaner handles:

- daily files `*-YYYY-MM-DD.log`, using the date in the file name
- other `*.log` files, using `[YYYY-MM-DD HH:MM:SS]` headers or JSON lines with a `datetime` field
- files without those dates, using the file modification time when `--rotate` is set

Only the selected directory is scanned. Subdirectories, hidden files, and files that do not end in `.log` stay in place. Omitting `--rotate` deletes every `*.log` file in the directory. A missing path or a negative rotate value stops the command before anything is deleted.

## Tests

```shell
composer update
composer test
```

## Credits

- [Sergei Gruzdov](https://github.com/cardee2000)

## Security

Report security issues to [cardee@mifesta.com](mailto:cardee@mifesta.com).

## License

MIT. Copyright (c) 2019-2026 Sergei Gruzdov \<cardee@mifesta.com\>.
