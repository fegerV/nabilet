package com.nabilet.checker.ui

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import com.nabilet.checker.CheckerApp
import com.nabilet.checker.R
import com.nabilet.checker.data.network.NetworkMonitor
import com.nabilet.checker.data.repository.CheckerRepository
import com.nabilet.checker.data.repository.CheckerState
import com.nabilet.checker.domain.model.CheckinResult
import com.nabilet.checker.domain.model.OpResult
import com.nabilet.checker.domain.model.ScanFeedback
import com.nabilet.checker.domain.model.ScanRecord
import com.nabilet.checker.domain.model.ScanSubmission
import kotlin.collections.distinctBy
import kotlinx.coroutines.channels.BufferOverflow
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.flow.update

/**
 * The only place the UI talks to the repository.
 *
 * Two rules hold the screen together:
 *
 *  - one long operation at a time ([busy]); a scan and a bundle download must
 *    not run concurrently, because both mutate the same session reference and
 *    the same queue;
 *  - every failure surfaces as a message, never as a silent no-op, because a
 *    doorman who sees nothing happen will scan the ticket again.
 */
class CheckerViewModel(
    private val app: Application,
    private val repository: CheckerRepository,
    private val monitor: NetworkMonitor,
) : ViewModel() {

    val state: StateFlow<CheckerState> = repository.state
    val online: StateFlow<Boolean> = monitor.online

    private val _feedback = MutableStateFlow<ScanFeedback?>(null)
    val feedback: StateFlow<ScanFeedback?> = _feedback.asStateFlow()

    private val _busy = MutableStateFlow(false)
    val busy: StateFlow<Boolean> = _busy.asStateFlow()

    private val _messages = MutableSharedFlow<String>(
        extraBufferCapacity = 16,
        onBufferOverflow = BufferOverflow.DROP_OLDEST,
    )
    val messages: SharedFlow<String> = _messages.asSharedFlow()

    private var initialized = false

    fun initialize() {
        if (initialized) return
        initialized = true
        viewModelScope.launch { repository.initialize() }
    }

    fun clearFeedback() {
        _feedback.value = null
    }

    fun submitScan(rawQr: String, online: Boolean) {
        if (_busy.value) return
        viewModelScope.launch {
            _busy.value = true
            try {
                when (val submission = repository.submitScan(rawQr, online)) {
                    is ScanSubmission.Feedback -> _feedback.value = submission.feedback
                    is ScanSubmission.Blocked -> emit(submission.message)
                }
            } finally {
                _busy.value = false
            }
        }
    }

    fun saveAndVerify(url: String, token: String) = runOperation {
        when (val configured = repository.configure(url, token)) {
            is OpResult.Ok -> when (val verified = repository.verifyToken()) {
                is OpResult.Ok -> emit(app.getString(R.string.verify_ok))
                is OpResult.Err -> emit(verified.message)
            }

            is OpResult.Err -> emit(configured.message)
        }
    }

    fun downloadBundle(url: String, token: String, bundleId: String) = runOperation {
        when (val configured = repository.configure(url, token)) {
            is OpResult.Ok -> when (val loaded = repository.downloadBundle(bundleId)) {
                is OpResult.Ok -> emit(
                    app.getString(R.string.bundle_loaded_toast, loaded.value.ticketCount),
                )

                is OpResult.Err -> emit(loaded.message)
            }

            is OpResult.Err -> emit(configured.message)
        }
    }

    fun setSession(reference: String) = runOperation {
        report(repository.setSessionReference(reference), app.getString(R.string.settings_session_updated))
    }

    /** Re-downloads a bundle with the token already stored on the device. */
    fun reloadBundle(bundleId: String) = runOperation {
        when (val loaded = repository.downloadBundle(bundleId)) {
            is OpResult.Ok -> emit(
                app.getString(R.string.bundle_loaded_toast, loaded.value.ticketCount),
            )

            is OpResult.Err -> emit(loaded.message)
        }
    }

    fun beginShift() = runOperation {
        when (val result = repository.beginShift()) {
            is OpResult.Ok -> Unit
            is OpResult.Err -> emit(result.message)
        }
    }

    fun startNewShift() = runOperation {
        repository.startNewShift()
        emit(app.getString(R.string.settings_shift_started))
    }

    fun endShift() = runOperation { repository.endShift() }

    fun removeBundle() = runOperation {
        report(repository.removeBundle(), app.getString(R.string.settings_bundle_removed))
    }

    fun clearAll() = runOperation {
        repository.clearAll()
        emit(app.getString(R.string.settings_cleared))
    }

    fun changeServer(url: String) = runOperation {
        when (val result = repository.changeServer(url)) {
            is OpResult.Ok -> emit(app.getString(R.string.settings_url_changed))
            is OpResult.Err -> emit(result.message)
        }
    }

    fun updateToken(token: String) = runOperation {
        report(repository.updateToken(token), app.getString(R.string.settings_token_updated))
    }

    fun verifyToken() = runOperation {
        report(repository.verifyToken(), app.getString(R.string.verify_ok))
    }

    fun syncNow() {
        viewModelScope.launch {
            repository.syncNow().message?.let { emit(it) }
        }
    }

    private fun runOperation(block: suspend () -> Unit) {
        if (_busy.value) return
        viewModelScope.launch {
            _busy.value = true
            try {
                block()
            } finally {
                _busy.value = false
            }
        }
    }

    private suspend fun report(result: OpResult<Unit>, success: String?) {
        when (result) {
            is OpResult.Ok -> success?.let { emit(it) }
            is OpResult.Err -> emit(result.message)
        }
    }

    private suspend fun emit(message: String) {
        _messages.emit(message)
    }

    /** Exposed for tests and for the settings screen's diagnostics block. */
    fun latestResult(): CheckinResult? = _feedback.value?.result

    fun emitMessage(message: String) {
        viewModelScope.launch {
            _messages.emit(message)
        }
    }

    suspend fun totalScanCount(): Int = repository.totalScanCount()

    suspend fun recentScans(limit: Int, offset: Int): List<ScanRecord> =
        repository.recentScans(limit, offset)

    suspend fun cleanupOldSyncedScans(olderThanMillis: Long): Int =
        repository.cleanupOldSyncedScans(olderThanMillis)

    /** Scans loaded for paginated history view. Starts empty, grows on scroll. */
    var pagedScans by mutableStateOf<List<ScanRecord>>(emptyList())

    /** Search results for ticket search in history. */
    var searchResults by mutableStateOf<List<ScanRecord>>(emptyList())

    /**
     * Loads the next page of scans and merges them into [pagedScans].
     * Duplicates are removed by client_scan_id.
     */
    suspend fun loadMoreScans(offset: Int) {
        val newScans = repository.recentScans(PAGE_SIZE, offset)
        pagedScans = (pagedScans + newScans)
            .distinctBy { it.clientScanId }
            .sortedByDescending { it.scannedAt }
    }

    /**
     * Экспортирует все сканы в CSV-строку для отправки администратору.
     */
    suspend fun exportScansCsv(): String = repository.exportScansCsv()

    /**
     * Ищет сканы по номеру билета или ID билета.
     */
    suspend fun searchByTicket(query: String): List<ScanRecord> =
        repository.searchByTicket(query)

    companion object {
        /** Number of scans loaded per page in history pagination. */
        const val PAGE_SIZE = 50

        fun factory(app: CheckerApp): ViewModelProvider.Factory = viewModelFactory {
            initializer {
                CheckerViewModel(
                    app = app,
                    repository = app.container.repository,
                    monitor = app.container.networkMonitor,
                )
            }
        }
    }
}
