/** Normalize the optional { schema: ... } import envelope without mutating input. */
export function unwrapSchemaRoot(data: unknown): Record<string, unknown> | null {
  if (!data || typeof data !== 'object' || Array.isArray(data)) return null

  const object = data as Record<string, unknown>
  const schema = object.schema
  if (schema && typeof schema === 'object' && !Array.isArray(schema)) {
    return schema as Record<string, unknown>
  }

  return object
}
