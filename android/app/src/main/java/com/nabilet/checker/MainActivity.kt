package com.nabilet.checker

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.runtime.LaunchedEffect
import androidx.lifecycle.viewmodel.compose.viewModel
import com.nabilet.checker.ui.CheckerRoot
import com.nabilet.checker.ui.CheckerViewModel
import com.nabilet.checker.ui.theme.NabiletTheme

class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val app = application as CheckerApp

        setContent {
            NabiletTheme {
                val viewModel: CheckerViewModel = viewModel(
                    factory = CheckerViewModel.factory(app),
                )
                LaunchedEffect(Unit) { viewModel.initialize() }
                CheckerRoot(viewModel, app.container.feedbackPlayer)
            }
        }
    }
}
