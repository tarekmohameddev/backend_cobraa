<?php

namespace App\Console\Commands;

use DB;
use Illuminate\Console\Command;

class RunMysqlScript extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mysql:backup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
	{
		DB::unprepared(file_get_contents('dropdb.sql'));
		sleep(5);
		DB::unprepared(file_get_contents('uzmart.sql'));

        return 0;
    }
}
