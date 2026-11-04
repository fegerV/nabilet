package com.nabilet.checker.domain.model

/**
 * Result of an operation that can fail for a reason an operator can act on.
 * Kept instead of `kotlin.Result` so the call site is forced to read the
 * message rather than the exception.
 */
sealed interface OpResult<out T> {
    data class Ok<T>(val value: T) : OpResult<T>
    data class Err(val message: String) : OpResult<Nothing>
}
