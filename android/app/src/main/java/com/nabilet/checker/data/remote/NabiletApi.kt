package com.nabilet.checker.data.remote

import com.nabilet.checker.BuildConfig
import com.nabilet.checker.domain.model.CheckinResult
import com.nabilet.checker.domain.model.ScanRecord
import com.nabilet.checker.domain.model.SyncItemResult
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.net.SocketTimeoutException
import java.util.concurrent.TimeUnit

/**
 * The four checker calls the app needs. Hand-written with OkHttp + org.json:
 * the response shapes are small, and a codegen/serialization plugin would add
 * build risk for no benefit.
 *
 * Endpoints (see `docs/openapi.yaml`):
 *
 *   POST /api/v1/checkin/validate   — token probe
 *   POST /api/v1/checkin/use        — authoritative admission
 *   POST /api/v1/checkin/sync       — offline scan reconciliation
 *   GET  /api/v1/offline-bundles/{id} — signed-by-hash ticket list
 */
class NabiletApi(private val client: OkHttpClient) {

    suspend fun validateCredentials(baseUrl: String, token: String): ApiResult<Unit> {
        val url = endpoint(baseUrl, "api/v1/checkin/validate") ?: return invalidUrl()
        val body = JSONObject()
            .put("qr_payload", PROBE_QR)
            .put("session_id", "0")
            .toString()

        return when (val result = execute(post(url, token, body), TIMEOUT_PROBE)) {
            // The server verifies the token BEFORE the QR, so a 200 with
            // `invalid_signature` is the expected answer for a valid device
            // token. A 401/403 means the token itself is not accepted.
            is ApiResult.Success -> ApiResult.Success(Unit)
            is ApiResult.HttpError -> result
            is ApiResult.NetworkError -> result
            is ApiResult.ParseError -> result
        }
    }

    suspend fun use(
        baseUrl: String,
        token: String,
        qrPayload: String,
        sessionId: String,
        clientScanId: String,
        scannedAtMillis: Long,
    ): ApiResult<ScanResponse> {
        val url = endpoint(baseUrl, "api/v1/checkin/use") ?: return invalidUrl()
        val body = JSONObject()
            .put("qr_payload", qrPayload)
            .put("session_id", sessionId)
            .put("client_scan_id", clientScanId)
            .put("scanned_at", com.nabilet.checker.domain.util.TimeFormat.isoInstant(scannedAtMillis))
            .put("mode", "online")
            .toString()

        return when (val result = execute(post(url, token, body), TIMEOUT_USE)) {
            is ApiResult.Success -> parseScan(result.value)
            is ApiResult.HttpError -> if (result.status == 409) parseScan(result) else result
            is ApiResult.NetworkError -> result
            is ApiResult.ParseError -> result
        }
    }

    suspend fun sync(
        baseUrl: String,
        token: String,
        deviceId: String,
        sessionId: String,
        scans: List<ScanRecord>,
    ): ApiResult<SyncResponse> {
        val url = endpoint(baseUrl, "api/v1/checkin/sync") ?: return invalidUrl()

        val array = JSONArray()
        for (scan in scans) {
            array.put(
                JSONObject()
                    .put("client_scan_id", scan.clientScanId)
                    .put("ticket_id", scan.ticketId)
                    .put("scanned_at", com.nabilet.checker.domain.util.TimeFormat.isoInstant(scan.scannedAt))
                    .put("result", scan.result.wire)
                    .put("mode", "offline"),
            )
        }
        val body = JSONObject()
            .put("device_id", deviceId)
            .put("session_id", sessionId)
            .put("scans", array)
            .toString()

        return when (val result = execute(post(url, token, body), TIMEOUT_SYNC)) {
            is ApiResult.Success -> parseSync(result.value)
            is ApiResult.HttpError -> result
            is ApiResult.NetworkError -> result
            is ApiResult.ParseError -> result
        }
    }

    suspend fun fetchBundle(
        baseUrl: String,
        token: String,
        bundleId: String,
    ): ApiResult<JSONObject> {
        val base = baseUrl.toHttpUrlOrNull() ?: return invalidUrl()
        val url = base.newBuilder()
            .addPathSegments("api/v1/offline-bundles")
            .addPathSegment(bundleId)
            .build()

        val request = Request.Builder()
            .url(url)
            .header("Accept", "application/json")
            .header("Authorization", "Bearer $token")
            .header("User-Agent", userAgent())
            .get()
            .build()

        return when (val result = execute(request, TIMEOUT_BUNDLE)) {
            is ApiResult.Success -> {
                val data = result.value.json?.optJSONObject("data")
                if (data == null) {
                    ApiResult.ParseError("Ответ сервера не содержит data")
                } else {
                    ApiResult.Success(data)
                }
            }
            is ApiResult.HttpError -> result
            is ApiResult.NetworkError -> result
            is ApiResult.ParseError -> result
        }
    }

    private fun parseScan(response: HttpResponse): ApiResult<ScanResponse> {
        val root = response.json ?: return ApiResult.ParseError("Пустой ответ сервера")
        val data = root.optJSONObject("data")
            ?: return ApiResult.ParseError("Ответ сервера не содержит data")
        val result = CheckinResult.fromWire(data.optString("result", ""))
            ?: return ApiResult.ParseError("Неизвестный результат проверки: ${data.optString("result")}")
        val ticket = data.optJSONObject("ticket")
        return ApiResult.Success(
            ScanResponse(
                result = result,
                message = data.optString("message").takeIf { it.isNotBlank() },
                ticketNumber = ticket?.optString("ticket_number")?.takeIf { it.isNotBlank() },
            ),
        )
    }

    private fun parseScan(error: ApiResult.HttpError): ApiResult<ScanResponse> {
        val data = error.json?.optJSONObject("data") ?: return error
        val result = CheckinResult.fromWire(data.optString("result", "")) ?: return error
        val ticket = data.optJSONObject("ticket")
        return ApiResult.Success(
            ScanResponse(
                result = result,
                message = data.optString("message").takeIf { it.isNotBlank() } ?: error.message,
                ticketNumber = ticket?.optString("ticket_number")?.takeIf { it.isNotBlank() },
            ),
        )
    }

    private fun parseSync(response: HttpResponse): ApiResult<SyncResponse> {
        val root = response.json ?: return ApiResult.ParseError("Пустой ответ сервера")
        val data = root.optJSONObject("data")
            ?: return ApiResult.ParseError("Ответ сервера не содержит data")

        val items = ArrayList<SyncItemResult>()
        val results = data.optJSONArray("results")
        if (results != null) {
            for (index in 0 until results.length()) {
                val item = results.optJSONObject(index) ?: continue
                items.add(
                    SyncItemResult(
                        clientScanId = item.optString("client_scan_id"),
                        result = CheckinResult.fromWire(item.optString("result", "")),
                        message = item.optString("message").takeIf { it.isNotBlank() },
                    ),
                )
            }
        }

        return ApiResult.Success(
            SyncResponse(
                accepted = data.optInt("accepted", 0),
                duplicates = data.optInt("duplicates", 0),
                conflicts = data.optInt("conflicts", 0),
                rejected = data.optInt("rejected", 0),
                items = items,
            ),
        )
    }

    private suspend fun execute(request: Request, timeoutSeconds: Long): ApiResult<HttpResponse> =
        withContext(Dispatchers.IO) {
            try {
                val call = client.newCall(request)
                call.timeout().timeout(timeoutSeconds, TimeUnit.SECONDS)
                call.execute().use { response ->
                    val text = response.body?.string().orEmpty()
                    val json = if (text.isBlank()) null else runCatching { JSONObject(text) }.getOrNull()

                    if (response.isSuccessful) {
                        ApiResult.Success(HttpResponse(json, text))
                    } else {
                        ApiResult.HttpError(
                            status = response.code,
                            code = json?.optJSONObject("error")?.optString("code")?.takeIf { it.isNotBlank() },
                            message = errorMessage(json, response.code),
                            body = text,
                            json = json,
                        )
                    }
                }
            } catch (e: SocketTimeoutException) {
                ApiResult.NetworkError("Сервер не ответил вовремя.")
            } catch (e: IOException) {
                ApiResult.NetworkError(e.message ?: "Нет связи с сервером.")
            } catch (e: Exception) {
                ApiResult.ParseError(e.message ?: "Не удалось прочитать ответ сервера.")
            }
        }

    private fun post(url: HttpUrl, token: String, body: String): Request =
        Request.Builder()
            .url(url)
            .header("Accept", "application/json")
            .header("Authorization", "Bearer $token")
            .header("User-Agent", userAgent())
            .post(body.toRequestBody(JSON_MEDIA_TYPE))
            .build()

    private fun endpoint(baseUrl: String, path: String): HttpUrl? =
        baseUrl.toHttpUrlOrNull()?.newBuilder()?.addPathSegments(path)?.build()

    private fun errorMessage(json: JSONObject?, status: Int): String {
        val fromEnvelope = json?.optJSONObject("error")?.optString("message")?.takeIf { it.isNotBlank() }
        if (fromEnvelope != null) return fromEnvelope
        val fromRoot = json?.optString("message")?.takeIf { it.isNotBlank() }
        if (fromRoot != null) return fromRoot
        return when (status) {
            401 -> "Токен устройства не принят."
            403 -> "Доступ запрещён."
            404 -> "Не найдено."
            422 -> "Сервер отклонил данные запроса."
            429 -> "Слишком много запросов. Повторите позже."
            in 500..599 -> "Ошибка сервера ($status)."
            else -> "Ошибка сервера ($status)."
        }
    }

    private fun userAgent(): String = "NabiletChecker/${BuildConfig.VERSION_NAME} (Android)"

    private fun invalidUrl(): ApiResult.ParseError =
        ApiResult.ParseError("Некорректный адрес сервера.")

    private companion object {
        const val PROBE_QR = "NB1.probe.probe.probe"
        const val TIMEOUT_PROBE = 15L
        const val TIMEOUT_USE = 12L
        const val TIMEOUT_SYNC = 30L
        const val TIMEOUT_BUNDLE = 120L
        val JSON_MEDIA_TYPE = "application/json; charset=utf-8".toMediaType()
    }
}
