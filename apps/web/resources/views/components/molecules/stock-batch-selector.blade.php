@props(['batches'])
<label class="field">Batch dan saldo saat halaman dimuat<select name="batch_id" data-stock-batch required>
@foreach($batches as $batch)<option value="{{ $batch['id'] }}" data-revision="{{ $batch['stock_revision'] }}">{{ $batch['sku'] }} · {{ $batch['batch_code'] }} · {{ $batch['pack_size'] }} biji/kemasan · Utuh {{ $batch['usable_pieces'] }}, terbuka {{ $batch['opened_pieces'] }}, siap {{ $batch['ready_pieces'] }}, karantina {{ $batch['quarantined_pieces'] }} · revisi {{ $batch['stock_revision'] }}</option>@endforeach
</select></label><input type="hidden" name="expected_revision" data-stock-revision value="{{ $batches[0]['stock_revision'] ?? '' }}">
