<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Exception\RequestException;
use Log;
use Illuminate\Support\Facades\Artisan;

class CreateAllProcess extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:all-import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'All import';

    public function handle()
    {
        try {
            $this->info("Import Product Start !");
            Artisan::call('app:create-product');
            $this->info(Artisan::output());
            $this->info("Import Service Start !");
            Artisan::call('app:create-service');
            $this->info(Artisan::output());
            $this->info("Import Estimate Start !");
            Artisan::call('app:create-estimate');
            $this->info(Artisan::output());
            $this->info("Import Invoces Start !");
            Artisan::call('app:create-invoice');
            $this->info(Artisan::output());

            $this->info("Import Ok !");
        } catch (RequestException $e) {
            Log::error("An error occurred: " . $e->getMessage());
            $this->error("An error occurred: " . $e->getMessage());
        }
    }
}
