<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddressStoreRequest;
use App\Http\Resources\AddressResource;
use App\Models\UserAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    public function index(): JsonResponse
    {
        $addresses = UserAddress::where('user_id', Auth::id())
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => true,
            'data' => AddressResource::collection($addresses),
            'message' => 'Addresses retrieved successfully',
        ]);
    }

    public function store(AddressStoreRequest $request): JsonResponse
    {
        $address = DB::transaction(function () use ($request) {
            $data = $request->validated();
            $data['user_id'] = Auth::id();
            $data['address_type'] = $data['address_type'] ?? 'home';
            $data['is_default'] = (bool) ($data['is_default'] ?? false);

            if ($data['is_default']) {
                $this->clearDefaultAddresses(Auth::id());
            }

            return UserAddress::create($data);
        });

        return response()->json([
            'status' => true,
            'data' => new AddressResource($address),
            'message' => 'Address saved successfully',
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $address = $this->findUserAddress($id);

        return response()->json([
            'status' => true,
            'data' => new AddressResource($address),
            'message' => 'Address retrieved successfully',
        ]);
    }

    public function update(AddressStoreRequest $request, int $id): JsonResponse
    {
        $address = DB::transaction(function () use ($request, $id) {
            $address = $this->findUserAddress($id);
            $data = $request->validated();
            $data['address_type'] = $data['address_type'] ?? $address->address_type;

            if ((bool) ($data['is_default'] ?? false)) {
                $this->clearDefaultAddresses(Auth::id());
            }

            $address->update($data);

            return $address;
        });

        return response()->json([
            'status' => true,
            'data' => new AddressResource($address),
            'message' => 'Address updated successfully',
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->findUserAddress($id)->delete();

        return response()->json([
            'status' => true,
            'message' => 'Address deleted successfully',
        ]);
    }

    public function makeDefault(int $id): JsonResponse
    {
        $address = DB::transaction(function () use ($id) {
            $address = $this->findUserAddress($id);
            $this->clearDefaultAddresses(Auth::id());
            $address->update(['is_default' => true]);

            return $address;
        });

        return response()->json([
            'status' => true,
            'data' => new AddressResource($address),
            'message' => 'Default address updated successfully',
        ]);
    }

    private function findUserAddress(int $id): UserAddress
    {
        return UserAddress::where('user_id', Auth::id())->findOrFail($id);
    }

    private function clearDefaultAddresses(int $userId): void
    {
        UserAddress::where('user_id', $userId)->update(['is_default' => false]);
    }
}
