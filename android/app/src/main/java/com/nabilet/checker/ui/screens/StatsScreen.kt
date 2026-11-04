package com.nabilet.checker.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.nabilet.checker.R
import com.nabilet.checker.data.repository.CheckerState
import com.nabilet.checker.domain.model.CheckinResult
import com.nabilet.checker.domain.util.TimeFormat
import com.nabilet.checker.ui.components.EmptyState
import com.nabilet.checker.ui.components.KeyValueRow
import com.nabilet.checker.ui.components.MetricTile
import com.nabilet.checker.ui.components.SectionCard
import com.nabilet.checker.ui.components.resultLabel
import com.nabilet.checker.ui.theme.CheckerColors
import java.util.Locale

/**
 * Shift counters. The numbers come from SQLite, not from an in-memory tally, so
 * they survive the app being killed mid-shift — which is exactly when an
 * operator needs to know how many people were let in.
 */
@Composable
fun StatsScreen(state: CheckerState) {
    val stats = state.stats
    val perMinute = String.format(Locale.US, "%.1f", stats.lastFiveMinutes / 5.0)

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(
                    text = stringResource(R.string.stats_title),
                    style = MaterialTheme.typography.headlineSmall,
                    fontWeight = FontWeight.Bold,
                )
                if (state.config.shiftStartedAt > 0L) {
                    Text(
                        text = stringResource(
                            R.string.stats_shift_start,
                        ) + " · " + TimeFormat.dateTime(state.config.shiftStartedAt),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }

        item {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    MetricTile(
                        label = stringResource(R.string.stats_total),
                        value = stats.total.toString(),
                        accent = MaterialTheme.colorScheme.primary,
                        modifier = Modifier.weight(1f),
                    )
                    MetricTile(
                        label = stringResource(R.string.stats_admitted),
                        value = stats.admitted.toString(),
                        accent = CheckerColors.Admit,
                        modifier = Modifier.weight(1f),
                    )
                }
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    MetricTile(
                        label = stringResource(R.string.stats_denied),
                        value = stats.denied.toString(),
                        accent = CheckerColors.Deny,
                        modifier = Modifier.weight(1f),
                    )
                    MetricTile(
                        label = stringResource(R.string.stats_pending),
                        value = stats.pending.toString(),
                        accent = CheckerColors.Warn,
                        modifier = Modifier.weight(1f),
                    )
                }
            }
        }

        item {
            SectionCard(title = stringResource(R.string.stats_rate)) {
                Text(
                    text = stringResource(R.string.stats_rate_value, perMinute),
                    style = MaterialTheme.typography.headlineSmall,
                    fontWeight = FontWeight.Bold,
                )
                Text(
                    text = stringResource(R.string.stats_rate_window),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                KeyValueRow(
                    label = stringResource(R.string.stats_last_scan),
                    value = stats.lastScanAt?.let { TimeFormat.timeOfDay(it) }
                        ?: stringResource(R.string.stats_no_data),
                )
            }
        }

        item {
            SectionCard(title = stringResource(R.string.stats_breakdown)) {
                val rows = CheckinResult.values().map { it to (stats.byResult[it] ?: 0) }
                    .filter { it.second > 0 }

                if (rows.isEmpty()) {
                    EmptyState(
                        title = stringResource(R.string.stats_empty_title),
                        text = stringResource(R.string.stats_empty_text),
                    )
                } else {
                    rows.forEach { (result, count) ->
                        KeyValueRow(label = resultLabel(result), value = count.toString())
                    }
                }
            }
        }

        item {
            Text(
                text = stringResource(R.string.brand_footer),
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.fillMaxWidth().padding(top = 8.dp),
            )
        }
    }
}
