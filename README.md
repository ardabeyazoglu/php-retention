# php-retention

![build](https://github.com/ardabeyazoglu/php-retention/actions/workflows/main.yml/badge.svg)
[![codecov](https://codecov.io/gh/ardabeyazoglu/php-retention/graph/badge.svg?token=5TE2OKaIPT)](https://codecov.io/gh/ardabeyazoglu/php-retention)

A simple but handy php library to apply retention policy to files before deleting, archiving or anything that is possible with a custom callback. 
A typical example would be backup archiving based on custom policies such as "keep last 7 daily, 2 weekly and 3 monthly backups".

# Features

- Apply hourly, daily, weekly, monthly and yearly policies
- Customize prune action to do something else instead of deleting (e.g. move them to cloud)
- Customize file finder logic (e.g. support different storage interfaces such as S3)
- Grouping files (e.g. prune multiple files together instead of a single file)
- Dry runnable
- Logger aware (PSR-3)
- No dependencies other than the PSR-3 logger interface (`psr/log`)

# Install

    composer require ardabeyazoglu/php-retention

# Test

    composer test

# Usage

```php
// define retention policy (UTC timezone)
$retention = new PhpRetention\Retention([
    "keep-daily" => 7,
    "keep-weekly" => 4,
    "keep-monthly" => 6,
    "keep-yearly" => 2
]);

// customize finder logic if required
$retention->setFindHandler(function () {});

// customize time calculation if required
$retention->setTimeHandler(function () {});

// customize time calculation if required
$retention->setPruneHandler(function () {});

// apply retention in given directory (this WILL PRUNE the files!)
$result = $retention->apply("/path/to/files");
print_r($result);
```

# Policy Configuration

This library is inspired by [Restic's policy model](https://restic.readthedocs.io/en/latest/060_forget.html#removing-snapshots-according-to-a-policy). 
Policy configuration without understanding how it works might be misleading. Please read the [explanation](https://restic.readthedocs.io/en/latest/060_forget.html#removing-snapshots-according-to-a-policy) to understand how each `keep-***` parameter works. 

    keep-last: keep the most recent N files. (default: 1)
    keep-hourly: for the last N hours which have one or more files, keep only the most recent one for each hour.
    keep-daily: for the last N days which have one or more files, keep only the most recent one for each day.
    keep-weekly: for the last N weeks which have one or more files, keep only the most recent one for each week.
    keep-monthly: for the last N months which have one or more files, keep only the most recent one for each month.
    keep-yearly: for the last N years which have one or more files, keep only the most recent one for each year.

# Examples

### 1. Custom Finder

By default, it will scan target directory non-recursively and apply retention policy to each file or directory in the target directory.
This will be okay for local files, mounted partitions and when using stream wrappers external storage protocols.
In all other cases, a custom finder logic must be implemented to detect files to apply retention.

```php
// get list of files from ftp
$rets->setFindHandler(function (string $targetDir) use ($ftpConnection) {
    $files = [];
    $fileList = ftp_mlsd($ftpConnection, $targetDir) ?: [];
    foreach ($fileList as $file) {
        $filename = $file['name'];
        $time = (int) $file['modify'];

        if (preg_match('/^backup_\w+\.zip$/', $filename)) {
            $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $date = $date->setTimestamp($time);

            $filepath = "$targetDir/$filename";

            $files[] = new FileInfo(
                date: $date,
                path: $filepath,
                isDirectory: false
            );
        }
    }

    return $files;
});
```

### 2. Custom Time Parser

By default, it will check posix modification time of files to detect date and time information.
It can be overriden by using a custom function, to resolve a different date value. 
Can be handy for reading date information from a custom file name format. 

NOTE: This will be only called when using default finder logic. If you are writing your own `findHandler`, that function is responsible for giving a valid list of files with a valid date info.

```php
// assume the files waiting for retention have this format: "backup@YYYYmmdd.zip"
$ret->setTimeHandler(function (string $filepath, bool $isDirectory) {
    if (preg_match('/^backup@([0-9]{4})([0-9]{2})([0-9]{2})\.zip$/', basename($filepath), $matches)) {
        $year = intval($matches[1]);
        $month = intval($matches[2]);
        $day = intval($matches[3]);

        $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $date = $date->setDate($year, $month, $day)->setTime(0, 0, 0, 0);

        return new FileInfo(
            date: $date,
            path: $filepath,
            isDirectory: $isDirectory
        );
    }
    else {
        return null;
    }
});
```

### 3. Custom Prune Action

Pruning action is by default `unlink` for files and `rmdir` for empty directories. 
It can be customized to call different delete functions or to do something else other than deleting.

```php
$ret->setPruneHandler(function (FileInfo $fileInfo) use ($ftpConnection) {
    ftp_delete($ftpConnection, $fileInfo->path);
});
```

### 4. Grouping Files to Prune Together

Let's assume the directory contains multiple backup files for the same date but for different purposes. 
They can be grouped by using regexp to apply the same retention to altogether.

    # example:
    /backup/tenant/mysql-20240106.tar.gz
    /backup/tenant/files-20240106.tar.gz
    /backup/tenant/mysql-20240107.tar.gz
    /backup/tenant/files-20240107.tar.gz

Without the following function, a "keep-last=1" policy will prune only the first file. 
By grouping them based on date, it will prune first two files together. 

```php
// group different types of backups based on tenant and date and prune them together
$ret->setGroupHandler(function (string $filepath) { 
    if (preg_match('/\-([0-9]{8})\.tar\.gz$/', $filepath, $matches)) {
        // group by date str
        return $matches[1];
    }

    return null;
});
```

# Contribution

Feel free to post an issue if you encounter a bug or you want to implement a new feature. 
Please be descriptive in your posts.
    
# ToDo

- [ ] Add keep-within-*** policy support 
