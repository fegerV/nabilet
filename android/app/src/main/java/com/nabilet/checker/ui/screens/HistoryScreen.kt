package com.nabilet.checker.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.collectLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.Card
import androidx.compose.material3.FilterChip
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.nabilet.checker.R
import com.nabilet.checker.data.repository.CheckerState
import com.nabilet.checker.domain.model.CheckinResult
import com.nabilet.checker.domain.model.ScanRecord
import com.nabilet.checker.domain.util.TimeFormat
import com.nabilet.checker.ui.components.EmptyState
import com.nabilet.checker.ui.components.resultLabel
import com.nabilet.checker.ui.components.sourceLabel
import com.nabilet.checker.ui.theme.CheckerColors
import kotlinx.coroutines.launch
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Close

private const val FILTER_ALL = 0
private const val FILTER_ADMITTED = 1
private const val FILTER_DENIED = 2
private const val FILTER_PENDING = 3

/** Number of scans loaded per page. */
private const val PAGE_SIZE = 50

/** Start loading the next page when 70% of the current page is visible. */
private const val PRELOAD_THRESHOLD = 0.7f

@Composable
fun HistoryScreen(
    state: CheckerState,
    pagedScans: List<ScanRecord> = emptyList(),
    totalScanCount: Int = 0,
    onLoadMore: ((Int) -> Unit)? = null,
    searchQuery: String = "",
    onSearch: ((String) -> Unit)? = null,
) {
    var filter by rememberSaveable { mutableStateOf(FILTER_ALL) }
    val lazyListState = collectLazyListState()
    var searchText by rememberSaveable { mutableStateOf(searchQuery) }

    // Use search results if searching, otherwise paginated scans
    val scans = if (searchText.isNotEmpty()) state.recentScans else pagedScans

    // Apply filter and search to the currently loaded scans
    val filtered = when (filter) {
        FILTER_ADMITTED -> scans.filter { it.result.admits }
        FILTER_DENIED -> scans.filter { !it.result.admits }
        FILTER_PENDING -> scans.filter { !it.synced }
        else -> scans
    }.filter {
        if (searchText.isNotBlank()) {
            val q = searchText.lowercase()
            it.ticketNumber?.lowercase()?.contains(q) == true ||
                it.ticketId.lowercase().contains(q)
        } else {
            true
        }
    }

    // Reset lazy list state when filter or search changes
    LaunchedEffect(filter, searchText) {
        lazyListState.scrollToItem(0)
    }

    // Preload next page when user scrolls near the bottom (only when not searching)
    LaunchedEffect(lazyListState) {
        if (searchText.isBlank()) {
            val snapshot = lazyListState.layoutInfo.visibleItemsInfo
            if (snapshot.isNotEmpty()) {
                val lastVisibleIndex = snapshot.last().index
                val totalItems = lazyListState.layoutInfo.totalItemsCount
                if (totalItems > 0 && lastVisibleIndex >= (totalItems * PRELOAD_THRESHOLD).toInt()) {
                    onLoadMore?.invoke(lazyListState.layoutInfo.totalItemsCount)
                }
            }
        }
    }

    Column(modifier = Modifier.fillMaxSize()) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState())
                .padding(horizontal = 12.dp, vertical = 8.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            FilterChip(
                selected = filter == FILTER_ALL,
                onClick = { filter = FILTER_ALL },
                label = { Text(stringResource(R.string.history_filter_all)) },
            )
            FilterChip(
                selected = filter == FILTER_ADMITTED,
                onClick = { filter = FILTER_ADMITTED },
                label = { Text(stringResource(R.string.history_filter_admitted)) },
            )
            FilterChip(
                selected = filter == FILTER_DENIED,
                onClick = { filter = FILTER_DENIED },
                label = { Text(stringResource(R.string.history_filter_denied)) },
            )
            FilterChip(
                selected = filter == FILTER_PENDING,
                onClick = { filter = FILTER_PENDING },
                label = { Text(stringResource(R.string.history_filter_pending)) },
            )
        }

        // Search field
        OutlinedTextField(
            value = searchText,
            onValueChange = { searchText = it; onSearch?.invoke(it) },
            label = { Text(stringResource(R.string.history_search_hint)) },
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
            trailingIcon = {
                if (searchText.isNotEmpty()) {
                    IconButton(onClick = { searchText = ""; onSearch?.invoke("") }) {
                        Icons.Default.Close
                    }
                }
            },
        )

        if (filtered.isEmpty()) {
            EmptyState(
                title = if (searchText.isNotEmpty()) stringResource(R.string.history_search_empty) else stringResource(R.string.history_empty_title),
                text = stringResource(R.string.history_empty_text),
            )
        } else {
            LazyColumn(
                modifier = Modifier
                    .fillMaxSize()
                    .state(lazyListState),
                contentPadding = PaddingValues(horizontal = 12.dp, vertical = 4.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                item {
                    Text(
                        text = stringResource(
                            R.string.history_count,
                            filtered.size,
                        ),
                        style = MaterialTheme.typography.labelMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                items(
                    items = filtered,
                    key = { it.clientScanId },
                ) { scan ->
                    HistoryRow(scan)
                }

                // Loading indicator at the bottom when more data is being fetched
                if (!searchText.isNotEmpty() && totalScanCount > filtered.size) {
                    item {
                        Box(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(vertical = 16.dp),
                            contentAlignment = Alignment.Center,
                        ) {
                            androidx.compose.material3.CircularProgressIndicator(
                                modifier = androidx.compose.ui.Modifier.size(20.dp),
                                strokeWidth = 2.dp,
                            )
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun HistoryRow(scan: ScanRecord) {
    val ticket = scan.ticketNumber
        ?: scan.ticketId.takeIf { it.isNotBlank() }
        ?: "—"
    val dot = if (scan.result.admits) CheckerColors.Admit else CheckerColors.Deny

    Card(modifier = Modifier.fillMaxWidth()) {
        Row(
            modifier = Modifier.padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Box(
                modifier = Modifier
                    .size(10.dp)
                    .background(dot, CircleShape),
            )

            Column(
                modifier = Modifier.weight(1f),
                verticalArrangement = Arrangement.spacedBy(2.dp),
            ) {
                Text(
                    text = resultLabel(scan.result),
                    style = MaterialTheme.typography.bodyLarge,
                    fontWeight = FontWeight.Medium,
                )
                Text(
                    text = sourceLabel(scan.source) + " · " + ticket,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }

            Column(
                horizontalAlignment = Alignment.End,
                verticalArrangement = Arrangement.spacedBy(2.dp),
            ) {
                Text(
                    text = TimeFormat.timeOfDay(scan.scannedAt),
                    style = MaterialTheme.typography.labelMedium,
                )
                Text(
                    text = stringResource(
                        if (scan.synced) R.string.history_synced else R.string.history_pending,
                    ),
                    style = MaterialTheme.typography.labelSmall,
                    color = if (scan.synced) {
                        MaterialTheme.colorScheme.onSurfaceVariant
                    } else {
                        CheckerColors.Warn
                    },
                )
            }
        }
    }
}

/** Kept for the diagnostic screen's colour legend. */
internal fun resultDotColor(result: CheckinResult): Color =
    if (result.admits) CheckerColors.Admit else CheckerColors.Deny
