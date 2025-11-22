<?php
declare(strict_types=1);

namespace Modules\UserManagement\Http\Controllers;

use App\Helpers\ResponseError;
use App\Http\Controllers\Controller;
use App\Http\Requests\FilterParamsRequest;
use App\Http\Resources\AreaResource;
use App\Http\Resources\CityResource;
use App\Models\Area;
use App\Models\City;
use App\Repositories\AreaRepository\AreaRepository;
use App\Repositories\CityRepository\CityRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LocationController extends Controller
{
    public function __construct(
        private AreaRepository $areaRepository,
        private CityRepository $cityRepository
    ) {
    }

    /**
     * Get all Areas of the system
     *
     * @param FilterParamsRequest $request
     * @return AnonymousResourceCollection
     */
    public function getAreas(FilterParamsRequest $request): AnonymousResourceCollection
    {
        $filter = $request->all();
        // If "all" is requested implicitly or we want to ensure we get many, 
        // we can adjust perPage if not provided.
        // But standard practice is to respect the repository's default or request's perPage.
        
        $areas = $this->areaRepository->paginate($filter);
        return AreaResource::collection($areas);
    }

    /**
     * Get the city based on a selected area.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function getCityByArea(int $id): JsonResponse
    {
        $area = $this->areaRepository->show(Area::find($id));
        
        if (!$area || !$area->city) {
             return $this->errorResponse(ResponseError::ERROR_404, 'City not found for this area');
        }

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR, locale: request('lang')),
            CityResource::make($area->city)
        );
    }

    /**
     * Get all areas belonging to a selected city.
     *
     * @param int $id
     * @return AnonymousResourceCollection
     */
    public function getAreasByCity(int $id): AnonymousResourceCollection
    {
        // We can filter areas by city_id using the repository's filter method.
        // AreaRepository::paginate supports 'city_id' in filter via Area::scopeFilter
        
        $filter = request()->all();
        $filter['city_id'] = $id;
        
        // Ensure we get active areas? Or all? Admin usually sees all.
        // FilterParamsRequest passes all query params.
        
        $areas = $this->areaRepository->paginate($filter);
        
        return AreaResource::collection($areas);
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
        ], 404);
    }
}

