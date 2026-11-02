<?php

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\UserAccountStatus;
use Illuminate\Http\Request;

final class UserReactivationController
{
    public function __construct(private UserAccountStatus $accounts) {}

    public function show(Request $r, string $organization, int $user): mixed
    {
        return response()->json(['data' => $this->accounts->context($r->user(), $organization, $user)])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $r, string $organization, int $user): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000']);

        return response()->json(['data' => $this->accounts->reactivate($r->user(), $organization, $user, $v)])->header('Cache-Control', 'private, no-store');
    }
}
