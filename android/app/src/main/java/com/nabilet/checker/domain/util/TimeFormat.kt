package com.nabilet.checker.domain.util

import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.temporal.ChronoUnit

/** Time formatting shared by the UI and the sync payload. */
object TimeFormat {

    private val TIME = DateTimeFormatter.ofPattern("HH:mm:ss")
    private val DATE_TIME = DateTimeFormatter.ofPattern("dd.MM.yyyy HH:mm")
    private val DATE = DateTimeFormatter.ofPattern("dd.MM.yyyy")

    fun timeOfDay(epochMillis: Long, zone: ZoneId = ZoneId.systemDefault()): String =
        TIME.withZone(zone).format(Instant.ofEpochMilli(epochMillis))

    fun dateTime(epochMillis: Long, zone: ZoneId = ZoneId.systemDefault()): String =
        DATE_TIME.withZone(zone).format(Instant.ofEpochMilli(epochMillis))

    fun date(epochMillis: Long, zone: ZoneId = ZoneId.systemDefault()): String =
        DATE.withZone(zone).format(Instant.ofEpochMilli(epochMillis))

    /** ISO-8601 in UTC, as Laravel's `date` rule expects it. */
    fun isoInstant(epochMillis: Long): String = Instant.ofEpochMilli(epochMillis).toString()

    /**
     * Human duration rounded down to minutes. Returns null for a moment in the
     * past; callers render "истёк" in that case.
     */
    fun durationUntil(targetMillis: Long, nowMillis: Long): DurationParts? {
        if (targetMillis <= nowMillis) return null
        val minutes = ChronoUnit.MINUTES.between(
            Instant.ofEpochMilli(nowMillis),
            Instant.ofEpochMilli(targetMillis),
        )
        val hours = minutes / 60
        val rest = minutes % 60
        return DurationParts(hours = hours, minutes = rest)
    }

    data class DurationParts(val hours: Long, val minutes: Long)
}
