<?php

declare(strict_types=1);

namespace OCA\GitCloud\Controller;

use OCA\GitCloud\Db\Snapshot;
use OCA\GitCloud\Service\VcsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private VcsService $vcsService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Commits staged file changes using a provided message.
	 *
	 * @param string[] $files File paths, relative to the user's storage, to stage and commit.
	 * @param string $message The commit message.
	 * @return DataResponse<Http::STATUS_OK, array{status: string, message: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED, array{status: string, message: string}, array{}>
	 *
	 * 200: Commit successful, data returned.
	 * 400: Missing or invalid data, or the commit failed.
	 * 401: No user is logged in.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/commit')]
	public function commitChanges(array $files = [], string $message = ''): DataResponse {
		if (empty($files) || empty($message)) {
			return new DataResponse(
				[
					'status' => 'error',
					'message' => 'Missing file paths or commit message.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$userFolder = $this->getUserFolderOrErrorResponse();
		if ($userFolder instanceof DataResponse) {
			return $userFolder;
		}

		$repositoryPath = $this->getRepositoryPathOrErrorResponse($userFolder);
		if ($repositoryPath instanceof DataResponse) {
			return $repositoryPath;
		}

		$relativePaths = [];
		foreach ($files as $filePath) {
			if (!is_string($filePath) || $filePath === '') {
				continue;
			}

			try {
				$node = $userFolder->get($filePath);
			} catch (NotFoundException) {
				return new DataResponse(
					[
						'status' => 'error',
						'message' => sprintf('File not found: %s', $filePath),
					],
					Http::STATUS_BAD_REQUEST,
				);
			}

			if (!$node->getStorage()->isLocal()) {
				return new DataResponse(
					[
						'status' => 'error',
						'message' => sprintf('File is not on local storage: %s', $filePath),
					],
					Http::STATUS_BAD_REQUEST,
				);
			}

			$relativePaths[] = ltrim($userFolder->getRelativePath($node->getPath()), '/');
		}

		if (empty($relativePaths)) {
			return new DataResponse(
				[
					'status' => 'error',
					'message' => 'No valid files were provided.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$result = $this->vcsService->commitChanges(
			$repositoryPath,
			$relativePaths,
			$message,
			$this->userSession->getUser()->getUID(),
		);

		return new DataResponse(
			[
				'status' => $result['success'] ? 'success' : 'error',
				'message' => $result['message'],
			],
			$result['success'] ? Http::STATUS_OK : Http::STATUS_BAD_REQUEST,
		);
	}

	/**
	 * Returns live dashboard stats (file/dir counts, total size, Git status)
	 * for the current user's GitCloud repository.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{status: string, fileCount: int, dirCount: int, totalSizeMb: float, gitStatus: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED, array{status: string, message: string}, array{}>
	 *
	 * 200: Status computed and returned.
	 * 400: Repository path could not be resolved or read.
	 * 401: No user is logged in.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/status')]
	public function getStatus(): DataResponse {
		$userFolder = $this->getUserFolderOrErrorResponse();
		if ($userFolder instanceof DataResponse) {
			return $userFolder;
		}

		$repositoryPath = $this->getRepositoryPathOrErrorResponse($userFolder);
		if ($repositoryPath instanceof DataResponse) {
			return $repositoryPath;
		}

		$result = $this->vcsService->getRepositoryStatus($repositoryPath);
		if (!$result['success']) {
			return new DataResponse(
				[
					'status' => 'error',
					'message' => $result['message'],
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		return new DataResponse(
			[
				'status' => 'success',
				'fileCount' => $result['fileCount'],
				'dirCount' => $result['dirCount'],
				'totalSizeMb' => round($result['totalSizeBytes'] / 1024 / 1024, 1),
				'gitStatus' => $result['gitStatus'],
			],
			Http::STATUS_OK,
		);
	}

	/**
	 * Returns the directories the current user has ever committed files under,
	 * each with the list of files tracked in it, for the dashboard's
	 * committed-directories list.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{status: string, directories: list<array{path: string, files: list<string>}>}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{status: string, message: string}, array{}>
	 *
	 * 200: Directories retrieved.
	 * 401: No user is logged in.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/directories')]
	public function getDirectories(): DataResponse {
		$userFolder = $this->getUserFolderOrErrorResponse();
		if ($userFolder instanceof DataResponse) {
			return $userFolder;
		}

		$userId = $this->userSession->getUser()->getUID();
		$directories = [];
		foreach ($this->vcsService->getCommittedDirectories($userId) as $directory) {
			// Snapshot rows for a file are kept even after the file itself is
			// deleted from Nextcloud, so this list must be filtered against
			// what's still actually on disk or it'll show ghost entries.
			$existingFiles = array_values(array_filter(
				$directory['files'],
				static fn (string $filePath): bool => $userFolder->nodeExists($filePath),
			));

			if ($existingFiles !== []) {
				$directories[] = ['path' => $directory['path'], 'files' => $existingFiles];
			}
		}

		return new DataResponse(
			[
				'status' => 'success',
				'directories' => $directories,
			],
			Http::STATUS_OK,
		);
	}

	/**
	 * Returns the snapshot history for a single file, newest first.
	 *
	 * @param string $filePath File path, relative to the user's storage, to fetch snapshots for.
	 * @return DataResponse<Http::STATUS_OK, array{status: string, snapshots: list<array{id: int, commitHash: string, message: string, status: string, createdAt: int, parentSnapshotId: int|null}>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED, array{status: string, message: string}, array{}>
	 *
	 * 200: Snapshots retrieved.
	 * 400: Missing file path, or the file could not be resolved.
	 * 401: No user is logged in.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/snapshots')]
	public function getSnapshots(string $filePath = ''): DataResponse {
		if ($filePath === '') {
			return new DataResponse(
				[
					'status' => 'error',
					'message' => 'Missing file path.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$userFolder = $this->getUserFolderOrErrorResponse();
		if ($userFolder instanceof DataResponse) {
			return $userFolder;
		}

		try {
			$node = $userFolder->get($filePath);
		} catch (NotFoundException) {
			return new DataResponse(
				[
					'status' => 'error',
					'message' => sprintf('File not found: %s', $filePath),
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$relativePath = ltrim($userFolder->getRelativePath($node->getPath()), '/');

		$snapshots = $this->vcsService->getSnapshotsForFile($this->userSession->getUser()->getUID(), $relativePath);

		return new DataResponse(
			[
				'status' => 'success',
				'snapshots' => array_map(static fn (Snapshot $snapshot): array => [
					'id' => $snapshot->getId(),
					'commitHash' => $snapshot->getCommitHash(),
					'message' => $snapshot->getMessage(),
					'status' => $snapshot->getStatus(),
					'createdAt' => $snapshot->getCreatedAt(),
					'parentSnapshotId' => $snapshot->getParentSnapshotId(),
				], $snapshots),
			],
			Http::STATUS_OK,
		);
	}

	/**
	 * Rolls back a single file to a previously recorded snapshot.
	 *
	 * @param string $filePath File path, relative to the user's storage, to roll back.
	 * @param int $snapshotId ID of the snapshot to restore the file to.
	 * @return DataResponse<Http::STATUS_OK, array{status: string, message: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED, array{status: string, message: string}, array{}>
	 *
	 * 200: Rollback successful, data returned.
	 * 400: Missing or invalid data, or the rollback failed.
	 * 401: No user is logged in.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/rollback')]
	public function rollbackSnapshot(string $filePath = '', int $snapshotId = 0): DataResponse {
		if ($filePath === '' || $snapshotId <= 0) {
			return new DataResponse(
				[
					'status' => 'error',
					'message' => 'Missing file path or snapshot ID.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$userFolder = $this->getUserFolderOrErrorResponse();
		if ($userFolder instanceof DataResponse) {
			return $userFolder;
		}

		$repositoryPath = $this->getRepositoryPathOrErrorResponse($userFolder);
		if ($repositoryPath instanceof DataResponse) {
			return $repositoryPath;
		}

		try {
			$node = $userFolder->get($filePath);
		} catch (NotFoundException) {
			return new DataResponse(
				[
					'status' => 'error',
					'message' => sprintf('File not found: %s', $filePath),
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		if (!$node->getStorage()->isLocal()) {
			return new DataResponse(
				[
					'status' => 'error',
					'message' => sprintf('File is not on local storage: %s', $filePath),
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$relativePath = ltrim($userFolder->getRelativePath($node->getPath()), '/');

		$result = $this->vcsService->rollbackToSnapshot(
			$repositoryPath,
			$relativePath,
			$snapshotId,
			$this->userSession->getUser()->getUID(),
		);

		return new DataResponse(
			[
				'status' => $result['success'] ? 'success' : 'error',
				'message' => $result['message'],
			],
			$result['success'] ? Http::STATUS_OK : Http::STATUS_BAD_REQUEST,
		);
	}

	/**
	 * Resolves the current user's Nextcloud folder, or an error DataResponse if none is logged in.
	 * @return Folder|DataResponse<Http::STATUS_UNAUTHORIZED, array{status: string, message: string}, array{}>
	 */
	private function getUserFolderOrErrorResponse(): Folder|DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(
				[
					'status' => 'error',
					'message' => 'No user is logged in.',
				],
				Http::STATUS_UNAUTHORIZED,
			);
		}

		return $this->rootFolder->getUserFolder($user->getUID());
	}

	/**
	 * Resolves the local filesystem path backing the given user folder, or an error DataResponse.
	 * @return string|DataResponse<Http::STATUS_BAD_REQUEST, array{status: string, message: string}, array{}>
	 */
	private function getRepositoryPathOrErrorResponse(Folder $userFolder): string|DataResponse {
		$userFolderStorage = $userFolder->getStorage();
		if (!$userFolderStorage->isLocal()) {
			return new DataResponse(
				[
					'status' => 'error',
					'message' => 'GitCloud only supports files stored on local storage.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$repositoryPath = $userFolderStorage->getLocalFile($userFolder->getInternalPath());
		if ($repositoryPath === false) {
			return new DataResponse(
				[
					'status' => 'error',
					'message' => 'Unable to resolve the local storage path.',
				],
				Http::STATUS_BAD_REQUEST,
			);
		}

		return $repositoryPath;
	}
}
