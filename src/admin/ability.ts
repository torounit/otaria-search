/**
 * Unwrap the payload of an Abilities API `/run` response.
 *
 * The run endpoint may return the ability output directly or wrapped in
 * `result`, `output` or `data`, so every caller goes through this helper.
 *
 * @param raw Raw apiFetch response.
 * @return The ability output.
 */
export function unwrapAbilityResponse< T >( raw: unknown ): T {
	const wrapped = raw as { result?: T; output?: T; data?: T } | null;

	return wrapped?.result ?? wrapped?.output ?? wrapped?.data ?? ( raw as T );
}
