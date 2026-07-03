<?php

declare(strict_types=1);

namespace OCA\GitCloud\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

/**
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController
{
    /**
     * Commits staged file changes using a provided message.
     *
     * @param array $data Expected to contain 'files' (array of paths) and 'message' (string).
     * @return DataResponse<Http::STATUS_OK, array{status: string, commitId?: string}, array{}>
     *
     * 200: Commit successful data returned.
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: "POST", url: "/commit")]
    public function commitChanges(array $data): DataResponse
    {
        // TODO: Implement actual Git logic here.
        // This method should retrieve staged files from the request body and create a git commit.
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

        // Placeholder for calling VcsService to commit changes:
        // $vcsService = $this->getVcsService(); // Assuming a way to access the service
        // $commitId = $vcsService->commit($files, $message);

        // For now, we return a mock success response.
        return new DataResponse(
            [
                "status" => "success",
                "message" => sprintf(
                    "Successfully committed %d files.",
                    count($files),
                ),
            ],
            Http::STATUS_OK,
        );
    }
}
