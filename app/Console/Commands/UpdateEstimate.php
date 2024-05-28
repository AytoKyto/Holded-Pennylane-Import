<?php

namespace App\Console\Commands;

use App\Services\PennyLaneService;
use App\Services\DataBaseService;
use App\Services\UtilsService;
use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use App\Models\InvoiceSyncModel;
use App\Models\CustomerSyncModel;
use Log;
use Illuminate\Support\Facades\Hash;

class UpdateEstimate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-estimate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update a estimate in PennyLane from Holded data';

    /**
     * Instance of PennyLaneService.
     *
     * @var PennyLaneService
     */
    protected $pennyLaneService;
    protected $dataBaseService;
    protected $utilsService;

    /**
     * HTTP client for making requests.
     *
     * @var Client
     */
    private $holdedClient;
    private $pennylaneClient;

    /**
     * Constructor initializes the HTTP client with headers including the API key from the environment configuration,
     * and sets up the PennyLaneService.
     *
     * @param PennyLaneService $pennyLaneService The PennyLane service
     */
    public function __construct(PennyLaneService $pennyLaneService, DataBaseService $dataBaseService, UtilsService $utilsService)
    {
        parent::__construct();
        $this->pennyLaneService = $pennyLaneService;
        $this->dataBaseService = $dataBaseService;
        $this->utilsService = $utilsService;

        $this->holdedClient = new Client([
            'headers' => [
                'Accept' => 'application/json',
                'key' => env("KEY_HOLDED_TEST"),
            ]
        ]);
        $this->pennylaneClient = new Client([
            'headers' => [
                'Accept' => 'application/json',
                'authorization' => 'Bearer ' . env("KEY_PENNYLANE_TEST"),
            ]
        ]);
    }

    public function handle()
    {
        try {
            // Get all estimate with statu = 1 (Accepted)
            $data =  InvoiceSyncModel::where("invoice_statue", 0)->get();

            $processed = 0;
            foreach ($data as $estimate) {
                try {
                    // Create Estimate in Pennylane
                    $dataPennylane = $this->pennyLaneService->showInvoicePennylane($estimate['id_pennylane']);
                    if (isset($dataPennylane)) {
                        if ($dataPennylane['invoice']['paid']) {
                            $isPennylaneEstimateUpodated = $this->pennyLaneService->updateEstimateStatutPennylane($estimate['id_pennylane_estimate'], "invoiced");
                            if ($isPennylaneEstimateUpodated) {
                                $this->info('Updated success for : ' . $estimate['id_pennylane_estimate']);
                            } else {
                                $this->error('Updated failed for : ' . $estimate['id_pennylane_estimate']);
                            }

                            // Création d'une nouvelle instance du modèle.
                            InvoiceSyncModel::where('id_pennylane_estimate', $estimate['id_pennylane_estimate'])->update(['invoice_statue' => 1]);

                            $processed++;
                        }
                    }
                } catch (\Exception $e) {
                    Log::error("Failed to save estimate due to: " . $e->getMessage());
                    // Handle error appropriately, possibly continue to next estimate
                    $this->error("Updated of estimate failed!");
                }
            }

            $this->info("Updated finished, $processed estimates imported!");
        } catch (RequestException $e) {
            Log::error("An error occurred: " . $e->getMessage());
            $this->error("An error occurred: " . $e->getMessage());
        }
    }
}
