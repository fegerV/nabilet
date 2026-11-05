package com.nabilet.checker.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.filled.ArrowDropDown
import androidx.compose.material.icons.filled.ArrowDropUp
import androidx.compose.material.icons.filled.Download
import androidx.compose.material.icons.filled.Info
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import com.nabilet.checker.R
import com.nabilet.checker.data.repository.CheckerState
import com.nabilet.checker.data.repository.TokenState
import com.nabilet.checker.domain.model.ActiveBundle
import com.nabilet.checker.domain.util.TimeFormat
import com.nabilet.checker.ui.components.KeyValueRow
import com.nabilet.checker.ui.components.StatusPill
import com.nabilet.checker.ui.theme.CheckerColors

/**
 * Экран первичной настройки устройства.
 *
 * Упрощённая иерархия:
 *  1. Заголовок + текущий статус
 *  2. Основная карта: сервер + токен + первичная кнопка «Подключить»
 *  3. Опциональная карта: офлайн-бандл (скрыта по умолчанию)
 *  4. Действие: «Начать смену» (доступно только когда настроено)
 *
 * Progress disclosure: оператор видит минимум полей. Дополнительные
 * параметры открываются по необходимости.
 */
@Composable
fun SetupScreen(
    state: CheckerState,
    busy: Boolean,
    onSaveAndVerify: (String, String) -> Unit,
    onDownloadBundle: (String, String, String) -> Unit,
    onBeginShift: () -> Unit,
) {
    var url by rememberSaveable { mutableStateOf(state.config.baseUrl) }
    var token by rememberSaveable { mutableStateOf("") }
    var bundleId by rememberSaveable { mutableStateOf(state.config.bundleId.orEmpty()) }
    var session by rememberSaveable { mutableStateOf(state.config.sessionReference.orEmpty()) }
    var tokenVisible by rememberSaveable { mutableStateOf(false) }
    var bundleSectionExpanded by rememberSaveable { mutableStateOf(state.bundle != null) }

    LaunchedEffect(state.config.baseUrl) {
        if (url.isBlank() && state.config.baseUrl.isNotBlank()) url = state.config.baseUrl
    }
    LaunchedEffect(state.config.sessionReference) {
        val fromBundle = state.config.sessionReference
        if (!fromBundle.isNullOrBlank()) session = fromBundle
    }

    val sessionLocked = state.bundle?.sessionInternalId != null
    val isConfigured = state.config.baseUrl.isNotBlank() && state.config.hasToken

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 20.dp, vertical = 16.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
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

        // ── Status row ──────────────────────────────────────────
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

        // ── Primary card: server + token ────────────────────────
        SetupCard(title = stringResource(R.string.setup_connection_title)) {
            OutlinedTextField(
                value = url,
                onValueChange = { url = it },
                label = { Text(stringResource(R.string.setup_server_label)) },
                placeholder = { Text(stringResource(R.string.setup_server_hint)) },
                supportingText = {
                    InfoHelperText(stringResource(R.string.setup_server_helper))
                },
                singleLine = true,
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            )

            Spacer(modifier = Modifier.height(12.dp))

            OutlinedTextField(
                value = token,
                onValueChange = { token = it },
                label = { Text(stringResource(R.string.setup_token_label)) },
                placeholder = { Text(stringResource(R.string.setup_token_hint)) },
                supportingText = {
                    InfoHelperText(stringResource(R.string.setup_token_helper))
                },
                singleLine = true,
                enabled = !busy,
                visualTransformation = if (tokenVisible) {
                    VisualTransformation.None
                } else {
                    PasswordVisualTransformation()
                },
                trailingIcon = {
                    TextButton(onClick = { tokenVisible = !tokenVisible }) {
                        Text(
                            stringResource(
                                if (tokenVisible) R.string.action_hide else R.string.action_show,
                            ),
                        )
                    }
                },
                modifier = Modifier.fillMaxWidth(),
            )

            Spacer(modifier = Modifier.height(16.dp))

            TokenStateRow(state.tokenState)

            Spacer(modifier = Modifier.height(16.dp))

            Button(
                onClick = { onSaveAndVerify(url, token) },
                enabled = !busy && url.isNotBlank() && token.isNotBlank(),
                modifier = Modifier.fillMaxWidth(),
                colors = ButtonDefaults.buttonColors(
                    containerColor = if (!busy && url.isNotBlank() && token.isNotBlank()) {
                        MaterialTheme.colorScheme.primary
                    } else {
                        MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.3f)
                    },
                ),
            ) {
                if (busy) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(20.dp),
                        strokeWidth = 2.dp,
                    )
                } else {
                    Text(stringResource(R.string.setup_check))
                }
            }
        }

        // ── Optional card: offline bundle ───────────────────────
        if (bundleSectionExpanded || state.bundle != null || bundleId.isNotBlank()) {
            BundleCard(
                expanded = bundleSectionExpanded,
                onToggle = { bundleSectionExpanded = !bundleSectionExpanded },
                state = state,
                busy = busy,
                bundleId = bundleId,
                onBundleIdChange = { bundleId = it },
                session = session,
                onSessionChange = { session = it },
                sessionLocked = sessionLocked,
                onDownloadBundle = { onDownloadBundle(url, token, bundleId) },
            )
        } else {
            // Compact expand trigger when no bundle yet
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.Center,
            ) {
                TextButton(
                    onClick = { bundleSectionExpanded = true },
                    enabled = !busy,
                ) {
                    Text(
                        text = stringResource(R.string.setup_bundle_add),
                        style = MaterialTheme.typography.labelMedium.copy(
                            fontWeight = FontWeight.Medium,
                        ),
                        color = MaterialTheme.colorScheme.primary,
                    )
                }
            }
        }

        // ── Loaded bundle info ──────────────────────────────────
        state.bundle?.let { bundle ->
            BundleInfoCard(bundle = bundle)
        }

        // ── Begin shift ─────────────────────────────────────────
        SetupActionCard(
            title = stringResource(R.string.setup_begin),
            onBeginShift = onBeginShift,
            busy = busy,
            canBeginShift = !busy && isConfigured,
        )

        // ── Footer ──────────────────────────────────────────────
        Text(
            text = stringResource(R.string.brand_footer),
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.fillMaxWidth(),
        )
    }
}

/** Small info text with an info icon — keeps the screen clean. */
@Composable
private fun InfoHelperText(text: String) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Icon(
            imageVector = Info,
            contentDescription = null,
            modifier = Modifier.size(16.dp),
            tint = MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.5f),
        )
        Text(
            text = text,
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.7f),
            maxLines = 2,
        )
    }
}

/** Styled card wrapper with title. */
@Composable
private fun SetupCard(
    title: String,
    content: @Composable () -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(16.dp))
            .background(
                color = MaterialTheme.colorScheme.surfaceContainerHighest.copy(alpha = 0.3f),
                shape = RoundedCornerShape(16.dp),
            ),
    ) {
        Text(
            text = title,
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
            color = MaterialTheme.colorScheme.onSurface,
            modifier = Modifier.padding(16.dp, 16.dp, 16.dp, 8.dp),
        )
        Column(
            modifier = Modifier.padding(16.dp, 0.dp, 16.dp, 16.dp),
        ) {
            content()
        }
    }
}

/** Token state pill. */
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

/** Optional bundle card with expand/collapse. */
@Composable
private fun BundleCard(
    expanded: Boolean,
    onToggle: () -> Unit,
    state: CheckerState,
    busy: Boolean,
    bundleId: String,
    onBundleIdChange: (String) -> Unit,
    session: String,
    onSessionChange: (String) -> Unit,
    sessionLocked: Boolean,
    onDownloadBundle: () -> Unit,
) {
    Column(
        modifier = Modifier.fillMaxWidth(),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                text = stringResource(R.string.setup_bundle_label),
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold,
            )
            Spacer(modifier = Modifier.weight(1f))
            IconButton(onClick = onToggle) {
                Icon(
                    imageVector = if (expanded) ArrowDropUp else ArrowDropDown,
                    contentDescription = null,
                )
            }
        }

        if (expanded) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .clip(RoundedCornerShape(12.dp))
                    .background(
                        color = MaterialTheme.colorScheme.surfaceContainerHighest.copy(alpha = 0.2f),
                        shape = RoundedCornerShape(12.dp),
                    ),
            ) {
                Column(modifier = Modifier.padding(16.dp)) {
                    OutlinedTextField(
                        value = bundleId,
                        onValueChange = onBundleIdChange,
                        label = { Text(stringResource(R.string.setup_bundle_label)) },
                        placeholder = { Text(stringResource(R.string.setup_bundle_hint)) },
                        singleLine = true,
                        enabled = !busy,
                        modifier = Modifier.fillMaxWidth(),
                    )

                    Spacer(modifier = Modifier.height(12.dp))

                    OutlinedTextField(
                        value = session,
                        onValueChange = onSessionChange,
                        label = { Text(stringResource(R.string.setup_session_label)) },
                        placeholder = { Text(stringResource(R.string.setup_session_hint)) },
                        supportingText = {
                            if (sessionLocked) {
                                InfoHelperText(stringResource(R.string.blocked_session_locked_by_bundle))
                            } else {
                                InfoHelperText(stringResource(R.string.setup_session_helper))
                            }
                        },
                        singleLine = true,
                        enabled = !busy && !sessionLocked,
                        modifier = Modifier.fillMaxWidth(),
                    )

                    Spacer(modifier = Modifier.height(16.dp))

                    OutlinedButton(
                        onClick = onDownloadBundle,
                        enabled = !busy && bundleId.isNotBlank(),
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Text(stringResource(R.string.setup_download_bundle))
                    }

                    if (state.bundle == null && !busy) {
                        Text(
                            text = stringResource(R.string.setup_bundle_online_only),
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.padding(top = 8.dp),
                        )
                    }
                }
            }
        }
    }
}

/** Compact info card for loaded bundle. */
@Composable
private fun BundleInfoCard(bundle: ActiveBundle) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .background(
                color = CheckerColors.Admit.copy(alpha = 0.1f),
                shape = RoundedCornerShape(12.dp),
            ),
    ) {
        Column(modifier = Modifier.padding(16.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(
                    imageVector = Download,
                    contentDescription = null,
                    modifier = Modifier.size(20.dp).tint(CheckerColors.Admit),
                )
                Spacer(modifier = Modifier.width(8.dp))
                Text(
                    text = stringResource(R.string.setup_bundle_loaded),
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                    color = CheckerColors.Admit,
                )
            }

            Spacer(modifier = Modifier.height(12.dp))

            KeyValueRow(
                label = stringResource(R.string.setup_bundle_event),
                value = bundle.eventPublicId,
            )
            KeyValueRow(
                label = stringResource(R.string.setup_bundle_session),
                value = bundle.sessionPublicId ?: "—",
            )
            KeyValueRow(
                label = stringResource(R.string.setup_bundle_tickets),
                value = bundle.ticketCount.toString(),
            )
            KeyValueRow(
                label = stringResource(R.string.setup_bundle_expires),
                value = TimeFormat.dateTime(bundle.expiresAt),
            )
        }
    }
}

/** Action card: begin shift. */
@Composable
private fun SetupActionCard(
    title: String,
    onBeginShift: () -> Unit,
    busy: Boolean,
    canBeginShift: Boolean,
) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(16.dp))
            .background(
                color = MaterialTheme.colorScheme.surfaceContainerHighest.copy(alpha = 0.3f),
                shape = RoundedCornerShape(16.dp),
            ),
    ) {
        Column(modifier = Modifier.padding(16.dp, 16.dp, 16.dp, 8.dp)) {
            Text(
                text = title,
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold,
                color = MaterialTheme.colorScheme.onSurface,
            )
        }

        Column(modifier = Modifier.padding(16.dp, 0.dp, 16.dp, 16.dp)) {
            if (!canBeginShift && !busy) {
                Text(
                    text = stringResource(R.string.setup_begin_disabled),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }

            Spacer(modifier = Modifier.height(12.dp))

            Button(
                onClick = onBeginShift,
                enabled = canBeginShift,
                modifier = Modifier.fillMaxWidth(),
                colors = ButtonDefaults.buttonColors(
                    containerColor = if (canBeginShift) {
                        MaterialTheme.colorScheme.primary
                    } else {
                        MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.3f)
                    },
                ),
            ) {
                if (busy) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(20.dp),
                        strokeWidth = 2.dp,
                    )
                } else {
                    Text(stringResource(R.string.setup_begin))
                }
            }
        }
    }
}

@Composable
private fun TokenStateRow(state: TokenState) {
    val (text, color) = when (state) {
        TokenState.UNKNOWN -> return
        TokenState.CHECKING -> stringResource(R.string.token_checking) to CheckerColors.Info
        TokenState.VALID -> stringResource(R.string.status_token_valid) to CheckerColors.Admit
        TokenState.INVALID -> stringResource(R.string.blocked_token) to CheckerColors.Deny
    }
    StatusPill(text = text, background = color)
}
