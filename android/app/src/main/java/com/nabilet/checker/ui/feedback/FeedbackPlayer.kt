package com.nabilet.checker.ui.feedback

import android.content.Context
import android.media.AudioManager
import android.media.ToneGenerator
import android.os.Build
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager

/**
 * Sound and vibration for a verdict.
 *
 * Both channels, because either one can be unavailable where a checker works:
 * a noisy hall swallows the beep, and gloves or a loud PA hide the buzz. The
 * phone may also be in silent mode; this deliberately still plays the tone so
 * the operator gets feedback without looking, and the on-screen card is the
 * authority either way.
 */
class FeedbackPlayer(context: Context) {

    private val appContext: Context = context.applicationContext

    private val tone: ToneGenerator? = runCatching {
        ToneGenerator(AudioManager.STREAM_MUSIC, TONE_VOLUME)
    }.getOrNull()

    fun play(admitted: Boolean) {
        vibrate(admitted)
        runCatching {
            tone?.startTone(
                if (admitted) ToneGenerator.TONE_PROP_ACK else ToneGenerator.TONE_PROP_NACK,
                TONE_DURATION_MS,
            )
        }
    }

    fun release() {
        runCatching { tone?.release() }
    }

    private fun vibrate(admitted: Boolean) {
        val vibrator = vibrator() ?: return
        if (!vibrator.hasVibrator()) return

        runCatching {
            val pattern = if (admitted) {
                longArrayOf(0L, 50L, 50L, 50L)
            } else {
                longArrayOf(0L, 220L, 90L, 220L)
            }
            vibrator.vibrate(VibrationEffect.createWaveform(pattern, -1))
        }
    }

    private fun vibrator(): Vibrator? =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            (appContext.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as? VibratorManager)
                ?.defaultVibrator
        } else {
            @Suppress("DEPRECATION")
            appContext.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
        }

    private companion object {
        const val TONE_VOLUME = 90
        const val TONE_DURATION_MS = 170
    }
}
