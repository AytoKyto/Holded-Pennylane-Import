<?php

namespace App\Console\Commands;

use App\Services\PennyLaneService;
use App\Services\DataBaseService;
use App\Services\UtilsService;
use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use App\Models\ProductSyncModel;

use Log;
use Illuminate\Support\Facades\Hash;

class CreateProduct extends Command
{
    protected $signature = 'app:create-product';
    protected $description = 'Create products in PennyLane from Holded data';

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


    public function __construct(PennyLaneService $pennyLaneService, DataBaseService $dataBaseService, UtilsService $utilsService)
    {
        parent::__construct();
        $this->pennyLaneService = $pennyLaneService;
        $this->dataBaseService = $dataBaseService;
        $this->utilsService = $utilsService;

        $this->holdedClient = new Client([
            'headers' => [
                'Accept' => 'application/json',
                'key' => env("KEY_HOLDED"),
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
            $response = $this->holdedClient->get('https://api.holded.com/api/invoicing/v1/products');
            $products = json_decode($response->getBody(), true);
            $this->info('Retrieved products from Holded successfully!');

            $processed = 0;
            foreach ($products as $product) {
                if ($this->shouldProcessProduct($product)) {

                    $pennylaneData = $this->createProductPennylane($product); // Correctly use the actual method

                    if ($pennylaneData) {
                        try {
                            $productSyncModel = new ProductSyncModel();
                            $productSyncModel->name = $product['name'];
                            $productSyncModel->id_holded = $product['id'];
                            $productSyncModel->id_pennylane = $pennylaneData['product']['source_id']; // Assuming 'id' is returned
                            $productSyncModel->checksum = Hash::make(json_encode($product));
                            $productSyncModel->save();

                            $processed++;
                        } catch (\Exception $e) {
                            Log::error("Failed to save product {$product['id']} due to: " . $e->getMessage());
                            $this->error("Creation of product {$product['id']} failed!");
                        }
                    }
                }
            }

            $this->info("Import finished, $processed products imported!");
        } catch (RequestException $e) {
            Log::error("An error occurred: " . $e->getMessage());
            $this->error("An error occurred: " . $e->getMessage());
        }
    }

    private function shouldProcessProduct(array $product)
    {
        $existingProductIds = ProductSyncModel::pluck('id_holded')->all();
        return !in_array($product['id'], $existingProductIds);
    }

    private function createProductPennylane($data)
    {
        try {
            $response = $this->pennylaneClient->post('https://app.pennylane.com/api/external/v1/products', [ // Adjusted endpoint
                'body' => json_encode([
                    'product' => [
                        "vat_rate" => $this->utilsService->getTvaValue(isset($data['taxes'][0]) ? $data['taxes'][0] : 20),
                        "label" => $data['name'],
                        "unit" => "piece",
                        "price" =>  $this->utilsService->trasnformValueTva(isset($data['taxes'][0]) ? $data['taxes'][0] : 20, $data['price']),
                        "currency" => "EUR",
                    ]
                ]),
                'headers' => [
                    'accept' => 'application/json',
                    'authorization' => 'Bearer ' . env("KEY_PENNYLANE_TEST"),
                    'content-type' => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $this->info("Creation of product {$data['id']} succeeded!");

            return $body ?? null; // Ensure you have a 'data' key or adjust accordingly
        } catch (RequestException $e) {
            Log::error("Error creating product in PennyLane for {$data['id']}: " . $e->getMessage());
            $this->error("Creation of product {$data['id']} failed!");
            return null;
        }
    }
}
