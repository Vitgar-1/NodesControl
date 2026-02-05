<?php

namespace App\Http\Controllers;

use App\Http\Requests\NodeActionRequest;
use App\Services\BalancerService;
use Illuminate\Http\JsonResponse;

class NodeController extends Controller
{
    public function __construct(
        private readonly BalancerService $balancerService,
    ) {}

    public function manage(NodeActionRequest $request): JsonResponse
    {
        $dto = $request->toDTO();

        match ($dto->action) {
            'add' => $this->balancerService->addNode($dto->address),
            'remove' => $this->balancerService->removeNode($dto->address),
        };

        return response()->json(['message' => 'OK']);
    }
}