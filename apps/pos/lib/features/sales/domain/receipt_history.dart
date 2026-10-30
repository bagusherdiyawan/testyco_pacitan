class ReceiptHistory {
  const ReceiptHistory(
    this.rows,
    this.storeName,
    this.scope,
    this.page,
    this.lastPage,
  );
  final List<Map<String, dynamic>> rows;
  final String storeName, scope;
  final int page, lastPage;
}
