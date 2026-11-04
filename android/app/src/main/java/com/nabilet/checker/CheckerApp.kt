package com.nabilet.checker

import android.app.Application
import com.nabilet.checker.di.AppContainer

/**
 * Process-wide object graph. Manual DI on purpose: the graph is nine objects
 * and two WorkManager entry points, so a Hilt/KSP setup would add a compiler
 * plugin and a second thing that can fail in CI for no benefit.
 */
class CheckerApp : Application() {

    lateinit var container: AppContainer
        private set

    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)
        // Safety net for scans queued while the app was killed. The immediate
        // enqueue after each scan is the fast path; this one survives reboots
        // and long offline stretches.
        container.syncScheduler.schedulePeriodic()
    }
}
