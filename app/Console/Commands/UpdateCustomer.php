<?php

namespace App\Console\Commands;

use App\Services\PennyLaneService;
use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use App\Models\CustomerSyncModel;
use Log;
use Illuminate\Support\Facades\Hash;

/**
 * The UpdateCustomer command synchronizes customer data from Holded to PennyLane and saves the synchronization data.
 */
class UpdateCustomer extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-customer';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update a customer in PennyLane from Holded data';

    /**
     * HTTP client for making requests.
     *
     * @var Client
     */
    private $client;
    protected $pennyLaneService;

    /**
     * Constructor initializes the HTTP client with headers including the API key from the environment configuration.
     */
    public function __construct(PennyLaneService $pennyLaneService)
    {
        parent::__construct();
        $this->pennyLaneService = $pennyLaneService;
        $this->client = new Client([
            'headers' => [
                'accept' => 'application/json',
                'key' => env("KEY_HOLDED_TEST"),
            ]
        ]);
    }

    /**
     * Main execution function of the command. It retrieves customer data from Holded,
     * filters out already processed customers, creates customers in PennyLane, and saves the sync data.
     */
    public function handle()
    {
        try {
            $all_customer_in_table = CustomerSyncModel::all();

            $count_customer = count($all_customer_in_table);
            $processed = 0;
            foreach ($all_customer_in_table as $contact) {
                if ($contact['id_pennylane'] !== null) {
                    $response_getcontact_data = json_decode($this->client->get("https://api.holded.com/api/invoicing/v1/contacts/{$contact['id_holded']}")->getBody(), true);

                    $this->pennyLaneService->updateCustomerPennylane($response_getcontact_data, $contact['id_pennylane']);

                    CustomerSyncModel::where('id_holded', $contact['id_holded'])->update([
                        'name' => $contact['name'],
                        'checksum' => Hash::make(json_encode($response_getcontact_data)),
                        'updatedat_holded' => date('Y-m-d H:i:s', $contact['updatedAt'])
                    ]);


                    $processed++;
                }
            }

            $this->info("Import finished, $processed customers imported!");
        } catch (RequestException $e) {
            Log::error("An error occurred: " . $e->getMessage());
            $this->error("An error occurred: " . $e->getMessage());
        }
    }

    /**
     * Extracts unique contact persons from the list of contacts.
     *
     * @param array $contacts The list of contacts from Holded.
     * @return array Unique contact persons.
     */
    private function extractUniqueContactPersons(array $contacts)
    {
        $uniqueContactPersons = [];
        foreach ($contacts as $contact) {
            if (!isset($contact['contactPersons']) || $contact['contactPersons'] === []) {
                $uniqueContactPersons[$contact['id']] = $contact;
            } else {
                foreach ($contact['contactPersons'] as $person) {
                    $uniqueContactPersons[$person['personId']] = $person;
                }
            }
        }
        return array_values($uniqueContactPersons);
    }

    /**
     * Determines if a contact should be processed based on whether it already exists in the synchronization table.
     *
     * @param array $contact A contact person's data.
     * @return bool True if the contact should be processed; otherwise, false.
     */
    public function shouldProcessContact(array $contact)
    {
        $customerSyncModel = CustomerSyncModel::pluck('id_holded')->all();
        return !in_array($contact['personId'], $customerSyncModel);
    }

    /**
     * Saves the customer synchronization data to the database.
     *
     * @param array $contact Contact data from Holded.
     * @param array $pennylaneData Data returned from PennyLane upon successful creation.
     * @param array $originalData Original contact data used for creating the customer in PennyLane.
     */
    public function saveCustomerSyncModel($contact, $pennylaneData, $originalData)
    {
        $contactPerson = new CustomerSyncModel();
        $contactPerson->name = $contact['name'];
        $contactPerson->id_holded = $contact['personId'];
        //  $contactPerson->id_pennylane = $pennylaneData['source_id'];
        $contactPerson->checksum = Hash::make(json_encode($originalData));
        $contactPerson->save();
    }
}
