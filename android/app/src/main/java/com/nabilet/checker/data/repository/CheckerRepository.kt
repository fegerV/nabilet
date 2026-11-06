package com.nabilet.checker.data.repository

import android.content.Context
import com.nabilet.checker.BuildConfig
import com.nabilet.checker.R
import com.nabilet.checker.data.local.BundleDao
import com.nabilet.checker.data.local.ScanDao
import com.nabilet.checker.data.prefs.AppPreferences
import com.nabilet.checker.data.remote.ApiResult
import com.nabilet.checker.data.remote.BundleMapper
import com.nabilet.checker.data.remote.NabiletApi
import com.nabilet.checker.data.remote.SyncResponse
import com.nabilet.checker.data.security.TokenVault
import com.nabilet.checker.domain.bundle.BundleParser
import com.nabilet.checker.domain.bundle.OfflineDecision
import com.nabilet.checker.domain.bundle.OfflineReason
import com.nabilet.checker.domain.bundle.OfflineUnavailable
import com.nabilet.checker.domain.bundle.OfflineVerifier
import com.nabilet.checker.domain.config.ConfigValidator
import com.nabilet.checker.domain.config.ValidationResult
import com.nabilet.checker.domain.model.ActiveBundle
import com.nabilet.checker.domain.model.BlockedReason
import com.nabilet.checker.domain.model.BundleParseError
import com.nabilet.checker.domain.model.BundleParseResult
import com.nabilet.checker.domain.model.CheckinResult
import com.nabilet.checker.domain.model.CheckerConfig
import com.nabilet.checker.domain.model.OpResult
import com.nabilet.checker.domain.model.QrParseResult
import com.nabilet.checker.domain.model.QrPayload
import com.nabilet.checker.domain.model.QrPayloadParser
import com.nabilet.checker.domain.model.ScanFeedback
import com.nabilet.checker.domain.model.ScanRecord
import com.nabilet.checker.domain.model.ScanSource
import com.nabilet.checker.domain.model.ScanSubmission
import com.nabilet.checker.domain.model.SessionStats
import com.nabilet.checker.domain.model.SyncReport
import com.nabilet.checker.sync.SyncScheduler
import java.util.UUID
import java.util.concurrent.atomic.AtomicLong
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.Semaphore
import kotlinx.coroutines.withContext
import kotlinx.coroutines.launch
import kotlinx.coroutines.Job
import kotlinx.coroutines.coroutineScope

/**
 * Single place where the scanner, the network, the offline bundle and the local
 * audit log meet.
 *
 * Invariants:
 *
 *  1. Exactly one scan is evaluated at a time (`scanMutex`). The camera can
 *     deliver the same QR twice in a hundred milliseconds; two concurrent
 *     admissions would both read "not used yet" and both admit.
 *  2. An online verdict is authoritative and never queued for sync.
 *  3. An offline verdict is stored immediately and queued; the server remains
 *     the final authority and may rewrite the local result during sync.
 *  4. A scan without a ticket id (unreadable QR) is recorded for statistics but
 *     never sent to the server — there is nothing to attach it to.
 *  5. Configuration changes that would strand the queue are refused.
 */
class CheckerRepository(
    private val context: Context,
    private val prefs: AppPreferences,
    private val vault: TokenVault,
    private val scanDao: ScanDao,
    private val bundleDao: BundleDao,
    private val api: NabiletApi,
    private val syncScheduler: SyncScheduler,
    private val clock: () -> Long = System::currentTimeMillis,
) {

    private val _state = MutableStateFlow(CheckerState())
    val state: StateFlow<CheckerState> = _state.asStateFlow()

    private val scanMutex = Mutex()
    private val syncMutex = Mutex()
    private val configMutex = Mutex()
    private val feedbackSequence = AtomicLong(0)

    suspend fun initialize() = withContext(Dispatchers.IO) {
        try {
            // A corrupted database must not leave the app on a splash screen
            // forever. Falling back to the stored config is the least bad
            // answer: the operator can still re-download the bundle.
            loadInitialState()
        } catch (_: Exception) {
            _state.value = CheckerState(
                initialized = true,
                config = prefs.readConfig(hasToken = vault.load() != null),
                tokenState = TokenState.UNKNOWN,
            )
        }
    }

    private fun loadInitialState() {
        val hasToken = vault.load() != null
        var config = prefs.readConfig(hasToken)

        val bundle = config.bundleId?.let { bundleDao.load(it) } ?: bundleDao.latest()
        if (bundle != null) {
            if (prefs.bundleId != bundle.id) prefs.bundleId = bundle.id
            if (prefs.devicePublicId != bundle.devicePublicId) prefs.devicePublicId = bundle.devicePublicId
            if (prefs.eventPublicId != bundle.eventPublicId) prefs.eventPublicId = bundle.eventPublicId
            config = prefs.readConfig(hasToken)
        } else if (config.bundleId != null) {
            // The bundle was removed because its hash no longer matches the
            // stored canonical bytes. Drop the pointer, keep the operator on
            // the setup screen instead of pretending offline works.
            prefs.bundleId = null
            prefs.devicePublicId = null
            prefs.eventPublicId = null
            config = prefs.readConfig(hasToken)
        }

        _state.value = CheckerState(
            initialized = true,
            config = config,
            bundle = bundle,
            recentScans = scanDao.recent(HISTORY_LIMIT),
            stats = scanDao.statsSince(statsSince(config), clock()),
            tokenState = TokenState.UNKNOWN,
        )
    }

    /**
     * Validates and stores the server origin and the device token. Changing the
     * origin wipes the identity: a bundle and a token belong to one server.
     */
    suspend fun configure(urlRaw: String, tokenRaw: String): OpResult<Unit> =
        withContext(Dispatchers.IO) {
            val url = when (val result = ConfigValidator.normalizeBaseUrl(urlRaw, BuildConfig.ALLOW_CLEARTEXT)) {
                is ValidationResult.Ok -> result.value
                is ValidationResult.Error -> return@withContext OpResult.Err(result.message)
            }
            val token = when (val result = ConfigValidator.validateToken(tokenRaw)) {
                is ValidationResult.Ok -> result.value
                is ValidationResult.Error -> return@withContext OpResult.Err(result.message)
            }

            configMutex.lock()
            try {
                val urlChanged = prefs.baseUrl.isNotBlank() && prefs.baseUrl != url
                if (urlChanged) {
                    if (scanDao.pendingCount() > 0) {
                        return@withContext OpResult.Err(context.getString(R.string.blocked_server_change_pending))
                    }
                    vault.clear()
                    bundleDao.deleteAll()
                    // History belongs to the server that accepted the scans.
                    // The queue is empty here (checked above), so nothing that
                    // still needs syncing is lost.
                    scanDao.deleteAll()
                    prefs.clearIdentity()
                }

                if (!vault.save(token)) {
                    return@withContext OpResult.Err(context.getString(R.string.token_vault_failed))
                }
                prefs.baseUrl = url

                val config = prefs.readConfig(hasToken = true)
                _state.update {
                    it.copy(
                        config = config,
                        bundle = if (urlChanged) null else it.bundle,
                        tokenState = TokenState.UNKNOWN,
                    )
                }
                OpResult.Ok(Unit)
            } finally {
                configMutex.unlock()
            }
        }

    /**
     * Changes only the origin. Used from Settings during a shift.
     *
     * A token belongs to one server, so the token is wiped together with the
     * bundles: keeping it would make the app answer "токен отклонён" from a
     * server that never issued it, and keeping the bundle would let offline
     * admission continue against an event the new server knows nothing about.
     */
    suspend fun changeServer(urlRaw: String): OpResult<Unit> = withContext(Dispatchers.IO) {
        val url = when (val result = ConfigValidator.normalizeBaseUrl(urlRaw, BuildConfig.ALLOW_CLEARTEXT)) {
            is ValidationResult.Ok -> result.value
            is ValidationResult.Error -> return@withContext OpResult.Err(result.message)
        }

        configMutex.lock()
        try {
            if (prefs.baseUrl == url) return@withContext OpResult.Ok(Unit)
            if (scanDao.pendingCount() > 0) {
                return@withContext OpResult.Err(context.getString(R.string.blocked_server_change_pending))
            }

            vault.clear()
            bundleDao.deleteAll()
            scanDao.deleteAll()
            prefs.clearIdentity()
            prefs.baseUrl = url

            _state.update {
                it.copy(
                    config = prefs.readConfig(hasToken = false),
                    bundle = null,
                    tokenState = TokenState.UNKNOWN,
                )
            }
            OpResult.Ok(Unit)
        } finally {
            configMutex.unlock()
        }
    }

    suspend fun updateToken(tokenRaw: String): OpResult<Unit> = withContext(Dispatchers.IO) {
        val token = when (val result = ConfigValidator.validateToken(tokenRaw)) {
            is ValidationResult.Ok -> result.value
            is ValidationResult.Error -> return@withContext OpResult.Err(result.message)
        }

        configMutex.lock()
        try {
            if (!vault.save(token)) {
                return@withContext OpResult.Err(context.getString(R.string.token_vault_failed))
            }
            _state.update {
                it.copy(
                    config = prefs.readConfig(hasToken = true),
                    tokenState = TokenState.UNKNOWN,
                )
            }
            OpResult.Ok(Unit)
        } finally {
            configMutex.unlock()
        }
    }

    suspend fun verifyToken(): OpResult<Unit> = withContext(Dispatchers.IO) {
        val token = vault.load()
            ?: return@withContext OpResult.Err(context.getString(R.string.token_missing))
        val baseUrl = prefs.baseUrl
        if (baseUrl.isBlank()) {
            return@withContext OpResult.Err(context.getString(R.string.server_missing))
        }

        val previous = _state.value.tokenState
        _state.update { it.copy(tokenState = TokenState.CHECKING) }

        when (val result = api.validateCredentials(baseUrl, token)) {
            is ApiResult.Success -> {
                _state.update { it.copy(tokenState = TokenState.VALID) }
                OpResult.Ok(Unit)
            }

            is ApiResult.HttpError -> {
                if (result.status == 401 || result.status == 403) {
                    _state.update { it.copy(tokenState = TokenState.INVALID) }
                    OpResult.Err(context.getString(R.string.blocked_token))
                } else {
                    _state.update { it.copy(tokenState = keepValidOrUnknown(previous)) }
                    OpResult.Err(context.getString(R.string.verify_http, result.status))
                }
            }

            is ApiResult.NetworkError -> {
                _state.update { it.copy(tokenState = keepValidOrUnknown(previous)) }
                OpResult.Err(context.getString(R.string.verify_network))
            }

            is ApiResult.ParseError -> {
                _state.update { it.copy(tokenState = keepValidOrUnknown(previous)) }
                OpResult.Err(result.message)
            }
        }
    }

    suspend fun downloadBundle(rawId: String): OpResult<ActiveBundle> = withContext(Dispatchers.IO) {
        val id = when (val result = ConfigValidator.validateBundleReference(rawId)) {
            is ValidationResult.Ok -> result.value
            is ValidationResult.Error -> return@withContext OpResult.Err(result.message)
        }
        val token = vault.load()
            ?: return@withContext OpResult.Err(context.getString(R.string.token_missing))
        val baseUrl = prefs.baseUrl
        if (baseUrl.isBlank()) {
            return@withContext OpResult.Err(context.getString(R.string.server_missing))
        }

        val data = when (val result = api.fetchBundle(baseUrl, token, id)) {
            is ApiResult.Success -> result.value
            is ApiResult.HttpError -> {
                if (result.status == 401 || result.status == 403) {
                    _state.update { it.copy(tokenState = TokenState.INVALID) }
                    return@withContext OpResult.Err(context.getString(R.string.blocked_token))
                }
                if (result.status == 404) {
                    return@withContext OpResult.Err(context.getString(R.string.bundle_not_found))
                }
                return@withContext OpResult.Err(context.getString(R.string.verify_http, result.status))
            }

            is ApiResult.NetworkError -> return@withContext OpResult.Err(result.message)
            is ApiResult.ParseError -> return@withContext OpResult.Err(result.message)
        }

        val serverBundle = BundleMapper.toServerBundle(data)
            ?: return@withContext OpResult.Err(context.getString(R.string.bundle_parse_failed))

        val parsed = when (val result = BundleParser.parse(serverBundle, clock())) {
            is BundleParseResult.Valid -> result.parsed
            is BundleParseResult.Invalid -> return@withContext OpResult.Err(
                context.getString(R.string.bundle_rejected, bundleReason(result.error)),
            )
        }

        configMutex.lock()
        try {
            val pending = scanDao.pendingCount()
            val currentDevice = prefs.devicePublicId
            if (pending > 0 && !currentDevice.isNullOrBlank() &&
                currentDevice != parsed.bundle.devicePublicId
            ) {
                return@withContext OpResult.Err(context.getString(R.string.blocked_device_change_pending))
            }

            bundleDao.save(parsed)
            prefs.bundleId = parsed.bundle.id
            prefs.devicePublicId = parsed.bundle.devicePublicId
            prefs.eventPublicId = parsed.bundle.eventPublicId
            parsed.bundle.sessionReference?.let { prefs.sessionReference = it }

            val config = prefs.readConfig(hasToken = true)
            _state.update {
                it.copy(
                    config = config,
                    bundle = parsed.bundle,
                    // A successful authenticated download proves the token.
                    tokenState = TokenState.VALID,
                )
            }
            OpResult.Ok(parsed.bundle)
        } finally {
            configMutex.unlock()
        }
    }

    suspend fun setSessionReference(raw: String): OpResult<Unit> = withContext(Dispatchers.IO) {
        val value = when (val result = ConfigValidator.validateSessionReference(raw)) {
            is ValidationResult.Ok -> result.value
            is ValidationResult.Error -> return@withContext OpResult.Err(result.message)
        }

        val bundle = _state.value.bundle
        if (bundle?.sessionInternalId != null && bundle.sessionReference != value) {
            return@withContext OpResult.Err(context.getString(R.string.blocked_session_locked_by_bundle))
        }

        configMutex.lock()
        try {
            prefs.sessionReference = value
            val config = prefs.readConfig(hasToken = _state.value.config.hasToken)
            _state.update { it.copy(config = config) }
            OpResult.Ok(Unit)
        } finally {
            configMutex.unlock()
        }
    }

    suspend fun beginShift(): OpResult<Unit> = withContext(Dispatchers.IO) {
        configMutex.lock()
        try {
            val hasToken = _state.value.config.hasToken
            var config = prefs.readConfig(hasToken)

            if (config.baseUrl.isBlank()) {
                return@withContext OpResult.Err(context.getString(R.string.server_missing))
            }
            if (!hasToken) {
                return@withContext OpResult.Err(context.getString(R.string.token_missing))
            }

            val session = config.sessionReference ?: _state.value.bundle?.sessionReference
            if (session.isNullOrBlank()) {
                return@withContext OpResult.Err(context.getString(R.string.session_required))
            }
            if (config.sessionReference != session) {
                prefs.sessionReference = session
                config = prefs.readConfig(hasToken)
            }

            // Without a bundle the first shift has to be online at least once:
            // otherwise the device has no proven identity and no ticket data.
            if (_state.value.tokenState != TokenState.VALID && _state.value.bundle == null) {
                return@withContext OpResult.Err(context.getString(R.string.shift_verify_required))
            }

            if (!prefs.setupComplete || prefs.shiftStartedAt == 0L) {
                prefs.shiftStartedAt = clock()
            }
            prefs.setupComplete = true

            val updated = prefs.readConfig(hasToken)
            _state.update {
                it.copy(
                    config = updated,
                    stats = scanDao.statsSince(statsSince(updated), clock()),
                )
            }
            syncScheduler.enqueue()
            OpResult.Ok(Unit)
        } finally {
            configMutex.unlock()
        }
    }

    suspend fun startNewShift() = withContext(Dispatchers.IO) {
        prefs.shiftStartedAt = clock()
        val config = prefs.readConfig(_state.value.config.hasToken)
        _state.update {
            it.copy(
                config = config,
                stats = scanDao.statsSince(statsSince(config), clock()),
            )
        }
    }

    suspend fun endShift() = withContext(Dispatchers.IO) {
        prefs.setupComplete = false
        val config = prefs.readConfig(_state.value.config.hasToken)
        _state.update { it.copy(config = config) }
    }

    suspend fun removeBundle(): OpResult<Unit> = withContext(Dispatchers.IO) {
        configMutex.lock()
        try {
            if (scanDao.pendingCount() > 0) {
                return@withContext OpResult.Err(context.getString(R.string.blocked_bundle_remove_pending))
            }
            val bundle = _state.value.bundle ?: return@withContext OpResult.Ok(Unit)
            bundleDao.delete(bundle.id)
            prefs.bundleId = null
            prefs.devicePublicId = null
            prefs.eventPublicId = null
            val config = prefs.readConfig(_state.value.config.hasToken)
            _state.update { it.copy(config = config, bundle = null) }
            OpResult.Ok(Unit)
        } finally {
            configMutex.unlock()
        }
    }

    suspend fun clearAll() = withContext(Dispatchers.IO) {
        configMutex.lock()
        try {
            vault.clear()
            prefs.clearAll()
            scanDao.deleteAll()
            bundleDao.deleteAll()
            syncScheduler.cancelAll()
            _state.value = CheckerState(initialized = true, tokenState = TokenState.UNKNOWN)
        } finally {
            configMutex.unlock()
        }
    }

    suspend fun submitScan(rawQr: String, online: Boolean): ScanSubmission =
        withContext(Dispatchers.IO) {
            if (!scanMutex.tryLock()) {
                return@withContext ScanSubmission.Blocked(
                    BlockedReason.BUSY,
                    context.getString(R.string.blocked_busy),
                )
            }
            try {
                handleScan(rawQr, online)
            } catch (_: Exception) {
                ScanSubmission.Blocked(
                    BlockedReason.INTERNAL,
                    context.getString(R.string.blocked_internal),
                )
            } finally {
                scanMutex.unlock()
            }
        }

    /**
     * Sends queued offline scans. Groups by session because the API takes one
     * session per request, and by nothing else: the device id is checked
     * against the authenticated token by the server.
     */
    suspend fun syncNow(): SyncOutcome = withContext(Dispatchers.IO) {
        if (!syncMutex.tryLock()) {
            return@withContext SyncOutcome(
                SyncOutcomeKind.DONE,
                context.getString(R.string.sync_already_running),
            )
        }

        var report = SyncReport()
        try {
            _state.update { it.copy(syncing = true) }

            val token = vault.load()
                ?: return@withContext SyncOutcome(
                    SyncOutcomeKind.FAILED,
                    context.getString(R.string.token_missing),
                )
            val config = prefs.readConfig(hasToken = true)
            if (config.baseUrl.isBlank()) {
                return@withContext SyncOutcome(
                    SyncOutcomeKind.FAILED,
                    context.getString(R.string.server_missing),
                )
            }

            val pending = scanDao.pending(SYNC_FETCH_LIMIT)
            if (pending.isEmpty()) {
                prefs.lastSyncAt = clock()
                _state.update { it.copy(config = prefs.readConfig(hasToken = true)) }
                return@withContext SyncOutcome(
                    SyncOutcomeKind.DONE,
                    context.getString(R.string.sync_nothing),
                )
            }

            val device = config.devicePublicId
            if (device.isNullOrBlank()) {
                return@withContext SyncOutcome(
                    SyncOutcomeKind.FAILED,
                    context.getString(R.string.sync_error_bundle),
                )
            }
            if (pending.any { it.deviceId != null && it.deviceId != device }) {
                return@withContext SyncOutcome(
                    SyncOutcomeKind.FAILED,
                    context.getString(R.string.sync_device_mismatch),
                )
            }

            val groups = pending
                .filter { it.sessionId != null }
                .groupBy { it.sessionId!! }

            // Собираем все батчи для параллельной отправки (до 3 одновременно).
            // HTTP-запросы идут параллельно, применение результатов к БД —
            // sequenceльно, чтобы не было гонок данных.
            val batchResults = mutableListOf<Pair<List<ScanRecord>, ApiResult<SyncResponse>>>()
            val semaphore = kotlinx.coroutines.sync.Semaphore(3)

            coroutineScope {
                val jobs = mutableListOf<Job>()
                for ((session, scans) in groups) {
                    for (batch in scans.chunked(SYNC_BATCH)) {
                        semaphore.acquire()
                        jobs.add(launch {
                            try {
                                val result = api.sync(
                                    config.baseUrl,
                                    token,
                                    device,
                                    session,
                                    batch,
                                )
                                batchResults.add(Pair(batch, result))
                            } catch (e: Exception) {
                                batchResults.add(
                                    Pair(
                                        batch,
                                        ApiResult.ParseError(e.message ?: "Unknown network error"),
                                    ),
                                )
                            } finally {
                                semaphore.release()
                            }
                        })
                    }
                }
                jobs.forEach { it.join() }
            }

            // Применяем результаты sequenceльно к БД и подсчитываем статистику.
            for ((batch, result) in batchResults) {
                when (result) {
                    is ApiResult.Success -> {
                        val response = result.value
                        scanDao.applySyncOutcome(response.items)
                        report = report.copy(
                            sent = report.sent + batch.size,
                            accepted = report.accepted + response.accepted,
                            duplicates = report.duplicates + response.duplicates,
                            conflicts = report.conflicts + response.conflicts,
                            rejected = report.rejected + response.rejected,
                        )
                    }

                    is ApiResult.HttpError -> {
                        val outcome = when {
                            result.status == 401 || result.status == 403 -> {
                                _state.update { it.copy(tokenState = TokenState.INVALID) }
                                SyncOutcome(
                                    SyncOutcomeKind.AUTH,
                                    context.getString(R.string.sync_auth_error),
                                    report.sent,
                                )
                            }

                            result.status >= 500 -> SyncOutcome(
                                SyncOutcomeKind.RETRY,
                                context.getString(R.string.sync_network_error),
                                report.sent,
                            )

                            else -> SyncOutcome(
                                SyncOutcomeKind.FAILED,
                                context.getString(R.string.sync_failed, result.message),
                                report.sent,
                            )
                        }
                        return@withContext outcome
                    }

                    is ApiResult.NetworkError -> return@withContext SyncOutcome(
                        SyncOutcomeKind.RETRY,
                        context.getString(R.string.sync_network_error),
                        report.sent,
                    )

                    is ApiResult.ParseError -> return@withContext SyncOutcome(
                        SyncOutcomeKind.FAILED,
                        context.getString(R.string.sync_failed, result.message),
                        report.sent,
                    )
                }
            }

            prefs.lastSyncAt = clock()
            val updated = prefs.readConfig(hasToken = true)
            _state.update {
                it.copy(
                    config = updated,
                    stats = scanDao.statsSince(statsSince(updated), clock()),
                    recentScans = scanDao.recent(HISTORY_LIMIT),
                )
            }
            SyncOutcome(
                SyncOutcomeKind.DONE,
                context.getString(R.string.sync_success, report.sent),
                report.sent,
            )
        } finally {
            _state.update { it.copy(syncing = false) }
            syncMutex.unlock()
        }
    }

    suspend fun pendingCount(): Int = withContext(Dispatchers.IO) { scanDao.pendingCount() }

    suspend fun totalScanCount(): Int = withContext(Dispatchers.IO) { scanDao.totalCount() }

    /**
     * Returns recent scans with pagination support.
     *
     * @param limit maximum number of rows per page
     * @param offset number of rows to skip
     */
    suspend fun recentScans(limit: Int, offset: Int): List<ScanRecord> =
        withContext(Dispatchers.IO) { scanDao.recent(limit, offset) }

    /**
     * Deletes already-synced scans older than the given age.
     *
     * @param olderThanMillis scans older than this (and already synced) are deleted
     * @return number of rows deleted
     */
    suspend fun cleanupOldSyncedScans(olderThanMillis: Long): Int =
        withContext(Dispatchers.IO) { scanDao.deleteOldSyncedScans(olderThanMillis) }

    /**
     * Экспортирует сканы за период в CSV-формат.
     *
     * @param sinceMillis начало периода в миллисекундах (включительно), 0 = все сканы
     * @return CSV-строка с заголовком и строками сканов, готовая к записи в файл
     */
    suspend fun exportScansCsv(sinceMillis: Long = 0L): String =
        withContext(Dispatchers.IO) {
            val scans = scanDao.scansSince(sinceMillis)
            val sb = StringBuilder()
            sb.append(
                "client_scan_id,ticket_id,ticket_number,result,source,session_id," +
                    "device_id,scanned_at,synced,server_message,note\n"
            )
            for (scan in scans) {
                sb.append("${scan.clientScanId},")
                sb.append("${scan.ticketId},")
                sb.append("\"${scan.ticketNumber ?: ""}\",")
                sb.append("${scan.result.wire},")
                sb.append("${scan.source.wire},")
                sb.append("\"${scan.sessionId ?: ""}\",")
                sb.append("\"${scan.deviceId ?: ""}\",")
                sb.append("${scan.scannedAt},")
                sb.append("${if (scan.synced) 1 else 0},")
                sb.append("\"${escapeCsv(scan.serverMessage)}\",")
                sb.append("\"${escapeCsv(scan.note)}\"\n")
            }
            sb.toString()
        }

    private fun escapeCsv(value: String?): String {
        val v = value ?: ""
        return v.replace("\"", "\"\"")
    }

    private suspend fun handleScan(rawQr: String, online: Boolean): ScanSubmission {
        val state = _state.value
        val config = state.config

        if (config.baseUrl.isBlank() || !config.hasToken) {
            return ScanSubmission.Blocked(
                BlockedReason.NOT_CONFIGURED,
                context.getString(R.string.blocked_config),
            )
        }

        val now = clock()

        when (val parsed = QrPayloadParser.parse(rawQr)) {
            is QrParseResult.Invalid -> {
                val record = ScanRecord(
                    clientScanId = UUID.randomUUID().toString(),
                    ticketId = "",
                    ticketNumber = null,
                    result = CheckinResult.INVALID_SIGNATURE,
                    source = ScanSource.LOCAL,
                    sessionId = config.sessionReference,
                    deviceId = config.devicePublicId,
                    scannedAt = now,
                    synced = true,
                    serverMessage = null,
                    note = context.getString(R.string.note_invalid_qr),
                )
                persist(record)
                return ScanSubmission.Feedback(feedbackOf(record, queued = false))
            }

            is QrParseResult.Valid -> {
                val payload = parsed.payload
                val session = config.sessionReference
                    ?: return ScanSubmission.Blocked(
                        BlockedReason.NO_SESSION,
                        context.getString(R.string.blocked_no_session),
                    )

                if (!online) {
                    return offlineFallback(
                        payload = payload,
                        session = session,
                        clientScanId = UUID.randomUUID().toString(),
                        now = now,
                        noteOverride = null,
                    )
                }

                val token = vault.load()
                    ?: return ScanSubmission.Blocked(
                        BlockedReason.TOKEN_REJECTED,
                        context.getString(R.string.blocked_token),
                    )
                val clientScanId = UUID.randomUUID().toString()

                when (val result = api.use(
                    baseUrl = config.baseUrl,
                    token = token,
                    qrPayload = payload.raw,
                    sessionId = session,
                    clientScanId = clientScanId,
                    scannedAtMillis = now,
                )) {
                    is ApiResult.Success -> {
                        val response = result.value
                        val record = ScanRecord(
                            clientScanId = clientScanId,
                            ticketId = payload.ticketId,
                            ticketNumber = response.ticketNumber,
                            result = response.result,
                            source = ScanSource.ONLINE,
                            sessionId = session,
                            deviceId = config.devicePublicId,
                            scannedAt = now,
                            synced = true,
                            serverMessage = response.message,
                            note = context.getString(R.string.note_online),
                        )
                        persist(record)
                        return ScanSubmission.Feedback(feedbackOf(record, queued = false))
                    }

                    is ApiResult.HttpError -> {
                        if (result.status == 401 || result.status == 403) {
                            _state.update { it.copy(tokenState = TokenState.INVALID) }
                            return ScanSubmission.Blocked(
                                BlockedReason.TOKEN_REJECTED,
                                context.getString(R.string.blocked_token),
                            )
                        }
                        if (result.status == 429 || result.status >= 500) {
                            return offlineFallback(
                                payload = payload,
                                session = session,
                                clientScanId = clientScanId,
                                now = now,
                                noteOverride = context.getString(R.string.note_offline_fallback),
                            )
                        }
                        return ScanSubmission.Blocked(BlockedReason.INTERNAL, result.message)
                    }

                    is ApiResult.NetworkError -> return offlineFallback(
                        payload = payload,
                        session = session,
                        clientScanId = clientScanId,
                        now = now,
                        noteOverride = context.getString(R.string.note_offline_fallback),
                    )

                    is ApiResult.ParseError -> return ScanSubmission.Blocked(
                        BlockedReason.INTERNAL,
                        result.message,
                    )
                }
            }
        }
    }

    private suspend fun offlineFallback(
        payload: QrPayload,
        session: String,
        clientScanId: String,
        now: Long,
        noteOverride: String?,
    ): ScanSubmission {
        val state = _state.value
        val bundle = state.bundle
            ?: return ScanSubmission.Blocked(
                BlockedReason.NO_BUNDLE,
                context.getString(R.string.blocked_no_bundle),
            )

        val bundleSession = bundle.sessionReference
            ?: return ScanSubmission.Blocked(
                BlockedReason.NO_SESSION,
                context.getString(R.string.blocked_bundle_event_wide),
            )

        if (session != bundleSession) {
            return ScanSubmission.Blocked(
                BlockedReason.NO_SESSION,
                context.getString(R.string.blocked_session_locked_by_bundle),
            )
        }

        if (bundle.isExpired(now)) {
            return ScanSubmission.Blocked(
                BlockedReason.BUNDLE_EXPIRED,
                context.getString(R.string.blocked_bundle_expired),
            )
        }

        val locallyAdmitted = scanDao.hasAdmitted(payload.ticketId, statsSince(state.config))
        val decision = OfflineVerifier.decide(
            bundle = bundle,
            ticketId = payload.ticketId,
            nowMillis = now,
            locallyAdmitted = locallyAdmitted,
            lookup = { ticketId -> bundleDao.findTicket(bundle.id, ticketId) },
        )

        return when (decision) {
            is OfflineDecision.Unavailable -> when (decision.reason) {
                OfflineUnavailable.EXPIRED_BUNDLE -> ScanSubmission.Blocked(
                    BlockedReason.BUNDLE_EXPIRED,
                    context.getString(R.string.blocked_bundle_expired),
                )

                OfflineUnavailable.NO_SESSION -> ScanSubmission.Blocked(
                    BlockedReason.NO_SESSION,
                    context.getString(R.string.blocked_bundle_event_wide),
                )
            }

            is OfflineDecision.Verdict -> {
                val queued = decision.result != CheckinResult.UNKNOWN_TICKET
                val note = noteOverride ?: when (decision.reason) {
                    OfflineReason.VALID_FROM_BUNDLE -> context.getString(R.string.note_offline_valid)
                    OfflineReason.ALREADY_ADMITTED_LOCALLY -> context.getString(R.string.note_local_duplicate)
                    OfflineReason.REVOKED_STATUS -> context.getString(R.string.note_offline_bundle_used)
                    OfflineReason.UNKNOWN_TICKET -> context.getString(R.string.note_offline_bundle_unknown)
                }

                val record = ScanRecord(
                    clientScanId = clientScanId,
                    ticketId = payload.ticketId,
                    ticketNumber = decision.ticketNumber,
                    result = decision.result,
                    source = ScanSource.OFFLINE,
                    sessionId = bundleSession,
                    deviceId = bundle.devicePublicId,
                    scannedAt = now,
                    synced = !queued,
                    serverMessage = null,
                    note = note,
                )
                persist(record)
                if (queued) syncScheduler.enqueue()
                ScanSubmission.Feedback(feedbackOf(record, queued))
            }
        }
    }

    private suspend fun persist(record: ScanRecord) {
        scanDao.insert(record)
        val config = _state.value.config
        _state.update {
            it.copy(
                stats = scanDao.statsSince(statsSince(config), clock()),
                recentScans = scanDao.recent(HISTORY_LIMIT),
            )
        }
    }

    private fun feedbackOf(record: ScanRecord, queued: Boolean): ScanFeedback = ScanFeedback(
        id = feedbackSequence.incrementAndGet(),
        result = record.result,
        ticketId = record.ticketId.takeIf { it.isNotBlank() },
        ticketNumber = record.ticketNumber,
        serverMessage = record.serverMessage,
        note = record.note,
        source = record.source,
        scannedAt = record.scannedAt,
        queued = queued,
    )

    private fun statsSince(config: CheckerConfig): Long =
        if (config.shiftStartedAt > 0L) config.shiftStartedAt else 0L

    private fun keepValidOrUnknown(previous: TokenState): TokenState =
        if (previous == TokenState.VALID) TokenState.VALID else TokenState.UNKNOWN

    private fun bundleReason(error: BundleParseError): String = context.getString(
        when (error) {
            BundleParseError.INVALID_JSON -> R.string.bundle_reason_json
            BundleParseError.TOO_LARGE -> R.string.bundle_reason_size
            BundleParseError.UNSUPPORTED_SCHEMA -> R.string.bundle_reason_schema
            BundleParseError.MISSING_FIELD -> R.string.bundle_reason_missing
            BundleParseError.COUNT_MISMATCH -> R.string.bundle_reason_count
            BundleParseError.KEY_MISMATCH -> R.string.bundle_reason_key
            BundleParseError.DUPLICATE_TICKET -> R.string.bundle_reason_duplicate
            BundleParseError.HASH_MISMATCH -> R.string.bundle_reason_hash
            BundleParseError.BAD_TIMESTAMP -> R.string.bundle_reason_timestamp
        },
    )

    private companion object {
        const val HISTORY_LIMIT = 200
        const val SYNC_BATCH = 200
        const val SYNC_FETCH_LIMIT = 5000
    }
}
