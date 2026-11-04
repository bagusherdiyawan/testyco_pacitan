import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:cryptography/cryptography.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:path_provider/path_provider.dart';

abstract class DeviceState {
  Future<Map<String, dynamic>?> read(String key);
  Future<void> write(String key, Map<String, dynamic> value);
  Future<void> delete(String key);
  Future<Map<String, dynamic>> update(
    String key,
    Map<String, dynamic> Function(Map<String, dynamic>?) change,
  );
}

class MemoryDeviceState implements DeviceState {
  final Map<String, String> values = {};
  Future<void> _tail = Future.value();
  Future<T> _serial<T>(Future<T> Function() f) {
    final next = _tail.then((_) => f());
    _tail = next.then((_) {}, onError: (Object e, StackTrace s) {});
    return next;
  }

  @override
  Future<Map<String, dynamic>?> read(String key) => _serial(
    () async => values[key] == null
        ? null
        : jsonDecode(values[key]!) as Map<String, dynamic>,
  );
  @override
  Future<void> write(String key, Map<String, dynamic> value) =>
      _serial(() async {
        values[key] = jsonEncode(value);
      });
  @override
  Future<void> delete(String key) => _serial(() async {
    values.remove(key);
  });
  @override
  Future<Map<String, dynamic>> update(
    String key,
    Map<String, dynamic> Function(Map<String, dynamic>?) change,
  ) => _serial(() async {
    final value = change(
      values[key] == null
          ? null
          : jsonDecode(values[key]!) as Map<String, dynamic>,
    );
    values[key] = jsonEncode(value);
    return jsonDecode(values[key]!) as Map<String, dynamic>;
  });
}

class EncryptedDeviceState implements DeviceState {
  EncryptedDeviceState(this.directory, this.key);
  final Directory directory;
  final SecretKey key;
  final _cipher = AesGcm.with256bits();
  Future<void> _tail = Future.value();
  static Future<EncryptedDeviceState> open() async {
    const secure = FlutterSecureStorage(
      aOptions: AndroidOptions(encryptedSharedPreferences: true),
      iOptions: IOSOptions(
        accessibility: KeychainAccessibility.unlocked_this_device,
      ),
    );
    final root = await getApplicationSupportDirectory();
    final directory = Directory('${root.path}/testyco-secure-v1');
    var encoded = await secure.read(key: 'testyco.device.aes256.v1');
    if (encoded == null) {
      if (await directory.exists() && !await directory.list().isEmpty) {
        throw StateError(
          'Kunci perangkat hilang; berkas transaksi dipertahankan untuk pemulihan.',
        );
      }
      final bytes = await AesGcm.with256bits().newSecretKey();
      encoded = base64Encode(await bytes.extractBytes());
      await secure.write(key: 'testyco.device.aes256.v1', value: encoded);
    }
    final bytes = base64Decode(encoded);
    if (bytes.length != 32) {
      throw StateError('Kunci perangkat tidak valid; data lama dipertahankan.');
    }
    await directory.create(recursive: true);
    return EncryptedDeviceState(directory, SecretKey(bytes));
  }

  Future<T> _serial<T>(Future<T> Function() f) {
    final next = _tail.then((_) => f());
    _tail = next.then((_) {}, onError: (Object e, StackTrace s) {});
    return next;
  }

  Future<File> _file(String name) async {
    final digest = await Sha256().hash(utf8.encode(name));
    return File(
      '${directory.path}/${digest.bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join()}.json.enc',
    );
  }

  Future<Map<String, dynamic>?> _read(String name) async {
    final file = await _file(name);
    if (await File('${file.path}.tmp').exists()) {
      throw StateError(
        'Penulisan sebelumnya terputus; berkas dipertahankan untuk pemeriksaan sebelum transaksi baru.',
      );
    }
    if (!await file.exists()) return null;
    try {
      final e = jsonDecode(await file.readAsString());
      if (e['v'] != 1) throw StateError('Versi vault tidak dikenal.');
      final box = SecretBox(
        base64Decode(e['ciphertext']),
        nonce: base64Decode(e['nonce']),
        mac: Mac(base64Decode(e['mac'])),
      );
      final bytes = await _cipher.decrypt(
        box,
        secretKey: key,
        aad: utf8.encode(name),
      );
      return jsonDecode(utf8.decode(bytes)) as Map<String, dynamic>;
    } catch (_) {
      throw StateError(
        'Data perangkat tidak dapat diverifikasi/didekripsi. Berkas dipertahankan; jangan membuat transaksi pengganti.',
      );
    }
  }

  Future<void> _write(String name, Map<String, dynamic> value) async {
    await directory.create(recursive: true);
    final box = await _cipher.encrypt(
      utf8.encode(jsonEncode(value)),
      secretKey: key,
      aad: utf8.encode(name),
    );
    final file = await _file(name);
    final temp = File('${file.path}.tmp');
    await temp.writeAsString(
      jsonEncode({
        'v': 1,
        'nonce': base64Encode(box.nonce),
        'ciphertext': base64Encode(box.cipherText),
        'mac': base64Encode(box.mac.bytes),
      }),
      flush: true,
    );
    await temp.rename(file.path);
  }

  @override
  Future<Map<String, dynamic>?> read(String name) => _serial(() => _read(name));
  @override
  Future<void> write(String name, Map<String, dynamic> value) =>
      _serial(() => _write(name, value));
  @override
  Future<void> delete(String name) => _serial(() async {
    final file = await _file(name);
    if (await file.exists()) await file.delete();
  });
  @override
  Future<Map<String, dynamic>> update(
    String name,
    Map<String, dynamic> Function(Map<String, dynamic>?) change,
  ) => _serial(() async {
    final value = change(await _read(name));
    await _write(name, value);
    return jsonDecode(jsonEncode(value)) as Map<String, dynamic>;
  });
}
