package com.nabilet.checker.ui.screens

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import android.os.Environment
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Error
import androidx.compose.material.icons.filled.Image
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import com.google.mlkit.vision.barcode.BarcodeScanner
import com.google.mlkit.vision.barcode.BarcodeScannerOptions
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.common.InputImage
import com.nabilet.checker.R
import com.nabilet.checker.domain.util.ConfigQrParser
import com.nabilet.checker.ui.scanner.CameraPreview
import com.nabilet.checker.ui.scanner.QrAnalyzer

@Composable
fun ConfigScanScreen(
    onConfigScanned: (String, String) -> Unit,
    onCancel: () -> Unit,
) {
    val context = LocalContext.current

    var permissionGranted by remember { mutableStateOf(false) }
    var cameraError by remember { mutableStateOf<String?>(null) }
    var scanState by remember { mutableStateOf<ScanState>(ScanState.Scanning) }
    var scanMessage by remember { mutableStateOf<String?>(null) }

    val permissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { granted ->
        permissionGranted = granted
    }

    val galleryLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.StartActivityForResult(),
    ) { result ->
        val data = result.data ?: return@rememberLauncherForActivityResult
        val uri = data.data ?: return@rememberLauncherForActivityResult
        val appContext = context.applicationContext
        try {
            val inputStream = appContext.contentResolver.openInputStream(uri)
            if (inputStream != null) {
                val bitmap = BitmapFactory.decodeStream(inputStream)
                inputStream.close()
                if (bitmap != null) {
                    processBitmap(bitmap, appContext, ::onConfigScanned) { success, msg ->
                        scanState = if (success) ScanState.Success else ScanState.Error
                        scanMessage = msg
                    }
                } else {
                    scanState = ScanState.Error
                    scanMessage = context.getString(R.string.config_scan_image_unreadable)
                }
            } else {
                scanState = ScanState.Error
                scanMessage = context.getString(R.string.config_scan_image_unreadable)
            }
        } catch (_: Exception) {
            scanState = ScanState.Error
            scanMessage = context.getString(R.string.config_scan_image_unreadable)
        }
    }

    LaunchedEffect(Unit) {
        if (!permissionGranted) {
            permissionLauncher.launch(Manifest.permission.CAMERA)
        }
    }

    val analyzer = remember {
        QrAnalyzer(
            onQr = { value ->
                if (scanState != ScanState.Scanning) return@onQr
                when (val result = ConfigQrParser.parse(value)) {
                    is ConfigQrParser.ConfigQrResult.Valid -> {
                        scanState = ScanState.Success
                        scanMessage = null
                        onConfigScanned(result.config.server, result.config.token)
                    }
                    is ConfigQrParser.ConfigQrResult.Invalid -> {
                        scanState = ScanState.Error
                        scanMessage = result.error
                    }
                }
            },
        )
    }

    Column(
        modifier = Modifier.fillMaxSize(),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        if (!permissionGranted && cameraError == null) {
            Column(
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(16.dp),
            ) {
                Text(
                    text = stringResource(R.string.config_scan_permission_title),
                    style = MaterialTheme.typography.headlineSmall,
                    color = MaterialTheme.colorScheme.onSurface,
                )
                Text(
                    text = stringResource(R.string.config_scan_permission_text),
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    textAlign = TextAlign.Center,
                )
                Button(
                    onClick = { permissionLauncher.launch(Manifest.permission.CAMERA) },
                    modifier = Modifier.fillMaxWidth(0.6f),
                ) {
                    Text(stringResource(R.string.scanner_permission_button))
                }
            }
        } else if (cameraError != null) {
            Column(
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(16.dp),
            ) {
                Surface(
                    color = MaterialTheme.colorScheme.error,
                    contentColor = Color.White,
                    shape = RoundedCornerShape(12.dp),
                    modifier = Modifier.padding(horizontal = 20.dp),
                ) {
                    Column(
                        modifier = Modifier.padding(20.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                    ) {
                        Icon(
                            imageVector = Icons.Default.Error,
                            contentDescription = null,
                            modifier = Modifier.size(48.dp),
                            tint = Color.White,
                        )
                        androidx.compose.foundation.layout.Spacer(
                            modifier = androidx.compose.foundation.layout.Spacer(Modifier.size(16.dp)),
                        )
                        Text(
                            text = stringResource(R.string.scanner_camera_unavailable),
                            style = MaterialTheme.typography.bodyMedium,
                            color = Color.White,
                            textAlign = TextAlign.Center,
                        )
                    }
                }
                Button(
                    onClick = onCancel,
                    modifier = Modifier.fillMaxWidth(0.6f),
                ) {
                    Text(stringResource(R.string.action_close))
                }
            }
        } else {
            Box(modifier = Modifier.fillMaxSize()) {
                CameraPreview(
                    modifier = Modifier.fillMaxSize(),
                    torchOn = false,
                    analyzer = analyzer,
                    onError = { cameraError = it },
                )

                Box(
                    modifier = Modifier
                        .size(280.dp)
                        .align(Alignment.Center)
                        .border(4.dp, Color.White, RoundedCornerShape(16.dp)),
                ) {
                }

                Box(
                    modifier = Modifier
                        .align(Alignment.TopCenter)
                        .padding(vertical = 24.dp),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(
                        text = stringResource(R.string.config_scan_hint),
                        style = MaterialTheme.typography.bodyLarge,
                        color = Color.White,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.padding(horizontal = 32.dp),
                    )
                }

                when (scanState) {
                    ScanState.Scanning -> Unit
                    ScanState.Success -> {
                        ResultOverlay(
                            icon = Icons.Default.CheckCircle,
                            color = Color(0xFF15803D),
                            title = stringResource(R.string.config_scan_success_title),
                            message = stringResource(R.string.config_scan_success_message),
                            onAction = { onCancel() },
                            actionLabel = stringResource(R.string.action_ok),
                        )
                    }
                    ScanState.Error -> {
                        ResultOverlay(
                            icon = Icons.Default.Error,
                            color = MaterialTheme.colorScheme.error,
                            title = stringResource(R.string.config_scan_error_title),
                            message = scanMessage ?: stringResource(R.string.config_scan_error_message),
                            onAction = {
                                scanState = ScanState.Scanning
                                scanMessage = null
                            },
                            actionLabel = stringResource(R.string.action_retry),
                        )
                    }
                }

                Box(
                    modifier = Modifier
                        .align(Alignment.BottomCenter)
                        .padding(horizontal = 24.dp, vertical = 24.dp),
                    contentAlignment = Alignment.Center,
                ) {
                    OutlinedButton(
                        onClick = {
                            val intent = Intent(Intent.ACTION_GET_CONTENT).apply {
                                type = "image/*"
                                addCategory(Intent.CATEGORY_OPENABLE)
                            }
                            galleryLauncher.launch(intent)
                        },
                        modifier = Modifier.fillMaxWidth(0.8f),
                    ) {
                        androidx.compose.foundation.layout.Row(
                            modifier = androidx.compose.foundation.layout.Row(
                                Modifier.fillMaxWidth(),
                                horizontalArrangement = Arrangement.Center,
                                verticalAlignment = Alignment.CenterVertically,
                            ),
                        ) {
                            Icon(
                                imageVector = Icons.Default.Image,
                                contentDescription = null,
                                modifier = Modifier.size(20.dp),
                            )
                            androidx.compose.foundation.layout.Spacer(
                                modifier = androidx.compose.foundation.layout.Spacer(Modifier.width(8.dp)),
                            )
                            Text(stringResource(R.string.config_scan_from_gallery))
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun processBitmap(
    bitmap: Bitmap,
    appContext: android.content.Context,
    onResult: (success: Boolean, message: String?) -> Unit,
) {
    val scanner = BarcodeScanning.getClient(
        BarcodeScannerOptions.Builder()
            .setBarcodeFormats(Barcode.FORMAT_QR_CODE)
            .build(),
    )
    val image = InputImage.fromBitmap(bitmap, 0)
    scanner.process(image)
        .addOnSuccessListener { barcodes ->
            val value = barcodes.firstNotNullOfOrNull { barcode ->
                barcode.rawValue?.takeIf { it.isNotBlank() }
            }
            if (value != null) {
                when (val result = ConfigQrParser.parse(value)) {
                    is ConfigQrParser.ConfigQrResult.Valid -> {
                        onResult(true, null)
                    }
                    is ConfigQrParser.ConfigQrResult.Invalid -> {
                        onResult(false, result.error)
                    }
                }
            } else {
                onResult(false, appContext.getString(R.string.config_scan_no_qr_found))
            }
            scanner.close()
        }
        .addOnFailureListener {
            onResult(false, appContext.getString(R.string.config_scan_image_unreadable))
            scanner.close()
        }
}

sealed class ScanState {
    object Scanning : ScanState()
    object Success : ScanState()
    data class Error(val detail: String?) : ScanState()
}

@Composable
private fun ResultOverlay(
    icon: androidx.compose.material.icons.filled.ImageVector,
    color: Color,
    title: String,
    message: String,
    onAction: () -> Unit,
    actionLabel: String,
) {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .align(Alignment.BottomCenter)
            .padding(horizontal = 24.dp, vertical = 32.dp),
        contentAlignment = Alignment.Center,
    ) {
        Surface(
            color = color,
            contentColor = Color.White,
            shape = RoundedCornerShape(20.dp),
            modifier = Modifier.fillMaxWidth(0.88f),
        ) {
            Column(
                modifier = Modifier.padding(28.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Icon(
                    imageVector = icon,
                    contentDescription = null,
                    modifier = Modifier.size(56.dp),
                    tint = Color.White,
                )
                androidx.compose.foundation.layout.Spacer(
                    modifier = androidx.compose.foundation.layout.Spacer(Modifier.size(20.dp)),
                )
                Text(
                    text = title,
                    style = MaterialTheme.typography.headlineMedium,
                    fontWeight = androidx.compose.ui.text.font.FontWeight.Bold,
                    color = Color.White,
                    textAlign = TextAlign.Center,
                )
                androidx.compose.foundation.layout.Spacer(
                    modifier = androidx.compose.foundation.layout.Spacer(Modifier.size(12.dp)),
                )
                Text(
                    text = message,
                    style = MaterialTheme.typography.bodyMedium,
                    color = Color.White.copy(alpha = 0.95f),
                    textAlign = TextAlign.Center,
                )
                androidx.compose.foundation.layout.Spacer(
                    modifier = androidx.compose.foundation.layout.Spacer(Modifier.size(24.dp)),
                )
                Button(
                    onClick = onAction,
                    colors = androidx.compose.material3.ButtonDefaults.buttonColors(
                        containerColor = Color.White,
                        contentColor = color,
                    ),
                    modifier = Modifier.fillMaxWidth(0.6f),
                ) {
                    Text(actionLabel)
                }
            }
        }
    }
}
