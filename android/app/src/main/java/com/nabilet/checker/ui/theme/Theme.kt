package com.nabilet.checker.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

/**
 * Brand and status colours.
 *
 * The three verdict colours are fixed rather than derived from the scheme: the
 * result card is what a doorman reads from two metres away under bad light, and
 * it must be green or red regardless of the phone's dark-mode setting. White
 * text on both is legible at WCAG AA for large text, which is the size the
 * card uses.
 */
object CheckerColors {
    val Admit: Color = Color(0xFF15803D)
    val Deny: Color = Color(0xFFB91C1C)
    val Warn: Color = Color(0xFFB45309)
    val Info: Color = Color(0xFF1D4ED8)
    val OnStatus: Color = Color(0xFFFFFFFF)
}

private val LightScheme = lightColorScheme(
    primary = Color(0xFF2C4BD8),
    onPrimary = Color(0xFFFFFFFF),
    primaryContainer = Color(0xFFDDE3FF),
    onPrimaryContainer = Color(0xFF0B1B57),
    secondary = Color(0xFF4A5A8A),
    onSecondary = Color(0xFFFFFFFF),
    secondaryContainer = Color(0xFFDDE2F5),
    onSecondaryContainer = Color(0xFF121B33),
    background = Color(0xFFF6F7FB),
    onBackground = Color(0xFF11131A),
    surface = Color(0xFFFFFFFF),
    onSurface = Color(0xFF11131A),
    surfaceVariant = Color(0xFFE7E9F2),
    onSurfaceVariant = Color(0xFF444A5A),
    outline = Color(0xFF767D90),
    error = Color(0xFFB3261E),
    onError = Color(0xFFFFFFFF),
)

private val DarkScheme = darkColorScheme(
    primary = Color(0xFF9DB2FF),
    onPrimary = Color(0xFF0A1A56),
    primaryContainer = Color(0xFF233B8F),
    onPrimaryContainer = Color(0xFFDDE3FF),
    secondary = Color(0xFFB7C3E8),
    onSecondary = Color(0xFF1E2A4D),
    secondaryContainer = Color(0xFF2B3555),
    onSecondaryContainer = Color(0xFFDDE2F5),
    background = Color(0xFF0B1220),
    onBackground = Color(0xFFE6E9F2),
    surface = Color(0xFF111A2B),
    onSurface = Color(0xFFE6E9F2),
    surfaceVariant = Color(0xFF1C2637),
    onSurfaceVariant = Color(0xFFB4BCCB),
    outline = Color(0xFF6F7889),
    error = Color(0xFFFFB4AB),
    onError = Color(0xFF690005),
)

@Composable
fun NabiletTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    // Dynamic colour is intentionally not used: a checker screen must look the
    // same on every phone in the venue, and the verdict colours are fixed.
    MaterialTheme(
        colorScheme = if (darkTheme) DarkScheme else LightScheme,
        content = content,
    )
}
