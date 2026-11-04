package com.nabilet.checker.domain.util

import java.time.ZoneId
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class TimeFormatTest {

    private val utc = ZoneId.of("UTC")

    @Test
    fun `formats times in the given zone`() {
        assertEquals("00:00:00", TimeFormat.timeOfDay(0L, utc))
        assertEquals("22:13:20", TimeFormat.timeOfDay(EPOCH_2023_11_14, utc))
    }

    @Test
    fun `formats dates with a four digit year`() {
        assertEquals("01.01.1970", TimeFormat.date(0L, utc))
        assertEquals("14.11.2023", TimeFormat.date(EPOCH_2023_11_14, utc))
    }

    @Test
    fun `formats date and time together`() {
        assertEquals("01.01.1970 00:00", TimeFormat.dateTime(0L, utc))
        assertEquals("14.11.2023 22:13", TimeFormat.dateTime(EPOCH_2023_11_14, utc))
    }

    @Test
    fun `a non-utc zone shifts the calendar day`() {
        val moscow = ZoneId.of("Europe/Moscow")
        assertEquals("15.11.2023 01:13", TimeFormat.dateTime(EPOCH_2023_11_14, moscow))
    }

    @Test
    fun `writes an iso instant in utc for the sync payload`() {
        assertEquals("1970-01-01T00:00:00Z", TimeFormat.isoInstant(0L))
        assertEquals("2023-11-14T22:13:20Z", TimeFormat.isoInstant(EPOCH_2023_11_14))
    }

    @Test
    fun `splits a future duration into whole hours and minutes`() {
        val now = 1_000_000_000_000L
        assertEquals(
            TimeFormat.DurationParts(hours = 1, minutes = 30),
            TimeFormat.durationUntil(now + 90 * 60_000L, now),
        )
        assertEquals(
            TimeFormat.DurationParts(hours = 25, minutes = 0),
            TimeFormat.durationUntil(now + 25 * 60 * 60_000L, now),
        )
    }

    @Test
    fun `rounds sub-minute remainders down`() {
        val now = 1_000_000_000_000L
        assertEquals(
            TimeFormat.DurationParts(hours = 0, minutes = 0),
            TimeFormat.durationUntil(now + 59_000L, now),
        )
    }

    @Test
    fun `returns null once the deadline has passed`() {
        val now = 1_000_000_000_000L
        assertNull(TimeFormat.durationUntil(now, now))
        assertNull(TimeFormat.durationUntil(now - 1, now))
        assertNull(TimeFormat.durationUntil(0L, now))
    }

    private companion object {
        /** 2023-11-14T22:13:20Z */
        const val EPOCH_2023_11_14 = 1_700_000_000_000L
    }
}
