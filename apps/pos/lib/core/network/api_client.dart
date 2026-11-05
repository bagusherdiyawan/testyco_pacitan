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

class MfaRequired extends ApiException {
  const MfaRequired()
    : super('Masukkan kode authenticator atau recovery untuk melanjutkan.');
}

class ApiClient {
  ApiClient({required this.baseUrl, http.Client? client})
    : _client = client ?? http.Client();
  final Uri baseUrl;
  final http.Client _client;
  String? _token, _mfaChallenge;
  DateTime? _mfaUntil, expiresAt;
  Map<String, dynamic>? identity;
  bool get needsMfa =>
      _mfaChallenge != null &&
      _mfaUntil != null &&
      _mfaUntil!.isAfter(DateTime.now().toUtc());
  void resetMfaChallenge() {
    _mfaChallenge = null;
    _mfaUntil = null;
  }

  Map<String, dynamic>? get savedSession =>
      _token == null || identity == null || expiresAt == null
      ? null
      : {
          'base_url': baseUrl.toString(),
          'token': _token,
          'user': identity,
          'expires_at': expiresAt!.toIso8601String(),
        };
  bool restoreSession(Map<String, dynamic> value) {
    final until = DateTime.tryParse(value['expires_at'] as String? ?? '');
    if (value['base_url'] != baseUrl.toString() ||
        value['token'] is! String ||
        value['user'] is! Map ||
        value['user']['id'] is! int ||
        until == null ||
        !until.isAfter(DateTime.now().toUtc())) {
      return false;
    }
    _token = value['token'];
    identity = Map<String, dynamic>.from(value['user']);
    expiresAt = until;
    return true;
  }

  String acceptLogin(Map<String, dynamic> result) {
    if (result['token'] is! String ||
        result['user'] is! Map ||
        result['user']['name'] is! String) {
      throw const ApiException('Respons login tidak valid.');
    }
    _token = result['token'];
    identity = Map<String, dynamic>.from(result['user']);
    expiresAt = DateTime.now().toUtc().add(
      Duration(seconds: result['expires_in'] as int? ?? 28800),
    );
    resetMfaChallenge();
    return identity!['name'] as String;
  }

  Future<String> completeMfa(String code) async {
    if (!needsMfa) {
      throw const ApiException('Tantangan kedaluwarsa, masuk kembali.');
    }
    return acceptLogin(
      await request('POST', 'auth/login/mfa', {
        'challenge': _mfaChallenge,
        'code': code,
      }),
    );
  }

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

  /// Private bounded PDF; never follows redirects or passes credentials to a URL from API data.
  Future<List<int>> pdf(String path) async {
    if (!RegExp(
      r'^organizations/[0-9A-Z]{26}/contracts/[0-9A-Z]{26}/attachment$',
    ).hasMatch(path)) {
      throw const ApiException('Rute PDF tidak valid.');
    }
    final request = http.Request('GET', baseUrl.resolve(path))
      ..followRedirects = false
      ..headers['Accept'] = 'application/pdf';
    if (_token != null) request.headers['Authorization'] = 'Bearer $_token';
    final response = await _client
        .send(request)
        .timeout(const Duration(seconds: 10));
    if (response.statusCode == 401) {
      clearToken();
      onUnauthorized?.call();
      throw const ApiException('Sesi berakhir.', status: 401);
    }
    if (response.statusCode != 200 ||
        response.headers['content-type']?.split(';').first !=
            'application/pdf' ||
        (response.contentLength ?? 0) > 5 * 1024 * 1024) {
      throw const ApiException('PDF tidak dapat dibaca.');
    }
    final bytes = <int>[];
    await for (final chunk in response.stream.timeout(
      const Duration(seconds: 10),
    )) {
      bytes.addAll(chunk);
      if (bytes.length > 5 * 1024 * 1024) {
        throw const ApiException('PDF melebihi batas.');
      }
    }
    if (bytes.length < 5 ||
        ascii.decode(bytes.sublist(0, 5), allowInvalid: true) != '%PDF-') {
      throw const ApiException('Lampiran bukan PDF.');
    }
    return bytes;
  }

  Future<String> login(String email, String password) async {
    clearToken();
    final result = await request('POST', 'auth/login', {
      'email': email.trim(),
      'password': password,
      'device_name': 'flutter-pos',
    });
    if (result['requires_mfa'] == true) {
      if (result['challenge'] is! String || result['challenge'].length != 64) {
        throw const ApiException('Tantangan MFA tidak valid.');
      }
      _mfaChallenge = result['challenge'];
      _mfaUntil = DateTime.now().toUtc().add(const Duration(minutes: 2));
      throw const MfaRequired();
    }
    return acceptLogin(result);
  }

  Future<void> logout() async {
    try {
      if (_token != null) await request('POST', 'auth/logout');
    } finally {
      clearToken();
    }
  }

  void clearToken() {
    _token = null;
    identity = null;
    expiresAt = null;
    resetMfaChallenge();
  }

  void close() => _client.close();
}
