package com.nabilet.checker.ui.screens

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.result.contract.ActivityResultContracts.StartActivityForResult
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Error
import androidx.compose.material.icons.filled.FlashOff
import androidx.compose.material.icons.filled.FlashOn
import androidx.compose.material.icons.filled.Keyboard
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilledTonalIconButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import com.nabilet.checker.R
import com.nabilet.checker.data.repository.CheckerState
import com.nabilet.checker.domain.model.ScanFeedback
import com.nabilet.checker.domain.util.TimeFormat
import com.nabilet.checker.ui.components.StatusPill
import com.nabilet.checker.ui.components.resultLabel
import com.nabilet.checker.ui.components.sourceLabel
import com.nabilet.checker.ui.feedback.FeedbackPlayer
import com.nabilet.checker.ui.scanner.CameraPreview
import com.nabilet.checker.ui.scanner.QrAnalyzer
import com.nabilet.checker.ui.theme.CheckerColors
import kotlinx.coroutines.delay

/**
 * The working screen: camera, one verdict card, and the two escape hatches an
 * operator actually needs — manual payload entry (cracked screen, dim phone)
 * and the torch.
 *
 * Everything that decides admission lives in the repository. This composable
 * only renders the decision it is given and never reinterprets it, so the
 * on-screen verdict and the audit row can never disagree.
 */
@Composable
fun ScannerScreen(
    state: CheckerState,
    online: Boolean,
    feedback: ScanFeedback?,
    busy: Boolean,
    feedbackPlayer: FeedbackPlayer,
    onSubmitScan: (String, Boolean) -> Unit,
    onClearFeedback: () -> Unit,
    onSync: () -> Unit,
    onOpenSettings: () -> Unit,
) {
    val context = LocalContext.current

    var permissionGranted by remember {
        mutableStateOf(
            ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) ==
                PackageManager.PERMISSION_GRANTED,
        )
    }
    var permissionDenied by remember { mutableStateOf(false) }
    var cameraError by remember { mutableStateOf<String?>(null) }
    var torchOn by remember { mutableStateOf(false) }
    var showManual by remember { mutableStateOf(false) }
    var manualText by rememberSaveable { mutableStateOf("") }

    val permissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { granted ->
        permissionGranted = granted
        permissionDenied = !granted
    }

    val settingsLauncher = rememberLauncherForActivityResult(
        StartActivityForResult(),
    ) { _ -> }

    // If the user permanently denied camera permission, the system dialog will
    // no longer appear. Offer a direct path to the app settings page.
    LaunchedEffect(Unit) {
        if (!permissionGranted) {
            permissionLauncher.launch(Manifest.permission.CAMERA)
        }
    }

    val appContext = context.applicationContext

    fun openAppSettings() {
        val intent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS)
            .setData(
                android.net.Uri.fromParts(
                    "package",
                    appContext.packageName,
                    null,
                ),
            )
        settingsLauncher.launch(intent)
    }

    // The analyzer is created once. Latest values are read through
    // remembered state so the closure never holds a stale verdict.
    val latestFeedback by rememberUpdatedState(feedback)
    val latestBusy by rememberUpdatedState(busy)
    val latestOnline by rememberUpdatedState(online)
    val latestSubmit by rememberUpdatedState(onSubmitScan)

    val analyzer = remember {
        QrAnalyzer(
            onQr = { value ->
                if (latestFeedback == null && !latestBusy) {
                    latestSubmit(value, latestOnline)
                }
            },
        )
    }

    DisposableEffect(Unit) {
        onDispose { analyzer.close() }
    }

    LaunchedEffect(feedback != null, busy) {
        analyzer.paused = feedback != null || busy
    }

    LaunchedEffect(feedback?.id) {
        val current = feedback ?: return@LaunchedEffect
        feedbackPlayer.play(current.result.admits)
        delay(FEEDBACK_VISIBLE_MS)
        onClearFeedback()
    }

    Column(modifier = Modifier.fillMaxSize()) {
        ScannerStatusBar(
            state = state,
            online = online,
            onSync = onSync,
            onOpenSettings = onOpenSettings,
        )

        Box(
            modifier = Modifier
                .weight(1f)
                .fillMaxWidth()
                .background(Color.Black),
        ) {
            if (permissionGranted && cameraError == null) {
                CameraPreview(
                    modifier = Modifier.fillMaxSize(),
                    torchOn = torchOn,
                    analyzer = analyzer,
                    onError = { cameraError = it },
                )
            } else {
                CameraPlaceholder(
                    denied = permissionDenied,
                    hasError = cameraError != null,
                    onRequestPermission = {
                        permissionLauncher.launch(Manifest.permission.CAMERA)
                    },
                    onOpenSettings = { openAppSettings() },
                )
            }

            Column(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center,
            ) {
                Box(
                    modifier = Modifier
                        .fillMaxWidth(0.76f)
                        .aspectRatio(1f)
                        .border(
                            width = 2.dp,
                            color = Color.White.copy(alpha = 0.8f),
                            shape = RoundedCornerShape(24.dp),
                        ),
                )
                Spacer(modifier = Modifier.height(16.dp))
                Text(
                    text = stringResource(R.string.scanner_ready),
                    style = MaterialTheme.typography.bodyMedium,
                    color = Color.White.copy(alpha = 0.85f),
                    textAlign = TextAlign.Center,
                )
            }

            if (state.bundle == null) {
                Column(
                    modifier = Modifier
                        .align(Alignment.TopCenter)
                        .padding(12.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Banner(
                        text = stringResource(R.string.scanner_no_bundle),
                        background = CheckerColors.Warn,
                    )
                    Button(
                        onClick = onOpenSettings,
                        modifier = Modifier.fillMaxWidth(0.6f),
                    ) {
                        Text(stringResource(R.string.setup_download_bundle))
                    }
                }
            }

            if (busy && feedback == null) {
                Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = Color.White)
                }
            }

            if (feedback != null) {
                ScanResultCard(
                    feedback = feedback,
                    onDismiss = onClearFeedback,
                    modifier = Modifier
                        .align(Alignment.BottomCenter)
                        .padding(12.dp),
                )
            }
        }

        ScannerActions(
            torchOn = torchOn,
            onToggleTorch = { torchOn = !torchOn },
            onManualInput = { showManual = true },
        )
    }

    if (showManual) {
        ManualInputDialog(
            value = manualText,
            onValueChange = { manualText = it },
            onDismiss = { showManual = false },
            onSubmit = {
                val payload = manualText.trim()
                showManual = false
                manualText = ""
                if (payload.isNotEmpty()) onSubmitScan(payload, online)
            },
        )
    }
}

@Composable
private fun ScannerStatusBar(
    state: CheckerState,
    online: Boolean,
    onSync: () -> Unit,
    onOpenSettings: () -> Unit,
) {
    Column(modifier = Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 8.dp)) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            StatusPill(
                text = stringResource(
                    if (online) R.string.scanner_status_online else R.string.scanner_status_no_network,
                ),
                background = if (online) CheckerColors.Admit else CheckerColors.Deny,
            )

            if (state.stats.pending > 0) {
                StatusPill(
                    text = stringResource(R.string.scanner_pending, state.stats.pending),
                    background = CheckerColors.Warn,
                )
            }

            Spacer(modifier = Modifier.weight(1f))

            if (state.syncing) {
                CircularProgressIndicator(
                    modifier = Modifier.size(20.dp),
                    strokeWidth = 2.dp,
                )
                Spacer(modifier = Modifier.width(12.dp))
            } else {
                IconButton(onClick = onSync) {
                    Icon(
                        imageVector = Icons.Filled.Refresh,
                        contentDescription = stringResource(R.string.cd_sync),
                    )
                }
            }

            IconButton(onClick = onOpenSettings) {
                Icon(
                    imageVector = Icons.Filled.Settings,
                    contentDescription = stringResource(R.string.cd_settings),
                )
            }
        }

        val bundle = state.bundle
        if (bundle != null) {
            val remaining = TimeFormat.durationUntil(bundle.expiresAt, System.currentTimeMillis())
            val remainingText = when {
                remaining == null -> stringResource(R.string.expired)
                remaining.hours > 0 -> stringResource(
                    R.string.duration_hours_minutes,
                    remaining.hours.toInt(),
                    remaining.minutes.toInt(),
                )

                else -> stringResource(R.string.duration_minutes, remaining.minutes.toInt())
            }
            Text(
                text = stringResource(R.string.scanner_bundle_expires_soon, remainingText),
                style = MaterialTheme.typography.labelSmall,
                color = if (remaining == null) {
                    CheckerColors.Deny
                } else {
                    MaterialTheme.colorScheme.onSurfaceVariant
                },
            )
        }
    }
}

@Composable
private fun CameraPlaceholder(
    denied: Boolean,
    hasError: Boolean,
    onRequestPermission: () -> Unit,
    onOpenSettings: () -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(28.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Text(
            text = when {
                hasError -> stringResource(R.string.scanner_camera_unavailable)
                denied -> stringResource(R.string.scanner_permission_denied)
                else -> stringResource(R.string.scanner_permission_text)
            },
            color = Color.White,
            textAlign = TextAlign.Center,
            style = MaterialTheme.typography.bodyMedium,
        )

        Spacer(modifier = Modifier.height(16.dp))

        if (!hasError) {
            Button(onClick = onRequestPermission) {
                Text(stringResource(R.string.scanner_permission_button))
            }
        }

        if (denied) {
            Spacer(modifier = Modifier.height(8.dp))
            OutlinedButton(onClick = onOpenSettings) {
                Text(stringResource(R.string.settings_open_app_settings))
            }
        }
    }
}

@Composable
private fun Banner(text: String, background: Color, modifier: Modifier = Modifier) {
    Surface(
        modifier = modifier,
        color = background,
        contentColor = Color.White,
        shape = RoundedCornerShape(12.dp),
    ) {
        Text(
            text = text,
            style = MaterialTheme.typography.bodySmall,
            modifier = Modifier.padding(horizontal = 12.dp, vertical = 8.dp),
        )
    }
}

@Composable
private fun ScanResultCard(
    feedback: ScanFeedback,
    onDismiss: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val admitted = feedback.result.admits
    val sourceText = sourceLabel(feedback.source)
    val timeText = TimeFormat.timeOfDay(feedback.scannedAt)
    val queuedText = stringResource(R.string.history_pending)
    val meta = if (feedback.queued) "$sourceText · $timeText · $queuedText" else "$sourceText · $timeText"

    Surface(
        modifier = modifier.fillMaxWidth(),
        color = if (admitted) CheckerColors.Admit else CheckerColors.Deny,
        contentColor = Color.White,
        shape = RoundedCornerShape(20.dp),
        shadowElevation = 10.dp,
    ) {
        Column(
            modifier = Modifier.padding(18.dp),
            verticalArrangement = Arrangement.spacedBy(4.dp),
        ) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(
                    imageVector = if (admitted) Icons.Default.CheckCircle else Icons.Filled.Error,
                    contentDescription = if (admitted) stringResource(R.string.result_admit) else stringResource(R.string.result_deny),
                    modifier = Modifier.size(24.dp),
                )
                Text(
                    text = stringResource(
                        if (admitted) R.string.result_admit else R.string.result_deny,
                    ),
                    style = MaterialTheme.typography.headlineSmall,
                    fontWeight = FontWeight.Black,
                )
            }
            Text(
                text = resultLabel(feedback.result),
                style = MaterialTheme.typography.titleMedium,
            )
            feedback.ticketNumber?.let { number ->
                Text(
                    text = number,
                    style = MaterialTheme.typography.bodyLarge,
                    fontWeight = FontWeight.SemiBold,
                )
            }
            feedback.note?.let { note ->
                Text(
                    text = note,
                    style = MaterialTheme.typography.bodySmall,
                    color = Color.White.copy(alpha = 0.9f),
                )
            }
            Text(
                text = meta,
                style = MaterialTheme.typography.labelSmall,
                color = Color.White.copy(alpha = 0.85f),
            )
            TextButton(
                onClick = onDismiss,
                colors = ButtonDefaults.textButtonColors(contentColor = Color.White),
            ) {
                Text(stringResource(R.string.scanner_clear_result))
            }
        }
    }
}

@Composable
private fun ScannerActions(
    torchOn: Boolean,
    onToggleTorch: () -> Unit,
    onManualInput: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        OutlinedButton(
            onClick = onManualInput,
            modifier = Modifier.weight(1f),
        ) {
            Icon(
                imageVector = Icons.Filled.Keyboard,
                contentDescription = null,
                modifier = Modifier.size(18.dp),
            )
            Spacer(modifier = Modifier.width(8.dp))
            Text(stringResource(R.string.scanner_manual))
        }

        FilledTonalIconButton(onClick = onToggleTorch) {
            Icon(
                imageVector = if (torchOn) Icons.Filled.FlashOff else Icons.Filled.FlashOn,
                contentDescription = stringResource(R.string.cd_torch),
            )
        }
    }
}

@Composable
private fun ManualInputDialog(
    value: String,
    onValueChange: (String) -> Unit,
    onDismiss: () -> Unit,
    onSubmit: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.scanner_manual_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = value,
                    onValueChange = onValueChange,
                    label = { Text(stringResource(R.string.scanner_manual_hint)) },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Text(
                    text = stringResource(R.string.scanner_manual_helper),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        },
        confirmButton = {
            TextButton(onClick = onSubmit, enabled = value.isNotBlank()) {
                Text(stringResource(R.string.scanner_manual_submit))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text(stringResource(R.string.action_cancel))
            }
        },
    )
}

private const val FEEDBACK_VISIBLE_MS = 6000L
