package com.nabilet.checker.domain.config

import java.net.URI

sealed interface ValidationResult<out T> {
    data class Ok<T>(val value: T) : ValidationResult<T>
    data class Error(val message: String) : ValidationResult<Nothing>
}

/**
 * Validation of the three values an operator can type: server address, device
 * token and session/bundle references. Kept pure so the rules are unit-tested
 * once and the UI cannot bypass them.
 */
object ConfigValidator {

    private val TOKEN = Regex("^nbck_[A-Za-z0-9]{16,128}$")
    private val REFERENCE = Regex("^[A-Za-z0-9_-]{1,64}$")

    /**
     * Accepts only an origin: scheme + host + optional port. A path, query,
     * fragment or user-info would silently change where requests go, so they
     * are rejected instead of "cleaned up".
     */
    fun normalizeBaseUrl(raw: String, allowCleartext: Boolean): ValidationResult<String> {
        val value = raw.trim()
        if (value.isEmpty()) return ValidationResult.Error("Укажите адрес сервера.")

        val uri = runCatching { URI(value) }.getOrNull()
            ?: return ValidationResult.Error("Адрес сервера указан неверно.")

        val scheme = uri.scheme?.lowercase()
        if (scheme != "https" && !(scheme == "http" && allowCleartext)) {
            return ValidationResult.Error(
                if (allowCleartext) "Адрес должен начинаться с https:// или http://."
                else "Адрес должен начинаться с https://.",
            )
        }

        // `URI.getHost()` keeps the square brackets around an IPv6 literal on
        // some JDKs, so they are stripped here and re-added when the authority
        // is rebuilt; otherwise the brackets would be doubled.
        val host = uri.host?.lowercase()?.removePrefix("[")?.removeSuffix("]")?.trim()
        if (host.isNullOrBlank()) return ValidationResult.Error("В адресе не указан хост.")
        if (!uri.userInfo.isNullOrBlank()) return ValidationResult.Error("Адрес не должен содержать логин и пароль.")
        if (!uri.rawQuery.isNullOrBlank()) return ValidationResult.Error("Уберите параметры из адреса.")
        if (!uri.rawFragment.isNullOrBlank()) return ValidationResult.Error("Уберите фрагмент из адреса.")

        val path = uri.rawPath.orEmpty()
        if (path.isNotEmpty() && path != "/") {
            return ValidationResult.Error("Укажите только адрес сервера, без пути.")
        }

        val authority = if (host.contains(':')) "[$host]" else host
        val normalized = buildString {
            append(scheme)
            append("://")
            append(authority)
            if (uri.port != -1) append(':').append(uri.port)
        }
        return ValidationResult.Ok(normalized)
    }

    fun validateToken(raw: String): ValidationResult<String> {
        val value = raw.trim()
        if (value.isEmpty()) return ValidationResult.Error("Укажите токен устройства.")
        if (!TOKEN.matches(value)) {
            return ValidationResult.Error("Токен должен начинаться с nbck_ и содержать только буквы и цифры.")
        }
        return ValidationResult.Ok(value)
    }

    /** Session ids are either internal numbers or public ids; both are accepted. */
    fun validateSessionReference(raw: String): ValidationResult<String> {
        val value = raw.trim()
        if (value.isEmpty()) return ValidationResult.Error("Укажите ID сеанса.")
        if (!REFERENCE.matches(value)) return ValidationResult.Error("ID сеанса содержит недопустимые символы.")
        return ValidationResult.Ok(value)
    }

    fun validateBundleReference(raw: String): ValidationResult<String> {
        val value = raw.trim()
        if (value.isEmpty()) return ValidationResult.Error("Укажите ID бандла.")
        if (!REFERENCE.matches(value)) return ValidationResult.Error("ID бандла содержит недопустимые символы.")
        return ValidationResult.Ok(value)
    }
}
