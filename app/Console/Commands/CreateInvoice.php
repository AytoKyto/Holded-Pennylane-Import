<?php

namespace App\Console\Commands;

use App\Models\InvoiceSyncModel;
use App\Models\EstimateSyncModel;
use App\Services\PennyLaneService;
use App\Services\DataBaseService;
use App\Services\UtilsService;
use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Log;
use Illuminate\Support\Facades\Hash;

class CreateInvoice extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-invoice';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a invoice in PennyLane from Holded data';

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
            $response = $this->holdedClient->get('https://api.holded.com/api/invoicing/v1/documents/invoice?paid=0');
            $invoices = json_decode($response->getBody(), true);
            $this->info('Retrieved invoices from Holded successfully!');


            $processed = 0;
            foreach ($invoices as $invoice) {
                $val = $this->shouldProcessInvoice($invoice['id']);
                if (!$val) {
                    try {
                        // Create Invoice in Pennylane
                        $pennylaneData = $this->createInvoicePennylane($invoice);

                        $processed++;
                    } catch (\Exception $e) {
                        Log::error("Failed to save invoice {$invoice['id']} due to: " . $e->getMessage());
                        // Handle error appropriately, possibly continue to next invoice
                        $this->error("Creation of invoice {$invoice['id']} failed!");
                    }
                }
            }

            $this->info("Import finished, $processed invoices imported!");
        } catch (RequestException $e) {
            Log::error("An error occurred: " . $e->getMessage());
            $this->error("An error occurred: " . $e->getMessage());
        }
    }

    private function createInvoicePennylane($data)
    {
        try {
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

            $pennylaneDataIdCustomer = $this->dataBaseService->getIdCustomer($data['contact']);
            $this->info("Customer {$pennylaneDataIdCustomer}");
            $invoiceData = [
                "create_customer" => false,
                "create_products" => false,
                "file_url" => "https://app.holded.com/box/doc/65fd462c8d908b418d00c741?p=invoices/{$data['id']}/{$data['id']}",
                "invoice" => [
                    "customer" => ["source_id" => $pennylaneDataIdCustomer],
                    "date" => date("Y-m-d", $data['date']),
                    "deadline" => date("Y-m-d", $data['dueDate']),
                    "external_id" => $data['id'],
                    "invoice_number" => $data['docNumber'],
                    "line_items" => $lineItems
                ]
            ];

            $response = $this->pennylaneClient->post('https://app.pennylane.com/api/external/v1/customer_invoices/import', [
                'json' => $invoiceData,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($data['from']['id'])) {
                $idEstimateHolded = EstimateSyncModel::where('id_holded', $data['from']['id'])->first();
                $estimateSyncModel = new InvoiceSyncModel();
                $estimateSyncModel->id_holded = $data['id'];
                $estimateSyncModel->id_pennylane =  $body['invoice']['id'];
                $estimateSyncModel->id_holded_estimate = $idEstimateHolded['id_holded'];
                $estimateSyncModel->id_pennylane_estimate = $idEstimateHolded['id_pennylane'];
                $estimateSyncModel->checksum = Hash::make(json_encode($data));
                $estimateSyncModel->save();
            }

            return $body['data'] ?? null;
        } catch (RequestException $e) {
            Log::error("Error creating invoice in PennyLane for " . $e->getMessage());
            $this->error("Creation of invoice failed!");
            return null;
        }
    }

    /**
     * Détermine si la facture doit être traitée basé sur le statut de la réponse HTTP.
     *
     * @param string $id Identifiant de la facture à vérifier.
     * @return bool Retourne true si le statut de la réponse est 200, sinon false.
     */
    private function shouldProcessInvoice(string $id): bool
    {
        try {
            // Effectue la requête GET et récupère la réponse.
            $response = $this->pennylaneClient->get("https://app.pennylane.com/api/external/v1/customer_invoices/" . $id);
            // Vérifie si le statut de la réponse est 200.
            return $response->getStatusCode() == 200;
        } catch (\Exception $e) {
            // En cas d'erreur (par exemple, une exception est levée si la requête échoue), retourne false.
            return false;
        }
    }
}
