<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class PennyLaneService
{
    /**
     * HTTP client for making requests.
     *
     * @var Client
     */
    private $client;

    /**
     * Constructor initializes the HTTP client with headers including the API key from the environment configuration.
     */
    public function __construct()
    {
        $this->client = new Client([
            'headers' => [
                'Accept' => 'application/json',
                'authorization' => 'Bearer ' . env("KEY_PENNYLANE_TEST"),
            ]
        ]);
    }

    public function createCustomerPennylane($data)
    {
        try {
            $response = $this->client->post('https://app.pennylane.com/api/external/v1/customers', [
                'json' => [
                    'customer' => [
                        "customer_type" => "company",
                        "name" => $data['name'],
                        "phone" => $data['phone'],
                        "address" => $data['billAddress']['address'],
                        "postal_code" => $data['billAddress']['postalCode'],
                        "city" => $data['billAddress']['city'],
                        "country_alpha2" => $data['billAddress']['countryCode'],
                    ]
                ]
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            Log::info("Creation of customer {$data['id']} succeeded!");

            return $body['data'] ?? null;
        } catch (RequestException $e) {
            Log::error("Error creating customer in PennyLane for {$data['id']}: " . $e->getMessage());
            Log::error("Creation of customer {$data['id']} failed!");
        }
    }


    public function createEstilatePennylane($data)
    {
        try {
            $response = $this->client->post('https://app.pennylane.com/api/external/v1/customer_estimates', [
                'json' => $data,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            return $body;
        } catch (RequestException $e) {
            return $e;
            Log::error("createEstilatePennylane failed!");
        }
    }

    public function updateCustomerPennylane($data, $id)
    {
        try {
            $response = $this->client->post('https://app.pennylane.com/api/external/v1/customers/' . $id, [
                'json' => [
                    'customer' => [
                        "customer_type" => "company",
                        "name" => $data['name'],
                        "phone" => $data['phone'],
                        "address" => $data['billAddress']['address'],
                        "postal_code" => $data['billAddress']['postalCode'],
                        "city" => $data['billAddress']['city'],
                        "country_alpha2" => $data['billAddress']['countryCode'],
                    ]
                ]
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            Log::info("Creation of customer {$data['id']} succeeded!");

            return $body['data'] ?? null;
        } catch (RequestException $e) {
            Log::error("Error creating customer in PennyLane for {$data['id']}: " . $e->getMessage());
            Log::error("Creation of customer {$data['id']} failed!");
        }
    }

    public function updateEstimateStatutPennylane(string $id, string $value)
    { {
            try {
                $this->client->put('https://app.pennylane.com/api/external/v1/customer_estimates/' . $id, [
                    'json' => [
                        'invoice' => [
                            "estimate_status" => $value,
                        ]
                    ]
                ]);


                Log::info("Update of estimate succeeded!");

                return true;
            } catch (RequestException $e) {
                Log::error("Error updated estimate in PennyLane for : " . $e->getMessage());
                Log::error("Update of estimate failed!");
                return false;
            }
        }
    }

    public function showInvoicePennylane($id)
    {
        try {
            return json_decode($this->client->get("https://app.pennylane.com/api/external/v1/customer_invoices/{$id}")->getBody(), true);
        } catch (RequestException $e) {
            return null;
        }
    }
}
