package com.nabilet.checker.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import com.nabilet.checker.BuildConfig
import com.nabilet.checker.R
import com.nabilet.checker.data.repository.CheckerState
import com.nabilet.checker.domain.util.TimeFormat
import com.nabilet.checker.ui.components.KeyValueRow
import com.nabilet.checker.ui.components.SectionCard

private enum class SettingsDialog { NONE, SERVER, TOKEN, SESSION, BUNDLE, EXPORT }

private enum class ConfirmAction { NONE, REMOVE_BUNDLE, NEW_SHIFT, END_SHIFT, CLEAR_ALL }

@Composable
fun SettingsScreen(
    state: CheckerState,
    busy: Boolean,
    onVerifyToken: () -> Unit,
    onChangeServer: (String) -> Unit,
    onChangeToken: (String) -> Unit,
    onChangeSession: (String) -> Unit,
    onLoadBundle: (String) -> Unit,
    onRemoveBundle: () -> Unit,
    onSync: () -> Unit,
    onNewShift: () -> Unit,
    onEndShift: () -> Unit,
    onClearAll: () -> Unit,
    onExportCsv: () -> Unit,
) {
    var dialog by remember { mutableStateOf(SettingsDialog.NONE) }
    var confirm by remember { mutableStateOf(ConfirmAction.NONE) }
    var exporting by remember { mutableStateOf(false) }

    val bundle = state.bundle
    val config = state.config

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text(
            text = stringResource(R.string.settings_title),
            style = MaterialTheme.typography.headlineSmall,
            fontWeight = FontWeight.Bold,
        )

        SectionCard(title = stringResource(R.string.settings_server)) {
            KeyValueRow(
                label = stringResource(R.string.settings_server),
                value = config.baseUrl.ifBlank { stringResource(R.string.stats_no_data) },
            )
            OutlinedButton(
                onClick = { dialog = SettingsDialog.SERVER },
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.settings_change_server))
            }
        }

        SectionCard(title = stringResource(R.string.settings_token)) {
            KeyValueRow(
                label = stringResource(R.string.settings_token),
                value = stringResource(
                    if (config.hasToken) R.string.settings_token_set else R.string.settings_token_missing,
                ),
            )
            OutlinedButton(
                onClick = onVerifyToken,
                enabled = !busy && config.hasToken,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.settings_verify_token))
            }
            OutlinedButton(
                onClick = { dialog = SettingsDialog.TOKEN },
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.settings_change_token))
            }
        }

        SectionCard(title = stringResource(R.string.settings_bundle)) {
            if (bundle != null) {
                KeyValueRow(
                    label = stringResource(R.string.settings_bundle),
                    value = stringResource(
                        R.string.settings_bundle_value,
                        bundle.id,
                        bundle.ticketCount,
                    ),
                    monospace = true,
                )
                KeyValueRow(
                    label = stringResource(R.string.setup_bundle_expires),
                    value = TimeFormat.dateTime(bundle.expiresAt),
                )
                OutlinedButton(
                    onClick = { onLoadBundle(bundle.id) },
                    enabled = !busy,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(stringResource(R.string.settings_reload_bundle))
                }
                OutlinedButton(
                    onClick = { confirm = ConfirmAction.REMOVE_BUNDLE },
                    enabled = !busy,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(stringResource(R.string.settings_remove_bundle))
                }
            } else {
                KeyValueRow(
                    label = stringResource(R.string.settings_bundle),
                    value = stringResource(R.string.settings_bundle_none),
                )
                OutlinedButton(
                    onClick = { dialog = SettingsDialog.BUNDLE },
                    enabled = !busy,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(stringResource(R.string.setup_download_bundle))
                }
            }
        }

        SectionCard(title = stringResource(R.string.settings_session)) {
            KeyValueRow(
                label = stringResource(R.string.settings_session),
                value = config.sessionReference ?: stringResource(R.string.settings_session_none),
                monospace = true,
            )
            OutlinedButton(
                onClick = { dialog = SettingsDialog.SESSION },
                enabled = !busy && bundle?.sessionInternalId == null,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.settings_change_session))
            }
        }

        SectionCard(title = stringResource(R.string.settings_sync)) {
            KeyValueRow(
                label = stringResource(R.string.settings_last_sync),
                value = config.lastSyncAt?.let { TimeFormat.dateTime(it) }
                    ?: stringResource(R.string.settings_last_sync_never),
            )
            KeyValueRow(
                label = stringResource(R.string.stats_pending),
                value = state.stats.pending.toString(),
            )
            OutlinedButton(
                onClick = onSync,
                enabled = !busy && !state.syncing,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.action_sync_now))
            }
        }

        SectionCard(title = stringResource(R.string.settings_diagnostics)) {
            KeyValueRow(
                label = stringResource(R.string.settings_device),
                value = bundle?.devicePublicId ?: stringResource(R.string.stats_no_data),
                monospace = true,
            )
            KeyValueRow(
                label = stringResource(R.string.settings_event),
                value = bundle?.eventPublicId ?: stringResource(R.string.stats_no_data),
                monospace = true,
            )
            KeyValueRow(
                label = stringResource(R.string.settings_bundle_hash),
                value = bundle?.hash?.take(20)?.plus("…") ?: stringResource(R.string.stats_no_data),
                monospace = true,
            )
            KeyValueRow(
                label = stringResource(R.string.settings_schema),
                value = bundle?.schemaVersion?.toString() ?: stringResource(R.string.stats_no_data),
            )
            KeyValueRow(
                label = stringResource(R.string.settings_key),
                value = bundle?.keyFingerprint ?: stringResource(R.string.stats_no_data),
                monospace = true,
            )
            OutlinedButton(
                onClick = onExportCsv,
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.settings_export_csv))
            }
        }

        SectionCard(title = stringResource(R.string.settings_danger)) {
            OutlinedButton(
                onClick = { confirm = ConfirmAction.NEW_SHIFT },
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.action_new_shift))
            }
            OutlinedButton(
                onClick = { confirm = ConfirmAction.END_SHIFT },
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.action_end_shift))
            }
            OutlinedButton(
                onClick = { confirm = ConfirmAction.CLEAR_ALL },
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.action_clear_data))
            }
        }

        SectionCard(title = stringResource(R.string.settings_about)) {
            KeyValueRow(
                label = stringResource(R.string.settings_version),
                value = BuildConfig.VERSION_NAME,
            )
            Text(
                text = stringResource(R.string.settings_about_text),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }

    when (dialog) {
        SettingsDialog.SERVER -> TextInputDialog(
            title = stringResource(R.string.settings_dialog_server_title),
            initial = config.baseUrl,
            label = stringResource(R.string.setup_server_label),
            helper = stringResource(R.string.setup_server_helper),
            confirmLabel = stringResource(R.string.action_save),
            secret = false,
            onDismiss = { dialog = SettingsDialog.NONE },
            onConfirm = { value ->
                dialog = SettingsDialog.NONE
                onChangeServer(value)
            },
        )

        SettingsDialog.TOKEN -> TextInputDialog(
            title = stringResource(R.string.settings_dialog_token_title),
            initial = "",
            label = stringResource(R.string.setup_token_label),
            helper = stringResource(R.string.setup_token_helper),
            confirmLabel = stringResource(R.string.action_save),
            secret = true,
            onDismiss = { dialog = SettingsDialog.NONE },
            onConfirm = { value ->
                dialog = SettingsDialog.NONE
                onChangeToken(value)
            },
        )

        SettingsDialog.SESSION -> TextInputDialog(
            title = stringResource(R.string.settings_dialog_session_title),
            initial = config.sessionReference.orEmpty(),
            label = stringResource(R.string.setup_session_label),
            helper = stringResource(R.string.setup_session_helper),
            confirmLabel = stringResource(R.string.action_save),
            secret = false,
            onDismiss = { dialog = SettingsDialog.NONE },
            onConfirm = { value ->
                dialog = SettingsDialog.NONE
                onChangeSession(value)
            },
        )

        SettingsDialog.BUNDLE -> TextInputDialog(
            title = stringResource(R.string.settings_dialog_bundle_title),
            initial = "",
            label = stringResource(R.string.setup_bundle_label),
            helper = stringResource(R.string.setup_bundle_helper),
            confirmLabel = stringResource(R.string.action_save),
            secret = false,
            onDismiss = { dialog = SettingsDialog.NONE },
            onConfirm = { value ->
                dialog = SettingsDialog.NONE
                onLoadBundle(value)
            },
        )

        SettingsDialog.EXPORT -> Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(24.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                text = stringResource(R.string.settings_export_csv),
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold,
            )
            Text(
                text = stringResource(R.string.settings_export_subtitle),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )
            Spacer(modifier = Modifier.height(16.dp))
            if (exporting) {
                CircularProgressIndicator()
            } else {
                Button(
                    onClick = {
                        exporting = true
                        onExportCsv()
                        exporting = false
                        dialog = SettingsDialog.NONE
                    },
                    enabled = !exporting,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(stringResource(R.string.settings_export_done))
                }
            }
            Spacer(modifier = Modifier.height(16.dp))
            TextButton(
                onClick = { dialog = SettingsDialog.NONE },
            ) {
                Text(stringResource(R.string.action_close))
            }
        }

        SettingsDialog.NONE -> Unit
    }

    when (confirm) {
        ConfirmAction.REMOVE_BUNDLE -> ConfirmDialog(
            title = stringResource(R.string.settings_remove_bundle_confirm_title),
            text = stringResource(R.string.settings_remove_bundle_confirm_text),
            confirmLabel = stringResource(R.string.action_delete),
            onDismiss = { confirm = ConfirmAction.NONE },
            onConfirm = {
                confirm = ConfirmAction.NONE
                onRemoveBundle()
            },
        )

        ConfirmAction.NEW_SHIFT -> ConfirmDialog(
            title = stringResource(R.string.settings_new_shift_confirm_title),
            text = stringResource(R.string.settings_new_shift_confirm_text),
            confirmLabel = stringResource(R.string.action_new_shift),
            onDismiss = { confirm = ConfirmAction.NONE },
            onConfirm = {
                confirm = ConfirmAction.NONE
                onNewShift()
            },
        )

        ConfirmAction.END_SHIFT -> ConfirmDialog(
            title = stringResource(R.string.settings_end_shift_confirm_title),
            text = stringResource(R.string.settings_end_shift_confirm_text),
            confirmLabel = stringResource(R.string.action_end_shift),
            onDismiss = { confirm = ConfirmAction.NONE },
            onConfirm = {
                confirm = ConfirmAction.NONE
                onEndShift()
            },
        )

        ConfirmAction.CLEAR_ALL -> ConfirmDialog(
            title = stringResource(R.string.settings_clear_confirm_title),
            text = stringResource(R.string.settings_clear_confirm_text),
            confirmLabel = stringResource(R.string.action_clear_data),
            onDismiss = { confirm = ConfirmAction.NONE },
            onConfirm = {
                confirm = ConfirmAction.NONE
                onClearAll()
            },
        )

        ConfirmAction.NONE -> Unit
    }
}

@Composable
private fun TextInputDialog(
    title: String,
    initial: String,
    label: String,
    helper: String?,
    confirmLabel: String,
    secret: Boolean,
    onDismiss: () -> Unit,
    onConfirm: (String) -> Unit,
) {
    var value by rememberSaveable { mutableStateOf(initial) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = value,
                    onValueChange = { value = it },
                    label = { Text(label) },
                    singleLine = true,
                    visualTransformation = if (secret) {
                        PasswordVisualTransformation()
                    } else {
                        VisualTransformation.None
                    },
                    modifier = Modifier.fillMaxWidth(),
                )
                if (helper != null) {
                    Text(
                        text = helper,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        },
        confirmButton = {
            TextButton(
                onClick = { onConfirm(value.trim()) },
                enabled = value.isNotBlank(),
            ) {
                Text(confirmLabel)
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text(stringResource(R.string.action_cancel))
            }
        },
    )
}

@Composable
private fun ConfirmDialog(
    title: String,
    text: String,
    confirmLabel: String,
    onDismiss: () -> Unit,
    onConfirm: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = { Text(text) },
        confirmButton = {
            TextButton(
                onClick = onConfirm,
                colors = ButtonDefaults.textButtonColors(
                    contentColor = MaterialTheme.colorScheme.error,
                ),
            ) {
                Text(confirmLabel)
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text(stringResource(R.string.action_cancel))
            }
        },
    )
}
