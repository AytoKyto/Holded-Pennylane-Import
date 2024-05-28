<?php

namespace App\Console\Commands;

use App\Services\PennyLaneService;
use App\Services\DataBaseService;
use App\Services\UtilsService;
use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use App\Models\EstimateSyncModel;
use App\Models\CustomerSyncModel;
use Log;
use Illuminate\Support\Facades\Hash;

class CreateEstimate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-estimate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a estimate in PennyLane from Holded data';

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
            $response = $this->holdedClient->get('https://api.holded.com/api/invoicing/v1/documents/estimate?paid=1');
            $estimates = json_decode($response->getBody(), true);

            $processed = 0;
            foreach ($estimates as $estimate) {
                if ($this->shouldProcessEstimate($estimate)) {
                    try {
                        // Create Estimate in Pennylane
                        $pennylaneData = $this->createEstimatePennylane($estimate);

                        $estimateSyncModel = new EstimateSyncModel();
                        $estimateSyncModel->id_holded = $estimate['id'];
                        $estimateSyncModel->id_pennylane =  $pennylaneData['estimate']['id']; // Use dynamic data if available
                        $estimateSyncModel->checksum = Hash::make(json_encode($estimate)); // Encode once, if $estimate is an array
                        $estimateSyncModel->save();
                        $processed++;
                    } catch (\Exception $e) {
                        Log::error("Failed to save estimate {$estimate['id']} due to: " . $e->getMessage());
                        // Handle error appropriately, possibly continue to next estimate
                        $this->error("Creation of estimate {$estimate['id']} failed!");
                    }
                }
            }

            $this->info("Import finished, $processed estimates imported!");
        } catch (RequestException $e) {
            Log::error("An error occurred: " . $e->getMessage());
            $this->error("An error occurred: " . $e->getMessage());
        }
    }

    private function createEstimatePennylane($data)
    {
        try {
            $pennylaneDataIdCustomer = $this->dataBaseService->getIdCustomer($data['contact']);
            $this->info("Customer {$pennylaneDataIdCustomer}");

            $customer_data = null;
            $is_customer_create = true; // Initialize assuming a customer needs to be created

            // If no existing Pennylane customer ID is found, prepare data to create a new customer
            if ($pennylaneDataIdCustomer === null) {
                $response_getcontact_data = json_decode($this->holdedClient->get("https://api.holded.com/api/invoicing/v1/contacts/{$data['contact']}")->getBody(), true);
                $customer_data = [
                    "customer_type" => "company",
                    "name" => $response_getcontact_data['name'],
                    "phone" => $response_getcontact_data['phone'],
                    "address" => $response_getcontact_data['billAddress']['address'],
                    "postal_code" => $response_getcontact_data['billAddress']['postalCode'],
                    "city" => $response_getcontact_data['billAddress']['city'],
                    "country_alpha2" => $response_getcontact_data['billAddress']['countryCode'],
                ]; // Missing semicolon added

            } else {
                // If an existing customer is found, use the source ID and indicate no need to create a new customer
                $pennylaneDataIdCustomer = $this->dataBaseService->getChecksumCustomer($data['contact']);

                $response_getcontact_data = json_decode($this->holdedClient->get("https://api.holded.com/api/invoicing/v1/contacts/{$data['contact']}")->getBody(), true);

                if ($pennylaneDataIdCustomer === null) {
                    if ($pennylaneDataIdCustomer !== Hash::make(json_encode($response_getcontact_data))) {
                        $this->pennyLaneService->updateCustomerPennylane($response_getcontact_data, $pennylaneDataIdCustomer);
                    }
                }
                $this->info("No create customer {$response_getcontact_data['name']}");
                $customer_data = ["source_id" => $pennylaneDataIdCustomer];
                $is_customer_create = false; // This flag should be false here as we're not creating a new customer
            } // Missing closing bracket added for else block


            $lineItems = [];
            $idsProducts = [];

            // First pass: Collect all product or service IDs from the products array.
            // This avoids repeated iterations over the same array.
            foreach ($data['products'] as $product) {
                if (isset($product['productId'])) {
                    // If the current product has a productId, add it to the collection.
                    $idsProducts[] = $product['productId'];
                } elseif (isset($product['serviceId'])) {
                    // If the current product has a serviceId instead, add that to the collection.
                    $idsProducts[] = $product['serviceId'];
                }
                // Products without a productId or serviceId are not added to the collection.
            }

            // Retrieve matching product data for all collected IDs in one operation.
            $pennylaneDataIdProducts = $this->dataBaseService->getAllIdProducts($idsProducts);

            // Second pass: Process each product in the products array for output preparation.
            foreach ($data['products'] as $product) {
                // Determine whether the current product has a productId or serviceId.
                // Assign the corresponding key to $idKey or null if neither exists.
                $idKey = isset($product['productId']) ? 'productId' : (isset($product['serviceId']) ? 'serviceId' : null);

                if ($idKey) {
                    // If a relevant ID key exists, find the first matching product in the retrieved data.
                    $matchedItem = $pennylaneDataIdProducts->firstWhere('id_holded', $product[$idKey]);
                    // Use the matched item to extract the 'id_pennylane' value, defaulting to null if not found.
                    $sourceId = $matchedItem ? $matchedItem['id_pennylane'] : null;

                    if ($sourceId) {
                        // If a source ID was found, prepare the product data for output.
                        $lineItems[] = [
                            "product" => ["source_id" => $sourceId],
                            "label" => $product['name'], // Assuming 'name' exists and is correct.
                            "quantity" => $product['units'] // Assuming 'units' exists and is correct.
                        ];
                    }
                } else {
                    // For products without a productId or serviceId, prepare a default output format.
                    $lineItems[] = [
                        "vat_rate" => $this->utilsService->getTvaValue(isset($product['taxes'][0]) ? $product['taxes'][0] : 20),
                        "label" => $product['name'], // Ensure 'name' exists and is correct.
                        "quantity" => $product['units'], // Ensure 'units' exists and is correct.
                        "currency_amount" => $this->utilsService->trasnformValueTva(isset($product['taxes'][0]) ? $product['taxes'][0] : 20, $product['price']),
                        "unit" => "piece"
                    ];
                }
            }

            $estimateData = [
                "create_customer" => $is_customer_create,
                "create_products" => false,
                "estimate" => [
                    "customer" => $customer_data,
                    "date" => date("Y-m-d", $data['date']),
                    "deadline" => date("Y-m-d", $data['dueDate']),
                    "pdf_invoice_free_text" => $data['notes'],
                    "pdf_invoice_subject" => $data['docNumber'],
                    "line_items" => $lineItems
                ]
            ];

            $pennylaneDataIdProducts = $this->pennyLaneService->createEstilatePennylane($estimateData);
            $isPennylaneEstimateUpodated = $this->pennyLaneService->updateEstimateStatutPennylane($pennylaneDataIdProducts['estimate']['id'], "accepted");
            if ($isPennylaneEstimateUpodated) {
                $this->info('Updated success for : ' . $pennylaneDataIdProducts['estimate']['id']);
            } else {
                $this->error('Updated failed for : ' . $pennylaneDataIdProducts['estimate']['id']);
            }

            if ($pennylaneDataIdCustomer === null) {
                $this->dataBaseService->createCustomer($response_getcontact_data, $pennylaneDataIdProducts['estimate']['customer']['source_id'], function ($success, $error) {
                    if ($success) {
                        $this->info("Customer creation successful.");
                    } else {
                        $this->info("Customer creation failed. Error: " . $error->getMessage());
                    }
                });
            }

            return $pennylaneDataIdProducts;
        } catch (RequestException $e) {
            Log::error("Error creating estimate in PennyLane for " . $e->getMessage());
            return null;
        }
    }

    private function shouldProcessEstimate(array $contact)
    {
        $customerSyncModel = EstimateSyncModel::pluck('id_holded')->all();
        return !in_array($contact['id'], $customerSyncModel);
    }
}
