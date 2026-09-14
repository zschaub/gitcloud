/**
 * Pulls the human-readable message out of a failed OCS request, falling back to a
 * caller-supplied default when the response isn't shaped like one (a network error,
 * an HTML error page, ...). Every GitCloud endpoint answers errors as
 * `{ ocs: { data: { status: 'error', message } } }`, so this is the one place that
 * shape is unwrapped.
 *
 * @param error The rejected request's error object.
 * @param fallback Message to use when no OCS message is present.
 */
export function extractErrorMessage(error: unknown, fallback: string): string {
	const axiosError = error as { response?: { data?: { ocs?: { data?: { message?: string } } } } }
	return axiosError.response?.data?.ocs?.data?.message ?? fallback
}

/**
 * The same for a response read as a Blob (the history-backup download uses
 * `responseType: 'blob'`, so an error body arrives unparsed rather than as JSON).
 *
 * @param error The rejected request's error object.
 * @param fallback Message to use when the blob isn't a parseable OCS error.
 */
export async function extractBlobErrorMessage(error: unknown, fallback: string): Promise<string> {
	const axiosError = error as { response?: { data?: Blob } }
	const blob = axiosError.response?.data
	if (!(blob instanceof Blob)) {
		return fallback
	}
	try {
		const parsed = JSON.parse(await blob.text())
		return parsed?.ocs?.data?.message ?? fallback
	} catch {
		return fallback
	}
}
