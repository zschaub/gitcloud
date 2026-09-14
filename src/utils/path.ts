/**
 * Last segment of a repository-relative path, for showing a file by name where the
 * full path would be too long (commit chips, the rollback panel header).
 *
 * @param path Repository-relative file path.
 */
export function fileName(path: string): string {
	return path.split('/').pop() ?? path
}
