<?php

namespace App\Console;

use Illuminate\Foundation\Console\ServeCommand;

class LocalServeCommand extends ServeCommand
{
    protected function serverCommand()
    {
        $command = parent::serverCommand();
        // These must reach the child HTTP server; ini_set or flags on the parent artisan process cannot fix an upload.
        array_splice($command, 1, 0, ['-d', 'upload_max_filesize=10M', '-d', 'post_max_size=12M']);

        return $command;
    }
}
