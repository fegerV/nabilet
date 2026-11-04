package com.nabilet.checker.data.remote

import org.json.JSONObject

sealed interface ApiResult<out T> {
    data class Success<T>(val value: T) : ApiResult<T>

    data class HttpError(
        val status: Int,
        val code: String?,
        val message: String,
        val body: String?,
        val json: JSONObject?,
    ) : ApiResult<Nothing>

    data class NetworkError(val message: String) : ApiResult<Nothing>

    data class ParseError(val message: String) : ApiResult<Nothing>
}

data class HttpResponse(val json: JSONObject?, val body: String)

data class ScanResponse(
    val result: com.nabilet.checker.domain.model.CheckinResult,
    val message: String?,
    val ticketNumber: String?,
)

data class SyncResponse(
    val accepted: Int,
    val duplicates: Int,
    val conflicts: Int,
    val rejected: Int,
    val items: List<com.nabilet.checker.domain.model.SyncItemResult>,
)
