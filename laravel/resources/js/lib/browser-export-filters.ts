export type BrowserExportFilters = {
  year?: string | null
  semester?: string | null
  week?: number | string | null
  tambon?: string | null
  search?: string | null
}

/** Export the applied server filters, excluding Laravel's null values for empty form fields. */
export function browserExportQuery(scope: 'week' | 'all', filters: BrowserExportFilters): URLSearchParams {
  const query = new URLSearchParams({ scope })
  for (const key of ['year', 'semester', 'week', 'tambon', 'search'] as const) {
    const value = filters[key]
    if (value !== null && value !== undefined && value !== '') query.set(key, String(value))
  }
  return query
}
