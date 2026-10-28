import 'package:flutter/material.dart';
import '../data/catalog_repository.dart';
import '../domain/catalog_product.dart';
import '../../../design_system/molecules/error_panel.dart';

class CatalogPage extends StatefulWidget {
  const CatalogPage({
    required this.repository,
    required this.storeId,
    super.key,
  });
  final CatalogRepository repository;
  final String storeId;
  @override
  State<CatalogPage> createState() => _CatalogPageState();
}

class _CatalogPageState extends State<CatalogPage> {
  late Future<List<CatalogProduct>> _products;
  String query = '';
  @override
  void initState() {
    super.initState();
    _products = widget.repository.load(widget.storeId);
  }

  @override
  Widget build(BuildContext context) => Column(
    children: [
      Padding(
        padding: const EdgeInsets.all(16),
        child: TextField(
          decoration: const InputDecoration(
            labelText: 'Cari nama atau SKU',
            prefixIcon: Icon(Icons.search),
          ),
          onChanged: (v) => setState(() => query = v.toLowerCase()),
        ),
      ),
      Expanded(
        child: FutureBuilder<List<CatalogProduct>>(
          future: _products,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return ErrorPanel(
                message: snapshot.error.toString(),
                onRetry: () => setState(() {
                  _products = widget.repository.load(widget.storeId);
                }),
              );
            }
            final products = snapshot.data!
                .where(
                  (p) => '${p.name} ${p.sku}'.toLowerCase().contains(query),
                )
                .toList();
            if (products.isEmpty) {
              return ErrorPanel(
                message: query.isEmpty
                    ? 'Belum ada produk aktif.'
                    : 'Produk tidak ditemukan.',
                onRetry: () => setState(() {
                  _products = widget.repository.load(widget.storeId);
                }),
              );
            }
            return LayoutBuilder(
              builder: (context, constraints) => GridView.builder(
                padding: const EdgeInsets.all(16),
                gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: constraints.maxWidth >= 700
                      ? 3
                      : constraints.maxWidth >= 480
                      ? 2
                      : 1,
                  mainAxisExtent: 210,
                  crossAxisSpacing: 16,
                  mainAxisSpacing: 16,
                ),
                itemCount: products.length,
                itemBuilder: (context, index) {
                  final p = products[index];
                  return Card(
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            p.sku,
                            style: Theme.of(context).textTheme.labelMedium,
                          ),
                          const SizedBox(height: 8),
                          Text(
                            p.name,
                            style: Theme.of(context).textTheme.titleLarge,
                          ),
                          Text(p.condition == 'frozen' ? 'Beku' : 'Siap jual'),
                          Text(
                            'Kemasan ${p.packSize} biji · Porsi ${p.portionSize} biji',
                          ),
                          const SizedBox(height: 8),
                          Text('Rp ${p.retailPrice} / biji'),
                        ],
                      ),
                    ),
                  );
                },
              ),
            );
          },
        ),
      ),
    ],
  );
}
