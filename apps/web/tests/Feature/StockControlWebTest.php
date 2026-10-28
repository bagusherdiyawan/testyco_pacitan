<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockControlWebTest extends TestCase
{
    private function apiSession(): array
    {
        return ['api_token' => 'private', 'api_expires_at' => time() + 1000, 'api_user' => ['id' => 1, 'name' => 'Pimpinan']];
    }

    private function fixtures(array $changes = []): void
    {
        $batch = ['id' => 'B1', 'sku' => 'AYAM10', 'condition' => 'frozen', 'batch_code' => 'LOT10', 'pack_size' => 10, 'expires_on' => '2030-01-01', 'usable_pieces' => 0, 'opened_pieces' => 4, 'ready_pieces' => 5, 'quarantined_pieces' => 2, 'waste_pieces' => 1, 'stock_revision' => 3];
        $count = ['id' => 'C1', 'batch' => $batch, 'purpose' => 'stocktake', 'bucket' => 'opened', 'status' => 'pending', 'version' => 1, 'snapshot_revision' => 3, 'expected_pieces' => 4, 'counted_pieces' => 1, 'difference_pieces' => -3, 'created_by' => 2, 'reason' => 'Hasil fisik', 'reviewed_by' => null, 'review_note' => null, 'is_stale' => false, 'can_review' => true, 'can_cancel' => false, ...$changes];
        Http::preventStrayRequests();
        Http::fake(['*/operations-context' => Http::response(['data' => ['id' => 'S1', 'name' => 'Pacitan', 'active' => true, 'can_manage_stock' => true]]), '*/inventory/batches' => Http::response(['data' => [$batch]]), '*/inventory/counts' => Http::response(['data' => [$count]]), '*/inventory/processes' => Http::response(['data' => [['kind' => 'cook', 'input_pieces' => 6, 'output_pieces' => 5, 'waste_pieces' => 1, 'reason' => 'Susut aktual', 'actor_id' => 2, 'created_at' => '2026-10-28T03:00:00Z']]])]);
    }

    public function test_stock_control_requires_session_and_denies_cashier_context(): void
    {
        $this->get('/app/stores/S1/stock-control')->assertRedirect('/login');
        Http::fake(['*' => Http::response(['data' => ['can_manage_stock' => false]])]);
        $this->withSession($this->apiSession())->get('/app/stores/S1/stock-control')->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_control_page_shows_real_states_revisions_and_separate_reviewer_controls(): void
    {
        $this->fixtures();
        $this->withSession($this->apiSession())->get('/app/stores/S1/stock-control')->assertOk()->assertSee('Buka kemasan beku')->assertSee('Beku terbuka')->assertSee('Siap jual')->assertSee('Susut kumulatif')->assertSee('expected_revision')->assertSee('snapshot 3')->assertSee('Setujui penyesuaian')->assertSee('28 Oct 2026 10:00')->assertSee('WIB')->assertDontSee('Batalkan pengajuan sendiri');
    }

    public function test_stale_count_is_visible_but_approval_button_is_disabled(): void
    {
        $this->fixtures(['is_stale' => true]);
        $html = $this->withSession($this->apiSession())->get('/app/stores/S1/stock-control')->assertOk()->assertSee('Snapshot kedaluwarsa')->getContent();
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>Setujui penyesuaian<\/button>/', $html);
    }

    public function test_creator_sees_cancel_not_self_approval_controls(): void
    {
        $this->fixtures(['created_by' => 1, 'can_review' => false, 'can_cancel' => true]);
        $this->withSession($this->apiSession())->get('/app/stores/S1/stock-control')->assertOk()->assertSee('Batalkan pengajuan sendiri')->assertDontSee('Setujui penyesuaian');
    }

    public function test_process_forwards_only_actual_inputs_and_stable_operation_key(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'P1']], 201)]);
        $this->withSession($this->apiSession())->post('/app/stores/S1/stock-control/processes', ['operation_id' => 'OP', 'batch_id' => 'B1', 'expected_revision' => 3, 'kind' => 'cook', 'input_pieces' => 6, 'output_pieces' => 5, 'reason' => 'Hasil fisik', 'ready_pieces' => 999, 'waste_pieces' => 0, 'organization_id' => 'FORGED'])->assertRedirect('/app/stores/S1/stock-control');
        Http::assertSent(fn ($r) => $r['input_pieces'] === 6 && $r['output_pieces'] === 5 && $r['operation_id'] === 'OP' && ! isset($r['ready_pieces']) && ! isset($r['waste_pieces']) && ! isset($r['organization_id']));
    }

    public function test_count_forwards_physical_count_but_not_computed_balance_status_or_reviewer(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'C1']], 201)]);
        $this->withSession($this->apiSession())->post('/app/stores/S1/stock-control/counts', ['operation_id' => 'OP', 'batch_id' => 'B1', 'expected_revision' => 3, 'purpose' => 'stocktake', 'bucket' => 'opened', 'counted_pieces' => 1, 'reason' => 'Hitung fisik', 'expected_pieces' => 999, 'difference_pieces' => 999, 'status' => 'approved', 'reviewed_by' => 1])->assertRedirect('/app/stores/S1/stock-control');
        Http::assertSent(fn ($r) => $r['counted_pieces'] === 1 && ! isset($r['expected_pieces']) && ! isset($r['difference_pieces']) && ! isset($r['status']) && ! isset($r['reviewed_by']));
    }

    public function test_review_requires_current_count_version_and_forwards_no_direct_adjustment(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'C1']])]);
        $this->withSession($this->apiSession())->post('/app/stores/S1/stock-control/counts/C1/approve', ['operation_id' => 'OP', 'version' => 1, 'review_note' => 'Ditinjau', 'counted_pieces' => 999])->assertRedirect('/app/stores/S1/stock-control');
        Http::assertSent(fn ($r) => $r['version'] === 1 && $r['review_note'] === 'Ditinjau' && ! isset($r['counted_pieces']));
    }

    public function test_api_validation_is_shown_without_faking_a_successful_movement(): void
    {
        Http::fake(['*' => Http::response(['errors' => ['quantity' => ['Hasil melebihi input.']]], 422)]);
        $this->withSession($this->apiSession())->post('/app/stores/S1/stock-control/processes', [])->assertSessionHasErrors('quantity')->assertSessionMissing('status');
    }
}
