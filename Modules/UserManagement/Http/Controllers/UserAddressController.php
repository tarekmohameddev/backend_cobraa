<?php
declare(strict_types=1);

namespace Modules\UserManagement\Http\Controllers;

use App\Helpers\ResponseError;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;use App\Models\Area;
use App\Models\User;
use App\Models\Region;
use App\Services\UserAddressService\UserAddressService;
use App\Services\UserServices\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\UserManagement\Http\Requests\StoreUserAddressRequest;
use Modules\UserManagement\Http\Requests\StoreExistingUserAddressRequest;
use Throwable;

class UserAddressController extends Controller
{
    public function __construct(
        private UserService $userService,
        private UserAddressService $addressService
    ) {
    }

    /**
     * Create a new user and address in one request.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), (new StoreUserAddressRequest)->rules());

        if ($validator->fails()) {
            return response()->json([
                'timestamp' => now(),
                'status' => false,
                'statusCode' => ResponseError::ERROR_400,
                'message' => __('errors.' . ResponseError::ERROR_400, [], request('lang', 'en')),
                'params' => $validator->errors()->messages(),
            ], 400);
        }

        $validated = $validator->validated();

        // Split name
        $nameParts = explode(' ', $validated['name'], 2);
        $firstname = $nameParts[0];
        $lastname = $nameParts[1] ?? '';

        DB::beginTransaction();
        try {
            // 1. Create User
            // We need to prepare data for UserService::create
            // Assuming role is 'user' by default
            $userData = [
                'firstname' => $firstname,
                'lastname'  => $lastname,
                'phone'     => $validated['phone'],
                'role'      => 'user',
                // 'password' will be defaulted in service if not provided
                // 'email' is not required by prompt, assuming nullable in DB or not needed
            ];

            $userResult = $this->userService->create($userData);

            if (!data_get($userResult, 'status')) {
                throw new \Exception(data_get($userResult, 'message', 'User creation failed'));
            }

            $user = data_get($userResult, 'data');

            // 2. Resolve location data from area (and optionally provided city)
            $area = Area::find($validated['area_id']);

            if (!$area) {
                throw new \Exception('Area not found for the provided area_id');
            }

            // Prefer an explicitly provided city_id, otherwise derive it from the area.
            $cityId = $validated['city_id'] ?? $area->city_id;

            if (!$cityId) {
                throw new \Exception('Unable to determine city_id from the provided area_id');
            }

            // Derive region and country from the area as well.
            $regionId  = $area->region_id ?? Region::active()->first()?->id ?? Region::first()?->id;
            $countryId = $area->country_id;

            if (!$countryId) {
                throw new \Exception('Unable to determine country_id from the provided area_id');
            }
            
            $addressData = [
                'user_id'   => $user->id,
                'region_id' => $regionId,
                'country_id'=> $countryId,
                'title'     => $validated['title'],
                'city_id'   => $cityId,
                'area_id'   => $validated['area_id'],
                // Store address as an array so it becomes JSON like: {"address": "Bänikonstrasse, ..."}
                'address'   => [
                    'address' => $validated['address'],
                ],
                'firstname' => $firstname, // Address contact name defaults to user name
                'lastname'  => $lastname,
                'phone'     => $validated['second_phone'] ?? $validated['phone'], // Use second_phone if available, else main phone
                'active'    => true,
                // 'note' doesn't seem to be a standard column in UserAddress from the model dump (it has additional_details), 
                // but prompt asks for 'note'. I will map 'note' to 'additional_details'.
                'additional_details' => $validated['note'] ?? null,
                'location'  => $validated['location'] ?? null,
            ];

            $addressResult = $this->addressService->create($addressData);

            if (!data_get($addressResult, 'status')) {
                throw new \Exception(data_get($addressResult, 'message', 'Address creation failed'));
            }

            // Commit transaction
            DB::commit();

            // Return the user with their new data (UserService usually returns the fresh user)
            // Or we can return what the prompt implies.
            // The prompt says "Create a new user (from POS) + create an address".
            // Existing system returns UserResource.

            return $this->successResponse(
                __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_CREATED, locale: request('lang')),
                UserResource::make($user->load('addresses'))
            );

        } catch (Throwable $e) {
            DB::rollBack();
            return $this->errorResponse(
                ResponseError::ERROR_400, 
                $e->getMessage()
            );
        }
    }

    /**
     * Create a new address for an existing user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function storeExistingUserAddress(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), (new StoreExistingUserAddressRequest)->rules());

        if ($validator->fails()) {
            return response()->json([
                'timestamp'  => now(),
                'status'     => false,
                'statusCode' => ResponseError::ERROR_400,
                'message'    => __('errors.' . ResponseError::ERROR_400, [], request('lang', 'en')),
                'params'     => $validator->errors()->messages(),
            ], 400);
        }

        $validated = $validator->validated();

        try {
            $user = User::find($validated['user_id']);

            if (!$user) {
                throw new \Exception('User not found for the provided user_id');
            }

            // Resolve location data from area (and optionally provided city)
            $area = Area::find($validated['area_id']);

            if (!$area) {
                throw new \Exception('Area not found for the provided area_id');
            }

            // Prefer an explicitly provided city_id, otherwise derive it from the area.
            $cityId = $validated['city_id'] ?? $area->city_id;

            if (!$cityId) {
                throw new \Exception('Unable to determine city_id from the provided area_id');
            }

            // Derive region and country from the area as well.
            $regionId  = $area->region_id ?? Region::active()->first()?->id ?? Region::first()?->id;
            $countryId = $area->country_id;

            if (!$countryId) {
                throw new \Exception('Unable to determine country_id from the provided area_id');
            }

            $addressData = [
                'user_id'            => $user->id,
                'region_id'          => $regionId,
                'country_id'         => $countryId,
                'title'              => $validated['title'],
                'city_id'            => $cityId,
                'area_id'            => $validated['area_id'],
                // Store address as an array so it becomes JSON like: {"address": "Bänikonstrasse, ..."}
                'address'            => [
                    'address' => $validated['address'],
                ],
                'firstname'          => $user->firstname,
                'lastname'           => $user->lastname,
                'phone'              => $user->phone,
                'active'             => true,
                'additional_details' => $validated['note'] ?? null,
                'location'           => $validated['location'] ?? null,
            ];

            $addressResult = $this->addressService->create($addressData);

            if (!data_get($addressResult, 'status')) {
                throw new \Exception(data_get($addressResult, 'message', 'Address creation failed'));
            }

            return $this->successResponse(
                __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_CREATED, locale: request('lang')),
                UserResource::make(data_get($addressResult, 'data'))
            );
        } catch (Throwable $e) {
            return $this->errorResponse(
                ResponseError::ERROR_400,
                $e->getMessage()
            );
        }
    }

    /**
     * Success Response
     */
    private function successResponse($message, $data): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data
        ]);
    }

    /**
     * Error Response
     */
    private function errorResponse($code, $message): JsonResponse
    {
        return response()->json([
            'status' => false,
            'code' => $code,
            'message' => $message
        ], 400); // Using 400 as generic error status
    }
}
