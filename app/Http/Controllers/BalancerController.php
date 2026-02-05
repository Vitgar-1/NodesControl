<?php

namespace App\Http\Controllers;

use App\Http\Requests\PressRequest;
use App\Services\BalancerService;
use Illuminate\Http\JsonResponse;

class BalancerController extends Controller
{
    public function __construct(
        private readonly BalancerService $balancerService,
    ) {}

    public function press(PressRequest $request): JsonResponse
    {
        $dto = $request->toDTO();

        $response = $this->balancerService->press($dto->sessionId);

        return response()->json(['message' => $response]);
    }
}