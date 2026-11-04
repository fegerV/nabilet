package com.nabilet.checker.ui.scanner

import androidx.camera.core.Camera
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.lifecycle.compose.LocalLifecycleOwner
import java.util.concurrent.Executors

/**
 * CameraX preview plus the analysis pipeline, bound to the current lifecycle.
 *
 * Binding happens inside [AndroidView]'s factory, which runs once per attached
 * view. Doing it in `update` would rebind the camera on every recomposition and
 * flash the preview on each scan result.
 */
@Composable
fun CameraPreview(
    modifier: Modifier = Modifier,
    torchOn: Boolean,
    analyzer: QrAnalyzer,
    onError: (String) -> Unit,
) {
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current

    val previewView = remember(context) {
        PreviewView(context).apply {
            implementationMode = PreviewView.ImplementationMode.COMPATIBLE
            scaleType = PreviewView.ScaleType.FILL_CENTER
        }
    }
    val executor = remember { Executors.newSingleThreadExecutor() }
    val cameraState = remember { mutableStateOf<Camera?>(null) }
    val providerState = remember { mutableStateOf<ProcessCameraProvider?>(null) }

    AndroidView(
        modifier = modifier,
        factory = { viewContext ->
            val future = ProcessCameraProvider.getInstance(viewContext)
            future.addListener(
                {
                    try {
                        val provider = future.get()
                        providerState.value = provider

                        val preview = Preview.Builder().build().also {
                            it.setSurfaceProvider(previewView.surfaceProvider)
                        }
                        val analysis = ImageAnalysis.Builder()
                            .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                            .build()
                        analysis.setAnalyzer(executor, analyzer)

                        provider.unbindAll()
                        cameraState.value = provider.bindToLifecycle(
                            lifecycleOwner,
                            CameraSelector.DEFAULT_BACK_CAMERA,
                            preview,
                            analysis,
                        )
                    } catch (error: Exception) {
                        onError(error.message ?: "camera unavailable")
                    }
                },
                ContextCompat.getMainExecutor(viewContext),
            )
            previewView
        },
    )

    LaunchedEffect(torchOn, cameraState.value) {
        val camera = cameraState.value ?: return@LaunchedEffect
        runCatching {
            if (camera.cameraInfo.hasFlashUnit()) {
                camera.cameraControl.enableTorch(torchOn)
            }
        }
    }

    DisposableEffect(Unit) {
        onDispose {
            runCatching { providerState.value?.unbindAll() }
            executor.shutdown()
        }
    }
}
