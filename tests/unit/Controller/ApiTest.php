<?php

declare(strict_types=1);

namespace Controller;

use OCA\GitCloud\AppInfo\Application;
use OCA\GitCloud\Controller\ApiController;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase {
	public function testCommitChangesSucceedsWithFilesAndMessage(): void {
		$request = $this->createMock(IRequest::class);
		$controller = new ApiController(Application::APP_ID, $request);

		$response = $controller->commitChanges([
			'files' => ['file1.txt', 'file2.txt'],
			'message' => 'Initial commit',
		]);

		$this->assertEquals('success', $response->getData()['status']);
	}

	public function testCommitChangesFailsWithoutFilesOrMessage(): void {
		$request = $this->createMock(IRequest::class);
		$controller = new ApiController(Application::APP_ID, $request);

		$response = $controller->commitChanges([]);

		$this->assertEquals('error', $response->getData()['status']);
	}
}
