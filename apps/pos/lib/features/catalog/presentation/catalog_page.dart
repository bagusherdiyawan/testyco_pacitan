import 'dart:convert';
import 'package:flutter/material.dart';
import '../../../core/network/api_client.dart';
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
                          Text(
                            'Rp ${p.retailPrice} / ${p.isBundle ? 'paket' : 'biji'}',
                          ),
                          if (p.hasImage)
                            TextButton(
                              onPressed: () => showDialog<void>(
                                context: context,
                                builder: (_) => _ProductImage(
                                  repository: widget.repository,
                                  store: widget.storeId,
                                  product: p,
                                ),
                              ),
                              child: const Text('Lihat gambar produk'),
                            ),
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

class _ProductImage extends StatefulWidget {
  const _ProductImage({
    required this.repository,
    required this.store,
    required this.product,
  });
  final CatalogRepository repository;
  final String store;
  final CatalogProduct product;
  @override
  State<_ProductImage> createState() => _ProductImageState();
}

class _ProductImageState extends State<_ProductImage> {
  late final Future<Map<String, dynamic>> data;
  @override
  void initState() {
    super.initState();
    data = widget.repository.api.request(
      'GET',
      'stores/${Uri.encodeComponent(widget.store)}/catalog/${Uri.encodeComponent(widget.product.id)}/image-data',
    );
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(widget.product.name),
    content: SizedBox(
      width: 320,
      height: 320,
      child: FutureBuilder<Map<String, dynamic>>(
        future: data,
        builder: (context, s) {
          if (s.hasError) return Text('${s.error}');
          if (!s.hasData) {
            return const Center(child: CircularProgressIndicator());
          }
          try {
            final d = s.data!;
            if (d['product_id'] != widget.product.id ||
                ![
                  'image/png',
                  'image/jpeg',
                  'image/webp',
                ].contains(d['mime']) ||
                d['image_base64'] is! String ||
                (d['image_base64'] as String).length > 7000000) {
              throw const ApiException('Gambar produk tidak valid.');
            }
            return Image.memory(
              base64Decode(d['image_base64']),
              fit: BoxFit.contain,
              errorBuilder: (_, error, stack) =>
                  const Text('Gambar tidak dapat dibaca.'),
            );
          } catch (e) {
            return Text('$e');
          }
        },
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Tutup'),
      ),
    ],
  );
}
