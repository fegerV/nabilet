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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import com.nabilet.checker.R
import com.nabilet.checker.data.repository.CheckerState
import com.nabilet.checker.data.repository.TokenState
import com.nabilet.checker.domain.util.TimeFormat
import com.nabilet.checker.ui.components.KeyValueRow
import com.nabilet.checker.ui.components.SectionCard
import com.nabilet.checker.ui.components.StatusPill
import com.nabilet.checker.ui.theme.CheckerColors

/**
 * First-run / end-of-shift screen. Three independent actions, because the
 * device can be usable before it is fully configured:
 *
 *  - save + verify the token (proves the server, the origin and the token match);
 *  - download a bundle (needs a bundle id from the admin);
 *  - begin the shift.
 *
 * "Begin shift" is not blocked by the bundle: a venue with reliable Wi-Fi can
 * work online-only, and refusing to start until a bundle is downloaded would
 * invent a requirement the backend does not impose.
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

    LaunchedEffect(state.config.baseUrl) {
        if (url.isBlank() && state.config.baseUrl.isNotBlank()) url = state.config.baseUrl
    }
    LaunchedEffect(state.config.sessionReference) {
        val fromBundle = state.config.sessionReference
        if (!fromBundle.isNullOrBlank()) session = fromBundle
    }

    val sessionLocked = state.bundle?.sessionInternalId != null

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 20.dp, vertical = 24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        Text(
            text = stringResource(R.string.app_name),
            style = MaterialTheme.typography.headlineMedium,
            fontWeight = FontWeight.Bold,
        )
        Text(
            text = stringResource(R.string.app_tagline),
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )

        SectionCard(title = stringResource(R.string.setup_title)) {
            Text(
                text = stringResource(R.string.setup_subtitle),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            OutlinedTextField(
                value = url,
                onValueChange = { url = it },
                label = { Text(stringResource(R.string.setup_server_label)) },
                placeholder = { Text(stringResource(R.string.setup_server_hint)) },
                supportingText = { Text(stringResource(R.string.setup_server_helper)) },
                singleLine = true,
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            )

            OutlinedTextField(
                value = token,
                onValueChange = { token = it },
                label = { Text(stringResource(R.string.setup_token_label)) },
                placeholder = { Text(stringResource(R.string.setup_token_hint)) },
                supportingText = { Text(stringResource(R.string.setup_token_helper)) },
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

            TokenStateRow(state.tokenState)

            Button(
                onClick = { onSaveAndVerify(url, token) },
                enabled = !busy && url.isNotBlank() && token.isNotBlank(),
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.setup_check))
            }
        }

        SectionCard(title = stringResource(R.string.setup_bundle_label)) {
            Text(
                text = stringResource(R.string.setup_bundle_helper),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            OutlinedTextField(
                value = bundleId,
                onValueChange = { bundleId = it },
                label = { Text(stringResource(R.string.setup_bundle_label)) },
                placeholder = { Text(stringResource(R.string.setup_bundle_hint)) },
                singleLine = true,
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            )

            OutlinedButton(
                onClick = { onDownloadBundle(url, token, bundleId) },
                enabled = !busy && url.isNotBlank() && token.isNotBlank() && bundleId.isNotBlank(),
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.setup_download_bundle))
            }

            OutlinedTextField(
                value = session,
                onValueChange = { session = it },
                label = { Text(stringResource(R.string.setup_session_label)) },
                placeholder = { Text(stringResource(R.string.setup_session_hint)) },
                supportingText = {
                    Text(
                        stringResource(
                            if (sessionLocked) {
                                R.string.blocked_session_locked_by_bundle
                            } else {
                                R.string.setup_session_helper
                            },
                        ),
                    )
                },
                singleLine = true,
                enabled = !busy && !sessionLocked,
                modifier = Modifier.fillMaxWidth(),
            )

            if (state.bundle == null) {
                Text(
                    text = stringResource(R.string.setup_bundle_online_only),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }

        state.bundle?.let { bundle ->
            SectionCard(title = stringResource(R.string.setup_bundle_loaded)) {
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
                    label = stringResource(R.string.setup_bundle_revoked),
                    value = bundle.revokedCount.toString(),
                )
                KeyValueRow(
                    label = stringResource(R.string.setup_bundle_expires),
                    value = TimeFormat.dateTime(bundle.expiresAt),
                )
                KeyValueRow(
                    label = stringResource(R.string.setup_bundle_key),
                    value = bundle.keyFingerprint.take(16) + "…",
                    monospace = true,
                )
            }
        }

        SectionCard(title = stringResource(R.string.setup_begin)) {
            Text(
                text = stringResource(R.string.setup_online_note),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Spacer(modifier = Modifier.height(2.dp))

            Button(
                onClick = onBeginShift,
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.setup_begin))
            }

            if (busy) {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.Center,
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    CircularProgressIndicator(modifier = Modifier.size(20.dp))
                }
            }
        }

        Text(
            text = stringResource(R.string.brand_footer),
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.fillMaxWidth(),
        )
    }
}

@Composable
private fun TokenStateRow(state: TokenState) {
    val (text, color) = when (state) {
        TokenState.UNKNOWN -> return
        TokenState.CHECKING -> stringResource(R.string.token_checking) to CheckerColors.Info
        TokenState.VALID -> stringResource(R.string.verify_ok) to CheckerColors.Admit
        TokenState.INVALID -> stringResource(R.string.blocked_token) to CheckerColors.Deny
    }
    StatusPill(text = text, background = color)
}
