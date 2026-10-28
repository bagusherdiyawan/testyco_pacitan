import 'dart:async';
import 'dart:convert';
import 'package:http/http.dart' as http;

class ApiException implements Exception {
  const ApiException(this.message, {this.status = 0});
  final String message;
  final int status;
  @override
  String toString() => message;
}

class ApiClient {
  ApiClient({required this.baseUrl, http.Client? client})
    : _client = client ?? http.Client();
  final Uri baseUrl;
  final http.Client _client;
  String? _token;
  void Function()? onUnauthorized;
  bool get authenticated => _token != null;

  Future<Map<String, dynamic>> request(
    String method,
    String path, [
    Map<String, dynamic>? data,
  ]) async {
    final uri = baseUrl.resolve(path);
    try {
      final request = http.Request(method, uri)
        ..headers.addAll({
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        });
      if (_token != null) request.headers['Authorization'] = 'Bearer $_token';
      if (data != null) request.body = jsonEncode(data);
      final streamed = await _client
          .send(request)
          .timeout(const Duration(seconds: 10));
      final response = await http.Response.fromStream(
        streamed,
      ).timeout(const Duration(seconds: 10));
      Map<String, dynamic> body = {};
      try {
        if (response.body.isNotEmpty) {
          body = jsonDecode(response.body) as Map<String, dynamic>;
        }
      } on FormatException {
        throw const ApiException('Respons server tidak valid.');
      }
      if (response.statusCode == 401) {
        clearToken();
        onUnauthorized?.call();
        throw const ApiException(
          'Sesi berakhir. Silakan masuk kembali.',
          status: 401,
        );
      }
      if (response.statusCode >= 400) {
        if (response.statusCode == 429) {
          throw const ApiException(
            'Terlalu banyak permintaan. Coba kembali sebentar lagi.',
            status: 429,
          );
        }
        if (response.statusCode >= 500) {
          throw const ApiException(
            'Layanan API sedang tidak tersedia.',
            status: 503,
          );
        }
        throw ApiException(
          body['message'] as String? ?? 'Permintaan gagal.',
          status: response.statusCode,
        );
      }
      return body;
    } on TimeoutException {
      throw const ApiException(
        'Koneksi terlalu lama. Periksa jaringan dan coba kembali.',
      );
    } on http.ClientException {
      throw const ApiException('Tidak dapat menghubungi API. Periksa koneksi.');
    }
  }

  Future<String> login(String email, String password) async {
    final result = await request('POST', 'auth/login', {
      'email': email.trim(),
      'password': password,
      'device_name': 'flutter-pos',
    });
    _token = result['token'] as String;
    return (result['user'] as Map<String, dynamic>)['name'] as String;
  }

  Future<void> logout() async {
    try {
      if (_token != null) await request('POST', 'auth/logout');
    } finally {
      clearToken();
    }
  }

  void clearToken() => _token = null;
  void close() => _client.close();
}
