package com.nabilet.checker.di

import android.content.Context
import com.nabilet.checker.data.local.BundleDao
import com.nabilet.checker.data.local.CheckerDatabase
import com.nabilet.checker.data.local.ScanDao
import com.nabilet.checker.data.network.NetworkMonitor
import com.nabilet.checker.data.prefs.AppPreferences
import com.nabilet.checker.data.remote.ApiClientFactory
import com.nabilet.checker.data.remote.NabiletApi
import com.nabilet.checker.data.repository.CheckerRepository
import com.nabilet.checker.data.security.TokenVault
import com.nabilet.checker.sync.SyncScheduler
import com.nabilet.checker.ui.feedback.FeedbackPlayer

/**
 * One instance of everything, created once in [com.nabilet.checker.CheckerApp].
 *
 * Why singletons rather than per-screen construction: the database connection,
 * the OkHttp connection pool and the sync scheduler all have to be shared.
 * Two `SyncScheduler` instances would enqueue the same unique work name, and
 * two repositories would hold two independent state flows.
 */
class AppContainer(context: Context) {

    private val appContext: Context = context.applicationContext

    val prefs: AppPreferences = AppPreferences(appContext)
    val tokenVault: TokenVault = TokenVault(appContext)

    private val database: CheckerDatabase = CheckerDatabase(appContext)
    val scanDao: ScanDao = ScanDao(database)
    val bundleDao: BundleDao = BundleDao(database)

    val networkMonitor: NetworkMonitor = NetworkMonitor(appContext)
    val api: NabiletApi = NabiletApi(ApiClientFactory.create())
    val syncScheduler: SyncScheduler = SyncScheduler(appContext)
    val feedbackPlayer: FeedbackPlayer = FeedbackPlayer(appContext)

    val repository: CheckerRepository = CheckerRepository(
        context = appContext,
        prefs = prefs,
        vault = tokenVault,
        scanDao = scanDao,
        bundleDao = bundleDao,
        api = api,
        syncScheduler = syncScheduler,
    )
}
