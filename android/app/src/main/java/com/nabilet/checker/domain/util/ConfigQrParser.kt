package com.nabilet.checker.domain.util

import org.json.JSONObject
import java.util.Base64
import java.nio.charset.Charset

/**
 * Результат разбора QR-кода конфигурации устройства.
 *
 * Администратор генерирует QR-код в формате:
 *     NABILET_CFG:<base64url(JSON)>
 * где JSON содержит сервер и токен, например:
 *     {"s":"https://nabilet.ru","t":"nbck_abcdef1234567890"}
 *
 * Это позволяет проверяющему настроить устройство одним сканированием,
 * без ручного ввода длинных адресов и токенов.
 */
data class CheckerConfigQr(
    val server: String,
    val token: String
)

sealed interface ConfigQrResult {
    data class Valid(val config: CheckerConfigQr) : ConfigQrResult
    data class Invalid(val error: String) : ConfigQrResult
}

object ConfigQrParser {

    private const val PREFIX = "NABILET_CFG:"

    fun parse(raw: String): ConfigQrResult {
        val text = raw.trim()
        if (text.isEmpty()) {
            return ConfigQrResult.Invalid("Пустой QR-код")
        }

        val jsonStr = if (text.startsWith(PREFIX)) {
            text.substring(PREFIX.length)
        } else {
            return ConfigQrResult.Invalid("Неизвестный формат конфигурации")
        }

        val jsonBytes = try {
            Base64.getUrlDecoder().decode(jsonStr)
        } catch (_: Exception) {
            return ConfigQrResult.Invalid("Некорректные данные конфигурации")
        }

        val json = try {
            JSONObject(String(jsonBytes, Charset.forName("UTF-8")))
        } catch (_: Exception) {
            return ConfigQrResult.Invalid("Некорректный JSON конфигурации")
        }

        val server = json.optString("server", "").takeIf { it.isNotBlank() }
            ?: return ConfigQrResult.Invalid("Отсутствует адрес сервера")
        val token = json.optString("token", "").takeIf { it.isNotBlank() }
            ?: return ConfigQrResult.Invalid("Отсутствует токен устройства")

        return ConfigQrResult.Valid(CheckerConfigQr(server, token))
    }
}
