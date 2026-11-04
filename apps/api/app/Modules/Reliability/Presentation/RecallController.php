<?php

namespace App\Modules\Reliability\Presentation;

use App\Modules\Reliability\Application\RecallCoordinator;
use Illuminate\Http\Request;

final class RecallController
{
    public function __construct(private RecallCoordinator $work) {}

    public function index(Request $r, string $organization): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json($this->work->index($r->user(), $organization))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, string $organization, string $campaign): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json($this->work->show($r->user(), $organization, $campaign))->header('Cache-Control', 'private, no-store');
    }

    public function start(Request $r, string $organization): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'recall_id' => 'required|ulid', 'instructions' => 'required|string|max:4000']);

        return response()->json(['data' => $this->work->start($r->user(), $organization, $v)], 201);
    }

    public function refresh(Request $r, string $organization, string $campaign): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid']);

        return ['data' => $this->work->refresh($r->user(), $organization, $campaign, $v)];
    }

    public function close(Request $r, string $organization, string $campaign): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000']);

        return ['data' => $this->work->close($r->user(), $organization, $campaign, $v)];
    }

    public function decide(Request $r, string $organization, string $campaign, string $target): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1', 'action' => 'required|in:hold,release', 'observed_pieces' => 'required|integer|min:0|max:1000000000', 'evidence_reference' => 'required|string|max:2000', 'reason' => 'required|string|max:2000']);

        return ['data' => $this->work->decide($r->user(), $organization, $campaign, $target, $v)];
    }
}
