package com.nabilet.checker.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material.icons.filled.Keyboard
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.FontWeight
import androidx.compose.ui.text.TextAlign
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import com.nabilet.checker.R
import com.nabilet.checker.data.repository.CheckerState
import com.nabilet.checker.data.repository.TokenState
import com.nabilet.checker.ui.components.StatusPill
import com.nabilet.checker.ui.theme.CheckerColors

/**
 * Экран первичной настройки — максимально упрощённый.
 *
 * Проверяющий видит минимум:
 *  1. Статус: настроено / не настроено
 *  2. Огромная кнопка «Отсканировать QR от администратора» (основной путь)
 *  3. Маленькая кнопка «Ввести вручную» (резерв)
 *  4. Если готово — огромная кнопка «Начать смену»
 *
 * Никаких полей ввода, копирования-вставки, длинных адресов.
 */
@Composable
fun SetupScreen(
    state: CheckerState,
    busy: Boolean,
    onScanConfig: () -> Unit,
    onConfigureManually: () -> Unit,
    onSaveAndVerify: (String, String) -> Unit,
    onBeginShift: () -> Unit,
) {
    var manualServer by rememberSaveable { mutableStateOf("") }
    var manualToken by rememberSaveable { mutableStateOf("") }
    var showManual by rememberSaveable { mutableStateOf(false) }

    val isConfigured = state.config.baseUrl.isNotBlank() && state.config.hasToken

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 24.dp, vertical = 16.dp),
        verticalArrangement = Arrangement.spacedBy(20.dp),
    ) {
        // ── Header ──────────────────────────────────────────────
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                text = stringResource(R.string.app_name),
                style = MaterialTheme.typography.headlineLarge,
                fontWeight = FontWeight.Bold,
            )
            Text(
                text = stringResource(R.string.app_tagline),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        // ── Статус ──────────────────────────────────────────────
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            TokenStatePill(state.tokenState)
            if (isConfigured) {
                StatusPill(
                    text = stringResource(R.string.status_configured),
                    background = CheckerColors.Admit,
                )
            } else {
                StatusPill(
                    text = stringResource(R.string.status_not_configured),
                    background = CheckerColors.Deny,
                )
            }
        }

        // ── Если уже настроено — сразу кнопка смены ─────────────
        if (isConfigured) {
            SetupActionCard(
                title = stringResource(R.string.setup_ready_title),
                subtitle = stringResource(R.string.setup_ready_subtitle),
                onAction = onBeginShift,
                busy = busy,
                actionLabel = stringResource(R.string.setup_begin),
            )
        }

        // ── Основная кнопка: сканировать QR ─────────────────────
        BigActionCard(
            icon = Icons.Default.QrCodeScanner,
            title = stringResource(R.string.setup_scan_title),
            subtitle = stringResource(R.string.setup_scan_subtitle),
            onClick = { onScanConfig() },
            busy = busy,
            primary = true,
        )

        // ── Резерв: ввести вручную ──────────────────────────────
        if (!showManual) {
            Button(
                onClick = { showManual = true },
                modifier = Modifier.fillMaxWidth(),
                colors = ButtonDefaults.buttonColors(
                    containerColor = Color.Transparent,
                    contentColor = MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.6f),
                ),
            ) {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.Center,
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(
                        imageVector = Icons.Default.Keyboard,
                        contentDescription = null,
                        modifier = Modifier.size(20.dp),
                    )
                    Spacer(modifier = Modifier.width(8.dp))
                    Text(stringResource(R.string.setup_manual_title))
                }
            }
        }

        // ── Ручное поле (скрыто по умолчанию) ───────────────────
        if (showManual) {
            ManualCard(
                server = manualServer,
                onServerChange = { manualServer = it },
                token = manualToken,
                onTokenChange = { manualToken = it },
                onSave = { onSaveAndVerify(manualServer, manualToken) },
                busy = busy,
            )
        }

        // ── Footer ──────────────────────────────────────────────
        Text(
            text = stringResource(R.string.brand_footer),
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.fillMaxWidth(),
        )
    }
}

/** Карта действия: огромная кнопка с иконкой. */
@Composable
private fun BigActionCard(
    icon: androidx.compose.material.icons.filled.ImageVector,
    title: String,
    subtitle: String,
    onClick: () -> Unit,
    busy: Boolean,
    primary: Boolean,
) {
    Surface(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(20.dp))
            .background(
                color = if (primary) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surfaceContainerHighest.copy(alpha = 0.3f),
                shape = RoundedCornerShape(20.dp),
            ),
        color = if (primary) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surfaceContainerHighest.copy(alpha = 0.3f),
        contentColor = if (primary) Color.White else MaterialTheme.colorScheme.onSurface,
    ) {
        Column(
            modifier = Modifier.padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Icon(
                imageVector = icon,
                contentDescription = null,
                modifier = Modifier.size(48.dp),
                tint = if (primary) Color.White else MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Text(
                text = title,
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.Bold,
                color = if (primary) Color.White else MaterialTheme.colorScheme.onSurface,
                textAlign = TextAlign.Center,
            )
            Text(
                text = subtitle,
                style = MaterialTheme.typography.bodyMedium,
                color = if (primary) Color.White.copy(alpha = 0.9f) else MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )
            Spacer(modifier = Modifier.height(16.dp))
            if (busy) {
                CircularProgressIndicator(
                    modifier = Modifier.size(24.dp),
                    strokeWidth = 3.dp,
                )
            } else {
                Button(
                    onClick = onClick,
                    modifier = Modifier.fillMaxWidth(0.7f),
                    colors = ButtonDefaults.buttonColors(
                        containerColor = if (primary) Color.White else MaterialTheme.colorScheme.primary,
                        contentColor = if (primary) MaterialTheme.colorScheme.primary else Color.White,
                    ),
                ) {
                    Text(stringResource(R.string.action_scan))
                }
            }
        }
    }
}

/** Карта действия «Начать смену». */
@Composable
private fun SetupActionCard(
    title: String,
    subtitle: String,
    onAction: () -> Unit,
    busy: Boolean,
    actionLabel: String,
) {
    Surface(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(20.dp))
            .background(
                color = CheckerColors.Admit.copy(alpha = 0.1f),
                shape = RoundedCornerShape(20.dp),
            ),
    ) {
        Column(
            modifier = Modifier.padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Icon(
                imageVector = Icons.Default.Close,
                contentDescription = null,
                modifier = Modifier.size(48.dp).tint(CheckerColors.Admit),
            )
            Text(
                text = title,
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.Bold,
                color = MaterialTheme.colorScheme.onSurface,
                textAlign = TextAlign.Center,
            )
            Text(
                text = subtitle,
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )
            Spacer(modifier = Modifier.height(16.dp))
            Button(
                onClick = onAction,
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(0.7f),
                colors = ButtonDefaults.buttonColors(
                    containerColor = CheckerColors.Admit,
                    contentColor = Color.White,
                ),
            ) {
                if (busy) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(20.dp),
                        strokeWidth = 3.dp,
                    )
                } else {
                    Text(actionLabel)
                }
            }
        }
    }
}

/** Ручной ввод (скрыт по умолчанию, только для резерва). */
@Composable
private fun ManualCard(
    server: String,
    onServerChange: (String) -> Unit,
    token: String,
    onTokenChange: (String) -> Unit,
    onSave: (String, String) -> Unit,
    busy: Boolean,
) {
    Surface(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(16.dp))
            .background(
                color = MaterialTheme.colorScheme.surfaceContainerHighest.copy(alpha = 0.3f),
                shape = RoundedCornerShape(16.dp),
            ),
    ) {
        Column(
            modifier = Modifier.padding(20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(
                text = stringResource(R.string.setup_manual_title),
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold,
                color = MaterialTheme.colorScheme.onSurface,
            )
            Text(
                text = stringResource(R.string.setup_manual_subtitle),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(modifier = Modifier.height(8.dp))
            androidx.compose.material3.OutlinedTextField(
                value = server,
                onValueChange = onServerChange,
                label = { Text(stringResource(R.string.setup_server_label)) },
                placeholder = { Text(stringResource(R.string.setup_server_hint)) },
                singleLine = true,
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            )
            Spacer(modifier = Modifier.height(12.dp))
            androidx.compose.material3.OutlinedTextField(
                value = token,
                onValueChange = onTokenChange,
                label = { Text(stringResource(R.string.setup_token_label)) },
                placeholder = { Text(stringResource(R.string.setup_token_hint)) },
                singleLine = true,
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            )
            Spacer(modifier = Modifier.height(16.dp))
            Button(
                onClick = { onSave(server, token) },
                enabled = !busy && server.isNotBlank() && token.isNotBlank(),
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.setup_check))
            }
        }
    }
}

/** Pill с состоянием токена. */
@Composable
private fun TokenStatePill(state: TokenState) {
    val (text, color) = when (state) {
        TokenState.UNKNOWN -> stringResource(R.string.status_unknown) to CheckerColors.Info
        TokenState.CHECKING -> stringResource(R.string.token_checking) to CheckerColors.Info
        TokenState.VALID -> stringResource(R.string.status_token_valid) to CheckerColors.Admit
        TokenState.INVALID -> stringResource(R.string.blocked_token) to CheckerColors.Deny
    }
    StatusPill(text = text, background = color)
}
