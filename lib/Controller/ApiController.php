<?php

declare(strict_types=1);

namespace OCA\GitCloud\Controller;

use OCA\GitCloud\Service\VcsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController
{
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
     * @param array $data Expected to contain 'files' (array of paths) and 'message' (string).
     * @return DataResponse<Http::STATUS_OK, array{status: string, message: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED, array{status: string, message: string}, array{}>
     *
     * 200: Commit successful, data returned.
     * 400: Missing or invalid data, or the commit failed.
     * 401: No user is logged in.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: "POST", url: "/commit")]
    public function commitChanges(array $data): DataResponse
    {
        $files = $data["files"] ?? [];
        $message = $data["message"] ?? "";

        if (empty($files) || empty($message)) {
            return new DataResponse(
                [
                    "status" => "error",
                    "message" => "Missing file paths or commit message.",
                ],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(
                [
                    "status" => "error",
                    "message" => "No user is logged in.",
                ],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        $userFolderStorage = $userFolder->getStorage();
        if (!$userFolderStorage->isLocal()) {
            return new DataResponse(
                [
                    "status" => "error",
                    "message" => "GitCloud only supports files stored on local storage.",
                ],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $repositoryPath = $userFolderStorage->getLocalFile($userFolder->getInternalPath());
        if ($repositoryPath === false) {
            return new DataResponse(
                [
                    "status" => "error",
                    "message" => "Unable to resolve the local storage path.",
                ],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $relativePaths = [];
        foreach ($files as $filePath) {
            if (!is_string($filePath) || $filePath === "") {
                continue;
            }

            try {
                $node = $userFolder->get($filePath);
            } catch (NotFoundException) {
                return new DataResponse(
                    [
                        "status" => "error",
                        "message" => sprintf("File not found: %s", $filePath),
                    ],
                    Http::STATUS_BAD_REQUEST,
                );
            }

            if (!$node->getStorage()->isLocal()) {
                return new DataResponse(
                    [
                        "status" => "error",
                        "message" => sprintf("File is not on local storage: %s", $filePath),
                    ],
                    Http::STATUS_BAD_REQUEST,
                );
            }

            $relativePaths[] = $userFolder->getRelativePath($node->getPath());
        }

        if (empty($relativePaths)) {
            return new DataResponse(
                [
                    "status" => "error",
                    "message" => "No valid files were provided.",
                ],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $result = $this->vcsService->commitChanges($repositoryPath, $relativePaths, $message);

        return new DataResponse(
            [
                "status" => $result["success"] ? "success" : "error",
                "message" => $result["message"],
            ],
            $result["success"] ? Http::STATUS_OK : Http::STATUS_BAD_REQUEST,
        );
    }
}
