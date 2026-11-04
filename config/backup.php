<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic database backup (php artisan backup:database)
    |--------------------------------------------------------------------------
    |
    | path:           folder for the .sql.gz files
    | copy_to:        optional second folder (e.g. a Google Drive / OneDrive synced folder or another disk)
    | interval_hours: the scheduler makes a new backup once the newest is older than this
    | keep_days:      backups older than this are deleted...
    | keep_min:       ...but the newest few are always kept, even when old
    | mysqldump:      path to mysqldump (XAMPP ships it in C:\xampp\mysql\bin)
    | timezone:       local time used in the file names
    |
    */

    'path' => env('BACKUP_PATH', storage_path('app'.DIRECTORY_SEPARATOR.'backup'.DIRECTORY_SEPARATOR.'otomatis')),

    'copy_to' => env('BACKUP_COPY_TO'),

    'interval_hours' => (int) env('BACKUP_INTERVAL_HOURS', 24),

    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),

    'keep_min' => (int) env('BACKUP_KEEP_MIN', 7),

    'mysqldump' => env('BACKUP_MYSQLDUMP', is_file('C:\xampp\mysql\bin\mysqldump.exe') ? 'C:\xampp\mysql\bin\mysqldump.exe' : 'mysqldump'),

    'timezone' => env('BACKUP_TIMEZONE', 'Asia/Jakarta'),

];
