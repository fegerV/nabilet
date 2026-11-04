package com.nabilet.checker.ui.scanner

import android.annotation.SuppressLint
import androidx.camera.core.ExperimentalGetImage
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import com.google.mlkit.vision.barcode.BarcodeScanner
import com.google.mlkit.vision.barcode.BarcodeScannerOptions
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.common.InputImage
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicLong
import java.util.concurrent.atomic.AtomicReference

/**
 * Decodes QR codes from the camera and hands the raw payload to the caller.
 *
 * Frames arrive far faster than a verdict takes (30 fps against a network call
 * that can last a second), so the analyzer drops everything while one decode is
 * in flight and then ignores the same payload for a short window. Without that,
 * holding a phone steady over one ticket would fire the same scan a dozen times
 * and produce a dozen rows in the audit log.
 *
 * The QR text itself is never interpreted here: [QrPayloadParser] decides
 * whether it is a NABILET payload, and the repository decides whether the
 * ticket may enter.
 */
class QrAnalyzer(
    private val onQr: (String) -> Unit,
    private val onFailure: (String) -> Unit = {},
) : ImageAnalysis.Analyzer {

    private val scanner: BarcodeScanner = BarcodeScanning.getClient(
        BarcodeScannerOptions.Builder()
            .setBarcodeFormats(Barcode.FORMAT_QR_CODE)
            .build(),
    )

    private val decoding = AtomicBoolean(false)
    private val lastValue = AtomicReference<String?>(null)
    private val lastEmittedAt = AtomicLong(0L)

    /**
     * Set while a verdict is on screen. The camera keeps running but decoding
     * is skipped, so a result card cannot be replaced by a re-scan of the same
     * ticket before the operator has read it.
     */
    @Volatile
    var paused: Boolean = false

    @SuppressLint("UnsafeOptInUsageError")
    @ExperimentalGetImage
    override fun analyze(imageProxy: ImageProxy) {
        val mediaImage = imageProxy.image
        if (mediaImage == null || paused || !decoding.compareAndSet(false, true)) {
            imageProxy.close()
            return
        }

        val input = InputImage.fromMediaImage(mediaImage, imageProxy.imageInfo.rotationDegrees)
        scanner.process(input)
            .addOnSuccessListener { barcodes ->
                val value = barcodes.firstNotNullOfOrNull { barcode ->
                    barcode.rawValue?.takeIf { it.isNotBlank() }
                }
                if (value != null) emit(value)
            }
            .addOnFailureListener { error ->
                onFailure(error.message ?: "barcode decode failed")
            }
            .addOnCompleteListener {
                decoding.set(false)
                imageProxy.close()
            }
    }

    fun close() {
        runCatching { scanner.close() }
    }

    private fun emit(value: String) {
        val now = System.currentTimeMillis()
        if (lastValue.get() == value && now - lastEmittedAt.get() < DEDUPE_WINDOW_MS) return
        lastValue.set(value)
        lastEmittedAt.set(now)
        onQr(value)
    }

    private companion object {
        const val DEDUPE_WINDOW_MS = 2500L
    }
}
