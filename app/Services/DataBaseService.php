<?php

namespace App\Services;

use App\Models\CustomerSyncModel;
use App\Models\ProductSyncModel;
use Log;
use Illuminate\Support\Facades\Hash;
use GuzzleHttp\Exception\RequestException;

class DataBaseService
{
    public function getIdCustomer($id)
    {
        // Assuming $id is a single value, not an array
        $customerSyncModel = CustomerSyncModel::where('id_holded', $id)->first();
        return $customerSyncModel ? $customerSyncModel->id_pennylane : null;
    }


    public function getChecksumCustomer($id)
    {
        // Assuming $id is a single value, not an array
        $customerSyncModel = CustomerSyncModel::where('id_holded', $id)->first();
        return $customerSyncModel ? $customerSyncModel->id_pennylane : null;
    }

    public function getAllIdProducts(array $ids)
    {
        // This returns a collection of models. Consider how you'll use this collection in your context.
        $productsSyncModels = ProductSyncModel::whereIn('id_holded', $ids)->get();
        return $productsSyncModels; // Assuming you need an array of 'id_pennylane'
    }

    public function createCustomer(array $data, $pennylaneSourceId, callable $callback = null)
    {
        try {
            $contactPerson = new CustomerSyncModel();
            $contactPerson->name = $data['name'];
            $contactPerson->id_holded = $data['id'];
            $contactPerson->id_pennylane = $pennylaneSourceId;
            $contactPerson->checksum = Hash::make(json_encode($data));
            $contactPerson->updatedat_holded = isset($data['updatedAt']) ? date('Y-m-d H:i:s', strtotime($data['updatedAt'])) : null;
            $contactPerson->save();

            // Invoke callback after successful creation
            if (is_callable($callback)) {
                $callback(true, null);
            }

            return true;
        } catch (RequestException $e) {
            Log::error("Error creating customer for {$data['id']}: " . $e->getMessage());
            if (is_callable($callback)) {
                // Invoke callback upon catching an exception
                $callback(false, $e);
            }
            return false;
        }
    }
}
