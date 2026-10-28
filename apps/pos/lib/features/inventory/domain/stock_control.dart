import 'stock_batch.dart';

class StockControlContext {
  const StockControlContext({
    required this.name,
    required this.active,
    required this.canProcess,
    required this.canPropose,
    required this.canReview,
  });
  factory StockControlContext.fromJson(Map<String, dynamic> j) =>
      StockControlContext(
        name: j['store_name'] as String,
        active: j['active'] as bool,
        canProcess: j['can_process'] as bool,
        canPropose: j['can_propose'] as bool,
        canReview: j['can_review'] as bool,
      );
  final String name;
  final bool active, canProcess, canPropose, canReview;
}

class StockCount {
  const StockCount({
    required this.id,
    required this.batch,
    required this.purpose,
    required this.bucket,
    required this.status,
    required this.reason,
    required this.expected,
    required this.counted,
    required this.difference,
    required this.revision,
    required this.version,
    required this.isStale,
    required this.canReview,
    required this.canCancel,
    this.reviewNote,
  });
  factory StockCount.fromJson(Map<String, dynamic> j) => StockCount(
    id: j['id'] as String,
    batch: StockBatch.fromJson(j['batch'] as Map<String, dynamic>),
    purpose: j['purpose'] as String,
    bucket: j['bucket'] as String,
    status: j['status'] as String,
    reason: j['reason'] as String,
    expected: j['expected_pieces'] as int,
    counted: j['counted_pieces'] as int,
    difference: j['difference_pieces'] as int,
    revision: j['snapshot_revision'] as int,
    version: j['version'] as int,
    isStale: j['is_stale'] as bool,
    canReview: j['can_review'] as bool,
    canCancel: j['can_cancel'] as bool,
    reviewNote: j['review_note'] as String?,
  );
  final String id, purpose, bucket, status, reason;
  final StockBatch batch;
  final int expected, counted, difference, revision, version;
  final bool isStale, canReview, canCancel;
  final String? reviewNote;
}

class StockProcess {
  const StockProcess({
    required this.kind,
    required this.batchCode,
    required this.input,
    required this.output,
    required this.waste,
    required this.reason,
  });
  factory StockProcess.fromJson(Map<String, dynamic> j) => StockProcess(
    kind: j['kind'] as String,
    batchCode: (j['batch'] as Map<String, dynamic>)['batch_code'] as String,
    input: j['input_pieces'] as int,
    output: j['output_pieces'] as int,
    waste: j['waste_pieces'] as int,
    reason: j['reason'] as String,
  );
  final String kind, batchCode, reason;
  final int input, output, waste;
}
