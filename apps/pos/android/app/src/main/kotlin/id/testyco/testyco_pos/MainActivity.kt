package id.testyco.testyco_pos

import android.Manifest
import android.bluetooth.BluetoothManager
import android.bluetooth.BluetoothSocket
import android.content.pm.PackageManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel
import java.util.UUID
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean

class MainActivity : FlutterActivity() {
    private val worker = Executors.newSingleThreadExecutor()
    private val handler = Handler(Looper.getMainLooper())
    @Volatile private var socket: BluetoothSocket? = null
    private var permissionResult: MethodChannel.Result? = null
    private val sending = AtomicBoolean(false)

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "testyco/receipt_bluetooth")
            .setMethodCallHandler { call, result ->
                if (call.method != "pairedDevices" && call.method != "send") {
                    result.notImplemented()
                } else if (Build.VERSION.SDK_INT >= 31 &&
                    checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT) != PackageManager.PERMISSION_GRANTED) {
                    if (call.method == "pairedDevices" && permissionResult == null) {
                        permissionResult = result
                        requestPermissions(arrayOf(Manifest.permission.BLUETOOTH_CONNECT), 617)
                    } else result.error("permission", "Izinkan koneksi Bluetooth melalui pemilihan printer.", null)
                } else handleBluetooth(call, result)
            }
    }

    override fun onRequestPermissionsResult(code: Int, permissions: Array<out String>, results: IntArray) {
        super.onRequestPermissionsResult(code, permissions, results)
        if (code != 617) return
        val result = permissionResult ?: return
        permissionResult = null
        if (results.isNotEmpty() && results[0] == PackageManager.PERMISSION_GRANTED) {
            handleBluetooth(MethodCall("pairedDevices", null), result)
        } else result.error("permission", "Izin Bluetooth ditolak. Aktifkan melalui pengaturan Android.", null)
    }

    @Suppress("MissingPermission")
    private fun handleBluetooth(call: MethodCall, result: MethodChannel.Result) {
        try {
            val adapter = getSystemService(BluetoothManager::class.java)?.adapter
            if (adapter == null || !adapter.isEnabled) {
                result.error("unavailable", "Aktifkan Bluetooth Android terlebih dahulu.", null)
                return
            }
            val paired = adapter.bondedDevices
            if (call.method == "pairedDevices") {
                result.success(paired.sortedBy { it.name ?: it.address }.map {
                    mapOf("id" to it.address, "name" to (it.name ?: "Printer Bluetooth"))
                })
                return
            }
            val device = paired.firstOrNull { it.address == call.argument<String>("device_id") }
            val bytes = call.argument<ByteArray>("bytes")
            if (device == null || bytes == null || bytes.isEmpty() || bytes.size > 1048576) {
                result.error("invalid", "Pilih printer yang sudah dipasangkan dan struk yang valid.", null)
                return
            }
            if (!sending.compareAndSet(false, true)) {
                result.error("busy", "Pengiriman Bluetooth masih berjalan.", null)
                return
            }
            val completed = AtomicBoolean(false)
            val timeout = Runnable {
                if (completed.compareAndSet(false, true)) {
                    try { socket?.close() } catch (_: Exception) { }
                    // Keep busy until the worker actually exits: never start concurrent writes.
                    result.error("timeout", "Hasil cetak belum pasti. Periksa kertas sebelum mencetak ulang.", null)
                }
            }
            handler.postDelayed(timeout, 15000)
            worker.execute {
                var localSocket: BluetoothSocket? = null
                try {
                    localSocket = device.createRfcommSocketToServiceRecord(
                        UUID.fromString("00001101-0000-1000-8000-00805F9B34FB"))
                    socket = localSocket
                    if (completed.get()) throw IllegalStateException("expired")
                    localSocket.connect()
                    localSocket.outputStream.write(bytes)
                    localSocket.outputStream.flush()
                    handler.post {
                        if (completed.compareAndSet(false, true)) result.success(true)
                    }
                } catch (_: Exception) {
                    handler.post {
                        if (completed.compareAndSet(false, true)) {
                            result.error("send_failed", "Hasil cetak belum pasti. Periksa kertas dan koneksi printer.", null)
                        }
                    }
                } finally {
                    try { localSocket?.close() } catch (_: Exception) { }
                    socket = null
                    sending.set(false)
                    handler.removeCallbacks(timeout)
                }
            }
        } catch (_: Exception) {
            result.error("bluetooth", "Bluetooth tidak tersedia atau izin belum diberikan.", null)
        }
    }

    override fun onDestroy() {
        permissionResult?.error("closed", "Pemilihan printer ditutup.", null)
        permissionResult = null
        try { socket?.close() } catch (_: Exception) { }
        worker.shutdownNow()
        super.onDestroy()
    }
}
