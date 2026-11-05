package com.nabilet.checker.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.BarChart
import androidx.compose.material.icons.filled.History
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.nabilet.checker.R
import com.nabilet.checker.ui.feedback.FeedbackPlayer
import com.nabilet.checker.ui.screens.ConfigScanScreen
import com.nabilet.checker.ui.screens.HistoryScreen
import com.nabilet.checker.ui.screens.ScannerScreen
import com.nabilet.checker.ui.screens.SettingsScreen
import com.nabilet.checker.ui.screens.SetupScreen
import com.nabilet.checker.ui.screens.StatsScreen
import kotlinx.coroutines.delay

private const val TAB_SCANNER = 0
private const val TAB_STATS = 1
private const val TAB_HISTORY = 2
private const val TAB_SETTINGS = 3

private const val LOAD_TIMEOUT_MS = 10_000L

@Composable
fun CheckerRoot(
    viewModel: CheckerViewModel,
    feedbackPlayer: FeedbackPlayer,
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val online by viewModel.online.collectAsStateWithLifecycle()
    val feedback by viewModel.feedback.collectAsStateWithLifecycle()
    val busy by viewModel.busy.collectAsStateWithLifecycle()

    val context = LocalContext.current
    val snackbarHostState = remember { SnackbarHostState() }

    // Каждый message из репозитория / ViewModel показывается как Snackbar
    // вместо мигающего Toasta. Snackbar остаётся на экране, пока оператор не
    // скроет его, чтобы не пропустить критическую ошибку.
    LaunchedEffect(snackbarHostState) {
        viewModel.messages.collect { message ->
            snackbarHostState.showSnackbar(message)
        }
    }

    if (!state.initialized) {
        LoadingScreen(onRetry = { viewModel.initialize() })
        return
    }

    // Состояние сканирования QR-кода настройки
    var configScanning by remember { mutableStateOf(false) }

    if (!state.config.setupComplete) {
        if (configScanning) {
            ConfigScanScreen(
                onConfigScanned = { server, token ->
                    viewModel.saveAndVerify(server, token)
                    configScanning = false
                },
                onCancel = { configScanning = false },
            )
            return
        }
        SetupScreen(
            state = state,
            busy = busy,
            onScanConfig = { configScanning = true },
            onConfigureManually = { /*	showManual уже внутри SetupScreen */ },
            onSaveAndVerify = viewModel::saveAndVerify,
            onBeginShift = viewModel::beginShift,
        )
        return
    }

    // Plain `remember`, not `rememberSaveable`: leaving this shell (end shift,
    // clear data) must return the operator to the scanner, not to whichever
    // tab they happened to end the shift from.
    var tab by remember { mutableStateOf(TAB_SCANNER) }

    LaunchedEffect(tab) {
        if (tab != TAB_SCANNER) viewModel.clearFeedback()
    }

    Scaffold(
        modifier = Modifier.fillMaxSize(),
        snackbarHost = { SnackbarHost(hostState = snackbarHostState) },
        bottomBar = {
            NavigationBar {
                NavigationBarItem(
                    selected = tab == TAB_SCANNER,
                    onClick = { tab = TAB_SCANNER },
                    icon = {
                        Icon(
                            Icons.Filled.QrCodeScanner,
                            contentDescription = stringResource(R.string.nav_scanner),
                        )
                    },
                    label = { Text(stringResource(R.string.nav_scanner)) },
                )
                NavigationBarItem(
                    selected = tab == TAB_STATS,
                    onClick = { tab = TAB_STATS },
                    icon = {
                        Icon(
                            Icons.Filled.BarChart,
                            contentDescription = stringResource(R.string.nav_stats),
                        )
                    },
                    label = { Text(stringResource(R.string.nav_stats)) },
                )
                NavigationBarItem(
                    selected = tab == TAB_HISTORY,
                    onClick = { tab = TAB_HISTORY },
                    icon = {
                        Icon(
                            Icons.Filled.History,
                            contentDescription = stringResource(R.string.nav_history),
                        )
                    },
                    label = { Text(stringResource(R.string.nav_history)) },
                )
                NavigationBarItem(
                    selected = tab == TAB_SETTINGS,
                    onClick = { tab = TAB_SETTINGS },
                    icon = {
                        Icon(
                            Icons.Filled.Settings,
                            contentDescription = stringResource(R.string.nav_settings),
                        )
                    },
                    label = { Text(stringResource(R.string.nav_settings)) },
                )
            }
        },
    ) { padding ->
        Box(modifier = Modifier.fillMaxSize().padding(padding)) {
            when (tab) {
                TAB_SCANNER -> ScannerScreen(
                    state = state,
                    online = online,
                    feedback = feedback,
                    busy = busy,
                    feedbackPlayer = feedbackPlayer,
                    onSubmitScan = viewModel::submitScan,
                    onClearFeedback = viewModel::clearFeedback,
                    onSync = viewModel::syncNow,
                    onOpenSettings = { tab = TAB_SETTINGS },
                )

                TAB_STATS -> StatsScreen(state)
                TAB_HISTORY -> HistoryScreen(state)
                else -> SettingsScreen(
                    state = state,
                    busy = busy,
                    onVerifyToken = viewModel::verifyToken,
                    onChangeServer = viewModel::changeServer,
                    onChangeToken = viewModel::updateToken,
                    onChangeSession = viewModel::setSession,
                    onLoadBundle = viewModel::reloadBundle,
                    onRemoveBundle = viewModel::removeBundle,
                    onSync = viewModel::syncNow,
                    onNewShift = viewModel::startNewShift,
                    onEndShift = viewModel::endShift,
                    onClearAll = viewModel::clearAll,
                )
            }
        }
    }
}

@Composable
private fun LoadingScreen(
    onRetry: () -> Unit,
) {
    var error by remember { mutableStateOf(false) }

    // If initialization does not complete within LOAD_TIMEOUT_MS, show an
    // error state with a retry button instead of a perpetual spinner.
    LaunchedEffect(Unit) {
        delay(LOAD_TIMEOUT_MS)
        if (!error) {
            error = true
        }
    }

    DisposableEffect(Unit) {
        onDispose {
            // no-op cleanup
        }
    }

    Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            if (error) {
                Text(
                    text = stringResource(R.string.error_load_timeout),
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.error,
                )
                Button(onClick = onRetry) {
                    Text(stringResource(R.string.action_retry))
                }
            } else {
                CircularProgressIndicator()
                Text(
                    text = stringResource(R.string.loading),
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}
